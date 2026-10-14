<?php
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM leads WHERE project_id=? ORDER BY created_at'); $st->execute([pid()]);
    respond(['leads' => $st->fetchAll()]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $fields = ['name','phone','email','notes','co','br','source','score','state','subject'];
    if (isset($in['email'])) {
        $in['email'] = trim((string)$in['email']);
        if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) fail('Email không hợp lệ');
    }
    $map = ['camp' => 'campaign_id'];

    if ($id) {
        $row = need('leads', $id, 'đầu mối');
        // Xác nhận CRM nhận / từ chối là quyết định duyệt → cần quyền duyệt.
        $stateChange = array_key_exists('state', $in) && $in['state'] !== $row['state'];
        $isDecision = $stateChange && in_array($in['state'], ['ACCEPTED', 'REJECTED'], true);
        if ($isDecision && !can_approve_project($me)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới xác nhận / từ chối đầu mối', 403);
        $set = []; $vals = [];
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = $in[$f]; }
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        if (!empty($in['camp']) && !scoped('campaigns', (string)$in['camp'])) fail('Chiến dịch không thuộc dự án này');
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE leads SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        if ($isDecision) {
            log_activity($in['state'] === 'ACCEPTED' ? 'approve' : 'reject', 'lead', $id,
                ($in['state'] === 'ACCEPTED' ? 'CRM nhận đầu mối ' : 'Từ chối đầu mối ') . $row['name']);
        } elseif ($stateChange) {
            log_activity('move', 'lead', $id, 'Đầu mối ' . $row['name'] . ': ' . $row['state'] . ' → ' . $in['state']);
        } else {
            log_activity('update', 'lead', $id, 'Cập nhật đầu mối ' . $row['name']);
        }
        if ($stateChange) fire_event('lead.state', ['lead_id' => $id, 'from' => $row['state'], 'to' => $in['state']]);
        respond(['ok' => true]);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên đầu mối');
    $newId = gen_id('L');
    if (!empty($in['camp']) && !scoped('campaigns', (string)$in['camp'])) fail('Chiến dịch không thuộc dự án này');
    $pdo->prepare('INSERT INTO leads(id,project_id,name,phone,email,notes,co,br,campaign_id,source,score,state,subject)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $name, $in['phone'] ?? '', ($in['email'] ?? '') !== '' ? $in['email'] : null, ($in['notes'] ?? '') !== '' ? (string)$in['notes'] : null, $in['co'] ?? 'all', $in['br'] ?? 'all',
        $in['camp'] ?? null, $in['source'] ?? 'Website', (int)($in['score'] ?? 0), 'CREATED', $in['subject'] ?? '',
    ]);
    log_activity('create', 'lead', $newId, 'Thêm đầu mối ' . $name);
    fire_event('lead.new', ['lead_id' => $newId, 'source' => $in['source'] ?? 'Website']);
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $name = need('leads', $id, 'đầu mối')['name'];
    $pdo->prepare('DELETE FROM leads WHERE id=? AND project_id=?')->execute([$id, pid()]);
    if ($name !== false) log_activity('delete', 'lead', $id, 'Xoá đầu mối ' . $name);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
