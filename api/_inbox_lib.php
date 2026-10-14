<?php
/* CỬA SỔ CHAT — hộp thư chung mọi dự án: tin nhắn Fanpage (Messenger) + Zalo OA. 12/10/2026
   Dùng cho api/inbox.php (người dùng) và cron/run.php (kéo tin mỗi 5 phút). Yêu cầu đã require _boot.php.

   inbox_sync_all($CFG)                 → chuỗi tóm tắt 1 dòng (cron)
   inbox_sync_connection($CFG, $conn)   → ['threads'=>n,'new'=>n] hoặc ném InboxError
   inbox_send($CFG, $thread, $text, $u) → ['ok'=>bool,'error'?]

   Bảng: inbox_threads (1 dòng = 1 cuộc trò chuyện với 1 khách trên 1 kênh), inbox_messages (từng tin, in|out).
   Facebook: Graph API /{page}/conversations (Page token, quyền pages_messaging). Trả lời qua Send API, trong cửa sổ 24 giờ kể từ tin cuối của khách.
   Zalo OA: listrecentchat + conversation (token OA tự làm mới). Trả lời qua /v3.0/oa/message/cs, trong 48 giờ.
   Không hỗ trợ Zalo cá nhân (không có API chính thức, dễ bị khoá tài khoản). */
require_once __DIR__ . '/_publish_lib.php'; // meta_page_token()
require_once __DIR__ . '/_inbox_ai_lib.php';  // AI chăm sóc tự động

class InboxError extends RuntimeException {}
const INBOX_PROVIDERS = ['facebook_page', 'zalo_oa'];

function ib_utc(?string $t): ?string { if (!$t) return null; $ts = strtotime($t); return $ts ? gmdate('Y-m-d H:i:s', $ts) : null; }

