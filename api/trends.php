<?php
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM trends WHERE project_id=? ORDER BY created_at'); $st->execute([pid()]); $rows = $st->fetchAll();
    $trends = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'vol' => $r['volume_change'], 'dir' => (int)$r['direction'],
        'data' => jdecode($r['data_points'], []), 'note' => $r['note'],
    ], $rows);
    respond(['trends' => $trends]);
}

require_once __DIR__ . '/_market_lib.php';

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['refresh', 'discover'], true)) {
    require_project_write();
    $pidNow = pid();
    session_write_close();
    if ($inA['action'] === 'refresh') {
        $rows = !empty($inA['id']) ? $pdo->prepare('SELECT * FROM trends WHERE id=? AND project_id=?') : $pdo->prepare('SELECT * FROM trends WHERE project_id=? ORDER BY created_at LIMIT 20');
        $rows->execute(!empty($inA['id']) ? [$inA['id'], $pidNow] : [$pidNow]);
        $res = [];
        foreach ($rows->fetchAll() as $r) {
            try { $res[] = trend_refresh($r); } catch (Throwable $e) { $res[] = ['id' => $r['id'], 'ok' => false, 'error' => $e->getMessage()]; }
        }
        log_activity('update', 'trend', null, 'Cập nhật ' . count($res) . ' xu hướng từ Google Trends');
        respond(['results' => $res]);
    }
    // discover: từ khoá đang lên / phổ biến quanh một từ khoá gốc.
    $seed = trim((string)($inA['q'] ?? ''));
    if ($seed === '') fail('Nhập từ khoá gốc');
    try { $d = serpapi_trends(['q' => $seed, 'date' => 'today 3-m', 'data_type' => 'RELATED_QUERIES']); }
    catch (RuntimeException $e) { fail($e->getMessage(), 424); }
    $map = fn($x) => ['query' => $x['query'], 'value' => (string)($x['value'] ?? ''), 'score' => $x['extracted_value'] ?? null];
    respond(['rising' => array_map($map, array_slice($d['related_queries']['rising'] ?? [], 0, 15)),
        'top' => array_map($map, array_slice($d['related_queries']['top'] ?? [], 0, 15))]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $map = ['name' => 'name', 'vol' => 'volume_change', 'dir' => 'direction', 'note' => 'note'];

    if ($id) {
        need('trends', $id, 'xu hướng');
        $set = []; $vals = [];
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        if (array_key_exists('data', $in)) { $set[] = "data_points=?"; $vals[] = json_encode($in['data']); }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE trends SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'trend', $id, 'Cập nhật xu hướng ' . mb_substr((string)(($in['name'] ?? '') ?: $id), 0, 80));
        respond(['ok' => true]);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên xu hướng');
    $newId = gen_id('TR');
    $pdo->prepare('INSERT INTO trends(id,project_id,name,volume_change,direction,data_points,note)
        VALUES (?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $name, $in['vol'] ?? '', (int)($in['dir'] ?? 1),
        json_encode($in['data'] ?? []), $in['note'] ?? '',
    ]);
    log_activity('create', 'trend', $newId, 'Thêm xu hướng ' . mb_substr((string)$name, 0, 80));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    need('trends', $id, 'xu hướng');
    $pdo->prepare('DELETE FROM trends WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'trend', $id, 'Xoá xu hướng ' . $id);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
