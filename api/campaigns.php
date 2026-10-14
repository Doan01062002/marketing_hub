<?php
require __DIR__ . '/_boot.php';
$me = require_login();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM campaigns WHERE project_id=? ORDER BY created_at'); $st->execute([pid()]); $rows = $st->fetchAll();
    respond(['campaigns' => array_map(function ($r) {
        $r['channels'] = jdecode($r['channels']);
        return $r;
    }, $rows)]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $id = $in['id'] ?? null;

    if ($id) {
        // cập nhật một chiến dịch đã có (sửa nội dung, đổi trạng thái, duyệt/từ chối...) — chỉ trong dự án hiện hành
        $st = $pdo->prepare('SELECT * FROM campaigns WHERE id=? AND project_id=?');
        $st->execute([$id, pid()]);
        $row = $st->fetch();
        if (!$row) fail('Không tìm thấy chiến dịch trong dự án này', 404);

        // Đổi trạng thái duyệt (trừ gửi lại "chờ duyệt") chỉ dành cho người có quyền duyệt,
        // và không được tự duyệt chiến dịch mình phụ trách (trừ admin).
        $aprChange = array_key_exists('approval', $in) && $in['approval'] !== $row['approval'] && $in['approval'] !== 'PENDING';
        if ($aprChange) {
            if (!can_approve_project($me)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được duyệt / từ chối', 403);
            if ($in['approval'] === 'APPROVED' && $row['owner_id'] === $me['id'] && $me['role'] !== 'admin') {
                fail('Không được tự duyệt chiến dịch do chính bạn phụ trách', 403);
            }
        }
        if (array_key_exists('owner', $in) && $in['owner'] !== $row['owner_id'] && !in_array($me['role'], ['admin', 'manager'], true)) {
            fail('Chỉ quản trị / trưởng nhóm được đổi người phụ trách chiến dịch', 403);
        }

        $fields = ['name','subject','co','br','state','approval','health','owner_id','budget','spent','leads','roas','start_date','end_date','objective'];
        $set = []; $vals = [];
        foreach ($fields as $f) {
            $key = $f === 'owner_id' ? 'owner' : ($f === 'start_date' ? 'start' : ($f === 'end_date' ? 'end' : $f));
            if (array_key_exists($key, $in)) { $set[] = "$f=?"; $vals[] = $in[$key]; }
        }
        if (array_key_exists('channels', $in)) { $set[] = 'channels=?'; $vals[] = json_encode($in['channels'], JSON_UNESCAPED_UNICODE); }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = 'updated_at=datetime(\'now\')';
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE campaigns SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        $st->execute([$id, pid()]);
        $row = $st->fetch(); $row['channels'] = jdecode($row['channels']);
        if ($aprChange && $in['approval'] === 'APPROVED') fire_event('campaign.approved', ['campaign_id' => $id]);
        if ($aprChange) {
            log_activity($in['approval'] === 'APPROVED' ? 'approve' : 'reject', 'campaign', $id,
                ($in['approval'] === 'APPROVED' ? 'Duyệt' : 'Không duyệt (' . $in['approval'] . ')') . ' chiến dịch ' . $row['name']);
        } else {
            log_activity('update', 'campaign', $id, 'Cập nhật chiến dịch ' . $row['name'] . ' (' . implode(', ', array_keys(array_diff_key($in, ['id' => 1]))) . ')');
        }
        respond(['campaign' => $row]);
    }

    // tạo chiến dịch mới
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên chiến dịch');
    $newId = gen_id('CMP');
    $pdo->prepare('INSERT INTO campaigns(id,project_id,name,subject,co,br,state,approval,health,owner_id,channels,budget,spent,leads,roas,start_date,end_date,objective)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $newId, pid(), $name, $in['subject'] ?? '', $in['co'] ?? 'all', $in['br'] ?? 'all',
        $in['state'] ?? 'DRAFT', 'PENDING', $in['health'] ?? 'ON_TRACK', current_user()['id'],
        json_encode($in['channels'] ?? [], JSON_UNESCAPED_UNICODE),
        (int)($in['budget'] ?? 0), 0, 0, 0, $in['start'] ?? null, $in['end'] ?? null, $in['obj'] ?? '',
    ]);
    log_activity('create', 'campaign', $newId, 'Tạo chiến dịch ' . $name);
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $st = $pdo->prepare('SELECT name FROM campaigns WHERE id=? AND project_id=?'); $st->execute([$id, pid()]);
    $name = $st->fetchColumn();
    if ($name === false) fail('Không tìm thấy chiến dịch trong dự án này', 404);
    $pdo->prepare('DELETE FROM campaigns WHERE id=? AND project_id=?')->execute([$id, pid()]);
    if ($name !== false) log_activity('delete', 'campaign', $id, 'Xoá chiến dịch ' . $name);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
