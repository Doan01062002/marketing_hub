<?php
/* CỬA SỔ CHAT — API hộp thư chung mọi dự án người dùng thuộc về (quản trị hệ thống thấy tất cả). 12/10/2026
   GET  ?action=threads&status=open|done|all&unread=1&project=&provider=&q=&limit=    → danh sách cuộc trò chuyện + đếm + trạng thái kéo từng kênh
   GET  ?action=messages&id=                                                           → tin của 1 cuộc (và đánh dấu đã đọc)
   GET  ?action=counts                                                                 → {unread, open} cho huy hiệu menu
   GET  ?action=templates                                                              → mẫu trả lời nhanh
   POST {action:'send', id, text}            trả lời khách trên nền tảng (Messenger / Zalo OA)
   POST {action:'sync', connection_id?}      kéo tin mới ngay
   POST {action:'done'|'reopen'|'read', id}  đóng / mở lại / đánh dấu đã đọc
   POST {action:'assign', id, user_id}       giao người chăm
   POST {action:'note', id, note}            ghi chú nội bộ
   POST {action:'to_lead', id, phone?}       tạo đầu mối từ cuộc trò chuyện */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_inbox_lib.php';
require_once __DIR__ . '/_inbox_ai_lib.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

$myProjects = projects_for_user($me);                       // [{id,name,color,member_role,...}]
$roleOf = []; foreach ($myProjects as $p) $roleOf[$p['id']] = $p['member_role'] ?? ($me['role'] === 'admin' ? 'owner' : 'viewer');
$pids = array_keys($roleOf);
if (!$pids) respond(['threads' => [], 'counts' => ['unread' => 0, 'open' => 0], 'note' => 'Bạn chưa thuộc dự án nào']);
$in = str_repeat('?,', count($pids) - 1) . '?';

function ib_thread(string $id, array $pids): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $st->execute([$id]); $t = $st->fetch();
    if (!$t || !in_array($t['project_id'], $pids, true)) fail('Không tìm thấy cuộc trò chuyện', 404);
    return $t;
}
function ib_can_write(string $pid, array $roleOf, array $me): bool { return $me['role'] === 'admin' || in_array($roleOf[$pid] ?? '', PROJECT_WRITE_ROLES, true); }
function ib_public(array $t): array {
    return ['id' => $t['id'], 'project_id' => $t['project_id'], 'connection_id' => $t['connection_id'], 'provider' => $t['provider'], 'customer_id' => $t['customer_id'],
        'name' => $t['customer_name'], 'avatar' => $t['customer_avatar'], 'last' => $t['last_message'], 'last_dir' => $t['last_direction'], 'last_time' => $t['last_time'],
        'unread' => (int)$t['unread'], 'status' => $t['status'], 'assigned_to' => $t['assigned_to'], 'lead_id' => $t['lead_id'], 'note' => $t['note'], 'read_at' => $t['read_at'],
        'ai_mode' => $t['ai_mode'] ?? 'on', 'needs_human' => (int)($t['needs_human'] ?? 0), 'kind' => $t['kind'] ?? 'message', 'permalink' => $t['permalink'] ?? null];
}

