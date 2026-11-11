<?php
/* api/chat_crm.php — CRM KHÁCH HÀNG (ứng dụng Chat) — dự án hiện hành (header X-Project). (api/crm.php là tích hợp CRM ngoài cũ, đã gỡ.) Dữ liệu: care_records + crm_deals. 11/11/2026
   GET  ?view=all|new|caring|won|recycled &q &care &branch &assignee(_none|_me|id) &src &need &phone=1 &channel &sort &page   → danh sách + đếm
   GET  ?id=…            → {record, history (cùng SĐT), deals, thread}
   GET  ?tasks=all|today|overdue|upcoming → lịch hẹn gọi lại
   GET  ?deals=1         → cơ hội bán hàng (Kanban)
   GET  ?report=1        → chỉ số CRM
   GET  ?config=1        → cấu hình (cơ sở + nhân viên chia vòng, cột tuỳ chỉnh, SLA)
   GET  ?export=1&(bộ lọc) → CSV
   POST create | update | import | sync_chat | deal_save | deal_move | deal_del | config_save | branch_save | branch_del */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_crm_lib.php';
$me = require_login();
$PID = pid();
$isAdmin = $me['role'] === 'admin' || in_array(project_role(), PROJECT_ADMIN_ROLES, true);

function crm_row(array $r): array {
    $r['care_log'] = jdecode($r['care_log'] ?? null, []); $r['custom'] = jdecode($r['custom'] ?? null, []) ?: new stdClass(); $r['items'] = jdecode($r['items'] ?? null, []);
    foreach (['subtotal', 'discount', 'total'] as $k) $r[$k] = isset($r[$k]) ? (float)$r[$k] : null;
    return $r;
}
function crm_where(array $g, string $pid, array $me): array {
    $w = ['c.project_id=?']; $a = [$pid];
    $v = (string)($g['view'] ?? 'all');
    if ($v === 'new') $w[] = "c.care_status='new' AND c.recycled_at IS NULL";
    elseif ($v === 'caring') $w[] = "c.care_status IN ('" . implode("','", CRM_CARING) . "')";
    elseif ($v === 'won') $w[] = "c.care_status='won'";
    elseif ($v === 'recycled') $w[] = "c.recycled_at IS NOT NULL AND c.assignee_id IS NULL AND c.care_status NOT IN ('won','lost','invalid')";
    if (($q = trim((string)($g['q'] ?? ''))) !== '') {
        $dg = preg_replace('/\D/', '', $q);
        $w[] = '(c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ? OR c.address LIKE ? OR c.message LIKE ? OR c.care_log LIKE ?' . (strlen($dg) >= 3 ? ' OR c.phone_norm LIKE ?' : '') . ')';
        array_push($a, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); if (strlen($dg) >= 3) $a[] = "%$dg%";
    }
    if (isset(CRM_ST[$g['care'] ?? ''])) { $w[] = 'c.care_status=?'; $a[] = $g['care']; }
    if (($g['branch'] ?? '') === '_none') $w[] = "COALESCE(c.branch_id,'')=''"; elseif (($g['branch'] ?? '') !== '') { $w[] = 'c.branch_id=?'; $a[] = (string)$g['branch']; }
    $as = (string)($g['assignee'] ?? '');
    if ($as === '_none') $w[] = "COALESCE(c.assignee_id,'')=''"; elseif ($as === '_me') { $w[] = 'c.assignee_id=?'; $a[] = $me['id']; } elseif ($as !== '') { $w[] = 'c.assignee_id=?'; $a[] = $as; }
    if (isset(CRM_SRC[$g['src'] ?? ''])) { $w[] = 'c.src=?'; $a[] = $g['src']; }
    if (isset(CRM_NEED[$g['need'] ?? ''])) { $w[] = 'c.need_level=?'; $a[] = $g['need']; }
    if (!empty($g['phone'])) $w[] = "COALESCE(c.phone_norm,'')<>''";
    if (($g['channel'] ?? '') !== '') { $w[] = 't.connection_id=?'; $a[] = (string)$g['channel']; }
    return [implode(' AND ', $w), $a];
}
const CRM_FROM = 'care_records c LEFT JOIN inbox_threads t ON t.id=c.thread_id LEFT JOIN connections cn ON cn.id=t.connection_id';
const CRM_COLS = 'c.*, t.provider thread_provider, t.connection_id channel_id, cn.label channel_label';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = crm_cfg($PID);
    $base = ['statuses' => CRM_ST, 'needs' => CRM_NEED, 'sources' => CRM_SRC, 'stages' => CRM_STAGES, 'staff' => crm_staff($PID), 'branches' => crm_branches($PID), 'cols' => $cfg['cols'], 'project' => ['id' => $PID, 'name' => current_project()['name'] ?? ''], 'can_admin' => $isAdmin];
    if (!empty($_GET['config'])) respond($base + ['sla_min' => $cfg['sla_min'], 'staff_export' => $cfg['staff_export']]);
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT ' . CRM_COLS . ' FROM ' . CRM_FROM . ' WHERE c.id=? AND c.project_id=?'); $st->execute([(string)$_GET['id'], $PID]);
        $r = $st->fetch(); if (!$r) fail('Không tìm thấy khách', 404);
        $hist = [];
        if ($r['phone_norm']) { $h = $pdo->prepare('SELECT id, kind, src, ext_id, name, care_status, total, ext_created_at, items FROM care_records WHERE project_id=? AND phone_norm=? AND id<>? ORDER BY ext_created_at DESC LIMIT 30'); $h->execute([$PID, $r['phone_norm'], $r['id']]); $hist = array_map('crm_row', $h->fetchAll()); }
        $d = $pdo->prepare('SELECT * FROM crm_deals WHERE care_id=? ORDER BY created_at DESC'); $d->execute([$r['id']]);
        respond($base + ['record' => crm_row($r), 'history' => $hist, 'deals' => $d->fetchAll()]);
    }
    if (!empty($_GET['deals'])) {
        $st = $pdo->prepare('SELECT d.*, c.name customer_name, c.phone customer_phone FROM crm_deals d LEFT JOIN care_records c ON c.id=d.care_id WHERE d.project_id=? ORDER BY d.updated_at DESC'); $st->execute([$PID]);
        respond($base + ['deals' => $st->fetchAll()]);
    }
    if (!empty($_GET['tasks'])) {
        $when = (string)$_GET['tasks']; $now = crm_now(); $today = date('Y-m-d');
        $w = "c.project_id=? AND c.next_follow_at IS NOT NULL AND c.care_status NOT IN ('won','lost','invalid')"; $a = [$PID];
        if ($when === 'today') { $w .= ' AND substr(c.next_follow_at,1,10)=?'; $a[] = $today; }
        elseif ($when === 'overdue') { $w .= ' AND c.next_follow_at<?'; $a[] = $now; }
        elseif ($when === 'upcoming') { $w .= ' AND substr(c.next_follow_at,1,10)>?'; $a[] = $today; }
        $st = $pdo->prepare('SELECT ' . CRM_COLS . ' FROM ' . CRM_FROM . " WHERE $w ORDER BY c.next_follow_at LIMIT 300"); $st->execute($a);
        $cnt = $pdo->prepare("SELECT COUNT(*) total, SUM(substr(next_follow_at,1,10)=?) today, SUM(next_follow_at<?) overdue, SUM(substr(next_follow_at,1,10)>?) upcoming FROM care_records WHERE project_id=? AND next_follow_at IS NOT NULL AND care_status NOT IN ('won','lost','invalid')");
        $cnt->execute([$today, $now, $today, $PID]);
        respond($base + ['rows' => array_map('crm_row', $st->fetchAll()), 'counts' => array_map('intval', $cnt->fetch() ?: [])]);
    }
    if (!empty($_GET['report'])) {
        $st = $pdo->prepare("SELECT COUNT(*) total, SUM(substr(ext_created_at,1,10)=?) today, SUM(need_level='hot') hot, SUM(next_follow_at IS NOT NULL AND next_follow_at<? AND care_status NOT IN ('won','lost','invalid')) overdue FROM care_records WHERE project_id=?");
        $st->execute([date('Y-m-d'), crm_now(), $PID]); $kpi = array_map('intval', $st->fetch() ?: []);
        $v = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE project_id=? AND stage<>'won'"); $v->execute([$PID]); $kpi['pipeline'] = (float)$v->fetchColumn();
        $src = $pdo->prepare('SELECT src k, COUNT(*) n FROM care_records WHERE project_id=? GROUP BY src ORDER BY n DESC'); $src->execute([$PID]);
        $fun = $pdo->prepare('SELECT care_status k, COUNT(*) n FROM care_records WHERE project_id=? GROUP BY care_status'); $fun->execute([$PID]);
        $stg = $pdo->prepare('SELECT stage k, COUNT(*) n, COALESCE(SUM(value),0) v FROM crm_deals WHERE project_id=? GROUP BY stage'); $stg->execute([$PID]);
        respond($base + ['kpi' => $kpi, 'by_source' => $src->fetchAll(), 'funnel' => $fun->fetchAll(), 'deal_stages' => $stg->fetchAll()]);
    }
    [$w, $a] = crm_where($_GET, $PID, $me);
    $sorts = ['new' => 'c.ext_created_at DESC', 'old' => 'c.ext_created_at ASC', 'follow' => 'CASE WHEN c.next_follow_at IS NULL THEN 1 ELSE 0 END, c.next_follow_at', 'updated' => 'c.updated_at DESC', 'name' => 'c.name COLLATE NOCASE'];
    $order = $sorts[$_GET['sort'] ?? 'new'] ?? $sorts['new'];
    if (!empty($_GET['export'])) {
        if (!$isAdmin && !crm_cfg($PID)['staff_export']) fail('Quản trị chưa cho phép nhân viên xuất file', 403);
        $st = $pdo->prepare('SELECT ' . CRM_COLS . ' FROM ' . CRM_FROM . " WHERE $w ORDER BY $order"); $st->execute($a);
        $names = array_column(crm_staff($PID), 'name', 'id'); $brs = array_column(crm_branches($PID), 'name', 'id'); $cols = crm_cfg($PID)['cols'];
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="khach-hang_' . date('Ymd_Hi') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, array_merge(['Mã', 'Họ tên', 'SĐT', 'Email', 'Công ty', 'Địa chỉ', 'Trạng thái', 'Nhu cầu', 'Nguồn', 'Kênh chat', 'Phụ trách', 'Cơ sở', 'Hẹn gọi lại', 'Liên hệ gần nhất', 'Ngày tạo', 'Ghi chú gần nhất'], array_column($cols, 'label')));
        foreach ($st as $r) {
            $log = jdecode($r['care_log'] ?? null, []); $cu = jdecode($r['custom'] ?? null, []);
            fputcsv($o, array_merge([$r['id'], $r['name'], $r['phone'], $r['email'], $r['company'], $r['address'], CRM_ST[$r['care_status']] ?? $r['care_status'], CRM_NEED[$r['need_level']] ?? '', CRM_SRC[$r['src']] ?? $r['src'], $r['channel_label'] ?? '',
                $names[$r['assignee_id']] ?? '', $brs[$r['branch_id']] ?? '', $r['next_follow_at'], $r['last_contact_at'], $r['ext_created_at'], $log ? (end($log)['text'] ?? '') : ''], array_map(fn($c) => (string)($cu[$c['key']] ?? ''), $cols)));
        }
        fclose($o); exit;
    }
    $per = max(10, min(200, (int)($_GET['per'] ?? 50))); $page = max(1, (int)($_GET['page'] ?? 1));
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . CRM_FROM . " WHERE $w"); $st->execute($a); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT ' . CRM_COLS . ' FROM ' . CRM_FROM . " WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($a);
    $rows = array_map('crm_row', $st->fetchAll());
    // đếm theo trạng thái trong bộ lọc hiện tại (trừ chính bộ lọc trạng thái) + đếm theo chế độ xem
    $g2 = $_GET; unset($g2['care']); [$w2, $a2] = crm_where($g2, $PID, $me);
    $sc = $pdo->prepare('SELECT c.care_status k, COUNT(*) n FROM ' . CRM_FROM . " WHERE $w2 GROUP BY c.care_status"); $sc->execute($a2);
    $vc = $pdo->prepare("SELECT COUNT(*) a, SUM(care_status='new' AND recycled_at IS NULL) n, SUM(care_status IN ('" . implode("','", CRM_CARING) . "')) c, SUM(care_status='won') w,
        SUM(recycled_at IS NOT NULL AND assignee_id IS NULL AND care_status NOT IN ('won','lost','invalid')) r FROM care_records WHERE project_id=?"); $vc->execute([$PID]); $v = $vc->fetch();
    $ch = $pdo->prepare('SELECT cn.id, cn.label, cn.provider, COUNT(*) n FROM care_records c JOIN inbox_threads t ON t.id=c.thread_id JOIN connections cn ON cn.id=t.connection_id WHERE c.project_id=? GROUP BY cn.id'); $ch->execute([$PID]);
    respond($base + ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'status_counts' => array_column($sc->fetchAll(), 'n', 'k'),
        'view_counts' => ['all' => (int)$v['a'], 'new' => (int)$v['n'], 'caring' => (int)$v['c'], 'won' => (int)$v['w'], 'recycled' => (int)$v['r']], 'channels' => $ch->fetchAll(),
        'can_export' => $isAdmin || crm_cfg($PID)['staff_export']]);
}

