<?php
/* CSKH — khách từ website (liên hệ + đơn hàng chungauto.vn) của dự án hiện hành. 16/10/2026
   GET  ?q&kind&care&web&assignee&due=today|overdue|none&from&to&sort&page&per   → {rows, total, page, per, counts, kinds, web_statuses, staff, stats, last_sync}
   GET  ?id=…                    → {record, history:[cùng SĐT]}
   GET  ?export=1&(bộ lọc như trên) → CSV toàn bộ kết quả lọc
   GET  ?report=1&from=Y-m-d&to=Y-m-d → báo cáo CSKH: chỉ số + so kỳ trước, theo ngày, theo chi nhánh, theo nhân viên, phễu trạng thái
   POST {action:'update', id, care_status?, assignee_id?, branch_id?, next_follow_at?, note?}   → ghi nhật ký chăm sóc
   POST {action:'branch_create', name} → tạo nhanh chi nhánh của dự án → {branch, branches}
   POST {action:'sync', full?}   → kéo dữ liệu mới từ chungauto.vn (chủ / quản lý dự án) */
require __DIR__ . '/_boot.php';
$me = require_login();
$PID = pid();

const CARE_ST = ['new' => 'Chưa chăm sóc', 'contacting' => 'Đang liên hệ', 'callback' => 'Hẹn gọi lại', 'consulted' => 'Đã tư vấn', 'transferred' => 'Chuyển chi nhánh / cơ sở', 'won' => 'Đã chốt / đã mua', 'lost' => 'Không nhu cầu / huỷ', 'invalid' => 'Sai số / trùng'];

