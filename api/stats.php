<?php
/* Thống kê / báo cáo — số liệu THẬT tổng hợp từ CSDL, không bịa số. Dữ liệu trống → mảng rỗng.
   GET ?range=30|90|180|365 [&co=…&br=…]     → tổng hợp chiến dịch, đầu mối, nội dung, công việc, kênh
   GET ?history=<conn_id>&range=…             → toàn bộ lịch sử số liệu của một kênh (bảng + biểu đồ)

   Cách tính (xấp xỉ, ghi rõ để báo cáo trung thực):
   - Chiến dịch: bảng campaigns chỉ lưu số LŨY KẾ (spent, leads). "totals"/"list" = số lũy kế của các chiến dịch
     có thời gian chạy giao với kỳ báo cáo. "by_month" = chia đều spent/leads của từng chiến dịch theo số ngày
     chạy (start_date → min(end_date, hôm nay)), chỉ giữ phần rơi vào kỳ → tổng by_month có thể nhỏ hơn totals.
     Chiến dịch không có ngày bắt đầu dùng ngày tạo.
   - ROAS = bình quân gia quyền theo chi tiêu; CPL = chi tiêu / lead.
   - Đầu mối: theo leads.created_at trong kỳ (nguồn, trạng thái, tháng).
   - Nội dung đã xuất bản theo tuần: content_items.state='PUBLISHED' lấy updated_at làm ngày xuất bản
     (chưa có bảng lịch sử chuyển trạng thái nên đây là xấp xỉ — sửa nội dung sau khi đăng sẽ dời ngày).
   - Công việc hoàn thành theo tuần: tasks ở cột cuối của bảng (DONE_COLS), updated_at làm ngày hoàn thành (xấp xỉ).
   - Kênh: metrics_history (1 bản/kênh/ngày). Chỉ số chính: followers (Fanpage), subscribers (YouTube),
     sessions (GA4, cửa sổ 30 ngày tại ngày đồng bộ), spend (Quảng cáo, cửa sổ 30 ngày tại ngày đồng bộ).
     Kênh không gắn công ty/chi nhánh nên bộ lọc co/br không áp dụng cho phần kênh. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_metrics_lib.php';
require_login();
require_method('GET');

$days = (int)($_GET['range'] ?? 90);
if (!in_array($days, [30, 90, 180, 365], true)) $days = 90;
$today = metrics_today();
$since = date('Y-m-d', strtotime("$today -" . ($days - 1) . ' days'));

/* ---------- Lịch sử một kênh ---------- */
if (isset($_GET['history'])) {
    $conn = get_connection((string)$_GET['history']);
    if (!$conn) fail('Không tìm thấy kênh trong dự án này', 404);
    $st = $pdo->prepare('SELECT day, metrics FROM metrics_history WHERE connection_id=? AND day>=? ORDER BY day');
    $st->execute([$conn['id'], $since]);
    $def = METRIC_KEYS[$conn['provider']] ?? null;
    $rows = array_map(function ($r) use ($conn) {
        $m = jdecode($r['metrics']);
        return ['day' => $r['day'], 'items' => $m['items'] ?? [], 'key_value' => metric_key_value($conn['provider'], $m)];
    }, $st->fetchAll());
    respond(['connection' => ['id' => $conn['id'], 'provider' => $conn['provider'], 'label' => $conn['label']],
        'key' => $def['key'] ?? null, 'key_label' => $def['label'] ?? null, 'range' => ['days' => $days, 'since' => $since, 'until' => $today],
        'rows' => $rows]);
}