require_method('POST');
$in = json_input(); $action = (string)($in['action'] ?? '');
$u = require_project_write();
$getRec = function (string $id) use ($pdo, $PID) { $st = $pdo->prepare('SELECT * FROM care_records WHERE id=? AND project_id=?'); $st->execute([$id, $PID]); $r = $st->fetch(); if (!$r) fail('Không tìm thấy khách', 404); return $r; };
$customIn = function ($v) use ($PID) { $out = []; foreach (crm_cfg($PID)['cols'] as $c) { $x = trim((string)(((array)$v)[$c['key']] ?? '')); if ($x !== '') $out[$c['key']] = mb_substr($x, 0, 300); } return $out; };

if ($action === 'create') {
    if (trim((string)($in['name'] ?? '')) === '' || crm_phone_norm($in['phone'] ?? '') === '') fail('Nhập họ tên và số điện thoại');
    if (($in['assignee_id'] ?? '') !== '' && !in_array($in['assignee_id'], array_column(crm_staff($PID), 'id'), true)) fail('Nhân viên không thuộc dự án');
    if (($in['thread_id'] ?? '') !== '') { $t = $pdo->prepare('SELECT project_id FROM inbox_threads WHERE id=?'); $t->execute([(string)$in['thread_id']]); if ($t->fetchColumn() !== $PID) fail('Hội thoại không thuộc dự án này'); }
    $r = crm_create($PID, ['src' => ($in['thread_id'] ?? '') ? 'chat' : 'manual', 'custom' => $customIn($in['custom'] ?? [])] + $in, $u);
    if (isset($r['dup'])) {
        if (($in['thread_id'] ?? '') !== '' && empty($r['dup']['thread_id'])) $pdo->prepare('UPDATE care_records SET thread_id=? WHERE id=?')->execute([(string)$in['thread_id'], $r['dup']['id']]);
        respond(['ok' => true, 'existed' => true, 'id' => $r['dup']['id'], 'name' => $r['dup']['name']]);
    }
    log_activity('create', 'care', $r['record']['id'], 'Thêm khách CRM: ' . $r['record']['name'], $u, $PID);
    respond(['ok' => true, 'id' => $r['record']['id']]);
}
if ($action === 'update') {
    $r = $getRec((string)($in['id'] ?? '')); $set = []; $vals = []; $log = [];
    $put = function (string $col, $val, string $label) use (&$set, &$vals, &$log, $r) { if ((string)$val !== (string)($r[$col] ?? '')) { $set[] = "$col=?"; $vals[] = $val === '' ? null : $val; if ($label !== '') $log[] = $label; } };
    foreach (['name' => 'Tên', 'email' => 'Email', 'company' => 'Công ty', 'address' => 'Địa chỉ'] as $k => $l) if (array_key_exists($k, $in)) $put($k, mb_substr(trim((string)$in[$k]), 0, 300), "$l: " . trim((string)$in[$k]));
    if (array_key_exists('phone', $in)) { $p = trim((string)$in['phone']); $put('phone', $p, 'SĐT: ' . $p); $put('phone_norm', crm_phone_norm($p), ''); }
    if (array_key_exists('care_status', $in)) { if (!isset(CRM_ST[$in['care_status']])) fail('Trạng thái không hợp lệ'); $put('care_status', $in['care_status'], 'Trạng thái: ' . CRM_ST[$in['care_status']]);
        if ($in['care_status'] !== 'new' && !$r['last_contact_at']) { $set[] = 'last_contact_at=?'; $vals[] = crm_now(); } }
    if (array_key_exists('need_level', $in)) { $n = (string)$in['need_level']; if ($n !== '' && !isset(CRM_NEED[$n])) fail('Mức nhu cầu không hợp lệ'); $put('need_level', $n, 'Nhu cầu: ' . (CRM_NEED[$n] ?? 'bỏ chọn')); }
    if (array_key_exists('branch_id', $in)) { $b = (string)$in['branch_id']; $brs = array_column(crm_branches($PID), 'name', 'id'); if ($b !== '' && !isset($brs[$b])) fail('Cơ sở không thuộc dự án');
        $put('branch_id', $b, $b ? 'Cơ sở: ' . $brs[$b] : 'Bỏ cơ sở');
        if ($b && $b !== (string)$r['branch_id'] && !$r['assignee_id'] && !array_key_exists('assignee_id', $in) && ($rr = crm_round_robin($b))) $in['assignee_id'] = $rr; }
    if (array_key_exists('assignee_id', $in)) { $aid = (string)$in['assignee_id']; $staff = array_column(crm_staff($PID), 'name', 'id'); if ($aid !== '' && !isset($staff[$aid])) fail('Nhân viên không thuộc dự án');
        if ($aid !== (string)$r['assignee_id']) { $put('assignee_id', $aid, 'Giao cho: ' . ($staff[$aid] ?? 'bỏ giao')); $set[] = 'assigned_at=?'; $vals[] = $aid ? crm_now() : null; if ($aid) { $set[] = 'recycled_at=NULL'; }
            if ($aid && $aid !== $u['id']) notify($aid, 'Bạn được giao chăm sóc ' . ($r['name'] ?: $r['phone']), (string)$r['phone'], '#inbox/crm', $PID); } }
    if (array_key_exists('next_follow_at', $in)) { $nf = str_replace('T', ' ', trim((string)$in['next_follow_at'])); if ($nf !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $nf)) fail('Ngày giờ hẹn không hợp lệ'); $put('next_follow_at', $nf, $nf ? 'Hẹn gọi lại: ' . $nf : 'Bỏ hẹn'); }
    if (array_key_exists('custom', $in)) { $c = $customIn($in['custom']); $j = json_encode((object)$c, JSON_UNESCAPED_UNICODE); if ($j !== ($r['custom'] ?? '{}')) { $set[] = 'custom=?'; $vals[] = $j; $log[] = 'Cập nhật thông tin bổ sung'; } }
    $note = trim((string)($in['note'] ?? '')); if ($note !== '') { $log[] = mb_substr($note, 0, 2000); $set[] = 'last_contact_at=?'; $vals[] = crm_now(); }
    if (!$set) respond(['ok' => true, 'unchanged' => true]);
    $set[] = 'care_log=?'; $vals[] = crm_log_add($r, implode(' · ', array_filter($log)), $u, $in['care_status'] ?? null); $set[] = "updated_at=datetime('now')"; $vals[] = $r['id'];
    $pdo->prepare('UPDATE care_records SET ' . implode(', ', $set) . ' WHERE id=?')->execute($vals);
    respond(['ok' => true]);
}
if ($action === 'import') {
    $rows = array_slice((array)($in['rows'] ?? []), 0, 5000); $n = ['new' => 0, 'dup' => 0, 'skip' => 0];
    $pdo->beginTransaction();
    foreach ($rows as $x) {
        $x = (array)$x; if (trim((string)($x['name'] ?? '')) === '' && crm_phone_norm($x['phone'] ?? '') === '') { $n['skip']++; continue; }
        $need = array_search(mb_strtolower(trim((string)($x['need'] ?? '')), 'UTF-8'), array_map(fn($s) => mb_strtolower($s, 'UTF-8'), CRM_NEED), true);
        $r = crm_create($PID, ['src' => 'excel', 'name' => $x['name'] ?? '', 'phone' => $x['phone'] ?? '', 'email' => $x['email'] ?? '', 'company' => $x['company'] ?? '', 'address' => $x['address'] ?? '', 'note' => $x['note'] ?? '', 'need_level' => $need ?: '', 'custom' => $customIn($x['custom'] ?? [])], $u);
        $n[isset($r['dup']) ? 'dup' : 'new']++;
    }
    $pdo->commit();
    log_activity('import', 'care', null, "Nhập Excel CRM: {$n['new']} khách mới, {$n['dup']} trùng SĐT", $u, $PID);
    respond(['ok' => true] + $n);
}
if ($action === 'sync_chat') {
    // Hội thoại của dự án có SĐT (từ đầu mối hoặc tin khách) → tạo / liên kết khách CRM
    $st = $pdo->prepare("SELECT t.*, l.phone lead_phone FROM inbox_threads t LEFT JOIN leads l ON l.id=t.lead_id WHERE t.project_id=? AND COALESCE(t.kind,'message')='message'"); $st->execute([$PID]);
    $n = ['new' => 0, 'linked' => 0];
    foreach ($st->fetchAll() as $t) {
        $phone = (string)($t['lead_phone'] ?? '');
        if ($phone === '') { $m = $pdo->prepare("SELECT text FROM inbox_messages WHERE thread_id=? AND direction='in' ORDER BY sent_at DESC LIMIT 50"); $m->execute([$t['id']]);
            foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $tx) if (preg_match('/(?<!\d)(?:\+?84|0)(?:[\s.\-]?\d){9}(?!\d)/', (string)$tx, $mm)) { $phone = $mm[0]; break; } }
        if (crm_phone_norm($phone) === '') continue;
        $ex = $pdo->prepare('SELECT id, thread_id FROM care_records WHERE project_id=? AND (thread_id=? OR phone_norm=?) LIMIT 1'); $ex->execute([$PID, $t['id'], crm_phone_norm($phone)]); $old = $ex->fetch();
        if ($old) { if (!$old['thread_id']) { $pdo->prepare('UPDATE care_records SET thread_id=? WHERE id=?')->execute([$t['id'], $old['id']]); $n['linked']++; } continue; }
        crm_create($PID, ['src' => 'chat', 'name' => $t['customer_name'], 'phone' => $phone, 'thread_id' => $t['id'], 'assignee_id' => $t['assigned_to'] ?? ''], $u); $n['new']++;
    }
    respond(['ok' => true] + $n);
}
if ($action === 'deal_save') {
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 160); if ($title === '') fail('Nhập tên cơ hội');
    $stage = isset(CRM_STAGES[$in['stage'] ?? '']) ? $in['stage'] : 'new'; $care = (string)($in['care_id'] ?? ''); if ($care !== '') $getRec($care);
    $vals = [$title, $care ?: null, max(0, (float)($in['value'] ?? 0)), $stage, ($in['assignee_id'] ?? '') ?: null, mb_substr(trim((string)($in['note'] ?? '')), 0, 1000)];
    if (!empty($in['id'])) $pdo->prepare("UPDATE crm_deals SET title=?, care_id=?, value=?, stage=?, assignee_id=?, note=?, updated_at=datetime('now') WHERE id=? AND project_id=?")->execute(array_merge($vals, [(string)$in['id'], $PID]));
    else $pdo->prepare('INSERT INTO crm_deals(title,care_id,value,stage,assignee_id,note,id,project_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [gen_id('DL'), $PID, $u['id']]));
    respond(['ok' => true]);
}
if ($action === 'deal_move') {
    if (!isset(CRM_STAGES[$in['stage'] ?? ''])) fail('Giai đoạn không hợp lệ');
    $pdo->prepare("UPDATE crm_deals SET stage=?, updated_at=datetime('now') WHERE id=? AND project_id=?")->execute([$in['stage'], (string)($in['id'] ?? ''), $PID]);
    respond(['ok' => true]);
}
if ($action === 'deal_del') { $pdo->prepare('DELETE FROM crm_deals WHERE id=? AND project_id=?')->execute([(string)($in['id'] ?? ''), $PID]); respond(['ok' => true]); }

