<?php
/* Chăm sóc bình luận.
   GET  ?status=new|replied|escalated|hidden|ignored|all &provider= &connection_id= &q=   → danh sách + đếm + trạng thái kéo từng kênh
   POST {action:'fetch', connection_id?}            kéo bình luận mới ngay (một kênh hoặc tất cả)
   POST {action:'reply', id, text}                  trả lời công khai trên nền tảng
   POST {action:'suggest', id}                      AI gợi ý câu trả lời (không gửi)
   POST {action:'hide'|'unhide'|'ignore'|'reopen'|'escalate', id}
   POST {action:'assign', id, user_id}
   POST {action:'to_lead', id}                      tạo đầu mối từ bình luận */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_comments_lib.php';
$user = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$PID = pid();

const CM_STATUS = ['new', 'replied', 'escalated', 'hidden', 'ignored'];

if ($method === 'GET' && ($_GET['action'] ?? '') === 'stats') {
    // Tiếng nói khách hàng trên kênh của mình: phân bố ý định / cảm xúc / nền tảng + số bình luận theo ngày (N ngày).
    $days = max(7, min(365, (int)($_GET['days'] ?? 30)));
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $q = fn(string $sql) => (function () use ($pdo, $sql, $since, $PID) { $st = $pdo->prepare($sql); $st->execute([$PID, $since]); return $st->fetchAll(); })();
    $base = "FROM comments WHERE project_id=? AND COALESCE(created_time,fetched_at) >= ?";
    respond([
        'days' => $days,
        'total' => (int)($q("SELECT COUNT(*) n $base")[0]['n'] ?? 0),
        'by_intent' => $q("SELECT COALESCE(intent,'khac') k, COUNT(*) n $base GROUP BY k ORDER BY n DESC"),
        'by_sentiment' => $q("SELECT COALESCE(sentiment,'neutral') k, COUNT(*) n $base GROUP BY k"),
        'by_provider' => $q("SELECT provider k, COUNT(*) n, SUM(status='replied') replied $base GROUP BY provider"),
        'by_status' => $q("SELECT status k, COUNT(*) n $base GROUP BY status"),
        'by_day' => $q("SELECT substr(COALESCE(created_time,fetched_at),1,10) d, COUNT(*) n, SUM(sentiment='negative') neg $base GROUP BY d ORDER BY d"),
        'by_conn' => $q("SELECT c.connection_id id, k.label, c.provider, COUNT(*) n, SUM(c.status='replied') replied FROM comments c LEFT JOIN connections k ON k.id=c.connection_id WHERE c.project_id=? AND COALESCE(c.created_time,c.fetched_at) >= ? GROUP BY c.connection_id ORDER BY n DESC"),
        'samples' => $q("SELECT provider, author_name, message, intent, sentiment, post_permalink $base AND intent IN ('hoi_gia','dat_lich','khieu_nai') ORDER BY COALESCE(created_time,fetched_at) DESC LIMIT 15"),
        'words' => array_slice(cm_top_words(array_column($q("SELECT message $base"), 'message')), 0, 30),
    ]);
}

/** Từ / cụm 2 từ hay gặp trong bình luận (bỏ từ dừng) — để thấy khách nói về gì. */
function cm_top_words(array $msgs): array {
    $stop = array_flip(explode(' ', 'và là của có không cho với được này những các một thì mà đã đi ra vào lên nhé ạ à ơi nha hả vậy rồi cũng rất quá lắm thế đó kia bạn mình tôi em anh chị ad shop the a an to of is it you i and in on for this that so my me be are was why there no not que by them he she him her his all they their even who do does did los el la le put gets get got man what when how just like looks can will would have has had with from at as but or if than then too very your our we us one out up about into more some any 😂 ❤️'));
    $cnt = [];
    foreach ($msgs as $m) {
        $m = mb_strtolower(preg_replace('/[★☆]|https?:\/\/\S+|[^\p{L}\p{N}\s]/u', ' ', (string)$m));
        $w = array_values(array_filter(preg_split('/\s+/u', $m), fn($x) => mb_strlen($x) > 1 && !isset($stop[$x]) && !ctype_digit($x)));
        $seen = [];
        for ($i = 0; $i < count($w); $i++) {
            foreach ([$w[$i], isset($w[$i + 1]) ? $w[$i] . ' ' . $w[$i + 1] : null] as $t) if ($t && !isset($seen[$t])) { $seen[$t] = 1; $cnt[$t] = ($cnt[$t] ?? 0) + 1; }
        }
    }
    $cnt = array_filter($cnt, fn($n) => $n >= 2);
    arsort($cnt);
    $out = [];
    foreach ($cnt as $k => $n) $out[] = ['w' => $k, 'n' => $n];
    return $out;
}

