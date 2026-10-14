<?php
/* Dự án và thành viên dự án (11/10/2026).
   GET                      → danh sách dự án người này thuộc + dự án hiện hành
   GET ?id=X                → chi tiết + thành viên (chủ/quản lý dự án hoặc admin)
   POST {action:'create'}   → tạo dự án (admin hệ thống hoặc manager hệ thống); người tạo thành owner
   POST {action:'update'}   → sửa thông tin (owner/manager dự án)
   POST {action:'archive'}  → lưu trữ (owner hoặc admin)
   POST {action:'member_set'}    {user_id, role} → thêm/đổi vai trò (owner/manager dự án; chỉ owner mới đặt owner)
   POST {action:'member_remove'} {user_id}       → gỡ (không gỡ owner cuối cùng)
   POST {action:'select'}   {id} → đặt dự án hiện hành cho phiên */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

function project_members_list(string $pid): array {
    global $pdo;
    $st = $pdo->prepare('SELECT m.user_id, m.role, m.created_at, u.name, u.email, u.title, u.active FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? ORDER BY m.role, u.name');
    $st->execute([$pid]);
    return array_map(fn($r) => ['user_id' => $r['user_id'], 'name' => $r['name'], 'email' => $r['email'], 'title' => $r['title'],
        'active' => (bool)$r['active'], 'role' => $r['role'], 'role_label' => PROJECT_ROLES[$r['role']] ?? $r['role'], 'since' => $r['created_at']], $st->fetchAll());
}
function slugify(string $s): string {
    $s = mb_strtolower(trim($s));
    $map = ['á'=>'a','à'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ắ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a','â'=>'a','ấ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','đ'=>'d','é'=>'e','è'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ế'=>'e','ề'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','í'=>'i','ì'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i','ó'=>'o','ò'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ố'=>'o','ồ'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o','ơ'=>'o','ớ'=>'o','ờ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ú'=>'u','ù'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ứ'=>'u','ừ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ý'=>'y','ỳ'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'du-an';
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $id = (string)$_GET['id'];
        $list = projects_for_user($me); $p = null;
        foreach ($list as $x) if ($x['id'] === $id) $p = $x;
        if (!$p) fail('Bạn không thuộc dự án này', 403);
        $out = public_project($p);
        if (in_array($p['member_role'], PROJECT_ADMIN_ROLES, true)) $out['members'] = project_members_list($id);
        respond(['project' => $out, 'roles' => PROJECT_ROLES]);
    }
    $cur = current_project();
    respond(['projects' => array_map('public_project', projects_for_user($me)), 'current' => $cur ? $cur['id'] : null, 'roles' => PROJECT_ROLES]);
}

