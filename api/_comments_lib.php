<?php
/* Thư viện CHĂM SÓC BÌNH LUẬN — dùng chung cho api/comments.php (người dùng) và cron/run.php (kéo bình luận mỗi 5 phút)
   và _automation_lib.php (kịch bản: trả lời / ẩn / tạo đầu mối). Yêu cầu đã require _boot.php.

   fetch_new_comments($CFG)                → chuỗi tóm tắt 1 dòng (cron)
   fetch_connection_comments($CFG, $conn)  → ['new'=>n, 'seen'=>n] hoặc ném CommentError
   comment_reply($CFG, $c, $text, $uid)    → ['ok'=>bool, 'error'?]
   comment_hide($CFG, $c, $hide)           → ['ok'=>bool, 'error'?]
   comment_classify($message)              → ['intent','sentiment','phone']
   comment_to_lead($c, $phone)             → mã đầu mối | null

   Nguồn: Fanpage (Graph API: bài mới nhất + bình luận), YouTube (commentThreads của kênh) và đánh giá Google Maps
   (Google Business Profile, API v4 — trả lời được, không ẩn được).
   TikTok: Display API không cho đọc bình luận — chưa hỗ trợ. */
require_once __DIR__ . '/_publish_lib.php'; // meta_page_token()
require_once __DIR__ . '/_metrics_lib.php'; // gbp_reviews()

class CommentError extends RuntimeException {}

const COMMENT_PROVIDERS = ['facebook_page', 'youtube', 'gbp', 'zalo_oa'];
const COMMENT_FB_POSTS = 15;    // số bài mới nhất của Page quét mỗi lần
const COMMENT_YT_THREADS = 50;  // số luồng bình luận mới nhất của kênh mỗi lần

