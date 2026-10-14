<?php
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM content_items WHERE project_id=? ORDER BY created_at'); $st->execute([pid()]);
    respond(['content' => $st->fetchAll()]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $fields = ['title','type','pillar','subject','co','br','state','channel'];
    $map = ['due' => 'due_date', 'campaign' => 'campaign_id', 'owner' => 'owner_id'];

    if ($id) {
        $row = need('content_items', $id, 'nội dung');
        // Duyệt / yêu cầu sửa / xuất bản cần quyền duyệt; không tự duyệt nội dung của mình (trừ admin).
        $stateChange = array_key_exists('state', $in) && $in['state'] !== $row['state'];
        $isApproval = $stateChange && in_array($in['state'], ['APPROVED', 'PUBLISHED', 'CHANGES'], true);
        if ($isApproval) {
            if (!can_approve_project($me)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được duyệt', 403);
            if ($in['state'] === 'APPROVED' && $row['owner_id'] === $me['id'] && $me['role'] !== 'admin') {
                fail('Không được tự duyệt nội dung do chính bạn phụ trách', 403);
            }
        }
        $set = []; $vals = [];
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = $in[$f]; }
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        if (array_key_exists('campaign', $in) && $in['campaign'] && !scoped('campaigns', (string)$in['campaign'])) fail('Chiến dịch không thuộc dự án này');
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE content_items SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        if ($isApproval) {
            $act = ['APPROVED' => 'approve', 'CHANGES' => 'reject', 'PUBLISHED' => 'publish'][$in['state']];
            $lbl = ['APPROVED' => 'Duyệt', 'CHANGES' => 'Yêu cầu sửa', 'PUBLISHED' => 'Xuất bản'][$in['state']];
            log_activity($act, 'content', $id, $lbl . ' nội dung ' . $row['title']);
        } elseif ($stateChange) {
            log_activity('move', 'content', $id, 'Chuyển "' . $row['title'] . '" ' . $row['state'] . ' → ' . $in['state']);
        } else {
            log_activity('update', 'content', $id, 'Cập nhật nội dung ' . $row['title']);
        }
        respond(['ok' => true]);
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') fail('Thiếu tiêu đề nội dung');
    $newId = gen_id('CT');
    if (!empty($in['campaign']) && !scoped('campaigns', (string)$in['campaign'])) fail('Chiến dịch không thuộc dự án này');
    $pdo->prepare('INSERT INTO content_items(id,project_id,title,type,pillar,subject,co,br,state,owner_id,channel,due_date,campaign_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $title, $in['type'] ?? '', $in['pillar'] ?? '', $in['subject'] ?? '',
        $in['co'] ?? 'all', $in['br'] ?? 'all', 'IDEA', current_user()['id'],
        $in['channel'] ?? '', $in['due'] ?? null, $in['campaign'] ?? null,
    ]);
    log_activity('create', 'content', $newId, 'Tạo yêu cầu nội dung ' . $title);
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $title = need('content_items', $id, 'nội dung')['title'];
    $pdo->prepare('DELETE FROM content_items WHERE id=? AND project_id=?')->execute([$id, pid()]);
    if ($title !== false) log_activity('delete', 'content', $id, 'Xoá nội dung ' . $title);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