/** Ghi/cập nhật một cuộc trò chuyện + các tin. $msgs: [['id','dir'=>in|out,'text','at'(UTC),'sender','attach'=>[]]] */
function ib_store(array $conn, string $extThread, array $cust, array $msgs, array $meta = []): int {
    global $pdo;
    $tid = $conn['provider'] . ':' . $extThread;
    $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $st->execute([$tid]); $th = $st->fetch();
    if (!$th) {
        $pdo->prepare("INSERT INTO inbox_threads(id,project_id,connection_id,provider,external_id,customer_id,customer_name,customer_avatar,status,kind,post_id,permalink) VALUES (?,?,?,?,?,?,?,?,'open',?,?,?)")
            ->execute([$tid, $conn['project_id'], $conn['id'], $conn['provider'], $extThread, (string)($cust['id'] ?? ''), (string)($cust['name'] ?? 'Khách'), $cust['avatar'] ?? null, $meta['kind'] ?? 'message', $meta['post_id'] ?? null, $meta['permalink'] ?? null]);
    } elseif (($cust['name'] ?? '') !== '' && ($cust['name'] !== $th['customer_name'] || (!empty($cust['avatar']) && $cust['avatar'] !== $th['customer_avatar']))) {
        $pdo->prepare('UPDATE inbox_threads SET customer_name=?, customer_avatar=COALESCE(?, customer_avatar) WHERE id=?')->execute([$cust['name'], $cust['avatar'] ?? null, $tid]);
    }
    $ins = $pdo->prepare("INSERT OR IGNORE INTO inbox_messages(id,thread_id,direction,text,attachments,sent_at,sender_name,external_id) VALUES (?,?,?,?,?,?,?,?)");
    $new = 0;
    foreach ($msgs as $m) {
        $mid = $conn['provider'] . ':' . $m['id'];
        $ins->execute([$mid, $tid, $m['dir'], (string)($m['text'] ?? ''), !empty($m['attach']) ? json_encode($m['attach'], JSON_UNESCAPED_UNICODE) : null, $m['at'] ?? gmdate('Y-m-d H:i:s'), $m['sender'] ?? null, (string)$m['id']]);
        if ($ins->rowCount() > 0 && $m['dir'] === 'in') $new++;
    }
    // Tin cuối + số chưa đọc (tin khách sau lần mở gần nhất) + mở lại nếu khách nhắn tiếp
    $last = $pdo->prepare('SELECT direction,text,attachments,sent_at FROM inbox_messages WHERE thread_id=? ORDER BY sent_at DESC, id DESC LIMIT 1'); $last->execute([$tid]); $l = $last->fetch();
    if ($l) {
        $txt = trim((string)$l['text']) !== '' ? $l['text'] : ($l['attachments'] ? '[Tệp đính kèm]' : '');
        // Chưa đọc = tin khách gửi SAU lần mình trả lời cuối và sau lần mở gần nhất. Hội thoại cũ (>14 ngày) nhập lần đầu coi như đã xử lý.
        if (!$th && strtotime($l['sent_at'] . ' UTC') < time() - 14 * 86400) $pdo->prepare('UPDATE inbox_threads SET read_at=? WHERE id=? AND read_at IS NULL')->execute([$l['sent_at'], $tid]);
        $un = $pdo->prepare("SELECT COUNT(*) FROM inbox_messages WHERE thread_id=? AND direction='in' AND sent_at > COALESCE((SELECT read_at FROM inbox_threads WHERE id=?),'') AND sent_at > COALESCE((SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out'),'')"); $un->execute([$tid, $tid, $tid]);
        $unread = (int)$un->fetchColumn();
        $pdo->prepare("UPDATE inbox_threads SET last_message=?, last_direction=?, last_time=?, unread=?, status=CASE WHEN ?>0 AND status='done' THEN 'open' ELSE status END, updated_at=datetime('now') WHERE id=?")
            ->execute([mb_substr($txt, 0, 200), $l['direction'], $l['sent_at'], $unread, $new, $tid]);
    }
    if ($new > 0) { ib_touch(); $GLOBALS['ib_new_threads'][$tid] = true; }
    return $new;
}
/** Chạy AI cho các hội thoại vừa có tin khách mới trong lượt đồng bộ/webhook này. */
function ib_ai_run_pending(array $CFG): array {
    $out = []; foreach (array_keys($GLOBALS['ib_new_threads'] ?? []) as $tid) { try { $out[$tid] = ib_ai_auto_reply($CFG, $tid); } catch (Throwable $e) { $out[$tid] = 'AI lỗi: ' . $e->getMessage(); } }
    $GLOBALS['ib_new_threads'] = []; return $out;
}
/** Nhãn "có thay đổi" để màn hình chat thăm dò rẻ (không cần đọc bảng). */
function ib_touch(): void { setting_set('inbox.changed', (string)microtime(true), ['id' => null]); }

/** Ghi 1 tin từ webhook Messenger. Hội thoại nhận diện theo (kênh, PSID khách); chưa có → hỏi Graph /conversations?user_id= để lấy id hội thoại + tên khách. */
function ib_store_event(array $CFG, array $conn, string $psid, string $mid, string $dir, string $text, array $att, string $at): int {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE connection_id=? AND customer_id=? ORDER BY last_time DESC LIMIT 1'); $st->execute([$conn['id'], $psid]); $th = $st->fetch();
    $ext = $th['external_id'] ?? null; $cust = ['id' => $psid, 'name' => $th['customer_name'] ?? 'Khách Facebook'];
    if (!$th) {
        try { $token = meta_page_token($CFG, $conn); } catch (PublishError $e) { throw new InboxError($e->getMessage()); }
        [$c, $d] = http_get_json(graph_url($CFG, rawurlencode((string)$conn['external_account_id']) . '/conversations', ['user_id' => $psid, 'fields' => 'id,participants', 'access_token' => $token]));
        $cv = $d['data'][0] ?? null;
        if ($cv) { $ext = (string)$cv['id']; foreach ($cv['participants']['data'] ?? [] as $p) if ((string)$p['id'] === $psid) $cust['name'] = (string)($p['name'] ?? $cust['name']); }
        else $ext = 'psid_' . $psid;
    }
    $n = ib_store($conn, (string)$ext, $cust, [['id' => $mid, 'dir' => $dir, 'text' => $text, 'at' => $at, 'attach' => $att]]);
    if ($dir === 'in' && $n > 0) { // báo ngay cho chủ/quản lý dự án
        $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$conn['project_id']]);
        foreach ($ms->fetchAll() as $m) notify($m['user_id'], 'Tin nhắn mới: ' . $cust['name'], mb_substr($text !== '' ? $text : '[Tệp đính kèm]', 0, 120), '#inbox', $conn['project_id']);
    }
    ib_touch();
    return $n;
}

