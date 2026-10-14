<?php
/* OKR Marketing theo quý.
   metric = 'manual'  → "current" do người dùng nhập tay.
   metric khác        → "current" TỰ TÍNH từ dữ liệu thật của quý đó (và được ghi lại vào cột current
                        để bootstrap.php cũng trả về đúng con số mới nhất).
   Quy ước tính cho quý Q (từ ngày đầu quý tới ngày cuối quý):
   - "Chiến dịch trong quý" = chiến dịch có khoảng [start_date, end_date] giao với quý và state ≠ CANCELLED
     (thiếu end_date coi như đang mở; thiếu start_date thì không tính). Chỉ số lead/chi tiêu của chiến dịch là
     số luỹ kế, nên chiến dịch kéo dài qua nhiều quý được tính đủ ở mỗi quý.
   - leads              = SUM(campaigns.leads) của chiến dịch trong quý
   - spend              = SUM(campaigns.spent) của chiến dịch trong quý (₫)
   - roas               = SUM(spent*roas)/SUM(spent) (bình quân gia quyền theo chi tiêu)
   - cpl                = SUM(spent)/SUM(leads) (₫/lead)
   - campaigns_active   = số chiến dịch state=ACTIVE trong quý
   - content_published  = số content_items state=PUBLISHED có updated_at trong quý
   - tasks_done         = số tasks ở cột cuối của bảng (PUBLISHED/DONE/REPORT/INSIGHT/ACCEPTED) hoặc life DONE/COMPLETED,
                          có updated_at trong quý */
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

const OKR_METRICS = ['manual', 'leads', 'spend', 'roas', 'cpl', 'content_published', 'tasks_done', 'campaigns_active'];

function quarter_range(string $q): ?array {
    if (!preg_match('/^(\d{4})-Q([1-4])$/', $q, $m)) return null;
    $y = (int)$m[1]; $n = (int)$m[2];
    $start = sprintf('%04d-%02d-01', $y, ($n - 1) * 3 + 1);
    $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $n * 3)));
    return [$start, $end];
}

function okr_compute(string $metric, string $quarter): ?float {
    global $pdo;
    static $cache = [];
    if ($metric === 'manual') return null;
    $r = quarter_range($quarter);
    if (!$r) return null;
    [$qs, $qe] = $r;
    $key = "$metric|$quarter";
    if (array_key_exists($key, $cache)) return $cache[$key];
    $campWhere = "project_id=? AND start_date IS NOT NULL AND start_date<>'' AND start_date<=? AND (end_date IS NULL OR end_date='' OR end_date>=?) AND state<>'CANCELLED'";
    $P = pid();
    $val = null;
    switch ($metric) {
        case 'leads': case 'spend': case 'roas': case 'cpl':
            $st = $pdo->prepare("SELECT COALESCE(SUM(leads),0) l, COALESCE(SUM(spent),0) s, COALESCE(SUM(spent*roas),0) w FROM campaigns WHERE $campWhere");
            $st->execute([$P, $qe, $qs]); $a = $st->fetch();
            $l = (float)$a['l']; $s = (float)$a['s']; $w = (float)$a['w'];
            if ($metric === 'leads') $val = $l;
            elseif ($metric === 'spend') $val = $s;
            elseif ($metric === 'roas') $val = $s > 0 ? round($w / $s, 2) : 0;
            else $val = $l > 0 ? round($s / $l) : 0;
            break;
        case 'campaigns_active':
            $st = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE $campWhere AND state='ACTIVE'");
            $st->execute([$P, $qe, $qs]); $val = (float)$st->fetchColumn();
            break;
        case 'content_published':
            $st = $pdo->prepare("SELECT COUNT(*) FROM content_items WHERE project_id=? AND state='PUBLISHED' AND date(updated_at) BETWEEN ? AND ?");
            $st->execute([$P, $qs, $qe]); $val = (float)$st->fetchColumn();
            break;
        case 'tasks_done':
            $st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE project_id=? AND (col IN ('PUBLISHED','DONE','REPORT','INSIGHT','ACCEPTED') OR life IN ('DONE','COMPLETED'))
                AND date(updated_at) BETWEEN ? AND ?");
            $st->execute([$P, $qs, $qe]); $val = (float)$st->fetchColumn();
            break;
    }
    return $cache[$key] = $val;
}