/* ---------- Bộ lọc công ty / chi nhánh (giống inScope() phía giao diện: bản ghi không gắn co/br vẫn tính) ---------- */
$co = trim((string)($_GET['co'] ?? '')); if ($co === 'all') $co = '';
$br = trim((string)($_GET['br'] ?? '')); if ($br === 'all') $br = '';
// Phạm vi DỰ ÁN là bắt buộc (11/10/2026); co/br chỉ là bộ lọc phụ bên trong dự án.
$scopeSql = ' AND project_id=?'; $scopeArgs = [pid()];
if ($co !== '') { $scopeSql .= " AND (co IS NULL OR co='' OR co=?)"; $scopeArgs[] = $co; }
if ($br !== '') { $scopeSql .= " AND (br IS NULL OR br='' OR br=?)"; $scopeArgs[] = $br; }

function q(string $sql, array $args = []): array {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Điền đủ các mốc (tháng/tuần) trong kỳ bằng 0 để vẽ biểu đồ; nếu toàn 0 thì trả mảng rỗng. */
function fill_series(array $keys, array $vals, string $name, array $fields): array {
    $out = []; $any = false;
    foreach ($keys as $k) {
        $row = [$name => $k];
        foreach ($fields as $f) { $v = $vals[$k][$f] ?? 0; $row[$f] = is_float($v) ? round($v, 2) : $v; if ($v) $any = true; }
        $out[] = $row;
    }
    return $any ? $out : [];
}

$months = [];
for ($d = new DateTime(substr($since, 0, 7) . '-01'); $d->format('Y-m') <= substr($today, 0, 7); $d->modify('+1 month')) $months[] = $d->format('Y-m');
$weeks = [];
$w = new DateTime($since); $w->modify('-' . (((int)$w->format('N')) - 1) . ' days'); // thứ Hai đầu tuần chứa $since
for (; $w->format('Y-m-d') <= $today; $w->modify('+7 days')) $weeks[] = $w->format('Y-m-d');
const WEEK_SQL = "date(substr(%s,1,10),'-6 days','weekday 1')"; // thứ Hai của tuần chứa ngày đó

/* ---------- Chiến dịch ---------- */
$camps = q("SELECT id,name,state,co,br,budget,spent,leads,roas,start_date,end_date,substr(created_at,1,10) created
    FROM campaigns WHERE state<>'CANCELLED' $scopeSql", $scopeArgs);
$inRange = []; $byMonth = [];
foreach ($camps as $c) {
    $s = $c['start_date'] ?: $c['created'];
    $e = $c['end_date'] ?: $today;
    if ($s > $today || $e < $since) continue; // không chạy trong kỳ
    $c['budget'] = (int)$c['budget']; $c['spent'] = (int)$c['spent']; $c['leads'] = (int)$c['leads']; $c['roas'] = (float)$c['roas'];
    $c['cpl'] = $c['leads'] ? (int)round($c['spent'] / $c['leads']) : 0;
    $inRange[] = $c;
    if (!$c['spent'] && !$c['leads']) continue;
    // Chia đều số lũy kế theo ngày đã chạy (start → min(end, hôm nay)).
    $runEnd = min($e, $today); if ($runEnd < $s) $runEnd = $s;
    $total = (int)((strtotime($runEnd) - strtotime($s)) / 86400) + 1;
    for ($t = strtotime(max($s, $since)), $end = strtotime($runEnd); $t <= $end; $t += 86400) {
        $m = date('Y-m', $t);
        $byMonth[$m]['spent'] = ($byMonth[$m]['spent'] ?? 0) + $c['spent'] / $total;
        $byMonth[$m]['leads'] = ($byMonth[$m]['leads'] ?? 0) + $c['leads'] / $total;
    }
}
foreach ($byMonth as &$bm) { $bm['spent'] = (int)round($bm['spent']); $bm['leads'] = (int)round($bm['leads']); } unset($bm);

$agg = function (array $rows): array {
    $sp = array_sum(array_column($rows, 'spent')); $ld = array_sum(array_column($rows, 'leads'));
    $w = 0.0; foreach ($rows as $r) $w += $r['spent'] * $r['roas'];
    return ['count' => count($rows), 'active' => count(array_filter($rows, fn($r) => $r['state'] === 'ACTIVE')),
        'budget' => array_sum(array_column($rows, 'budget')), 'spent' => $sp, 'leads' => $ld,
        'roas' => $sp ? round($w / $sp, 2) : 0, 'cpl' => $ld ? (int)round($sp / $ld) : 0];
};
$byCo = [];
foreach ($inRange as $c) $byCo[$c['co'] ?: ''][] = $c;
$byCompany = [];
foreach ($byCo as $k => $rows) $byCompany[] = ['co' => $k] + $agg($rows);
usort($byCompany, fn($a, $b) => $b['spent'] <=> $a['spent']);
$pick = fn(array $c) => array_intersect_key($c, array_flip(['id', 'name', 'state', 'co', 'br', 'budget', 'spent', 'leads', 'roas', 'cpl', 'start_date', 'end_date']));
$list = array_map($pick, $inRange);
usort($list, fn($a, $b) => $b['spent'] <=> $a['spent']);
$topRoas = array_values(array_filter($list, fn($c) => $c['roas'] > 0 && $c['spent'] > 0));
usort($topRoas, fn($a, $b) => $b['roas'] <=> $a['roas']);
$topLeads = array_values(array_filter($list, fn($c) => $c['leads'] > 0));
usort($topLeads, fn($a, $b) => $b['leads'] <=> $a['leads']);

/* ---------- Đầu mối ---------- */
$leadRows = q("SELECT source,state,substr(created_at,1,7) m FROM leads WHERE substr(created_at,1,10)>=? $scopeSql", array_merge([$since], $scopeArgs));
$bySource = []; $byState = []; $leadMonth = [];
foreach ($leadRows as $l) {
    $src = $l['source'] ?: 'Không rõ';
    $bySource[$src]['count'] = ($bySource[$src]['count'] ?? 0) + 1;
    $bySource[$src]['accepted'] = ($bySource[$src]['accepted'] ?? 0) + ($l['state'] === 'ACCEPTED' ? 1 : 0);
    $byState[$l['state']] = ($byState[$l['state']] ?? 0) + 1;
    $leadMonth[$l['m']]['count'] = ($leadMonth[$l['m']]['count'] ?? 0) + 1;
}
$srcOut = []; foreach ($bySource as $k => $v) $srcOut[] = ['source' => $k] + $v;
usort($srcOut, fn($a, $b) => $b['count'] <=> $a['count']);
$stateOut = []; foreach ($byState as $k => $v) $stateOut[] = ['state' => $k, 'count' => $v];
// Phễu: mỗi bước gồm các lead đã đi tới bước đó trở đi (REJECTED = đã bàn giao nhưng CRM từ chối).
$reach = fn(array $states) => array_sum(array_map(fn($s) => $byState[$s] ?? 0, $states));
$funnel = count($leadRows) ? [
    ['stage' => 'CREATED', 'label' => 'Đầu mối mới', 'count' => count($leadRows)],
    ['stage' => 'VALIDATING', 'label' => 'Đã kiểm tra', 'count' => $reach(['VALIDATING', 'ROUTED', 'DELIVERED', 'ACCEPTED', 'REJECTED'])],
    ['stage' => 'ROUTED', 'label' => 'Đã định tuyến', 'count' => $reach(['ROUTED', 'DELIVERED', 'ACCEPTED', 'REJECTED'])],
    ['stage' => 'DELIVERED', 'label' => 'Bàn giao CRM', 'count' => $reach(['DELIVERED', 'ACCEPTED', 'REJECTED'])],
    ['stage' => 'ACCEPTED', 'label' => 'CRM đã nhận', 'count' => $reach(['ACCEPTED'])],
] : [];

/* ---------- Nội dung ---------- */
$wk = fn(string $col) => sprintf(WEEK_SQL, $col);
$pubWeek = []; foreach (q("SELECT {$wk('updated_at')} w, COUNT(*) n FROM content_items WHERE state='PUBLISHED' AND substr(updated_at,1,10)>=? $scopeSql GROUP BY 1", array_merge([$since], $scopeArgs)) as $r) $pubWeek[$r['w']]['count'] = (int)$r['n'];
$newWeek = []; foreach (q("SELECT {$wk('created_at')} w, COUNT(*) n FROM content_items WHERE substr(created_at,1,10)>=? $scopeSql GROUP BY 1", array_merge([$since], $scopeArgs)) as $r) $newWeek[$r['w']]['count'] = (int)$r['n'];
$contentState = array_map(fn($r) => ['state' => $r['state'], 'count' => (int)$r['n']],
    q("SELECT state, COUNT(*) n FROM content_items WHERE 1=1 $scopeSql GROUP BY state ORDER BY n DESC", $scopeArgs));
$contentChannel = array_map(fn($r) => ['channel' => $r['channel'], 'count' => (int)$r['n']],
    q("SELECT COALESCE(NULLIF(channel,''),'Khác') channel, COUNT(*) n FROM content_items WHERE state='PUBLISHED' AND substr(updated_at,1,10)>=? $scopeSql GROUP BY 1 ORDER BY n DESC", array_merge([$since], $scopeArgs)));

/* ---------- Công việc ---------- */
const DONE_COLS = ['content' => 'PUBLISHED', 'design' => 'DONE', 'ads' => 'REPORT', 'research' => 'INSIGHT', 'lead' => 'ACCEPTED'];
$doneCase = "CASE board " . implode(' ', array_map(fn($b, $c) => "WHEN '$b' THEN col='$c'", array_keys(DONE_COLS), DONE_COLS)) . " ELSE 0 END";
$doneWeek = []; foreach (q("SELECT {$wk('updated_at')} w, COUNT(*) n FROM tasks WHERE ($doneCase) AND substr(updated_at,1,10)>=? $scopeSql GROUP BY 1", array_merge([$since], $scopeArgs)) as $r) $doneWeek[$r['w']]['count'] = (int)$r['n'];
$taskNewWeek = []; foreach (q("SELECT {$wk('created_at')} w, COUNT(*) n FROM tasks WHERE substr(created_at,1,10)>=? $scopeSql GROUP BY 1", array_merge([$since], $scopeArgs)) as $r) $taskNewWeek[$r['w']]['count'] = (int)$r['n'];
$boards = array_map(fn($r) => ['board' => $r['board'], 'open' => (int)$r['open'], 'done' => (int)$r['done']],
    q("SELECT board, SUM(CASE WHEN ($doneCase) THEN 0 ELSE 1 END) open, SUM(CASE WHEN ($doneCase) THEN 1 ELSE 0 END) done FROM tasks WHERE 1=1 $scopeSql GROUP BY board ORDER BY board", $scopeArgs));
$overdue = (int)(q("SELECT COUNT(*) n FROM tasks WHERE NOT ($doneCase) AND due_date IS NOT NULL AND due_date<>'' AND due_date<? $scopeSql", array_merge([$today], $scopeArgs))[0]['n'] ?? 0);

/* ---------- Kênh (metrics_history) ---------- */
$conns = []; foreach (q('SELECT id,provider,label,status,last_synced_at FROM connections WHERE project_id=?', [pid()]) as $c) $conns[$c['id']] = $c;
$prov = []; $perConn = [];
foreach (q('SELECT h.connection_id,h.provider,h.day,h.metrics FROM metrics_history h JOIN connections c ON c.id=h.connection_id WHERE c.project_id=? AND h.day>=? ORDER BY h.day', [pid(), $since]) as $h) {
    $v = metric_key_value($h['provider'], jdecode($h['metrics']));
    if ($v === null) continue;
    $prov[$h['provider']][$h['day']][$h['connection_id']] = $v;
    $perConn[$h['connection_id']][] = ['day' => $h['day'], 'value' => $v];
}
// Tổng theo loại kênh mỗi ngày: kênh nào hôm đó chưa đồng bộ thì giữ giá trị gần nhất trước đó
// (tránh tổng bị "tụt" giả chỉ vì một kênh lỡ một ngày). n = số kênh có số liệu thật hôm đó.
$provOut = [];
foreach ($prov as $p => $byDay) {
    ksort($byDay);
    $pts = []; $lastVal = [];
    foreach ($byDay as $d => $vals) {
        foreach ($vals as $cid => $v) $lastVal[$cid] = $v;
        $pts[] = ['day' => $d, 'value' => array_sum($lastVal), 'n' => count($vals)];
    }
    $provOut[] = ['provider' => $p, 'key' => METRIC_KEYS[$p]['key'] ?? null, 'label' => METRIC_KEYS[$p]['label'] ?? $p,
        'connections' => count(array_filter($conns, fn($c) => $c['provider'] === $p)), 'points' => $pts];
}
$connOut = [];
foreach ($perConn as $id => $pts) {
    $c = $conns[$id] ?? ['provider' => '', 'label' => '(kênh đã xoá)', 'status' => 'deleted', 'last_synced_at' => null];
    $first = $pts[0]['value']; $last = end($pts)['value'];
    $p = $c['provider'] ?: (q('SELECT provider FROM metrics_history WHERE connection_id=? LIMIT 1', [$id])[0]['provider'] ?? '');
    $connOut[] = ['id' => $id, 'provider' => $p, 'label' => $c['label'], 'key' => METRIC_KEYS[$p]['key'] ?? null,
        'key_label' => METRIC_KEYS[$p]['label'] ?? null, 'points' => $pts, 'first' => $first, 'last' => $last,
        'change' => $last - $first, 'change_pct' => $first ? round(($last - $first) / $first * 100, 1) : null, 'days' => count($pts)];
}

respond([
    'range' => ['days' => $days, 'since' => $since, 'until' => $today, 'co' => $co ?: null, 'br' => $br ?: null],
    'campaigns' => [
        'totals' => $agg($inRange),
        'by_month' => fill_series($months, $byMonth, 'month', ['spent', 'leads']),
        'by_company' => $inRange ? $byCompany : [],
        'top_roas' => array_slice($topRoas, 0, 5),
        'top_leads' => array_slice($topLeads, 0, 5),
        'list' => $list,
    ],
    'leads' => [
        'total' => count($leadRows),
        'by_source' => $srcOut, 'by_state' => $stateOut, 'funnel' => $funnel,
        'by_month' => fill_series($months, $leadMonth, 'month', ['count']),
    ],
    'content' => [
        'published_by_week' => fill_series($weeks, $pubWeek, 'week', ['count']),
        'created_by_week' => fill_series($weeks, $newWeek, 'week', ['count']),
        'published_total' => array_sum(array_column($pubWeek, 'count')),
        'by_state' => $contentState, 'published_by_channel' => $contentChannel,
    ],
    'tasks' => [
        'done_by_week' => fill_series($weeks, $doneWeek, 'week', ['count']),
        'created_by_week' => fill_series($weeks, $taskNewWeek, 'week', ['count']),
        'done_total' => array_sum(array_column($doneWeek, 'count')),
        'by_board' => $boards, 'overdue' => $overdue,
    ],
    'channels' => ['providers' => $provOut, 'connections' => $connOut],
    'notes' => [
        'Chi tiêu & lead theo tháng: chia đều số lũy kế của mỗi chiến dịch theo số ngày chạy (ước tính).',
        'Nội dung xuất bản & công việc hoàn thành theo tuần: dùng thời điểm cập nhật cuối làm ngày hoàn thành (xấp xỉ).',
        'Kênh: lấy từ lịch sử đồng bộ mỗi ngày; GA4 và quảng cáo là số cộng dồn 30 ngày tại ngày đồng bộ.',
    ],
]);