if ($method === 'GET') {
    $w = ['c.project_id=?']; $v = [$PID];
    $st = (string)($_GET['status'] ?? 'new');
    if ($st === 'open') $w[] = "c.status IN ('new','escalated')";
    elseif (in_array($st, CM_STATUS, true)) { $w[] = 'c.status=?'; $v[] = $st; }
    if (!empty($_GET['provider'])) { $w[] = 'c.provider=?'; $v[] = $_GET['provider']; }
    if (!empty($_GET['connection_id'])) { $w[] = 'c.connection_id=?'; $v[] = $_GET['connection_id']; }
    if (!empty($_GET['intent'])) { $w[] = 'c.intent=?'; $v[] = $_GET['intent']; }
    if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $w[] = '(c.message LIKE ? OR c.author_name LIKE ?)'; $v[] = "%$q%"; $v[] = "%$q%"; }
    if (!empty($_GET['mine'])) { $w[] = 'c.assigned_to=?'; $v[] = $user['id']; }
    $sql = 'SELECT c.*, k.label AS connection_label, u.name AS assigned_name, r.name AS replied_name FROM comments c
        LEFT JOIN connections k ON k.id=c.connection_id LEFT JOIN users u ON u.id=c.assigned_to LEFT JOIN users r ON r.id=c.replied_by'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY COALESCE(c.created_time,c.fetched_at) DESC LIMIT 300';
    $q = $pdo->prepare($sql); $q->execute($v);
    $counts = [];
    $stC = $pdo->prepare('SELECT status, COUNT(*) n FROM comments WHERE project_id=? GROUP BY status'); $stC->execute([$PID]);
    foreach ($stC->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
    $sources = [];
    $stS = $pdo->prepare("SELECT id,provider,label,status,external_account_id,auth_method,refresh_token IS NOT NULL AND refresh_token<>'' AS has_rt FROM connections WHERE project_id=? AND provider IN ('facebook_page','youtube','gbp','zalo_oa','tiktok') ORDER BY provider,label"); $stS->execute([$PID]);
    foreach ($stS->fetchAll() as $k) {
        $fs = jdecode(setting_get('comments.fetch.' . $k['id']), []);
        $note = null;
        if ($k['provider'] === 'tiktok') $note = 'TikTok chưa cho ứng dụng bên ngoài đọc bình luận (Display API không có) — xem bình luận trong app TikTok';
        elseif ($k['provider'] === 'youtube' && $k['auth_method'] === 'manual' && !$k['has_rt']) $note = 'Chỉ đọc được bình luận (API key). Muốn trả lời từ đây: thêm Client ID / Secret / Refresh Token cho kênh';
        $sources[] = ['id' => $k['id'], 'provider' => $k['provider'], 'label' => $k['label'], 'status' => $k['status'],
            'supported' => in_array($k['provider'], COMMENT_PROVIDERS, true), 'fetch' => $fs ?: null, 'note' => $note];
    }
    $stU = $pdo->prepare("SELECT u.id,u.name FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=?)) ORDER BY u.name"); $stU->execute([$PID]); $staff = $stU->fetchAll();
    respond(['comments' => $q->fetchAll(), 'counts' => $counts, 'sources' => $sources, 'staff' => $staff,
        'last_cron' => setting_get('cron.last_run')]);
}

require_method('POST');
require_project_write();
$in = json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'fetch') {
    session_write_close();
    $cid = (string)($in['connection_id'] ?? '');
    $stF = $pdo->prepare("SELECT * FROM connections WHERE project_id=? AND status='connected' AND external_account_id IS NOT NULL AND provider IN ('facebook_page','youtube','gbp','zalo_oa')"); $stF->execute([$PID]);
    $rows = $cid ? array_filter([get_connection($cid)]) : $stF->fetchAll();
    $res = [];
    foreach ($rows as $conn) {
        try { $r = fetch_connection_comments($CFG, $conn); $res[] = ['id' => $conn['id'], 'label' => $conn['label'], 'ok' => true] + $r; }
        catch (Throwable $e) { cm_fetch_status($conn['id'], ['ok' => false, 'error' => $e->getMessage()]); $res[] = ['id' => $conn['id'], 'label' => $conn['label'], 'ok' => false, 'error' => $e->getMessage()]; }
    }
    respond(['results' => $res]);
}