if ($method === 'POST') {
    $in = json_input();
    $action = (string)($in['action'] ?? '');

    if ($action === 'select') {
        $id = (string)($in['id'] ?? '');
        foreach (projects_for_user($me) as $p) if ($p['id'] === $id) { $_SESSION['project_id'] = $id; respond(['ok' => true, 'current' => $id]); }
        fail('Bạn không thuộc dự án này', 403);
    }

    if ($action === 'create') {
        if (!in_array($me['role'], ['admin', 'manager'], true)) fail('Chỉ quản trị / trưởng nhóm hệ thống được tạo dự án', 403);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') fail('Thiếu tên dự án');
        $id = trim((string)($in['id'] ?? '')) ?: slugify($name);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $id)) fail('Mã dự án chỉ gồm chữ thường, số, dấu gạch (2–41 ký tự)');
        $st = $pdo->prepare('SELECT 1 FROM projects WHERE id=?'); $st->execute([$id]);
        if ($st->fetchColumn()) fail('Mã dự án đã tồn tại: ' . $id);
        $type = in_array($in['type'] ?? '', ['internal', 'client'], true) ? $in['type'] : 'client';
        $pdo->prepare('INSERT INTO projects(id,name,type,domain,color,goal,budget_month,settings,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $name, $type, trim((string)($in['domain'] ?? '')) ?: null, (string)($in['color'] ?? '') ?: '#2563eb',
                trim((string)($in['goal'] ?? '')) ?: null, max(0, (int)($in['budget_month'] ?? 0)),
                json_encode((array)($in['settings'] ?? []), JSON_UNESCAPED_UNICODE), $me['id']]);
        $pdo->prepare('INSERT OR IGNORE INTO project_members(project_id,user_id,role) VALUES (?,?,?)')->execute([$id, $me['id'], 'owner']);
        log_activity('create', 'project', $id, 'Tạo dự án ' . $name, null, $id);
        $_SESSION['project_id'] = $id;
        respond(['id' => $id], 201);
    }

    // Các thao tác còn lại cần dự án đích (id trong body) và quyền trong dự án đó
    $id = (string)($in['id'] ?? ($in['project_id'] ?? ''));
    $p = null; foreach (projects_for_user($me) as $x) if ($x['id'] === $id) $p = $x;
    if (!$p) fail('Bạn không thuộc dự án này', 403);
    $isAdmin = in_array($p['member_role'], PROJECT_ADMIN_ROLES, true);

    if ($action === 'update') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được sửa thông tin dự án', 403);
        $set = []; $vals = [];
        foreach (['name', 'domain', 'color', 'goal', 'timezone'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = trim((string)$in[$f]) ?: null; }
        if (array_key_exists('type', $in) && in_array($in['type'], ['internal', 'client'], true)) { $set[] = 'type=?'; $vals[] = $in['type']; }
        if (array_key_exists('budget_month', $in)) { $set[] = 'budget_month=?'; $vals[] = max(0, (int)$in['budget_month']); }
        if (array_key_exists('settings', $in) && is_array($in['settings'])) {
            $old = jdecode($p['settings'] ?? null);
            $set[] = 'settings=?'; $vals[] = json_encode(array_merge($old, $in['settings']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('status', $in) && in_array($in['status'], ['active', 'paused'], true)) { $set[] = 'status=?'; $vals[] = $in['status']; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')"; $vals[] = $id;
        $pdo->prepare('UPDATE projects SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals);
        log_activity('update', 'project', $id, 'Cập nhật dự án (' . implode(', ', array_keys(array_diff_key($in, ['id' => 1, 'action' => 1]))) . ')', null, $id);
        respond(['ok' => true]);
    }

    if ($action === 'archive') {
        if ($p['member_role'] !== 'owner' && $me['role'] !== 'admin') fail('Chỉ chủ dự án được lưu trữ dự án', 403);
        $pdo->prepare("UPDATE projects SET status='archived', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        log_activity('archive', 'project', $id, 'Lưu trữ dự án ' . $p['name'], null, $id);
        if (($_SESSION['project_id'] ?? '') === $id) unset($_SESSION['project_id']);
        respond(['ok' => true]);
    }

    if ($action === 'member_set') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được quản lý thành viên', 403);
        $uid = (string)($in['user_id'] ?? ''); $role = (string)($in['role'] ?? 'viewer');
        if (!isset(PROJECT_ROLES[$role])) fail('Vai trò không hợp lệ');
        if ($role === 'owner' && $p['member_role'] !== 'owner' && $me['role'] !== 'admin') fail('Chỉ chủ dự án mới đặt được chủ dự án khác', 403);
        $st = $pdo->prepare('SELECT name FROM users WHERE id=? AND active=1'); $st->execute([$uid]);
        $uname = $st->fetchColumn(); if ($uname === false) fail('Không tìm thấy tài khoản (hoặc đã khoá)', 404);
        $pdo->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES (?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET role=excluded.role')->execute([$id, $uid, $role]);
        log_activity('update', 'project', $id, "Thành viên $uname → " . PROJECT_ROLES[$role], null, $id);
        respond(['ok' => true, 'members' => project_members_list($id)]);
    }

    if ($action === 'member_remove') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được quản lý thành viên', 403);
        $uid = (string)($in['user_id'] ?? '');
        $st = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE project_id=? AND role='owner' AND user_id<>?"); $st->execute([$id, $uid]);
        $stR = $pdo->prepare('SELECT role FROM project_members WHERE project_id=? AND user_id=?'); $stR->execute([$id, $uid]);
        if ($stR->fetchColumn() === 'owner' && !(int)$st->fetchColumn()) fail('Không gỡ được chủ dự án cuối cùng');
        $pdo->prepare('DELETE FROM project_members WHERE project_id=? AND user_id=?')->execute([$id, $uid]);
        log_activity('update', 'project', $id, 'Gỡ thành viên ' . $uid, null, $id);
        respond(['ok' => true, 'members' => project_members_list($id)]);
    }

    fail('Thao tác không hỗ trợ');
}

fail('Method không hỗ trợ', 405);