/** Thời điểm nền tảng → 'YYYY-MM-DD HH:MM:SS' UTC. */
function cm_utc(?string $t): ?string {
    if (!$t) return null;
    $ts = strtotime($t);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

/** Ghi trạng thái lần kéo gần nhất của một kênh (hiện ở trang Chăm sóc). */
function cm_fetch_status(string $cid, array $st): void {
    setting_set('comments.fetch.' . $cid, json_encode($st + ['at' => gmdate('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE), ['id' => null]);
}

/** Phân loại nhanh bằng từ khoá (không tốn AI): ý định, cảm xúc, số điện thoại. */
function comment_classify(string $m): array {
    $t = mb_strtolower($m);
    $phone = null;
    if (preg_match('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', $m, $mm)) $phone = preg_replace('/\D/', '', $mm[0]);
    $has = fn(array $ws) => (bool)array_filter($ws, fn($w) => str_contains($t, $w));
    $intent = 'khac';
    if (preg_match('#https?://|t\.me/|bit\.ly#', $t) && mb_strlen($t) < 400 && $has(['kiếm tiền', 'đầu tư', 'casino', 'cá cược', 'vay', 'free', 'click'])) $intent = 'spam';
    elseif ($has(['lừa', 'tệ', 'kém', 'chán', 'thất vọng', 'không hài lòng', 'bực', 'scam', 'lỗi', 'hỏng', 'chậm quá', 'trả lại tiền', 'khiếu nại'])) $intent = 'khieu_nai';
    elseif ($has(['giá', 'bao nhiêu', 'bn ', 'báo giá', 'chi phí', 'phí', 'price', 'how much', 'ib', 'inbox', 'tư vấn'])) $intent = 'hoi_gia';
    elseif ($has(['đặt', 'đăng ký', 'mua', 'order', 'liên hệ', 'gọi', 'sđt', 'zalo']) || $phone) $intent = 'dat_lich';
    elseif ($has(['hay', 'tuyệt', 'đẹp', 'cảm ơn', 'thank', 'love', 'great', 'nice', 'good', 'dễ thương', 'cute', '❤', '😍', '👍'])) $intent = 'khen';
    $sentiment = $intent === 'khieu_nai' ? 'negative' : ($intent === 'khen' ? 'positive' : 'neutral');
    return ['intent' => $intent, 'sentiment' => $sentiment, 'phone' => $phone];
}

/** Lưu một bình luận nếu chưa có. Trả về id nếu là bình luận MỚI, null nếu đã có. */
function cm_store(array $conn, string $provider, array $r): ?string {
    global $pdo;
    $id = $provider . ':' . $r['external_id'];
    $ex = $pdo->prepare('SELECT status FROM comments WHERE id=?');
    $ex->execute([$id]);
    $cur = $ex->fetchColumn();
    if ($cur !== false) {
        // Chủ kênh đã trả lời trực tiếp trên nền tảng → đánh dấu đã trả lời.
        if (!empty($r['owner_replied']) && $cur === 'new') $pdo->prepare("UPDATE comments SET status='replied', replied_at=COALESCE(replied_at, datetime('now')) WHERE id=?")->execute([$id]);
        return null;
    }
    $cl = comment_classify((string)$r['message']);
    if (isset($r['stars'])) { // đánh giá sao: cảm xúc theo số sao
        if ($r['stars'] <= 2) { $cl['sentiment'] = 'negative'; $cl['intent'] = 'khieu_nai'; }
        elseif ($r['stars'] >= 4 && $cl['intent'] === 'khac') { $cl['sentiment'] = 'positive'; $cl['intent'] = 'khen'; }
    }
    $pdo->prepare("INSERT INTO comments(id,project_id,connection_id,provider,external_id,parent_external_id,post_external_id,post_permalink,post_excerpt,
        author_name,author_id,message,created_time,status,intent,sentiment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $conn['project_id'] ?? null, $conn['id'], $provider, $r['external_id'], $r['parent'] ?? null, $r['post_id'] ?? null, $r['post_link'] ?? null,
            mb_substr((string)($r['post_text'] ?? ''), 0, 200), $r['author'] ?? null, $r['author_id'] ?? null, (string)$r['message'],
            $r['created'] ?? null, !empty($r['owner_replied']) ? 'replied' : 'new', $cl['intent'], $cl['sentiment']]);
    return $id;
}

/** Kéo bình luận mới của một kênh. */
function fetch_connection_comments(array $CFG, array $conn): array {
    if (!in_array($conn['provider'], COMMENT_PROVIDERS, true)) throw new CommentError('Loại kênh chưa hỗ trợ đọc bình luận');
    if ($conn['status'] !== 'connected' || !$conn['external_account_id']) throw new CommentError('Kênh chưa kết nối');
    $rows = match ($conn['provider']) { 'facebook_page' => cm_fetch_facebook($CFG, $conn), 'gbp' => cm_fetch_gbp($CFG, $conn), 'zalo_oa' => cm_fetch_zalo($conn), default => cm_fetch_youtube($CFG, $conn) };
    // Đánh giá Google Maps: giữ 90 ngày (ít hơn bình luận nhiều). Bình luận cũ hơn 30 ngày không đưa vào hộp (tránh lần đầu kéo về cả đống việc tồn).
    $cut = gmdate('Y-m-d H:i:s', time() - ($conn['provider'] === 'gbp' ? 90 : 30) * 86400);
    $new = [];
    foreach ($rows as $r) {
        if (($r['created'] ?? '') !== '' && $r['created'] < $cut) continue;
        if ($id = cm_store($conn, $conn['provider'], $r)) $new[] = $id;
    }
    // Lần kéo đầu tiên của kênh: không bắn sự kiện kịch bản cho bình luận cũ (tránh tự trả lời hàng loạt).
    $first = setting_get('comments.fetch.' . $conn['id']) === null;
    if (!$first) foreach ($new as $id) fire_event('comment.new', ['comment_id' => $id]);
    cm_fetch_status($conn['id'], ['ok' => true, 'new' => count($new), 'seen' => count($rows)]);
    return ['new' => count($new), 'seen' => count($rows)];
}

function cm_fetch_facebook(array $CFG, array $conn): array {
    try { $token = meta_page_token($CFG, $conn); } catch (PublishError $e) { throw new CommentError($e->getMessage()); }
    $pageId = $conn['external_account_id'];
    [$c, $d] = http_get_json(graph_url($CFG, rawurlencode($pageId) . '/feed', [
        'fields' => 'id,message,permalink_url,created_time,comments.filter(stream).order(reverse_chronological).limit(50){id,message,from,created_time,parent{id}}',
        'limit' => COMMENT_FB_POSTS, 'access_token' => $token,
    ]));
    if ($c !== 200) {
        $msg = $d['error']['message'] ?? "HTTP $c";
        if (preg_match('/permission|pages_read_user_content|pages_read_engagement/i', $msg)) $msg .= ' — Page token cần quyền pages_read_engagement + pages_read_user_content (trả lời cần thêm pages_manage_engagement)';
        throw new CommentError('Facebook: ' . $msg);
    }
    $out = [];
    foreach ($d['data'] ?? [] as $post) {
        $cms = $post['comments']['data'] ?? [];
        // Bình luận đã có Page trả lời (Page trả lời dưới bình luận gốc) → coi như đã trả lời.
        $answered = [];
        foreach ($cms as $cm) if (($cm['from']['id'] ?? '') === $pageId && !empty($cm['parent']['id'])) $answered[$cm['parent']['id']] = true;
        foreach ($cms as $cm) {
            if (($cm['from']['id'] ?? '') === $pageId) continue; // bình luận của chính Page
            if (trim((string)($cm['message'] ?? '')) === '') continue;
            $out[] = ['external_id' => $cm['id'], 'parent' => $cm['parent']['id'] ?? null, 'post_id' => $post['id'],
                'post_link' => $post['permalink_url'] ?? null, 'post_text' => $post['message'] ?? '',
                'author' => $cm['from']['name'] ?? 'Người dùng Facebook', 'author_id' => $cm['from']['id'] ?? null,
                'message' => $cm['message'], 'created' => cm_utc($cm['created_time'] ?? null), 'owner_replied' => !empty($answered[$cm['id']])];
        }
    }
    return $out;
}

/** Header xác thực YouTube: OAuth (đọc + trả lời được) nếu có, không thì API key (chỉ đọc). */
function cm_yt_auth(array $CFG, array $conn, bool $needWrite = false): array {
    $oauth = ($conn['auth_method'] ?? 'oauth') !== 'manual' || !empty($conn['refresh_token']);
    if ($oauth) {
        $tok = google_access_token($CFG, $conn);
        if ($tok) return [['Authorization: Bearer ' . $tok], []];
        if ($needWrite) throw new CommentError('YouTube: không làm mới được token — kiểm tra Client ID / Secret / Refresh Token của kênh');
    }
    if ($needWrite) throw new CommentError('YouTube: trả lời/ẩn bình luận cần OAuth của kênh (Client ID + Secret + Refresh Token, quyền youtube.force-ssl)');
    $key = $conn['secret'] ?: ($CFG['youtube_api_key'] ?? '');
    if (!$key) throw new CommentError('YouTube: kênh chưa có token / API key để đọc bình luận');
    return [[], ['key' => $key]];
}

function cm_fetch_youtube(array $CFG, array $conn): array {
    $ch = $conn['external_account_id'];
    [$h, $q] = cm_yt_auth($CFG, $conn);
    [$c, $d] = http_get_json('https://www.googleapis.com/youtube/v3/commentThreads?' . http_build_query($q + [
        'part' => 'snippet,replies', 'allThreadsRelatedToChannelId' => $ch, 'maxResults' => COMMENT_YT_THREADS, 'order' => 'time', 'textFormat' => 'plainText',
    ]), $h);
    if ($c !== 200 && $h) { // token thiếu quyền đọc (chỉ có youtube.upload) → thử API key
        $key = $conn['secret'] ?: ($CFG['youtube_api_key'] ?? '');
        if ($key) [$c, $d] = http_get_json('https://www.googleapis.com/youtube/v3/commentThreads?' . http_build_query([
            'part' => 'snippet,replies', 'allThreadsRelatedToChannelId' => $ch, 'maxResults' => COMMENT_YT_THREADS, 'order' => 'time', 'textFormat' => 'plainText', 'key' => $key]));
    }
    if ($c !== 200) {
        $msg = $d['error']['message'] ?? "HTTP $c";
        if ($c === 403 && preg_match('/insufficient|scope/i', $msg)) $msg .= ' — token cần quyền youtube.force-ssl (hoặc youtube.readonly) để đọc bình luận';
        throw new CommentError('YouTube: ' . $msg);
    }
    $out = [];
    foreach ($d['items'] ?? [] as $th) {
        $top = $th['snippet']['topLevelComment'] ?? null;
        if (!$top) continue;
        $s = $top['snippet'];
        $vid = $th['snippet']['videoId'] ?? ($s['videoId'] ?? null);
        $replies = $th['replies']['comments'] ?? [];
        $ownerReplied = (bool)array_filter($replies, fn($r) => ($r['snippet']['authorChannelId']['value'] ?? '') === $ch);
        if (($s['authorChannelId']['value'] ?? '') !== $ch) {
            $out[] = ['external_id' => $top['id'], 'parent' => null, 'post_id' => $vid,
                'post_link' => $vid ? 'https://www.youtube.com/watch?v=' . $vid . '&lc=' . $top['id'] : null, 'post_text' => '',
                'author' => $s['authorDisplayName'] ?? 'Người xem YouTube', 'author_id' => $s['authorChannelId']['value'] ?? null,
                'message' => (string)($s['textOriginal'] ?? $s['textDisplay'] ?? ''), 'created' => cm_utc($s['publishedAt'] ?? null), 'owner_replied' => $ownerReplied];
        }
        // Trả lời của người xem khác trong luồng (YouTube chỉ trả vài trả lời gần nhất).
        foreach ($replies as $rp) {
            $rs = $rp['snippet'];
            if (($rs['authorChannelId']['value'] ?? '') === $ch) continue;
            $out[] = ['external_id' => $rp['id'], 'parent' => $top['id'], 'post_id' => $vid,
                'post_link' => $vid ? 'https://www.youtube.com/watch?v=' . $vid . '&lc=' . $rp['id'] : null, 'post_text' => '',
                'author' => $rs['authorDisplayName'] ?? 'Người xem YouTube', 'author_id' => $rs['authorChannelId']['value'] ?? null,
                'message' => (string)($rs['textOriginal'] ?? $rs['textDisplay'] ?? ''), 'created' => cm_utc($rs['publishedAt'] ?? null), 'owner_replied' => false];
        }
    }
    return $out;
}

/** Zalo OA: tin nhắn gần nhất của từng người (listrecentchat). Tin cuối là của OA → coi như đã trả lời. */
function cm_fetch_zalo(array $conn): array {
    [$tok, $err] = zalo_access_token($conn);
    if (!$tok) throw new CommentError($err);
    $oa = (string)$conn['external_account_id']; $out = [];
    foreach ([0, 10, 20, 30, 40] as $off) {
        [$c, $d] = http_get_json('https://openapi.zalo.me/v2.0/oa/listrecentchat?data=' . rawurlencode(json_encode(['offset' => $off, 'count' => 10])), ['access_token: ' . $tok]);
        if ($c !== 200 || ($d['error'] ?? 0) != 0) { if ($off === 0) throw new CommentError('Zalo OA: ' . ($d['message'] ?? "HTTP $c")); break; }
        $rows = $d['data'] ?? [];
        foreach ($rows as $m) {
            $fromOa = (string)($m['from_id'] ?? '') === $oa || (int)($m['src'] ?? 1) === 0;
            $uid = $fromOa ? (string)($m['to_id'] ?? '') : (string)($m['from_id'] ?? '');
            if ($fromOa) { // tin cuối của OA: đánh dấu tin khách trước đó đã trả lời
                $GLOBALS['pdo']->prepare("UPDATE comments SET status='replied', replied_at=COALESCE(replied_at, datetime('now')) WHERE provider='zalo_oa' AND connection_id=? AND author_id=? AND status='new'")->execute([$conn['id'], $uid]);
                continue;
            }
            $txt = ($m['type'] ?? 'text') === 'text' ? (string)($m['message'] ?? '') : '[' . ($m['type'] ?? 'tệp') . '] ' . (string)($m['message'] ?? '');
            $out[] = ['external_id' => (string)$m['message_id'], 'post_id' => $uid, 'post_link' => null, 'post_text' => 'Tin nhắn Zalo OA',
                'author' => $m['from_display_name'] ?? 'Khách Zalo', 'author_id' => $uid, 'message' => $txt,
                'created' => gmdate('Y-m-d H:i:s', (int)(($m['time'] ?? 0) / 1000)), 'owner_replied' => false];
        }
        if (count($rows) < 10) break;
    }
    return $out;
}

function cm_fetch_gbp(array $CFG, array $conn): array {
    $tok = google_access_token($CFG, $conn);
    if (!$tok) throw new CommentError('Google Business Profile: không làm mới được token — kiểm tra Client ID / Secret / Refresh Token');
    $rv = gbp_reviews($conn, ['Authorization: Bearer ' . $tok], 50);
    if (!$rv['ok']) throw new CommentError('Google Business Profile: ' . $rv['error']);
    $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
    $out = [];
    foreach ($rv['reviews'] as $r) {
        $n = $stars[$r['starRating'] ?? ''] ?? 0;
        $txt = trim((string)($r['comment'] ?? ''));
        // Google gắn bản dịch "(Translated by Google)…" — giữ phần gốc.
        if (($p = strpos($txt, '(Original)')) !== false) $txt = trim(substr($txt, $p + 10));
        $out[] = ['external_id' => $r['name'], 'stars' => $n, 'post_id' => $rv['resource'], 'post_link' => null,
            'post_text' => 'Đánh giá Google Maps', 'author' => $r['reviewer']['displayName'] ?? 'Người dùng Google',
            'message' => str_repeat('★', $n) . str_repeat('☆', 5 - $n) . ($txt !== '' ? ' ' . $txt : ' (chỉ chấm sao)'),
            'created' => cm_utc($r['createTime'] ?? null), 'owner_replied' => !empty($r['reviewReply']['comment'])];
    }
    return $out;
}

/** Cron: kéo bình luận mọi kênh hỗ trợ. */
function fetch_new_comments(array $CFG = []): string {
    global $pdo;
    if (!$CFG) $CFG = $GLOBALS['CFG'];
    $conns = $pdo->query("SELECT * FROM connections WHERE status='connected' AND external_account_id IS NOT NULL AND provider IN ('facebook_page','youtube','gbp','zalo_oa')")->fetchAll();
    $new = 0; $errs = [];
    foreach ($conns as $conn) {
        try { $new += fetch_connection_comments($CFG, $conn)['new']; }
        catch (Throwable $e) { cm_fetch_status($conn['id'], ['ok' => false, 'error' => $e->getMessage()]); $errs[] = $conn['label'] . ': ' . $e->getMessage(); }
    }
    return count($conns) . " kênh, $new bình luận mới" . ($errs ? '; lỗi: ' . implode(' | ', array_map(fn($e) => mb_substr($e, 0, 120), $errs)) : '');
}

/** Trả lời công khai một bình luận. */
function comment_reply(array $CFG, array $c, string $text, string $uid): array {
    global $pdo;
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'Nội dung trả lời trống'];
    $conn = get_connection($c['connection_id']);
    if (!$conn) return ['ok' => false, 'error' => 'Kênh của bình luận đã bị xoá'];
    try {
        if ($c['provider'] === 'facebook_page') {
            $token = meta_page_token($CFG, $conn);
            [$code, $d] = http_post_form(graph_url($CFG, rawurlencode($c['external_id']) . '/comments'), ['message' => $text, 'access_token' => $token]);
            if ($code !== 200 || empty($d['id'])) {
                $m = $d['error']['message'] ?? "HTTP $code";
                if (preg_match('/permission|pages_manage_engagement/i', $m)) $m .= ' — Page token cần quyền pages_manage_engagement';
                return ['ok' => false, 'error' => 'Facebook: ' . $m];
            }
            $rid = $d['id'];
        } elseif ($c['provider'] === 'youtube') {
            [$h] = cm_yt_auth($CFG, $conn, true);
            // Trả lời luôn gắn vào bình luận gốc của luồng (YouTube không cho trả lời lồng nhiều cấp).
            [$code, $d] = http_call('POST', 'https://www.googleapis.com/youtube/v3/comments?part=snippet', ['headers' => $h,
                'json' => ['snippet' => ['parentId' => $c['parent_external_id'] ?: $c['external_id'], 'textOriginal' => $text]]]);
            if ($code !== 200 || empty($d['id'])) {
                $m = $d['error']['message'] ?? "HTTP $code";
                if ($code === 403) $m .= ' — token cần quyền youtube.force-ssl';
                return ['ok' => false, 'error' => 'YouTube: ' . $m];
            }
            $rid = $d['id'];
        } elseif ($c['provider'] === 'zalo_oa') {
            [$tok, $err] = zalo_access_token($conn);
            if (!$tok) return ['ok' => false, 'error' => $err];
            [$code, $d] = http_call('POST', 'https://openapi.zalo.me/v3.0/oa/message/cs', ['headers' => ['access_token: ' . $tok],
                'json' => ['recipient' => ['user_id' => $c['author_id']], 'message' => ['text' => $text]]]);
            if ($code !== 200 || ($d['error'] ?? 0) != 0) return ['ok' => false, 'error' => 'Zalo OA: ' . ($d['message'] ?? "HTTP $code") . ' (tin tư vấn chỉ gửi được trong khung thời gian Zalo cho phép sau tin cuối của khách)'];
            $rid = (string)($d['data']['message_id'] ?? '');
        } elseif ($c['provider'] === 'gbp') {
            $tok = google_access_token($CFG, $conn);
            if (!$tok) return ['ok' => false, 'error' => 'Google Business Profile: token hết hạn — kiểm tra Refresh Token'];
            [$code, $d] = http_call('PUT', 'https://mybusiness.googleapis.com/v4/' . $c['external_id'] . '/reply', ['headers' => ['Authorization: Bearer ' . $tok], 'json' => ['comment' => $text]]);
            if ($code !== 200) return ['ok' => false, 'error' => 'Google Business Profile: ' . ($d['error']['message'] ?? "HTTP $code")];
            $rid = $c['external_id'] . '/reply';
        } else return ['ok' => false, 'error' => 'Nền tảng chưa hỗ trợ trả lời'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $pdo->prepare("UPDATE comments SET status='replied', reply_text=?, replied_by=?, replied_at=datetime('now'), reply_external_id=? WHERE id=?")
        ->execute([$text, $uid ?: null, $rid, $c['id']]);
    return ['ok' => true, 'reply_id' => $rid];
}

/** Ẩn (hoặc hiện lại) bình luận. YouTube: chuyển sang "chờ duyệt" (heldForReview) / bỏ ẩn = published. */
function comment_hide(array $CFG, array $c, bool $hide = true): array {
    global $pdo;
    $conn = get_connection($c['connection_id']);
    if (!$conn) return ['ok' => false, 'error' => 'Kênh của bình luận đã bị xoá'];
    try {
        if ($c['provider'] === 'facebook_page') {
            [$code, $d] = http_post_form(graph_url($CFG, rawurlencode($c['external_id'])), ['is_hidden' => $hide ? 'true' : 'false', 'access_token' => meta_page_token($CFG, $conn)]);
            if ($code !== 200) return ['ok' => false, 'error' => 'Facebook: ' . ($d['error']['message'] ?? "HTTP $code")];
        } elseif ($c['provider'] === 'youtube') {
            [$h] = cm_yt_auth($CFG, $conn, true);
            [$code, $d] = http_call('POST', 'https://www.googleapis.com/youtube/v3/comments/setModerationStatus?' . http_build_query(['id' => $c['external_id'], 'moderationStatus' => $hide ? 'heldForReview' : 'published']), ['headers' => $h]);
            if ($code !== 204 && $code !== 200) return ['ok' => false, 'error' => 'YouTube: ' . ($d['error']['message'] ?? "HTTP $code")];
        } else return ['ok' => false, 'error' => 'Nền tảng chưa hỗ trợ ẩn'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $pdo->prepare('UPDATE comments SET status=? WHERE id=?')->execute([$hide ? 'hidden' : 'new', $c['id']]);
    return ['ok' => true];
}

/** Gửi tin tư vấn Zalo OA tới đầu mối: tìm tin Zalo gần nhất của khách đã gắn với đầu mối (hoặc cùng SĐT trong tin). */
function zalo_send_to_lead(array $lead, string $text): array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM comments WHERE provider='zalo_oa' AND (lead_id=? OR (? <> '' AND message LIKE ?)) ORDER BY COALESCE(created_time,fetched_at) DESC LIMIT 1");
    $ph = preg_replace('/\D/', '', (string)($lead['phone'] ?? ''));
    $st->execute([$lead['id'], $ph, '%' . substr($ph, -9) . '%']);
    $c = $st->fetch();
    if (!$c) return ['ok' => false, 'skip' => true, 'error' => 'đầu mối chưa từng nhắn Zalo OA (Zalo chỉ cho OA nhắn người đã tương tác)'];
    $conn = get_connection($c['connection_id']);
    if (!$conn) return ['ok' => false, 'error' => 'kênh Zalo OA đã bị xoá'];
    [$tok, $err] = zalo_access_token($conn);
    if (!$tok) return ['ok' => false, 'error' => $err];
    [$code, $d] = http_call('POST', 'https://openapi.zalo.me/v3.0/oa/message/cs', ['headers' => ['access_token: ' . $tok],
        'json' => ['recipient' => ['user_id' => $c['author_id']], 'message' => ['text' => mb_substr($text, 0, 2000)]]]);
    if ($code !== 200 || ($d['error'] ?? 0) != 0) return ['ok' => false, 'error' => 'Zalo OA: ' . ($d['message'] ?? "HTTP $code")];
    return ['ok' => true];
}

/** Tạo đầu mối từ bình luận (thường là bình luận có số điện thoại). */
function comment_to_lead(array $c, ?string $phone): ?string {
    global $pdo;
    if (!empty($c['lead_id'])) return $c['lead_id'];
    $id = gen_id('L');
    $src = ['facebook_page' => 'Facebook', 'youtube' => 'YouTube', 'instagram' => 'Instagram', 'gbp' => 'Google Maps', 'zalo_oa' => 'Zalo'][$c['provider']] ?? $c['provider'];
    $pdo->prepare('INSERT INTO leads(id,project_id,name,phone,notes,source_ref,source,score,state) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $c['project_id'] ?? null, $c['author_name'] ?: 'Khách bình luận', $phone, 'Bình luận: ' . mb_substr((string)$c['message'], 0, 500) . ($c['post_permalink'] ? "\n" . $c['post_permalink'] : ''),
            'comment:' . $c['id'], $src, $phone ? 60 : 30, 'CREATED']);
    $pdo->prepare('UPDATE comments SET lead_id=? WHERE id=?')->execute([$id, $c['id']]);
    fire_event('lead.new', ['lead_id' => $id]);
    return $id;
}
