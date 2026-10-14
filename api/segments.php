<?php
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM segments WHERE project_id=? ORDER BY size DESC'); $st->execute([pid()]); $rows = $st->fetchAll();
    $segments = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'size' => (int)$r['size'], 'value' => $r['value'],
        'camp' => $r['campaigns_fit'],
    ], $rows);
    respond(['segments' => $segments]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $fields = ['name', 'value'];
    $map = ['camp' => 'campaigns_fit'];

    if ($id) {
        need('segments', $id, 'phân khúc');
        $set = []; $vals = [];
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = $in[$f]; }
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        if (array_key_exists('size', $in)) { $set[] = "size=?"; $vals[] = (int)$in['size']; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE segments SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'segment', $id, 'Cập nhật phân khúc ' . mb_substr((string)(($in['name'] ?? '') ?: $id), 0, 80));
        respond(['ok' => true]);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên phân khúc');
    $newId = gen_id('SG');
    $pdo->prepare('INSERT INTO segments(id,project_id,name,size,value,campaigns_fit)
        VALUES (?,?,?,?,?,?)')->execute([
        $newId, pid(), $name, (int)($in['size'] ?? 0), $in['value'] ?? '', $in['camp'] ?? '',
    ]);
    log_activity('create', 'segment', $newId, 'Thêm phân khúc ' . mb_substr((string)$name, 0, 80));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    need('segments', $id, 'phân khúc');
    $pdo->prepare('DELETE FROM segments WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'segment', $id, 'Xoá phân khúc ' . $id);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
