<?php
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at'); $st->execute([pid()]);
    respond(['tasks' => $st->fetchAll()]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;
    $fields = ['title','co','br','board','col','type','pri','life','apr','health'];
    $map = ['due' => 'due_date', 'camp' => 'campaign_id', 'assignee' => 'assignee_id', 'assigner' => 'assigner_id'];

    if ($id) {
        $row = need('tasks', $id, 'công việc');
        // Duyệt / từ chối (apr), chuyển thẳng sang vòng đời đã duyệt, hoặc đưa thẻ qua cột "Chờ duyệt / Duyệt Master"
        // đều cần quyền duyệt; không tự duyệt việc của chính mình (trừ admin).
        $aprChange = array_key_exists('apr', $in) && $in['apr'] !== $row['apr'] && !in_array($in['apr'], ['PENDING', 'NA'], true);
        $lifeApprove = array_key_exists('life', $in) && $in['life'] !== $row['life'] && in_array($in['life'], ['APPROVED', 'PUBLISHED'], true);
        $passReview = array_key_exists('col', $in) && $in['col'] !== $row['col'] && $row['col'] === 'REVIEW';
        $isApproval = $aprChange || $lifeApprove || $passReview;
        if ($isApproval) {
            if (!can_approve_project($me)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được duyệt', 403);
            $approving = ($in['apr'] ?? 'APPROVED') === 'APPROVED';
            if ($approving && $row['assignee_id'] === $me['id'] && $me['role'] !== 'admin') {
                fail('Không được tự duyệt công việc do chính bạn thực hiện', 403);
            }
        }
        if ((array_key_exists('assignee', $in) && $in['assignee'] !== $row['assignee_id'])
            && !in_array($me['role'], ['admin', 'manager'], true) && $row['assigner_id'] !== $me['id']) {
            fail('Chỉ người giao việc, trưởng nhóm hoặc quản trị được đổi người nhận', 403);
        }
        $set = []; $vals = [];
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = $in[$f]; }
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        if (!empty($in['camp']) && !scoped('campaigns', (string)$in['camp'])) fail('Chiến dịch không thuộc dự án này');
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE tasks SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        if ($isApproval) {
            $ok = ($in['apr'] ?? 'APPROVED') === 'APPROVED';
            log_activity($ok ? 'approve' : 'reject', 'task', $id, ($ok ? 'Duyệt' : 'Từ chối') . ' công việc ' . $row['title']);
        } elseif (array_key_exists('col', $in) && $in['col'] !== $row['col']) {
            log_activity('move', 'task', $id, 'Chuyển "' . $row['title'] . '" ' . $row['col'] . ' → ' . $in['col']);
        } else {
            log_activity('update', 'task', $id, 'Cập nhật công việc ' . $row['title']);
        }
        respond(['ok' => true]);
    }

    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') fail('Thiếu tiêu đề công việc');
    $newId = gen_id('T');
    if (!empty($in['camp']) && !scoped('campaigns', (string)$in['camp'])) fail('Chiến dịch không thuộc dự án này');
    $pdo->prepare('INSERT INTO tasks(id,project_id,title,co,br,board,col,type,assignee_id,assigner_id,due_date,pri,life,apr,health,campaign_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $title, $in['co'] ?? 'all', $in['br'] ?? 'all', $in['board'] ?? 'content',
        $in['col'] ?? 'IDEA', $in['type'] ?? 'content', $in['assignee'] ?? null, current_user()['id'],
        $in['due'] ?? null, $in['pri'] ?? 'P2', 'DRAFT', 'NA', 'ON_TRACK', $in['camp'] ?? null,
    ]);
    $an = '';
    if (!empty($in['assignee'])) { $q = $pdo->prepare('SELECT name FROM users WHERE id=?'); $q->execute([$in['assignee']]); $an = (string)$q->fetchColumn(); }
    log_activity('create', 'task', $newId, 'Giao việc "' . $title . '"' . ($an !== '' ? ' cho ' . $an : ''));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $title = need('tasks', $id, 'công việc')['title'];
    $pdo->prepare('DELETE FROM tasks WHERE id=? AND project_id=?')->execute([$id, pid()]);
    if ($title !== false) log_activity('delete', 'task', $id, 'Xoá công việc ' . $title);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