if ($method === 'GET') {
    $a = (string)($_GET['action'] ?? 'threads');
    if ($a === 'counts') {
        $st = $pdo->prepare("SELECT COALESCE(SUM(unread>0),0) unread, COALESCE(SUM(status='open'),0) open FROM inbox_threads WHERE project_id IN ($in)"); $st->execute($pids);
        $r = $st->fetch(); respond(['unread' => (int)$r['unread'], 'open' => (int)$r['open']]);
    }
    if ($a === 'changes') { // thăm dò 4 giây/lần từ màn hình chat: chỉ đọc 1 setting + 1 đếm
        $st = $pdo->prepare("SELECT COALESCE(SUM(unread>0),0) FROM inbox_threads WHERE project_id IN ($in)"); $st->execute($pids);
        respond(['changed' => (string)setting_get('inbox.changed', ''), 'unread' => (int)$st->fetchColumn(), 'webhook' => (string)setting_get('meta.webhook_status', '')]);
    }
    if ($a === 'templates') respond(['templates' => $pdo->query('SELECT id,title,body,intent FROM reply_templates ORDER BY title')->fetchAll()]);
    if ($a === 'messages') {
        $t = ib_thread((string)($_GET['id'] ?? ''), $pids);
        $st = $pdo->prepare('SELECT id,direction,text,attachments,sent_at,sender_name,sent_by FROM inbox_messages WHERE thread_id=? ORDER BY sent_at, id LIMIT 300'); $st->execute([$t['id']]);
        $msgs = array_map(fn($m) => ['id' => $m['id'], 'dir' => $m['direction'], 'text' => $m['text'], 'attach' => jdecode($m['attachments'], []), 'at' => $m['sent_at'], 'sender' => $m['sender_name'], 'ai' => ($m['sent_by'] ?? '') === 'ai'], $st->fetchAll());
        $aiCfg = ib_ai_cfg($t['project_id']);
        if ((int)$t['unread'] > 0 || !$t['read_at']) { $pdo->prepare("UPDATE inbox_threads SET unread=0, read_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'), $t['id']]); $t['unread'] = 0; }
        $conn = get_connection($t['connection_id'], true);
        $lastIn = null; foreach (array_reverse($msgs) as $m) if ($m['dir'] === 'in') { $lastIn = $m['at']; break; }
        $win = $t['provider'] === 'facebook_page' ? ((($t['kind'] ?? 'message') === 'comment') ? 168 : 24) : ($t['provider'] === 'zalo_user' ? null : 48); $hoursLeft = $lastIn && $win ? round($win - (time() - strtotime($lastIn . ' UTC')) / 3600, 1) : null;
        respond(['thread' => ib_public($t), 'messages' => $msgs, 'channel' => $conn ? ['label' => $conn['label'], 'status' => $conn['status']] : null,
            'can_write' => ib_can_write($t['project_id'], $roleOf, $me), 'reply_window_hours' => $win, 'hours_left' => $hoursLeft, 'ai_project_mode' => $aiCfg['mode'], 'zu_ai' => $t['provider'] === 'zalo_user' ? (function ($uid) { require_once __DIR__ . '/_zalo_ai_lib.php'; $c = zu_ai_cfg(); return ['enabled' => $c['enabled'], 'scope' => $c['scope'], 'allowed' => zu_ai_person_allowed($uid, $c) || (!$c['enabled'] && in_array($uid, array_column($c['people'], 'uid'), true))]; })((string)$t['customer_id']) : null, 'ai_ok' => ai_available()['ok'] || ib_hl_alive(), 'ai_via' => ai_available()['ok'] ? 'api' : (ib_hl_alive() ? 'hl' : 'none')]);
    }
    // threads
    $w = ["t.project_id IN ($in)"]; $v = $pids;
    $status = (string)($_GET['status'] ?? 'open'); if ($status !== 'all') { $w[] = 't.status=?'; $v[] = $status === 'done' ? 'done' : 'open'; }
    if (!empty($_GET['unread'])) $w[] = 't.unread>0';
    if (($_GET['mine'] ?? '') === '1') { $w[] = 't.assigned_to=?'; $v[] = $me['id']; }
    if (!empty($_GET['project'])) { $w[] = 't.project_id=?'; $v[] = (string)$_GET['project']; }
    if (!empty($_GET['provider'])) { $w[] = 't.provider=?'; $v[] = (string)$_GET['provider']; }
    if (in_array($_GET['kind'] ?? '', ['message', 'comment'], true)) { $w[] = "COALESCE(t.kind,'message')=?"; $v[] = $_GET['kind']; }
    if (trim((string)($_GET['q'] ?? '')) !== '') { $q = '%' . trim((string)$_GET['q']) . '%'; $w[] = '(t.customer_name LIKE ? OR t.last_message LIKE ? OR EXISTS (SELECT 1 FROM inbox_messages m WHERE m.thread_id=t.id AND m.text LIKE ?))'; array_push($v, $q, $q, $q); }
    $lim = max(10, min(300, (int)($_GET['limit'] ?? 120)));
    $st = $pdo->prepare('SELECT t.*, c.label conn_label FROM inbox_threads t LEFT JOIN connections c ON c.id=t.connection_id WHERE ' . implode(' AND ', $w) . " ORDER BY (t.unread>0) DESC, t.last_time DESC LIMIT $lim"); $st->execute($v);
    $threads = array_map(fn($t) => ib_public($t) + ['conn_label' => $t['conn_label']], $st->fetchAll());
    $cs = $pdo->prepare("SELECT COALESCE(SUM(unread>0),0) unread, COALESCE(SUM(status='open'),0) open, COALESCE(SUM(status='done'),0) done, COUNT(*) total FROM inbox_threads WHERE project_id IN ($in)"); $cs->execute($pids);
    $conns = $pdo->prepare("SELECT id,project_id,provider,label,status,external_account_id,last_error FROM connections WHERE project_id IN ($in) AND provider IN ('facebook_page','zalo_oa','zalo_user') ORDER BY project_id,label"); $conns->execute($pids);
    $sources = array_map(fn($c) => $c + ['sync' => jdecode(setting_get('inbox.sync.' . $c['id']) ?: null, [])], $conns->fetchAll());
    respond(['threads' => $threads, 'counts' => $cs->fetch(), 'sources' => $sources, 'meta_app_live' => setting_get('meta.app_live', '1') !== '0', 'projects' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'color' => $p['color'] ?? null, 'role' => $roleOf[$p['id']]], $myProjects),
        'last_cron' => setting_get('cron.last_run')]);
}