function care_staff(string $pid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=?)) ORDER BY u.name");
    $st->execute([$pid]); return $st->fetchAll();
}
function care_branches(string $pid): array {
    global $pdo;
    $st = $pdo->prepare('SELECT id, name FROM branches WHERE project_id=? ORDER BY name'); $st->execute([$pid]); return $st->fetchAll();
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
        $w[] = '(name LIKE ? OR address LIKE ? OR message LIKE ? OR items LIKE ? OR email LIKE ? OR interest LIKE ? OR ext_id=?' . (strlen($digits) >= 3 ? ' OR phone_norm LIKE ?' : '') . ')';
        array_push($a, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", $q); if (strlen($digits) >= 3) $a[] = "%$digits%";
    }
    if (in_array($g['kind'] ?? '', ['contact', 'order'], true)) { $w[] = 'kind=?'; $a[] = $g['kind']; }
    if (isset(CARE_ST[$g['care'] ?? ''])) { $w[] = 'care_status=?'; $a[] = $g['care']; }
    if (($g['web'] ?? '') !== '') { $w[] = 'web_status=?'; $a[] = (string)$g['web']; }
    if (($g['branch'] ?? '') === '_none') $w[] = "COALESCE(branch_id,'')=''";
    elseif (($g['branch'] ?? '') !== '') { $w[] = 'branch_id=?'; $a[] = (string)$g['branch']; }
    if (($g['assignee'] ?? '') === '_none') $w[] = "COALESCE(assignee_id,'')=''";
    elseif (($g['assignee'] ?? '') !== '') { $w[] = 'assignee_id=?'; $a[] = (string)$g['assignee']; }
    $now = date('Y-m-d H:i');
    if (($g['due'] ?? '') === 'today') { $w[] = "next_follow_at IS NOT NULL AND substr(next_follow_at,1,10)<=?"; $a[] = date('Y-m-d'); $w[] = "care_status NOT IN ('won','lost','invalid')"; }
    if (($g['due'] ?? '') === 'overdue') { $w[] = "next_follow_at IS NOT NULL AND next_follow_at<?"; $a[] = $now; $w[] = "care_status NOT IN ('won','lost','invalid')"; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['from'] ?? ''))) { $w[] = 'ext_created_at>=?'; $a[] = $g['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['to'] ?? ''))) { $w[] = 'ext_created_at<=?'; $a[] = $g['to'] . ' 23:59:59'; }
    return [implode(' AND ', $w), $a];
}

/** Báo cáo CSKH trong khoảng [from, to] (ngày VN). Khách mới tính theo ngày tạo trên web; hoạt động (chăm sóc, chuyển
 *  chi nhánh, chốt) tính theo thời điểm ghi nhật ký. Trả kèm số kỳ trước cùng độ dài để so sánh. */
function care_report(string $pid, string $from, string $to): array {
    global $pdo;
    $d1 = new DateTimeImmutable($from); $d2 = new DateTimeImmutable($to); $days = (int)$d1->diff($d2)->days + 1;
    $p2 = $d1->modify('-1 day'); $p1 = $p2->modify('-' . ($days - 1) . ' day');
    $in = fn(?string $t, DateTimeImmutable $a, DateTimeImmutable $b) => $t !== null && $t !== '' && substr($t, 0, 10) >= $a->format('Y-m-d') && substr($t, 0, 10) <= $b->format('Y-m-d');
    $branches = array_column(care_branches($pid), 'name', 'id'); $bByName = array_flip($branches);
    $staff = array_column(care_staff($pid), 'name', 'id');
    $st = $pdo->prepare('SELECT id, kind, ext_id, name, phone, web_status, care_status, total, branch_id, assignee_id, next_follow_at, ext_created_at, care_log FROM care_records WHERE project_id=?');
    $st->execute([$pid]);
    $now = date('Y-m-d H:i'); $won = fn($r) => $r['care_status'] === 'won';
    $sum = ['cur' => [], 'prev' => []];
    foreach (['cur', 'prev'] as $k) $sum[$k] = ['new' => 0, 'contacts' => 0, 'orders' => 0, 'assigned' => 0, 'unassigned' => 0, 'cared' => 0, 'actions' => 0, 'transfers' => 0, 'won' => 0, 'revenue' => 0.0, 'order_value' => 0.0];
    $series = []; for ($d = $d1; $d <= $d2; $d = $d->modify('+1 day')) $series[$d->format('Y-m-d')] = ['d' => $d->format('Y-m-d'), 'contacts' => 0, 'orders' => 0, 'transfers' => 0, 'actions' => 0, 'won' => 0];
    $statusKeys = array_keys(CARE_ST);
    $br = []; $mkB = function ($id, $name) use ($statusKeys) { return ['id' => $id, 'name' => $name, 'new' => 0, 'contacts' => 0, 'orders' => 0, 'transfers' => 0, 'status' => array_fill_keys($statusKeys, 0), 'won' => 0, 'revenue' => 0.0, 'overdue' => 0, 'total_now' => 0]; };
    foreach ($branches as $id => $n) $br[$id] = $mkB($id, $n); $br['_none'] = $mkB('_none', 'Chưa phân chi nhánh');
    $sf = []; $mkS = fn($id, $n) => ['id' => $id, 'name' => $n, 'assigned_new' => 0, 'actions' => 0, 'cared' => 0, 'transfers' => 0, 'won' => 0, 'revenue' => 0.0, 'callback' => 0, 'overdue' => 0];
    foreach ($staff as $id => $n) $sf[$id] = $mkS($id, $n);
    $funnel = array_fill_keys($statusKeys, 0);
    foreach ($st as $r) {
        $bk = ($r['branch_id'] && isset($br[$r['branch_id']])) ? $r['branch_id'] : '_none';
        $isOver = $r['next_follow_at'] && $r['next_follow_at'] < $now && !in_array($r['care_status'], ['won', 'lost', 'invalid'], true);
        $br[$bk]['total_now']++; if ($isOver) { $br[$bk]['overdue']++; if ($r['assignee_id'] && isset($sf[$r['assignee_id']])) $sf[$r['assignee_id']]['overdue']++; }
        if ($r['assignee_id'] && isset($sf[$r['assignee_id']]) && $r['care_status'] === 'callback') $sf[$r['assignee_id']]['callback']++;
        foreach (['cur' => [$d1, $d2], 'prev' => [$p1, $p2]] as $k => [$a, $b]) {
            if ($in($r['ext_created_at'], $a, $b)) {
                $S = &$sum[$k]; $S['new']++; $S[$r['kind'] === 'order' ? 'orders' : 'contacts']++; $S[$bk === '_none' ? 'unassigned' : 'assigned']++;
                if ($r['kind'] === 'order') $S['order_value'] += (float)$r['total'];
                if ($won($r)) { $S['won']++; if ($r['kind'] === 'order') $S['revenue'] += (float)$r['total']; }
                unset($S);
                if ($k === 'cur') {
                    $day = substr($r['ext_created_at'], 0, 10); if (isset($series[$day])) $series[$day][$r['kind'] === 'order' ? 'orders' : 'contacts']++;
                    $B = &$br[$bk]; $B['new']++; $B[$r['kind'] === 'order' ? 'orders' : 'contacts']++; $B['status'][$r['care_status']] = ($B['status'][$r['care_status']] ?? 0) + 1;
                    if ($won($r)) { $B['won']++; if ($r['kind'] === 'order') $B['revenue'] += (float)$r['total']; } unset($B);
                    $funnel[$r['care_status']] = ($funnel[$r['care_status']] ?? 0) + 1;
                    if ($r['assignee_id'] && isset($sf[$r['assignee_id']])) $sf[$r['assignee_id']]['assigned_new']++;
                }
            }
        }
        // nhật ký chăm sóc: hoạt động trong kỳ
        $caredIn = ['cur' => false, 'prev' => false];
        foreach (jdecode($r['care_log'] ?? null) as $lg) {
            foreach (['cur' => [$d1, $d2], 'prev' => [$p1, $p2]] as $k => [$a, $b]) {
                if (!$in($lg['at'] ?? null, $a, $b)) continue;
                $txt = (string)($lg['text'] ?? ''); $sum[$k]['actions']++; $caredIn[$k] = true;
                $isTr = preg_match('/Chuyển chi nhánh: ([^·]+)/u', $txt, $mm); $isWon = str_contains($txt, 'Trạng thái: ' . CARE_ST['won']);
                if ($isTr) $sum[$k]['transfers']++;
                if ($k !== 'cur') continue;
                $day = substr((string)$lg['at'], 0, 10); if (isset($series[$day])) { $series[$day]['actions']++; if ($isTr) $series[$day]['transfers']++; if ($isWon) $series[$day]['won']++; }
                if ($isTr) { $bid = $bByName[trim($mm[1])] ?? null; if ($bid) $br[$bid]['transfers']++; }
                $u = (string)($lg['by'] ?? '');
                if ($u !== '') { if (!isset($sf[$u])) $sf[$u] = $mkS($u, (string)($lg['name'] ?? $u)); $sf[$u]['actions']++; if ($isTr) $sf[$u]['transfers']++; if ($isWon) { $sf[$u]['won']++; if ($r['kind'] === 'order') $sf[$u]['revenue'] += (float)$r['total']; } }
            }
        }
        foreach ($caredIn as $k => $v) if ($v) $sum[$k]['cared']++;
        if ($caredIn['cur']) foreach (array_unique(array_column(array_filter(jdecode($r['care_log'] ?? null), fn($lg) => $in($lg['at'] ?? null, $d1, $d2)), 'by')) as $u) if (isset($sf[$u])) $sf[$u]['cared']++;
    }
    $br = array_values(array_filter($br, fn($b) => $b['new'] || $b['transfers'] || $b['total_now'] || $b['id'] !== '_none'));
    usort($br, fn($a, $b) => ($b['id'] !== '_none') <=> ($a['id'] !== '_none') ?: $b['new'] <=> $a['new']);
    $sf = array_values(array_filter($sf, fn($x) => $x['assigned_new'] || $x['actions'] || $x['callback'] || $x['overdue']));
    usort($sf, fn($a, $b) => $b['actions'] <=> $a['actions']);
    return ['range' => ['from' => $d1->format('Y-m-d'), 'to' => $d2->format('Y-m-d'), 'days' => $days, 'prev_from' => $p1->format('Y-m-d'), 'prev_to' => $p2->format('Y-m-d')],
        'summary' => $sum, 'series' => array_values($series), 'branches' => $br, 'staff' => $sf, 'funnel' => $funnel, 'care_statuses' => CARE_ST, 'generated_at' => date('Y-m-d H:i')];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['report'])) {
        $re = '/^\d{4}-\d{2}-\d{2}$/'; $from = (string)($_GET['from'] ?? date('Y-m-d')); $to = (string)($_GET['to'] ?? $from);
        if (!preg_match($re, $from) || !preg_match($re, $to) || $from > $to) fail('Khoảng ngày không hợp lệ');
        if ((new DateTime($from))->diff(new DateTime($to))->days > 731) fail('Khoảng ngày tối đa 2 năm');
        respond(care_report($PID, $from, $to));
    }
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM care_records WHERE id=? AND project_id=?'); $st->execute([(string)$_GET['id'], $PID]);
        $r = $st->fetch(); if (!$r) fail('Không tìm thấy khách', 404);
        $hist = [];
        if ($r['phone_norm'] !== '' && $r['phone_norm'] !== null) {
            $h = $pdo->prepare('SELECT id, kind, ext_id, name, web_status, care_status, total, ext_created_at, items, message FROM care_records WHERE project_id=? AND phone_norm=? AND id<>? ORDER BY ext_created_at DESC LIMIT 50');
            $h->execute([$PID, $r['phone_norm'], $r['id']]); $hist = array_map('care_row', $h->fetchAll());
        }
        respond(['record' => care_row($r), 'history' => $hist, 'staff' => care_staff($PID), 'branches' => care_branches($PID), 'care_statuses' => CARE_ST]);
    }
    [$w, $a] = care_where($_GET, $PID);
    $sorts = ['new' => 'ext_created_at DESC', 'old' => 'ext_created_at ASC', 'follow' => "CASE WHEN next_follow_at IS NULL THEN 1 ELSE 0 END, next_follow_at ASC", 'value' => 'COALESCE(total,0) DESC', 'updated' => 'updated_at DESC'];
    $order = $sorts[$_GET['sort'] ?? 'new'] ?? $sorts['new'];
    if (!empty($_GET['export'])) {
        $st = $pdo->prepare("SELECT * FROM care_records WHERE $w ORDER BY $order"); $st->execute($a);
        $names = array_column(care_staff($PID), 'name', 'id'); $brs = array_column(care_branches($PID), 'name', 'id');
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="CSKH_chungauto_' . date('Ymd_Hi') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Loại', 'ID web', 'Ngày tạo', 'Tên', 'SĐT', 'Email', 'Địa chỉ', 'Nội dung / ghi chú khách', 'Sản phẩm / bài viết quan tâm', 'Link sản phẩm quan tâm', 'Sản phẩm trong đơn', 'Tạm tính', 'Giảm giá', 'Tổng tiền', 'Mã giảm giá', 'Trạng thái web', 'Trạng thái CSKH', 'Phụ trách', 'Chi nhánh', 'Hẹn gọi lại', 'Liên hệ gần nhất', 'Ghi chú CSKH gần nhất']);
        foreach ($st as $r) {
            $items = implode('; ', array_map(fn($i) => $i['name'] . ' x' . $i['qty'], jdecode($r['items'] ?? null)));
            $log = jdecode($r['care_log'] ?? null); $lastNote = $log ? end($log)['text'] ?? '' : '';
            fputcsv($o, [$r['kind'] === 'order' ? 'Đơn hàng' : 'Liên hệ', $r['ext_id'], $r['ext_created_at'], $r['name'], $r['phone'], $r['email'], $r['address'], $r['message'], (string)($r['interest'] ?? ''), (string)($r['interest_url'] ?? ''), $items,
                $r['subtotal'], $r['discount'], $r['total'], $r['coupon'], $r['web_status'], CARE_ST[$r['care_status']] ?? $r['care_status'], $names[$r['assignee_id']] ?? '', $brs[$r['branch_id']] ?? '', $r['next_follow_at'], $r['last_contact_at'], $lastNote]);
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
        'stats' => array_map(fn($v) => (float)$v, $stats ?: []), 'staff' => care_staff($PID), 'branches' => care_branches($PID), 'care_statuses' => CARE_ST,
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
    if (array_key_exists('branch_id', $in)) {
        $bid = (string)($in['branch_id'] ?? '');
        $brs = array_column(care_branches($PID), 'name', 'id');
        if ($bid !== '' && !isset($brs[$bid])) fail('Chi nhánh không thuộc dự án');
        if ($bid !== (string)$r['branch_id']) { $set[] = 'branch_id=?'; $vals[] = $bid ?: null; $logText[] = $bid ? 'Chuyển chi nhánh: ' . $brs[$bid] : 'Bỏ chi nhánh'; }
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

if ($action === 'branch_create') {
    require_project_write();
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 80) fail('Tên chi nhánh 1–80 ký tự');
    $st = $pdo->prepare('SELECT id, name FROM branches WHERE project_id=? AND lower(name)=lower(?)'); $st->execute([$PID, $name]);
    $b = $st->fetch();
    if (!$b) {
        $b = ['id' => 'BR' . strtoupper(bin2hex(random_bytes(5))), 'name' => $name];
        $pdo->prepare('INSERT INTO branches(id,project_id,name,created_by) VALUES (?,?,?,?)')->execute([$b['id'], $PID, $name, $me['id']]);
        log_activity('create', 'branch', $b['id'], 'Tạo chi nhánh ' . $name, $me, $PID);
    }
    respond(['ok' => true, 'branch' => $b, 'branches' => care_branches($PID)]);
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