/* ---------- Facebook Messenger (Page) ---------- */
function ib_fb_attach(array $a): array {
    $o = ['type' => str_starts_with((string)($a['mime_type'] ?? ''), 'image/') ? 'image' : 'file', 'name' => $a['name'] ?? null];
    if (!empty($a['image_data']['url'])) $o['url'] = $a['image_data']['url']; elseif (!empty($a['file_url'])) $o['url'] = $a['file_url']; elseif (!empty($a['video_data']['url'])) { $o['url'] = $a['video_data']['url']; $o['type'] = 'video'; }
    return $o;
}
function ib_sync_facebook(array $CFG, array $conn): array {
    try { $token = meta_page_token($CFG, $conn); } catch (PublishError $e) { throw new InboxError($e->getMessage()); }
    $pageId = (string)$conn['external_account_id'];
    [$c, $d] = http_get_json(graph_url($CFG, rawurlencode($pageId) . '/conversations', [
        'fields' => 'id,updated_time,participants,messages.limit(25){id,message,from,created_time,attachments{mime_type,name,image_data,file_url,video_data}}',
        'limit' => 50, 'access_token' => $token]));
    if ($c !== 200) {
        $msg = $d['error']['message'] ?? "HTTP $c";
        if (preg_match('/permission|pages_messaging/i', $msg)) $msg .= ' — Page token cần quyền pages_messaging (đọc & trả lời tin nhắn)';
        throw new InboxError('Facebook: ' . $msg);
    }
    $threads = 0; $new = 0;
    foreach ($d['data'] ?? [] as $cv) {
        $cust = ['id' => '', 'name' => 'Khách Facebook'];
        foreach ($cv['participants']['data'] ?? [] as $p) if ((string)$p['id'] !== $pageId) { $cust = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? 'Khách Facebook')]; break; }
        $msgs = [];
        foreach ($cv['messages']['data'] ?? [] as $m) {
            $att = array_map('ib_fb_attach', $m['attachments']['data'] ?? []);
            if (trim((string)($m['message'] ?? '')) === '' && !$att) continue;
            $msgs[] = ['id' => $m['id'], 'dir' => ((string)($m['from']['id'] ?? '') === $pageId) ? 'out' : 'in', 'text' => (string)($m['message'] ?? ''), 'at' => ib_utc($m['created_time'] ?? null), 'sender' => $m['from']['name'] ?? null, 'attach' => $att];
        }
        if (!$msgs) continue;
        $new += ib_store($conn, (string)$cv['id'], $cust, $msgs); $threads++;
    }
    // Bình luận dưới 10 bài mới nhất → mỗi bình luận gốc của khách = 1 hội thoại (kind=comment); trả lời của Trang = tin out
    [$c, $fd] = http_get_json(graph_url($CFG, rawurlencode($pageId) . '/feed', ['limit' => 10, 'fields' => 'id,permalink_url,message,comments.order(reverse_chronological).limit(30){id,from,message,created_time,parent{id},permalink_url,attachment}', 'access_token' => $token]));
    if ($c === 200) foreach ($fd['data'] ?? [] as $post) {
        $cms = $post['comments']['data'] ?? []; $byParent = [];
        foreach ($cms as $cm) if (!empty($cm['parent']['id'])) $byParent[$cm['parent']['id']][] = $cm;
        foreach ($cms as $cm) {
            if (!empty($cm['parent']['id'])) continue;
            $fromId = (string)($cm['from']['id'] ?? ''); if ($fromId === $pageId) continue;
            $txt = trim((string)($cm['message'] ?? '')); $att = [];
            if (!empty($cm['attachment'])) $att[] = ['type' => 'image', 'url' => $cm['attachment']['media']['image']['src'] ?? ($cm['attachment']['url'] ?? null), 'name' => $cm['attachment']['type'] ?? 'ảnh'];
            if ($txt === '' && !$att) continue;
            $msgs = [['id' => 'c_' . $cm['id'], 'dir' => 'in', 'text' => $txt, 'at' => ib_utc($cm['created_time'] ?? null), 'sender' => $cm['from']['name'] ?? null, 'attach' => $att]];
            foreach ($byParent[$cm['id']] ?? [] as $r) $msgs[] = ['id' => 'c_' . $r['id'], 'dir' => ((string)($r['from']['id'] ?? '') === $pageId) ? 'out' : 'in', 'text' => (string)($r['message'] ?? ''), 'at' => ib_utc($r['created_time'] ?? null), 'sender' => $r['from']['name'] ?? null, 'attach' => []];
            $new += ib_store($conn, 'c_' . $cm['id'], ['id' => $fromId ?: 'fb-' . $cm['id'], 'name' => (string)($cm['from']['name'] ?? 'Khách Facebook')], $msgs,
                ['kind' => 'comment', 'post_id' => (string)$post['id'], 'permalink' => $cm['permalink_url'] ?? ($post['permalink_url'] ?? null)]);
            $threads++;
        }
    }
    return ['threads' => $threads, 'new' => $new];
}
/** Trả lời BÌNH LUẬN: trả lời công khai dưới bình luận; nếu có link thì gửi thêm tin riêng qua Messenger (private reply, 7 ngày). */
function ib_fb_comment_send(array $CFG, array $conn, array $th, string $text): array {
    try { $token = meta_page_token($CFG, $conn); } catch (PublishError $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    $cid = (string)$th['external_id']; if (str_starts_with($cid, 'c_')) $cid = substr($cid, 2);
    [$c, $d] = http_call('POST', graph_url($CFG, rawurlencode($cid) . '/comments'), ['form' => ['message' => mb_substr($text, 0, 7000), 'access_token' => $token]]);
    if ($c !== 200 || empty($d['id'])) return ['ok' => false, 'error' => 'Facebook (bình luận): ' . ($d['error']['message'] ?? "HTTP $c")];
    $note = '';
    if (preg_match('#https?://\S+#', $text)) { // gửi riêng để khách có link trong Messenger: Send API với recipient.comment_id (1 lần/bình luận, trong 7 ngày)
        [$c2, $d2] = http_call('POST', graph_url($CFG, rawurlencode((string)$conn['external_account_id']) . '/messages'), ['form' => [
            'recipient' => json_encode(['comment_id' => $cid]), 'messaging_type' => 'RESPONSE', 'message' => json_encode(['text' => mb_substr($text, 0, 2000)], JSON_UNESCAPED_UNICODE), 'access_token' => $token]]);
        $note = ($c2 === 200 && !empty($d2['message_id'])) ? ' · đã gửi riêng qua Messenger' : ' · không gửi riêng được: ' . mb_substr((string)($d2['error']['message'] ?? "HTTP $c2"), 0, 120);
    }
    return ['ok' => true, 'id' => 'c_' . $d['id'], 'note' => $note];
}
function ib_fb_send(array $CFG, array $conn, array $th, string $text): array {
    try { $token = meta_page_token($CFG, $conn); } catch (PublishError $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    [$c, $d] = http_call('POST', graph_url($CFG, rawurlencode((string)$conn['external_account_id']) . '/messages'), ['form' => [
        'recipient' => json_encode(['id' => $th['customer_id']]), 'messaging_type' => 'RESPONSE', 'message' => json_encode(['text' => mb_substr($text, 0, 2000)], JSON_UNESCAPED_UNICODE), 'access_token' => $token]]);
    if (($c !== 200 || empty($d['message_id'])) && (($d['error']['code'] ?? 0) == 10 || stripos((string)($d['error']['message'] ?? ''), 'outside') !== false)) {
        // Quá cửa sổ 24 giờ → thử nhãn HUMAN_AGENT (Meta cho người thật trả lời muộn; cần quyền human_agent)
        [$c, $d] = http_call('POST', graph_url($CFG, rawurlencode((string)$conn['external_account_id']) . '/messages'), ['form' => [
            'recipient' => json_encode(['id' => $th['customer_id']]), 'messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT', 'message' => json_encode(['text' => mb_substr($text, 0, 2000)], JSON_UNESCAPED_UNICODE), 'access_token' => $token]]);
    }
    if ($c !== 200 || empty($d['message_id'])) {
        $m = $d['error']['message'] ?? "HTTP $c";
        if (($d['error']['code'] ?? 0) == 10 || stripos($m, 'outside') !== false || stripos($m, '24') !== false) $m .= ' — Messenger chỉ cho Page trả lời trong 24 giờ kể từ tin cuối của khách (đã thử nhãn Human Agent)';
        if (($d['error']['code'] ?? 0) == 551) $m = 'Meta chặn gửi (#551 "người này hiện không có mặt"): khách chưa nhắn cho Trang lần nào — hộp thoại này mở từ tin nhắn riêng theo bình luận, Meta chỉ cho gửi đúng 1 tin cho tới khi khách trả lời. Muốn nói thêm, hãy trả lời công khai ở hội thoại 💬 Bình luận của khách này. (Cũng gặp khi khách chặn Trang hoặc khoá tài khoản.)';
        return ['ok' => false, 'error' => 'Facebook: ' . $m];
    }
    return ['ok' => true, 'id' => (string)$d['message_id']];
}

/* ---------- Zalo OA ---------- */
function ib_zalo_get(string $tok, string $path, array $data): array {
    return http_get_json('https://openapi.zalo.me/v2.0/oa/' . $path . '?data=' . rawurlencode(json_encode($data)), ['access_token: ' . $tok]);
}
function ib_sync_zalo(array $CFG, array $conn): array {
    [$tok, $err] = zalo_access_token($conn);
    if (!$tok) throw new InboxError($err);
    $oa = (string)$conn['external_account_id']; $users = []; $threads = 0; $new = 0;
    foreach ([0, 10, 20, 30, 40] as $off) {
        [$c, $d] = ib_zalo_get($tok, 'listrecentchat', ['offset' => $off, 'count' => 10]);
        if ($c !== 200 || ($d['error'] ?? 0) != 0) { if ($off === 0) throw new InboxError('Zalo OA: ' . ($d['message'] ?? "HTTP $c")); break; }
        $rows = $d['data'] ?? [];
        foreach ($rows as $m) {
            $fromOa = (string)($m['from_id'] ?? '') === $oa || (int)($m['src'] ?? 1) === 0;
            $uid = $fromOa ? (string)($m['to_id'] ?? '') : (string)($m['from_id'] ?? '');
            if ($uid === '' || isset($users[$uid])) continue;
            $users[$uid] = ['id' => $uid, 'name' => $fromOa ? (string)($m['to_display_name'] ?? 'Khách Zalo') : (string)($m['from_display_name'] ?? 'Khách Zalo'), 'avatar' => $fromOa ? ($m['to_avatar'] ?? null) : ($m['from_avatar'] ?? null)];
        }
        if (count($rows) < 10) break;
    }
    foreach ($users as $uid => $cust) {
        [$c, $d] = ib_zalo_get($tok, 'conversation', ['user_id' => $uid, 'offset' => 0, 'count' => 20]);
        if ($c !== 200 || ($d['error'] ?? 0) != 0) continue;
        $msgs = [];
        foreach ($d['data'] ?? [] as $m) {
            $fromOa = (int)($m['src'] ?? 1) === 0 || (string)($m['from_id'] ?? '') === $oa;
            $type = (string)($m['type'] ?? 'text'); $att = [];
            if ($type !== 'text') $att[] = ['type' => in_array($type, ['photo', 'image', 'sticker', 'gif'], true) ? 'image' : $type, 'url' => $m['url'] ?? ($m['thumb'] ?? null), 'name' => $type];
            $msgs[] = ['id' => (string)$m['message_id'], 'dir' => $fromOa ? 'out' : 'in', 'text' => $type === 'text' ? (string)($m['message'] ?? '') : '', 'at' => gmdate('Y-m-d H:i:s', (int)(($m['time'] ?? 0) / 1000)), 'sender' => $m['from_display_name'] ?? null, 'attach' => $att];
        }
        if (!$msgs) continue;
        $new += ib_store($conn, $uid, $cust, $msgs); $threads++;
    }
    return ['threads' => $threads, 'new' => $new];
}
function ib_zalo_send(array $conn, array $th, string $text): array {
    [$tok, $err] = zalo_access_token($conn);
    if (!$tok) return ['ok' => false, 'error' => $err];
    [$c, $d] = http_call('POST', 'https://openapi.zalo.me/v3.0/oa/message/cs', ['headers' => ['access_token: ' . $tok], 'json' => ['recipient' => ['user_id' => $th['customer_id']], 'message' => ['text' => mb_substr($text, 0, 2000)]]]);
    if ($c !== 200 || ($d['error'] ?? 0) != 0) {
        $m = $d['message'] ?? "HTTP $c"; if (($d['error'] ?? 0) == -213 || stripos($m, '48') !== false) $m .= ' — Zalo chỉ cho OA trả lời trong 48 giờ kể từ tin cuối của khách';
        return ['ok' => false, 'error' => 'Zalo OA: ' . $m];
    }
    return ['ok' => true, 'id' => (string)($d['data']['message_id'] ?? ('oa-' . time()))];
}

function inbox_recount(): int {
    global $pdo; $n = 0;
    foreach ($pdo->query('SELECT id, read_at, last_time FROM inbox_threads')->fetchAll() as $t) {
        if (!$t['read_at'] && $t['last_time'] && strtotime($t['last_time'] . ' UTC') < time() - 14 * 86400) $pdo->prepare('UPDATE inbox_threads SET read_at=? WHERE id=?')->execute([$t['last_time'], $t['id']]);
        $un = $pdo->prepare("SELECT COUNT(*) FROM inbox_messages WHERE thread_id=? AND direction='in' AND sent_at > COALESCE((SELECT read_at FROM inbox_threads WHERE id=?),'') AND sent_at > COALESCE((SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out'),'')"); $un->execute([$t['id'], $t['id'], $t['id']]);
        $pdo->prepare('UPDATE inbox_threads SET unread=? WHERE id=?')->execute([(int)$un->fetchColumn(), $t['id']]); $n++;
    }
    return $n;
}
/* ---------- Chung ---------- */
function inbox_sync_connection(array $CFG, array $conn): array {
    $r = $conn['provider'] === 'facebook_page' ? ib_sync_facebook($CFG, $conn) : ib_sync_zalo($CFG, $conn);
    setting_set('inbox.sync.' . $conn['id'], json_encode(['ok' => true, 'at' => date('Y-m-d H:i:s')] + $r), ['id' => null]);
    return $r;
}
function inbox_sync_all(array $CFG = []): string {
    global $pdo;
    if (!$CFG) $CFG = $GLOBALS['CFG'];
    $conns = $pdo->query("SELECT * FROM connections WHERE status='connected' AND external_account_id IS NOT NULL AND provider IN ('facebook_page','zalo_oa')")->fetchAll();
    $new = 0; $errs = [];
    foreach ($conns as $conn) {
        try { $new += inbox_sync_connection($CFG, $conn)['new']; }
        catch (Throwable $e) { setting_set('inbox.sync.' . $conn['id'], json_encode(['ok' => false, 'at' => date('Y-m-d H:i:s'), 'error' => mb_substr($e->getMessage(), 0, 300)]), ['id' => null]); $errs[] = $conn['label'] . ': ' . $e->getMessage(); }
    }
    if ($new > 0) { // báo cho chủ/quản lý các dự án có tin mới
        $rows = $pdo->query("SELECT project_id, COUNT(*) n FROM inbox_threads WHERE unread>0 AND status='open' GROUP BY project_id")->fetchAll();
        foreach ($rows as $r) {
            $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$r['project_id']]);
            foreach ($ms->fetchAll() as $m) notify($m['user_id'], 'Cửa sổ chat: ' . $r['n'] . ' cuộc trò chuyện chưa đọc', null, '#inbox', $r['project_id']);
        }
    }
    $ai = ib_ai_run_pending($CFG); $aiN = count(array_filter($ai, fn($v) => str_starts_with($v, 'đã trả lời')));
    return count($conns) . " kênh, $new tin mới" . ($aiN ? ", AI trả lời $aiN" : '') . ($errs ? '; lỗi: ' . implode(' | ', array_map(fn($e) => mb_substr($e, 0, 120), $errs)) : '');
}
function inbox_send(array $CFG, array $th, string $text, array $u): array {
    global $pdo;
    $conn = get_connection($th['connection_id'], true);
    if (!$conn || $conn['status'] !== 'connected') return ['ok' => false, 'error' => 'Kênh của cuộc trò chuyện này đã ngắt kết nối'];
    if ($th['provider'] === 'zalo_user') { require_once __DIR__ . '/_zalo_user_lib.php'; $r = zu_send((string)$th['customer_id'], $text); }
    else $r = $th['provider'] === 'facebook_page' ? ((($th['kind'] ?? 'message') === 'comment') ? ib_fb_comment_send($CFG, $conn, $th, $text) : ib_fb_send($CFG, $conn, $th, $text)) : ib_zalo_send($conn, $th, $text);
    if (!$r['ok']) return $r;
    $pdo->prepare("INSERT OR IGNORE INTO inbox_messages(id,thread_id,direction,text,sent_at,sender_name,external_id,sent_by) VALUES (?,?,'out',?,?,?,?,?)")
        ->execute([$th['provider'] . ':' . $r['id'], $th['id'], $text, gmdate('Y-m-d H:i:s'), $u['name'] ?? 'Hub', $r['id'], $u['id'] ?? null]);
    // Zalo cá nhân dội tin vừa gửi về rất nhanh → có thể đã lưu trước dưới dạng "gửi từ điện thoại": ghi đúng người gửi (AI / nhân viên)
    $pdo->prepare("UPDATE inbox_messages SET sent_by=?, sender_name=? WHERE id=? AND sent_by IS NULL")->execute([$u['id'] ?? null, $u['name'] ?? 'Hub', $th['provider'] . ':' . $r['id']]);
    $pdo->prepare("UPDATE inbox_threads SET last_message=?, last_direction='out', last_time=?, unread=0, read_at=?, updated_at=datetime('now') WHERE id=?")
        ->execute([mb_substr($text, 0, 200), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $th['id']]);
    ib_touch();
    return $r;
}