$c = need('comments', (string)($in['id'] ?? ''), 'bình luận');

if ($action === 'reply') {
    $r = comment_reply($CFG, $c, (string)($in['text'] ?? ''), $user['id']);
    if (empty($r['ok'])) fail($r['error'] ?? 'Không trả lời được', 502);
    log_activity('update', 'comment', $c['id'], 'Trả lời bình luận của ' . ($c['author_name'] ?? ''), $user);
    respond(['ok' => true]);
}

if ($action === 'suggest') {
    require_once __DIR__ . '/_ai_lib.php';
    $stB = $pdo->prepare("SELECT kind,title,content FROM brand_items WHERE project_id=? ORDER BY kind"); $stB->execute([$PID]); $brand = $stB->fetchAll();
    $rules = implode("\n", array_map(fn($b) => '- ' . $b['title'] . ': ' . $b['content'], $brand));
    $hotline = setting_get('brand.hotline');
    $sys = "Bạn là nhân viên chăm sóc khách hàng, trả lời " . (['youtube' => 'bình luận công khai trên YouTube', 'gbp' => 'đánh giá của khách trên Google Maps (cảm ơn, nếu chê thì xin lỗi và mời liên hệ)', 'zalo_oa' => 'tin nhắn riêng của khách gửi Zalo OA (trả lời như nhân viên tư vấn, ngắn, cụ thể)'][$c['provider']] ?? 'bình luận công khai trên Fanpage Facebook')
        . ". Viết 1–3 câu tiếng Việt (hoặc theo ngôn ngữ của bình luận), lịch sự, cụ thể, không hứa điều không chắc, không bịa giá."
        . " Nếu khách hỏi giá / muốn tư vấn: mời nhắn tin hoặc gọi hotline. Bình luận tiêu cực: xin lỗi, mời nhắn tin để hỗ trợ riêng."
        . ($rules ? "\nThông tin thương hiệu:\n$rules" : '') . ($hotline ? "\nHotline: $hotline" : '')
        . "\nChỉ trả về đúng câu trả lời, không kèm giải thích.";
    $ctx = ($c['post_excerpt'] ? 'Bài viết: ' . $c['post_excerpt'] . "\n" : '') . 'Bình luận của ' . ($c['author_name'] ?: 'khách') . ': ' . $c['message'];
    $r = ai_generate($sys, [['role' => 'user', 'content' => $ctx]], ['max_tokens' => 300, 'purpose' => 'comment_suggest']);
    if (empty($r['ok'])) fail($r['error'] ?? 'AI không trả lời được', 502);
    respond(['text' => trim((string)$r['text'])]);
}

if (in_array($action, ['hide', 'unhide'], true)) {
    $r = comment_hide($CFG, $c, $action === 'hide');
    if (empty($r['ok'])) fail($r['error'] ?? 'Không thực hiện được', 502);
    respond(['ok' => true]);
}

if (in_array($action, ['ignore', 'reopen', 'escalate'], true)) {
    $to = ['ignore' => 'ignored', 'reopen' => 'new', 'escalate' => 'escalated'][$action];
    $pdo->prepare('UPDATE comments SET status=? WHERE id=?')->execute([$to, $c['id']]);
    if ($action === 'escalate') notify(null, 'Bình luận cần trưởng nhóm xử lý', ($c['author_name'] ?? 'Khách') . ': ' . mb_substr((string)$c['message'], 0, 300), 'care');
    respond(['ok' => true]);
}

if ($action === 'assign') {
    $uid = (string)($in['user_id'] ?? '');
    $pdo->prepare('UPDATE comments SET assigned_to=? WHERE id=?')->execute([$uid ?: null, $c['id']]);
    if ($uid && $uid !== $user['id']) notify($uid, 'Bạn được giao một bình luận', ($c['author_name'] ?? 'Khách') . ': ' . mb_substr((string)$c['message'], 0, 300), 'care');
    respond(['ok' => true]);
}

if ($action === 'to_lead') {
    $phone = comment_classify((string)$c['message'])['phone'];
    $lid = comment_to_lead($c, $phone);
    if (!$lid) fail('Không tạo được đầu mối', 500);
    log_activity('create', 'lead', $lid, 'Đầu mối từ bình luận của ' . ($c['author_name'] ?? ''), $user);
    respond(['lead_id' => $lid]);
}

fail('action không hợp lệ');