if (in_array($action, ['config_save', 'branch_save', 'branch_del'], true) && !$isAdmin) fail('Chỉ chủ / quản lý dự án được đổi cấu hình CRM', 403);
if ($action === 'config_save') {
    $cols = [];
    foreach ((array)($in['cols'] ?? []) as $c) { $c = (array)$c; $l = mb_substr(trim((string)($c['label'] ?? '')), 0, 60); if ($l === '') continue;
        $type = in_array($c['type'] ?? '', ['text', 'select', 'number', 'date'], true) ? $c['type'] : 'text';
        $cols[] = ['key' => preg_match('/^c_[a-z0-9]{4,12}$/', (string)($c['key'] ?? '')) ? $c['key'] : 'c_' . bin2hex(random_bytes(4)), 'label' => $l, 'type' => $type,
            'options' => $type === 'select' ? array_values(array_filter(array_map(fn($o) => mb_substr(trim((string)$o), 0, 60), (array)($c['options'] ?? [])), fn($o) => $o !== '')) : []]; }
    setting_set('crm.cfg.' . $PID, json_encode(['sla_min' => max(0, min(10080, (int)($in['sla_min'] ?? 0))), 'staff_export' => !empty($in['staff_export']), 'cols' => $cols], JSON_UNESCAPED_UNICODE), $u);
    respond(['ok' => true]);
}
if ($action === 'branch_save') {
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))); if ($name === '' || mb_strlen($name) > 80) fail('Tên cơ sở 1–80 ký tự');
    $ids = array_column(crm_staff($PID), 'id'); $staff = array_values(array_intersect(array_map('strval', (array)($in['staff'] ?? [])), $ids)); $kw = mb_substr(trim((string)($in['keywords'] ?? '')), 0, 500);
    if (!empty($in['id'])) $pdo->prepare('UPDATE branches SET name=?, staff=?, keywords=? WHERE id=? AND project_id=?')->execute([$name, json_encode($staff), $kw, (string)$in['id'], $PID]);
    else { $dup = $pdo->prepare('SELECT 1 FROM branches WHERE project_id=? AND lower(name)=lower(?)'); $dup->execute([$PID, $name]); if ($dup->fetchColumn()) fail('Đã có cơ sở cùng tên');
        $pdo->prepare('INSERT INTO branches(id,project_id,name,created_by,staff,keywords) VALUES (?,?,?,?,?,?)')->execute(['BR' . strtoupper(bin2hex(random_bytes(5))), $PID, $name, $u['id'], json_encode($staff), $kw]); }
    respond(['ok' => true, 'branches' => crm_branches($PID)]);
}
if ($action === 'branch_del') {
    $id = (string)($in['id'] ?? '');
    $pdo->prepare('UPDATE care_records SET branch_id=NULL WHERE branch_id=? AND project_id=?')->execute([$id, $PID]);
    $pdo->prepare('DELETE FROM branches WHERE id=? AND project_id=?')->execute([$id, $PID]);
    respond(['ok' => true, 'branches' => crm_branches($PID)]);
}
fail('Hành động không hợp lệ', 404);