function okr_row(array $r): array {
    global $pdo;
    $metric = $r['metric'] ?: 'manual';
    $cur = (float)$r['current'];
    $auto = okr_compute($metric, $r['quarter']);
    if ($auto !== null) {
        if (abs($auto - $cur) > 1e-9) {
            $pdo->prepare('UPDATE okrs SET current=? WHERE id=?')->execute([$auto, $r['id']]);
        }
        $cur = $auto;
    }
    return [
        'id' => $r['id'], 'quarter' => $r['quarter'], 'objective' => $r['objective'], 'kr' => $r['key_result'],
        'target' => (float)$r['target'], 'current' => $cur, 'unit' => $r['unit'],
        'dir' => (int)$r['direction'], 'owner' => $r['owner_id'], 'metric' => $metric, 'auto' => $auto !== null,
    ];
}

if ($method === 'GET') {
    if (!empty($_GET['quarter'])) {
        $st = $pdo->prepare('SELECT * FROM okrs WHERE project_id=? AND quarter=? ORDER BY objective, created_at');
        $st->execute([pid(), $_GET['quarter']]);
        $rows = $st->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT * FROM okrs WHERE project_id=? ORDER BY quarter DESC, objective, created_at'); $st->execute([pid()]); $rows = $st->fetchAll();
    }
    respond(['okrs' => array_map('okr_row', $rows)]);
}

if ($method === 'POST') {
    $u = require_project_admin();
    $in = json_input();
    $id = $in['id'] ?? null;

    if (array_key_exists('quarter', $in) && !quarter_range((string)$in['quarter'])) fail('Quý không hợp lệ (dạng 2026-Q4)');
    if (array_key_exists('metric', $in) && !in_array($in['metric'], OKR_METRICS, true)) fail('Chỉ số tự tính không hợp lệ');
    if (array_key_exists('dir', $in)) $in['dir'] = ((int)$in['dir']) < 0 ? -1 : 1;
    foreach (['target', 'current'] as $k) if (array_key_exists($k, $in)) {
        if (!is_numeric($in[$k])) fail('Chỉ tiêu / hiện tại phải là số');
        $in[$k] = (float)$in[$k];
    }
    $map = ['quarter' => 'quarter', 'objective' => 'objective', 'kr' => 'key_result', 'target' => 'target',
        'current' => 'current', 'unit' => 'unit', 'dir' => 'direction', 'metric' => 'metric', 'owner' => 'owner_id'];
    if (array_key_exists('owner', $in) && ($in['owner'] === '' )) $in['owner'] = null;

    if ($id) {
        need('okrs', $id, 'OKR');
        foreach (['objective', 'kr'] as $k) if (array_key_exists($k, $in) && trim((string)$in[$k]) === '') fail('Mục tiêu / KR không được để trống');
        $set = []; $vals = [];
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = is_string($in[$jsKey]) ? trim($in[$jsKey]) : $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE okrs SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'okr', $id, 'Sửa OKR: ' . ($in['kr'] ?? $id), $u);
        $st = $pdo->prepare('SELECT * FROM okrs WHERE id=?'); $st->execute([$id]);
        respond(['ok' => true, 'okr' => okr_row($st->fetch())]);
    }

    $quarter = (string)($in['quarter'] ?? '');
    $obj = trim((string)($in['objective'] ?? ''));
    $kr = trim((string)($in['kr'] ?? ''));
    if (!quarter_range($quarter)) fail('Thiếu quý (dạng 2026-Q4)');
    if ($obj === '' || $kr === '') fail('Thiếu mục tiêu hoặc kết quả then chốt');
    $newId = gen_id('OK');
    $pdo->prepare('INSERT INTO okrs(id,project_id,quarter,objective,key_result,target,current,unit,direction,metric,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$newId, pid(), $quarter, $obj, $kr, $in['target'] ?? 0, $in['current'] ?? 0, trim((string)($in['unit'] ?? '')),
            $in['dir'] ?? 1, $in['metric'] ?? 'manual', $in['owner'] ?? null]);
    log_activity('create', 'okr', $newId, "Tạo OKR $quarter: $kr", $u);
    $st = $pdo->prepare('SELECT * FROM okrs WHERE id=?'); $st->execute([$newId]);
    respond(['id' => $newId, 'okr' => okr_row($st->fetch())], 201);
}

if ($method === 'DELETE') {
    $u = require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $kr = need('okrs', $id, 'OKR')['key_result'];
    $pdo->prepare('DELETE FROM okrs WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'okr', $id, 'Xoá OKR: ' . $kr, $u);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
