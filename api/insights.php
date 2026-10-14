<?php
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM insights WHERE project_id=? ORDER BY created_at DESC'); $st->execute([pid()]); $rows = $st->fetchAll();
    $insights = array_map(fn($r) => [
        'id' => $r['id'], 'q' => $r['question'], 'ins' => $r['insight'], 'src' => $r['source'],
        'conf' => $r['confidence'], 'subject' => $r['subject'], 'state' => $r['state'],
    ], $rows);
    respond(['insights' => $insights]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $map = ['q' => 'question', 'ins' => 'insight', 'src' => 'source', 'conf' => 'confidence'];
    $fields = ['subject', 'state'];

    if ($id) {
        need('insights', $id, 'insight');
        $set = []; $vals = [];
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = $in[$f]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE insights SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'insight', $id, 'Cập nhật insight ' . mb_substr((string)(($in['q'] ?? '') ?: $id), 0, 80));
        respond(['ok' => true]);
    }

    $q = trim((string)($in['q'] ?? ''));
    $ins = trim((string)($in['ins'] ?? ''));
    if ($q === '' || $ins === '') fail('Thiếu câu hỏi hoặc insight');
    $newId = gen_id('IN');
    $pdo->prepare('INSERT INTO insights(id,project_id,question,insight,source,confidence,subject,state,created_by)
        VALUES (?,?,?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $q, $ins, $in['src'] ?? '', $in['conf'] ?? '', $in['subject'] ?? '',
        $in['state'] ?? 'Insight', current_user()['id'],
    ]);
    log_activity('create', 'insight', $newId, 'Thêm insight ' . mb_substr((string)$q, 0, 80));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    need('insights', $id, 'insight');
    $pdo->prepare('DELETE FROM insights WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'insight', $id, 'Xoá insight ' . $id);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