require_method('POST');
$b = json_input(); $action = (string)($b['action'] ?? '');

if ($action === 'sync') {
    $cid = (string)($b['connection_id'] ?? '');
    if ($cid !== '') { $c = get_connection($cid, true); if (!$c || !in_array($c['project_id'], $pids, true) || !in_array($c['provider'], INBOX_PROVIDERS, true)) fail('Kênh không hợp lệ');
        try { respond(['ok' => true] + inbox_sync_connection($CFG, $c)); } catch (Throwable $e) { setting_set('inbox.sync.' . $c['id'], json_encode(['ok' => false, 'at' => date('Y-m-d H:i:s'), 'error' => mb_substr($e->getMessage(), 0, 300)]), ['id' => null]); fail($e->getMessage(), 502); } }
    $st = $pdo->prepare("SELECT * FROM connections WHERE status='connected' AND external_account_id IS NOT NULL AND provider IN ('facebook_page','zalo_oa') AND project_id IN ($in)"); $st->execute($pids);
    $new = 0; $errs = [];
    foreach ($st->fetchAll() as $c) { try { $new += inbox_sync_connection($CFG, $c)['new']; } catch (Throwable $e) { setting_set('inbox.sync.' . $c['id'], json_encode(['ok' => false, 'at' => date('Y-m-d H:i:s'), 'error' => mb_substr($e->getMessage(), 0, 300)]), ['id' => null]); $errs[] = $c['label'] . ': ' . $e->getMessage(); } }
    respond(['ok' => true, 'new' => $new, 'errors' => $errs]);
}

$t = ib_thread((string)($b['id'] ?? ''), $pids);
if (!ib_can_write($t['project_id'], $roleOf, $me) && $action !== 'read') fail('Bạn chỉ có quyền xem ở dự án này', 403);

if ($action === 'send') {
    $text = trim((string)($b['text'] ?? '')); if ($text === '') fail('Nhập nội dung');
    $r = inbox_send($CFG, $t, $text, $me);
    if (!$r['ok']) fail($r['error'], 502);
    if (!empty($r['note'])) log_activity('reply', 'inbox', $t['id'], 'Bình luận' . $r['note'], $me, $t['project_id']);
    $pdo->prepare('UPDATE inbox_threads SET needs_human=0 WHERE id=?')->execute([$t['id']]);
    log_activity('reply', 'inbox', $t['id'], 'Trả lời ' . $t['customer_name'] . ' (' . $t['provider'] . ')', $me, $t['project_id']);
    respond(['ok' => true, 'id' => $r['id']]);
}
if ($action === 'ai_suggest') {
    if (!ai_available()['ok']) { $prep = ib_ai_prepare($CFG, $t); if (empty($prep['ok'])) fail($prep['error']); if (!ib_hl_alive()) fail('Máy HL (Claude Max) chưa kết nối — mở app phòng marketing trên máy HL', 503); respond(['job_id' => ib_ai_enqueue($t, 'suggest', $prep)], 202); }
    $d = ib_ai_draft($CFG, $t); if (empty($d['ok'])) fail($d['error'] ?? 'AI lỗi', 502); respond(['text' => $d['reply'], 'handoff' => $d['handoff'], 'reason' => $d['reason']]);
}
if ($action === 'ai_job') { $st = $pdo->prepare('SELECT * FROM inbox_ai_jobs WHERE id=? AND thread_id=?'); $st->execute([(string)($b['job_id'] ?? ''), $t['id']]); $j = $st->fetch(); if (!$j) fail('Không có việc', 404);
    $res = jdecode($j['result'] ?: null, []); respond(['status' => $j['status'], 'text' => $res['reply'] ?? null, 'handoff' => $res['handoff'] ?? null, 'reason' => $res['reason'] ?? null, 'error' => $j['error']]); }
