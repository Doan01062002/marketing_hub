<?php
/* CSKH — khách từ website (liên hệ + đơn hàng chungauto.vn) của dự án hiện hành. 16/10/2026
   GET  ?q&kind&care&web&assignee&due=today|overdue|none&from&to&sort&page&per   → {rows, total, page, per, counts, kinds, web_statuses, staff, stats, last_sync}
   GET  ?id=…                    → {record, history:[cùng SĐT]}
   GET  ?export=1&(bộ lọc như trên) → CSV toàn bộ kết quả lọc
   POST {action:'update', id, care_status?, assignee_id?, next_follow_at?, note?}   → ghi nhật ký chăm sóc
   POST {action:'sync', full?}   → kéo dữ liệu mới từ chungauto.vn (chủ / quản lý dự án) */
require __DIR__ . '/_boot.php';
$me = require_login();
$PID = pid();

const CARE_ST = ['new' => 'Chưa chăm sóc', 'contacting' => 'Đang liên hệ', 'callback' => 'Hẹn gọi lại', 'consulted' => 'Đã tư vấn', 'won' => 'Đã chốt / đã mua', 'lost' => 'Không nhu cầu / huỷ', 'invalid' => 'Sai số / trùng'];

function care_staff(string $pid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=?)) ORDER BY u.name");
    $st->execute([$pid]); return $st->fetchAll();
}
function care_row(array $r): array {
    $r['items'] = jdecode($r['items'] ?? null); $r['care_log'] = jdecode($r['care_log'] ?? null);
    foreach (['subtotal', 'discount', 'total'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
    return $r;
}
/** WHERE theo bộ lọc. */
function care_where(array $g, string $pid): array {
    $w = ['project_id=?']; $a = [$pid];
    if (($q = trim((string)($g['q'] ?? ''))) !== '') {
        $digits = preg_replace('/\D/', '', $q);
        $w[] = '(name LIKE ? OR address LIKE ? OR message LIKE ? OR items LIKE ? OR email LIKE ? OR ext_id=?' . (strlen($digits) >= 3 ? ' OR phone_norm LIKE ?' : '') . ')';
        array_push($a, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", $q); if (strlen($digits) >= 3) $a[] = "%$digits%";
    }
    if (in_array($g['kind'] ?? '', ['contact', 'order'], true)) { $w[] = 'kind=?'; $a[] = $g['kind']; }
    if (isset(CARE_ST[$g['care'] ?? ''])) { $w[] = 'care_status=?'; $a[] = $g['care']; }
    if (($g['web'] ?? '') !== '') { $w[] = 'web_status=?'; $a[] = (string)$g['web']; }
    if (($g['assignee'] ?? '') === '_none') $w[] = "COALESCE(assignee_id,'')=''";
    elseif (($g['assignee'] ?? '') !== '') { $w[] = 'assignee_id=?'; $a[] = (string)$g['assignee']; }
    $now = date('Y-m-d H:i');
    if (($g['due'] ?? '') === 'today') { $w[] = "next_follow_at IS NOT NULL AND substr(next_follow_at,1,10)<=?"; $a[] = date('Y-m-d'); $w[] = "care_status NOT IN ('won','lost','invalid')"; }
    if (($g['due'] ?? '') === 'overdue') { $w[] = "next_follow_at IS NOT NULL AND next_follow_at<?"; $a[] = $now; $w[] = "care_status NOT IN ('won','lost','invalid')"; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['from'] ?? ''))) { $w[] = 'ext_created_at>=?'; $a[] = $g['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['to'] ?? ''))) { $w[] = 'ext_created_at<=?'; $a[] = $g['to'] . ' 23:59:59'; }
    return [implode(' AND ', $w), $a];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM care_records WHERE id=? AND project_id=?'); $st->execute([(string)$_GET['id'], $PID]);
        $r = $st->fetch(); if (!$r) fail('Không tìm thấy khách', 404);
        $hist = [];
        if ($r['phone_norm'] !== '' && $r['phone_norm'] !== null) {
            $h = $pdo->prepare('SELECT id, kind, ext_id, name, web_status, care_status, total, ext_created_at, items, message FROM care_records WHERE project_id=? AND phone_norm=? AND id<>? ORDER BY ext_created_at DESC LIMIT 50');
            $h->execute([$PID, $r['phone_norm'], $r['id']]); $hist = array_map('care_row', $h->fetchAll());
        }
        respond(['record' => care_row($r), 'history' => $hist, 'staff' => care_staff($PID), 'care_statuses' => CARE_ST]);
    }
    [$w, $a] = care_where($_GET, $PID);
    $sorts = ['new' => 'ext_created_at DESC', 'old' => 'ext_created_at ASC', 'follow' => "CASE WHEN next_follow_at IS NULL THEN 1 ELSE 0 END, next_follow_at ASC", 'value' => 'COALESCE(total,0) DESC', 'updated' => 'updated_at DESC'];
    $order = $sorts[$_GET['sort'] ?? 'new'] ?? $sorts['new'];
    if (!empty($_GET['export'])) {
        $st = $pdo->prepare("SELECT * FROM care_records WHERE $w ORDER BY $order"); $st->execute($a);
        $names = array_column(care_staff($PID), 'name', 'id');
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="CSKH_chungauto_' . date('Ymd_Hi') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Loại', 'ID web', 'Ngày tạo', 'Tên', 'SĐT', 'Email', 'Địa chỉ', 'Nội dung / ghi chú khách', 'Sản phẩm', 'Tạm tính', 'Giảm giá', 'Tổng tiền', 'Mã giảm giá', 'Trạng thái web', 'Trạng thái CSKH', 'Phụ trách', 'Hẹn gọi lại', 'Liên hệ gần nhất', 'Ghi chú CSKH gần nhất']);
        foreach ($st as $r) {
            $items = implode('; ', array_map(fn($i) => $i['name'] . ' x' . $i['qty'], jdecode($r['items'] ?? null)));
            $log = jdecode($r['care_log'] ?? null); $lastNote = $log ? end($log)['text'] ?? '' : '';
            fputcsv($o, [$r['kind'] === 'order' ? 'Đơn hàng' : 'Liên hệ', $r['ext_id'], $r['ext_created_at'], $r['name'], $r['phone'], $r['email'], $r['address'], $r['message'], $items,
                $r['subtotal'], $r['discount'], $r['total'], $r['coupon'], $r['web_status'], CARE_ST[$r['care_status']] ?? $r['care_status'], $names[$r['assignee_id']] ?? '', $r['next_follow_at'], $r['last_contact_at'], $lastNote]);
        }
        fclose($o); exit;
    }
    $per = max(10, min(200, (int)($_GET['per'] ?? 50))); $page = max(1, (int)($_GET['page'] ?? 1));
    $st = $pdo->prepare("SELECT COUNT(*) FROM care_records WHERE $w"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM care_records WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a);
    $rows = array_map('care_row', $st->fetchAll());
    // số khách cùng SĐT (để CSKH biết khách quen)
    $phones = array_values(array_unique(array_filter(array_column($rows, 'phone_norm'))));
    $dup = [];
    if ($phones) { $in = implode(',', array_fill(0, count($phones), '?')); $q = $pdo->prepare("SELECT phone_norm, COUNT(*) n, SUM(CASE WHEN kind='order' THEN 1 ELSE 0 END) o FROM care_records WHERE project_id=? AND phone_norm IN ($in) GROUP BY phone_norm"); $q->execute(array_merge([$PID], $phones)); foreach ($q as $x) $dup[$x['phone_norm']] = [(int)$x['n'], (int)$x['o']]; }
    foreach ($rows as &$r) { [$n, $o] = $dup[$r['phone_norm']] ?? [1, 0]; $r['same_phone'] = $n; $r['same_phone_orders'] = $o; } unset($r);
    $cnt = []; $q = $pdo->prepare('SELECT care_status, COUNT(*) n FROM care_records WHERE project_id=? GROUP BY care_status'); $q->execute([$PID]); foreach ($q as $x) $cnt[$x['care_status']] = (int)$x['n'];
    $kinds = []; $q = $pdo->prepare('SELECT kind, COUNT(*) n FROM care_records WHERE project_id=? GROUP BY kind'); $q->execute([$PID]); foreach ($q as $x) $kinds[$x['kind']] = (int)$x['n'];
    $webs = $pdo->prepare('SELECT web_status, kind, COUNT(*) n FROM care_records WHERE project_id=? AND web_status IS NOT NULL GROUP BY web_status, kind ORDER BY kind, n DESC'); $webs->execute([$PID]);
    $q = $pdo->prepare("SELECT SUM(CASE WHEN next_follow_at IS NOT NULL AND substr(next_follow_at,1,10)<=? AND care_status NOT IN ('won','lost','invalid') THEN 1 ELSE 0 END) due_today,
        SUM(CASE WHEN next_follow_at IS NOT NULL AND next_follow_at<? AND care_status NOT IN ('won','lost','invalid') THEN 1 ELSE 0 END) overdue,
        SUM(CASE WHEN COALESCE(assignee_id,'')='' AND care_status NOT IN ('won','lost','invalid') THEN 1 ELSE 0 END) unassigned,
        SUM(CASE WHEN kind='order' AND web_status IN ('Thành công','Đã thanh toán') THEN total ELSE 0 END) revenue,
        SUM(CASE WHEN ext_created_at>=? THEN 1 ELSE 0 END) last7 FROM care_records WHERE project_id=?");
    $q->execute([date('Y-m-d'), date('Y-m-d H:i'), date('Y-m-d 00:00:00', strtotime('-7 days')), $PID]); $stats = $q->fetch();
    respond(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $cnt, 'kinds' => $kinds, 'web_statuses' => $webs->fetchAll(),
        'stats' => array_map(fn($v) => (float)$v, $stats ?: []), 'staff' => care_staff($PID), 'care_statuses' => CARE_ST,
        'last_sync' => jdecode(setting_get('care.last_sync.' . $PID) ?: null), 'can_sync' => in_array(project_role(), PROJECT_ADMIN_ROLES, true) || $me['role'] === 'admin',
        'sync_configured' => !empty($CFG['chungauto_admin_email']) && !empty($CFG['chungauto_admin_password'])]);
}

require_method('POST');
$in = json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'update') {
    require_project_write();
    $st = $pdo->prepare('SELECT * FROM care_records WHERE id=? AND project_id=?'); $st->execute([(string)($in['id'] ?? ''), $PID]);
    $r = $st->fetch(); if (!$r) fail('Không tìm thấy khách', 404);
    $set = []; $vals = []; $logText = [];
    if (array_key_exists('care_status', $in)) {
        if (!isset(CARE_ST[$in['care_status']])) fail('Trạng thái chăm sóc không hợp lệ');
        if ($in['care_status'] !== $r['care_status']) { $set[] = 'care_status=?'; $vals[] = $in['care_status']; $logText[] = 'Trạng thái: ' . CARE_ST[$in['care_status']]; }
    }
    if (array_key_exists('assignee_id', $in)) {
        $aid = (string)($in['assignee_id'] ?? '');
        $staff = array_column(care_staff($PID), 'name', 'id');
        if ($aid !== '' && !isset($staff[$aid])) fail('Người phụ trách không thuộc dự án');
        if ($aid !== (string)$r['assignee_id']) { $set[] = 'assignee_id=?'; $vals[] = $aid ?: null; $logText[] = 'Giao cho: ' . ($staff[$aid] ?? 'bỏ giao');
            if ($aid && $aid !== $me['id']) notify($aid, 'Bạn được giao chăm sóc ' . ($r['name'] ?: 'khách') . ' (' . ($r['kind'] === 'order' ? 'đơn #' : 'liên hệ #') . $r['ext_id'] . ')', (string)$r['phone'], '#leadsmenu/care', $PID); }
    }
    if (array_key_exists('next_follow_at', $in)) {
        $nf = trim((string)($in['next_follow_at'] ?? ''));
        if ($nf !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $nf = str_replace('T', ' ', $nf))) fail('Ngày hẹn không hợp lệ');
        if ($nf !== (string)$r['next_follow_at']) { $set[] = 'next_follow_at=?'; $vals[] = $nf ?: null; $logText[] = $nf ? 'Hẹn gọi lại: ' . $nf : 'Bỏ hẹn'; }
    }
    $note = trim((string)($in['note'] ?? ''));
    if ($note !== '') { $logText[] = mb_substr($note, 0, 2000); $set[] = 'last_contact_at=?'; $vals[] = date('Y-m-d H:i'); }
    if (!$set) respond(['ok' => true, 'unchanged' => true]);
    $log = jdecode($r['care_log'] ?? null);
    $log[] = ['at' => date('Y-m-d H:i'), 'by' => $me['id'], 'name' => $me['name'], 'status' => $in['care_status'] ?? $r['care_status'], 'text' => implode(' · ', $logText)];
    $set[] = 'care_log=?'; $vals[] = json_encode(array_slice($log, -200), JSON_UNESCAPED_UNICODE);
    $set[] = "updated_at=datetime('now')";
    $vals[] = $r['id'];
    $pdo->prepare('UPDATE care_records SET ' . implode(', ', $set) . ' WHERE id=?')->execute($vals);
    $st->execute([$r['id'], $PID]);
    respond(['ok' => true, 'record' => care_row($st->fetch())]);
}

if ($action === 'sync') {
    if (!in_array(project_role(), PROJECT_ADMIN_ROLES, true) && $me['role'] !== 'admin') fail('Chỉ chủ / quản lý dự án được đồng bộ', 403);
    require_once __DIR__ . '/_chungauto_sync_lib.php';
    session_write_close(); @set_time_limit(300);
    try { $r = ca_sync($CFG, $PID, ['full' => !empty($in['full']), 'max_seconds' => 240]); }
    catch (CaSyncError $e) { fail($e->getMessage(), 502); }
    log_activity('import', 'care', null, 'Đồng bộ khách chungauto.vn: ' . $r['contacts']['new'] . ' liên hệ mới, ' . $r['orders']['new'] . ' đơn mới', $me, $PID);
    respond(['ok' => true] + $r);
}
fail('action không hợp lệ', 404);
