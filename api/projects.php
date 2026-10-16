<?php
/* Dự án và thành viên dự án (11/10/2026).
   GET                      → danh sách dự án người này thuộc + dự án hiện hành
   GET ?id=X                → chi tiết + thành viên (chủ/quản lý dự án hoặc admin)
   POST {action:'create'}   → tạo dự án (admin hệ thống hoặc manager hệ thống); người tạo thành owner
   POST {action:'update'}   → sửa thông tin (owner/manager dự án)
   POST {action:'archive'}  → lưu trữ (owner hoặc admin)
   POST {action:'member_set'}    {user_id, role} → thêm/đổi vai trò (owner/manager dự án; chỉ owner mới đặt owner)
   POST {action:'member_remove'} {user_id}       → gỡ (không gỡ owner cuối cùng)
   POST {action:'select'}   {id} → đặt dự án hiện hành cho phiên
   POST {action:'role_save'}   {id, role_id?, name, perms:{module:'view'|'edit', approve:bool}} → tạo / sửa vai trò tuỳ chỉnh (owner/manager)
   POST {action:'role_delete'} {id, role_id} → xoá vai trò tuỳ chỉnh (khi không còn ai dùng) */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

function project_members_list(string $pid): array {
    global $pdo;
    $st = $pdo->prepare('SELECT m.user_id, m.role, m.created_at, u.name, u.email, u.title, u.active FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? ORDER BY m.role, u.name');
    $st->execute([$pid]);
    return array_map(fn($r) => ['user_id' => $r['user_id'], 'name' => $r['name'], 'email' => $r['email'], 'title' => $r['title'],
        'active' => (bool)$r['active'], 'role' => $r['role'], 'role_label' => PROJECT_ROLES[$r['role']] ?? (str_starts_with($r['role'], 'cr_') ? custom_role_get($r['role'], $pid)['name'] : $r['role']), 'since' => $r['created_at']], $st->fetchAll());
}
/** Vai trò tuỳ chỉnh của dự án kèm số người đang dùng. */
function project_custom_roles(string $pid): array {
    global $pdo;
    $st = $pdo->prepare('SELECT r.id, r.name, r.perms, (SELECT COUNT(*) FROM project_members m WHERE m.project_id=r.project_id AND m.role=r.id) AS members FROM project_roles r WHERE r.project_id=? ORDER BY r.name');
    $st->execute([$pid]);
    return array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'perms' => jdecode($r['perms']), 'members' => (int)$r['members']], $st->fetchAll());
}
function project_all_roles(string $pid): array { $o = PROJECT_ROLES; foreach (project_custom_roles($pid) as $r) $o[$r['id']] = $r['name']; return $o;
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
        $extra = [];
        if (in_array($p['member_role'], PROJECT_ADMIN_ROLES, true)) { $out['members'] = project_members_list($id); $extra = ['custom_roles' => project_custom_roles($id), 'modules' => ROLE_MODULES]; }
        respond(['project' => $out, 'roles' => project_all_roles($id)] + $extra);
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

    if ($action === 'role_save') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được tạo vai trò', 403);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 60) fail('Tên vai trò 1–60 ký tự');
        if (in_array(mb_strtolower($name), array_map('mb_strtolower', PROJECT_ROLES), true)) fail('Trùng tên vai trò có sẵn — đặt tên khác');
        $perms = [];
        foreach ((array)($in['perms'] ?? []) as $k => $v) {
            if ($k === 'approve') { if ($v) $perms['approve'] = true; continue; }
            if (isset(ROLE_MODULES[$k]) && in_array($v, ['view', 'edit'], true)) $perms[$k] = $v;
        }
        if (!array_diff_key($perms, ['approve' => 1])) fail('Tick ít nhất một chức năng');
        $rid = (string)($in['role_id'] ?? '');
        try {
            if ($rid !== '') {
                $st = $pdo->prepare("UPDATE project_roles SET name=?, perms=?, updated_at=datetime('now') WHERE id=? AND project_id=?");
                $st->execute([$name, json_encode($perms), $rid, $id]); if (!$st->rowCount()) fail('Không tìm thấy vai trò', 404);
            } else {
                $rid = 'cr_' . bin2hex(random_bytes(5));
                $pdo->prepare('INSERT INTO project_roles(id,project_id,name,perms,created_by) VALUES (?,?,?,?,?)')->execute([$rid, $id, $name, json_encode($perms), $me['id']]);
            }
        } catch (PDOException $e) { if (str_contains($e->getMessage(), 'UNIQUE')) fail('Đã có vai trò cùng tên'); throw $e; }
        log_activity('update', 'project', $id, 'Vai trò "' . $name . '": ' . implode(', ', array_map(fn($k, $v) => $k === 'approve' ? 'duyệt' : (ROLE_MODULES[$k] . ' (' . ($v === 'edit' ? 'sửa' : 'xem') . ')'), array_keys($perms), $perms)), null, $id);
        respond(['ok' => true, 'role_id' => $rid, 'custom_roles' => project_custom_roles($id), 'roles' => project_all_roles($id)]);
    }
    if ($action === 'role_delete') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được xoá vai trò', 403);
        $rid = (string)($in['role_id'] ?? '');
        $st = $pdo->prepare('SELECT COUNT(*) FROM project_members WHERE project_id=? AND role=?'); $st->execute([$id, $rid]);
        if (($n = (int)$st->fetchColumn()) > 0) fail("Còn $n thành viên đang dùng vai trò này — đổi vai trò cho họ trước");
        $pdo->prepare('DELETE FROM project_roles WHERE id=? AND project_id=?')->execute([$rid, $id]);
        respond(['ok' => true, 'custom_roles' => project_custom_roles($id), 'roles' => project_all_roles($id)]);
    }
    if ($action === 'member_set') {
        if (!$isAdmin) fail('Chỉ chủ / quản lý dự án được quản lý thành viên', 403);
        $uid = (string)($in['user_id'] ?? ''); $role = (string)($in['role'] ?? 'viewer');
        if (!isset(PROJECT_ROLES[$role]) && !(str_starts_with($role, 'cr_') && isset(project_all_roles($id)[$role]))) fail('Vai trò không hợp lệ');
        if ($role === 'owner' && $p['member_role'] !== 'owner' && $me['role'] !== 'admin') fail('Chỉ chủ dự án mới đặt được chủ dự án khác', 403);
        $st = $pdo->prepare('SELECT name FROM users WHERE id=? AND active=1'); $st->execute([$uid]);
        $uname = $st->fetchColumn(); if ($uname === false) fail('Không tìm thấy tài khoản (hoặc đã khoá)', 404);
        $pdo->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES (?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET role=excluded.role')->execute([$id, $uid, $role]);
        log_activity('update', 'project', $id, "Thành viên $uname → " . (project_all_roles($id)[$role] ?? $role), null, $id);
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