if ($action === 'ai_mode') { $m = ($b['mode'] ?? 'on') === 'off' ? 'off' : 'on'; $pdo->prepare("UPDATE inbox_threads SET ai_mode=?, needs_human=CASE WHEN ?='on' THEN 0 ELSE needs_human END WHERE id=?")->execute([$m, $m, $t['id']]); respond(['ok' => true, 'ai_mode' => $m]); }
if ($action === 'human_done') { $pdo->prepare('UPDATE inbox_threads SET needs_human=0 WHERE id=?')->execute([$t['id']]); respond(['ok' => true]); }
if ($action === 'done' || $action === 'reopen') { $pdo->prepare("UPDATE inbox_threads SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$action === 'done' ? 'done' : 'open', $t['id']]); respond(['ok' => true]); }
if ($action === 'read') { $pdo->prepare("UPDATE inbox_threads SET unread=0, read_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s'), $t['id']]); respond(['ok' => true]); }
if ($action === 'assign') { $uid = (string)($b['user_id'] ?? '') ?: null; $pdo->prepare('UPDATE inbox_threads SET assigned_to=? WHERE id=?')->execute([$uid, $t['id']]); if ($uid && $uid !== $me['id']) notify($uid, 'Bạn được giao chăm sóc ' . $t['customer_name'], mb_substr((string)$t['last_message'], 0, 120), '#inbox', $t['project_id']); respond(['ok' => true]); }
if ($action === 'note') { $pdo->prepare('UPDATE inbox_threads SET note=? WHERE id=?')->execute([mb_substr(trim((string)($b['note'] ?? '')), 0, 2000), $t['id']]); respond(['ok' => true]); }
if ($action === 'to_lead') {
    if ($t['lead_id']) respond(['ok' => true, 'lead_id' => $t['lead_id'], 'existed' => true]);
    $phone = preg_replace('/\D+/', '', (string)($b['phone'] ?? ''));
    if ($phone === '') { $st = $pdo->prepare("SELECT text FROM inbox_messages WHERE thread_id=? AND direction='in' ORDER BY sent_at DESC LIMIT 50"); $st->execute([$t['id']]);
        foreach ($st->fetchAll() as $m) if (preg_match('/(?:\+?84|0)(?:3|5|7|8|9)\d{8}\b/', preg_replace('/[\s.\-]/', '', (string)$m['text']), $mm)) { $phone = $mm[0]; break; } }
    $id = gen_id('LD');
    $pdo->prepare("INSERT INTO leads(id,project_id,name,phone,source,subject,state,notes,source_ref) VALUES (?,?,?,?,?,?,'CREATED',?,?)")
        ->execute([$id, $t['project_id'], $t['customer_name'], $phone ?: null, $t['provider'] === 'facebook_page' ? 'messenger' : $t['provider'], 'Tin nhắn ' . (['facebook_page' => 'Messenger', 'zalo_user' => 'Zalo cá nhân'][$t['provider']] ?? 'Zalo OA'), mb_substr((string)$t['last_message'], 0, 500), $t['id']]);
    $pdo->prepare('UPDATE inbox_threads SET lead_id=? WHERE id=?')->execute([$id, $t['id']]);
    log_activity('create', 'lead', $id, 'Tạo đầu mối từ cửa sổ chat: ' . $t['customer_name'], $me, $t['project_id']);
    respond(['ok' => true, 'lead_id' => $id, 'phone' => $phone ?: null], 201);
}
fail('Thao tác không hỗ trợ');
