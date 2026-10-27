<?php
/* Quản lý tài khoản nhân viên + Phân quyền vai trò (RBAC: Xem / Thêm / Sửa / Xoá / Duyệt) + thống kê hiệu suất.
   GET                         → danh sách users + roles + rbac matrix
   GET  ?action=stats&period=7|30|90[&user_id=] → thống kê công việc theo người
   POST {action:'create', …}   → tạo tài khoản người dùng + gán vào dự án + phân quyền
   POST {action:'update', id,…}→ cập nhật thông tin, vai trò, mật khẩu, phân quyền riêng
   POST {action:'delete', id}  → xoá tài khoản người dùng
   POST {action:'lock'|'unlock', id}      → khoá / mở khoá tài khoản
   POST {action:'reset_password', id}     → đặt lại mật khẩu tạm
   POST {action:'save_role', …}           → tạo mới hoặc cập nhật vai trò & bảng phân quyền
   POST {action:'save_roles_matrix', …}   → lưu ma trận phân quyền Xem/Thêm/Sửa/Xoá/Duyệt của các vai trò
   POST {action:'delete_role', key}       → xoá vai trò tự tạo */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$isMgr = in_array($me['role'], ['admin', 'manager'], true) || has_perm('settings', 'edit', $me) || has_perm('staff', 'edit', $me);
$canManageUsers = ($me['role'] === 'admin') || has_perm('settings', 'edit', $me) || has_perm('staff', 'create', $me);

/** Cột "hoàn thành" của từng bảng kanban (khớp BOARDS ở index.html). */
const DONE_COLS = ['content' => 'PUBLISHED', 'design' => 'DONE', 'ads' => 'REPORT', 'research' => 'INSIGHT', 'lead' => 'ACCEPTED'];

function public_user(array $r, bool $full, array $projMap = []): array {
    $customPerms = jdecode($r['permissions'] ?? null, []);
    $o = [
        'id' => $r['id'], 'name' => $r['name'], 'title' => $r['title'] ?? '', 'role' => $r['role'],
        'approve' => (int)$r['approve'] === 1, 'active' => (int)$r['active'] === 1,
        'scope' => $r['scope'] ?? '', 'co' => $r['co'] ?? '', 'br' => $r['br'] ?? '',
        'custom_permissions' => $customPerms,
        'permissions' => user_permissions($r),
        'projects' => $projMap[$r['id']] ?? [],
    ];
    if ($full) {
        $o['email'] = $r['email']; $o['phone'] = $r['phone'] ?? '';
        $o['last_login_at'] = $r['last_login_at']; $o['created_at'] = $r['created_at'];
    }
    return $o;
}

function find_user(string $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('Không tìm thấy người dùng', 404);
    return $u;
}

function gen_password(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
    return $s . random_int(10, 99);
}

function active_admins(?string $exceptId = null): int {
    global $pdo;
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1 AND id<>?");
    $st->execute([$exceptId ?? '']);
    return (int)$st->fetchColumn();
}

function task_done(array $t): bool {
    return (DONE_COLS[$t['board']] ?? null) === $t['col'] || in_array($t['life'], ['PUBLISHED', 'APPROVED'], true);
}

function default_project_role_for_sysrole(string $role): string {
    return match ($role) {
        'admin'   => 'owner',
        'manager' => 'manager',
        'ads'     => 'ads',
        'viewer'  => 'viewer',
        default   => 'content',
    };
}

function sanitize_perms_matrix($raw): array {
    if (!is_array($raw)) return [];
    $clean = [];
    foreach (array_keys(RBAC_MODULES) as $m) {
        if (!isset($raw[$m]) || !is_array($raw[$m])) continue;
        foreach (array_keys(RBAC_ACTIONS) as $a) {
            if (array_key_exists($a, $raw[$m])) {
                $clean[$m][$a] = (bool)$raw[$m][$a];
            }
        }
    }
    return $clean;
}

/* ------------------------------ GET ------------------------------ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'stats') {
        $period = (int)($_GET['period'] ?? 30);
        if (!in_array($period, [7, 30, 90], true)) $period = 30;
        $since = gmdate('Y-m-d H:i:s', time() - $period * 86400);
        $today = date('Y-m-d');

        $users = $pdo->query('SELECT * FROM users ORDER BY active DESC, id')->fetchAll();
        if (!$isMgr) $users = array_values(array_filter($users, fn($u) => $u['id'] === $me['id']));
        elseif (!empty($_GET['user_id'])) $users = array_values(array_filter($users, fn($u) => $u['id'] === $_GET['user_id']));

        $PQ = $pdo->quote(pid());
        $tasks = $pdo->query("SELECT id,title,board,col,life,apr,pri,due_date,assignee_id,created_at,updated_at FROM tasks WHERE project_id=$PQ")->fetchAll();
        $content = $pdo->query("SELECT owner_id,state,due_date FROM content_items WHERE project_id=$PQ")->fetchAll();
        $camps = $pdo->query("SELECT owner_id,state,approval FROM campaigns WHERE project_id=$PQ")->fetchAll();
        $act = $pdo->prepare('SELECT user_id, COUNT(*) n FROM activity_log WHERE created_at>=? GROUP BY user_id');
        $act->execute([$since]);
        $actN = array_column($act->fetchAll(), 'n', 'user_id');
        $last = array_column($pdo->query('SELECT user_id, MAX(created_at) t FROM activity_log GROUP BY user_id')->fetchAll(), 't', 'user_id');

        $out = [];
        foreach ($users as $u) {
            $uid = $u['id'];
            $s = ['assigned' => 0, 'assigned_period' => 0, 'open' => 0, 'done' => 0, 'done_period' => 0, 'overdue' => 0, 'waiting' => 0];
            foreach ($tasks as $t) {
                if ($t['assignee_id'] !== $uid) continue;
                $s['assigned']++;
                if ($t['created_at'] >= $since) $s['assigned_period']++;
                $done = task_done($t);
                if ($done) {
                    $s['done']++;
                    if ($t['updated_at'] >= $since) $s['done_period']++;
                } else {
                    $s['open']++;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$t['due_date']) && $t['due_date'] < $today) $s['overdue']++;
                }
                if ($t['apr'] === 'PENDING') $s['waiting']++;
            }
            $cs = ['IDEA' => 0, 'DRAFT' => 0, 'IN_REVIEW' => 0, 'CHANGES' => 0, 'APPROVED' => 0, 'PUBLISHED' => 0];
            $cOver = 0;
            foreach ($content as $c) {
                if ($c['owner_id'] !== $uid) continue;
                $cs[$c['state']] = ($cs[$c['state']] ?? 0) + 1;
                if (!in_array($c['state'], ['APPROVED', 'PUBLISHED'], true)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$c['due_date']) && $c['due_date'] < $today) $cOver++;
            }
            $cp = ['total' => 0, 'active' => 0, 'pending' => 0];
            foreach ($camps as $c) {
                if ($c['owner_id'] !== $uid) continue;
                $cp['total']++;
                if ($c['state'] === 'ACTIVE') $cp['active']++;
                if ($c['approval'] === 'PENDING') $cp['pending']++;
            }
            $closed = $s['done'] + $s['open'];
            $out[] = [
                'id' => $uid, 'name' => $u['name'], 'title' => $u['title'] ?? '', 'role' => $u['role'],
                'active' => (int)$u['active'] === 1,
                'tasks' => $s + ['done_rate' => $closed ? (int)round($s['done'] * 100 / $closed) : 0],
                'content' => ['by_state' => $cs, 'total' => array_sum($cs), 'overdue' => $cOver],
                'campaigns' => $cp,
                'actions' => (int)($actN[$uid] ?? 0),
                'last_activity' => $last[$uid] ?? null,
                'last_login_at' => $u['last_login_at'],
            ];
        }
        respond(['period' => $period, 'since' => $since, 'today' => $today, 'stats' => $out]);
    }

    $projMap = [];
    foreach ($pdo->query('SELECT project_id, user_id, role FROM project_members')->fetchAll() as $pm) {
        $projMap[$pm['user_id']][$pm['project_id']] = $pm['role'];
    }

    if ($isMgr || has_perm('settings', 'view', $me) || has_perm('staff', 'view', $me)) {
        $rows = $pdo->query('SELECT * FROM users ORDER BY active DESC, id')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$me['id']]);
        $rows = $st->fetchAll();
    }
    respond([
        'users' => array_map(fn($r) => public_user($r, true, $projMap), $rows),
        'roles' => rbac_role_labels(),
        'rbac'  => [
            'modules' => RBAC_MODULES,
            'actions' => RBAC_ACTIONS,
            'roles'   => rbac_roles(),
        ],
    ]);
}

if ($method !== 'POST') fail('Method không hỗ trợ', 405);

/* ------------------------------ POST ------------------------------ */
$in = json_input();
$action = (string)($in['action'] ?? (!empty($in['id']) ? 'update' : 'create'));
$allRoles = rbac_roles();

/* ---- Quản lý Vai trò (Roles) & Ma trận Phân quyền (Xem / Thêm / Sửa / Xoá / Duyệt) ---- */
if ($action === 'save_role') {
    if (!$canManageUsers) fail('Chỉ Quản trị viên hoặc người có quyền Cài đặt mới được cấu hình vai trò', 403);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Nhập tên vai trò');
    $key = strtolower(trim((string)($in['key'] ?? '')));
    if ($key === '') {
        $map = ['á'=>'a','à'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ắ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a','â'=>'a','ấ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a','đ'=>'d','é'=>'e','è'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ế'=>'e','ề'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e','í'=>'i','ì'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i','ó'=>'o','ò'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ố'=>'o','ồ'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o','ơ'=>'o','ớ'=>'o','ờ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o','ú'=>'u','ù'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ứ'=>'u','ừ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u','ý'=>'y','ỳ'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y'];
        $key = preg_replace('/[^a-z0-9_]+/', '_', strtr(mb_strtolower($name), $map));
        $key = trim((string)$key, '_') ?: ('role_' . strtolower(substr(bin2hex(random_bytes(2)), 0, 4)));
    }
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $key)) fail('Mã vai trò chỉ gồm chữ thường, số và dấu gạch dưới (2–32 ký tự)');

    $rawSaved = setting_get('rbac.roles');
    $saved = is_string($rawSaved) ? jdecode($rawSaved, []) : [];
    $deleted = is_array($saved['__deleted'] ?? null) ? array_values(array_diff($saved['__deleted'], [$key])) : [];
    $existing = $allRoles[$key] ?? null;

    $perms = isset($in['perms']) && is_array($in['perms'])
        ? rbac_make_perms(sanitize_perms_matrix($in['perms']))
        : ($existing['perms'] ?? rbac_make_perms(['*' => ['view'=>true,'create'=>false,'edit'=>false,'delete'=>false,'approve'=>false]]));
    if ($key === 'admin') {
        $perms = rbac_make_perms(['*' => ['view'=>true,'create'=>true,'edit'=>true,'delete'=>true,'approve'=>true]]);
    }

    $saved[$key] = [
        'name'  => $name,
        'color' => in_array($in['color'] ?? '', ['vio','info','ok','warn','mut','bad'], true) ? $in['color'] : ($existing['color'] ?? 'info'),
        'desc'  => trim((string)($in['desc'] ?? ($existing['desc'] ?? ''))),
        'perms' => $perms,
    ];
    if ($deleted) $saved['__deleted'] = $deleted; else unset($saved['__deleted']);
    setting_set('rbac.roles', json_encode($saved, JSON_UNESCAPED_UNICODE), $me);
    log_activity('update', 'role', $key, "Lưu vai trò $name ($key)");
    respond(['ok' => true, 'key' => $key, 'roles' => rbac_role_labels(), 'rbac_roles' => rbac_roles()]);
}

if ($action === 'save_roles_matrix') {
    if (!$canManageUsers) fail('Chỉ Quản trị viên hoặc người có quyền Cài đặt mới được lưu phân quyền', 403);
    $matrix = $in['roles'] ?? [];
    if (!is_array($matrix)) fail('Dữ liệu phân quyền không hợp lệ');
    $rawSaved = setting_get('rbac.roles');
    $saved = is_string($rawSaved) ? jdecode($rawSaved, []) : [];
    $curRoles = rbac_roles();
    foreach ($matrix as $k => $rData) {
        if ($k === 'admin' || !isset($curRoles[$k]) || !is_array($rData)) continue;
        $permsInput = isset($rData['perms']) && is_array($rData['perms']) ? $rData['perms'] : $rData;
        $saved[$k] = [
            'name'  => trim((string)($rData['name'] ?? $curRoles[$k]['name'])),
            'color' => (string)($rData['color'] ?? $curRoles[$k]['color'] ?? 'info'),
            'desc'  => trim((string)($rData['desc'] ?? $curRoles[$k]['desc'] ?? '')),
            'perms' => rbac_make_perms(sanitize_perms_matrix($permsInput)),
        ];
    }
    setting_set('rbac.roles', json_encode($saved, JSON_UNESCAPED_UNICODE), $me);
    log_activity('update', 'role', null, 'Cập nhật bảng phân quyền chức năng (Xem / Thêm / Sửa / Xoá / Duyệt)');
    respond(['ok' => true, 'roles' => rbac_role_labels(), 'rbac_roles' => rbac_roles()]);
}

if ($action === 'delete_role') {
    if (!$canManageUsers) fail('Chỉ Quản trị viên mới được xoá vai trò', 403);
    $key = strtolower(trim((string)($in['key'] ?? '')));
    if (in_array($key, ['admin', 'manager', 'staff'], true)) {
        fail('Không thể xoá 3 vai trò gốc của hệ thống (Quản trị, Trưởng nhóm, Nhân viên)');
    }
    if (!isset($allRoles[$key])) fail('Không tìm thấy vai trò này', 404);
    $rawSaved = setting_get('rbac.roles');
    $saved = is_string($rawSaved) ? jdecode($rawSaved, []) : [];
    unset($saved[$key]);
    $deleted = is_array($saved['__deleted'] ?? null) ? $saved['__deleted'] : [];
    if (!in_array($key, $deleted, true)) $deleted[] = $key;
    $saved['__deleted'] = $deleted;
    setting_set('rbac.roles', json_encode($saved, JSON_UNESCAPED_UNICODE), $me);
    // Chuyển người dùng đang giữ vai trò bị xoá về 'staff'
    $pdo->prepare("UPDATE users SET role='staff' WHERE role=?")->execute([$key]);
    log_activity('delete', 'role', $key, 'Xoá vai trò ' . ($allRoles[$key]['name'] ?? $key));
    respond(['ok' => true, 'roles' => rbac_role_labels(), 'rbac_roles' => rbac_roles()]);
}

/* ---- Tạo người dùng mới ---- */
if ($action === 'create') {
    if (!$canManageUsers) fail('Bạn không có quyền tạo người dùng mới', 403);
    $name = trim((string)($in['name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $role = (string)($in['role'] ?? 'staff');
    if ($name === '') fail('Nhập họ tên người dùng');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email đăng nhập không hợp lệ');
    if (!isset($allRoles[$role])) fail('Vai trò không hợp lệ');
    if ($role === 'admin' && $me['role'] !== 'admin') fail('Chỉ Quản trị viên mới được tạo tài khoản Quản trị', 403);

    $st = $pdo->prepare('SELECT 1 FROM users WHERE lower(email)=?'); $st->execute([$email]);
    if ($st->fetch()) fail('Email này đã được sử dụng cho một tài khoản khác');

    $pw = trim((string)($in['password'] ?? ''));
    $generated = false;
    if ($pw === '') { $pw = gen_password(); $generated = true; }
    if (strlen($pw) < 6) fail('Mật khẩu cần tối thiểu 6 ký tự');

    $customPerms = null;
    if (!empty($in['custom_permissions']) && is_array($in['custom_permissions'])) {
        $clean = sanitize_perms_matrix($in['custom_permissions']);
        if ($clean) $customPerms = json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    do { $id = gen_id('U'); $st = $pdo->prepare('SELECT 1 FROM users WHERE id=?'); $st->execute([$id]); } while ($st->fetch());
    $pdo->prepare('INSERT INTO users(id,name,email,password_hash,role,title,approve,active,phone,scope,co,br,permissions) VALUES (?,?,?,?,?,?,?,1,?,?,?,?,?)')
        ->execute([$id, $name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, trim((string)($in['title'] ?? '')),
            !empty($in['approve']) ? 1 : 0, trim((string)($in['phone'] ?? '')), trim((string)($in['scope'] ?? '')),
            (string)($in['co'] ?? ''), (string)($in['br'] ?? ''), $customPerms]);

    // Tự động thêm người dùng vào dự án hiện hành (hoặc danh sách dự án được chọn)
    $pRole = (string)($in['project_role'] ?? '');
    if (!isset(PROJECT_ROLES[$pRole])) $pRole = default_project_role_for_sysrole($role);
    $projIds = [];
    if (!empty($in['project_ids']) && is_array($in['project_ids'])) {
        $projIds = array_values(array_filter(array_map('strval', $in['project_ids'])));
    } else {
        $curP = current_project();
        if ($curP && !empty($curP['id'])) $projIds[] = $curP['id'];
        else {
            foreach ($pdo->query("SELECT id FROM projects WHERE status<>'archived'")->fetchAll() as $pr) $projIds[] = $pr['id'];
        }
    }
    $insMem = $pdo->prepare('INSERT OR REPLACE INTO project_members(project_id,user_id,role) VALUES (?,?,?)');
    foreach ($projIds as $pidItem) {
        try { $insMem->execute([$pidItem, $id, $pRole]); } catch (Throwable $e) {}
    }

    log_activity('create', 'user', $id, "Tạo người dùng $name (" . ($allRoles[$role]['name'] ?? $role) . ')');
    respond(['id' => $id, 'password' => $generated ? $pw : null], 201);
}

$target = find_user((string)($in['id'] ?? ''));

/* ---- Cập nhật người dùng ---- */
if ($action === 'update') {
    if ($canManageUsers) {
        $allowed = ['name', 'email', 'title', 'role', 'approve', 'phone', 'scope', 'co', 'br'];
    } elseif ($me['role'] === 'manager') {
        if ($target['role'] === 'admin') fail('Trưởng nhóm không sửa được tài khoản Quản trị', 403);
        $allowed = ['name', 'title', 'phone', 'scope', 'br'];
    } else {
        fail('Bạn không có quyền sửa thông tin người dùng', 403);
    }

    $set = []; $vals = [];
    foreach ($allowed as $f) {
        if (!array_key_exists($f, $in)) continue;
        $v = $in[$f];
        if ($f === 'name') { $v = trim((string)$v); if ($v === '') fail('Họ tên không được trống'); }
        if ($f === 'email') {
            $v = strtolower(trim((string)$v));
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) fail('Email không hợp lệ');
            $st = $pdo->prepare('SELECT 1 FROM users WHERE lower(email)=? AND id<>?'); $st->execute([$v, $target['id']]);
            if ($st->fetch()) fail('Email này đã có tài khoản khác dùng');
        }
        if ($f === 'role') {
            if (!isset($allRoles[$v])) fail('Vai trò không hợp lệ');
            if ($v === 'admin' && $me['role'] !== 'admin') fail('Chỉ Quản trị viên mới được gán vai trò Quản trị', 403);
            if ($target['role'] === 'admin' && $v !== 'admin' && (int)$target['active'] && active_admins($target['id']) === 0) {
                fail('Không thể hạ quyền quản trị viên cuối cùng');
            }
        }
        if ($f === 'approve') $v = !empty($v) ? 1 : 0;
        if (is_string($v)) $v = trim($v);
        $set[] = "$f=?"; $vals[] = $v;
    }

    if ($canManageUsers && array_key_exists('custom_permissions', $in)) {
        $cp = $in['custom_permissions'];
        if (empty($cp) || !is_array($cp)) {
            $set[] = 'permissions=NULL';
        } else {
            $clean = sanitize_perms_matrix($cp);
            $set[] = 'permissions=?';
            $vals[] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
        }
    }

    if ($canManageUsers && !empty($in['password'])) {
        $newPw = trim((string)$in['password']);
        if (strlen($newPw) < 6) fail('Mật khẩu mới cần tối thiểu 6 ký tự');
        $set[] = 'password_hash=?';
        $vals[] = password_hash($newPw, PASSWORD_DEFAULT);
    }

    if ($set) {
        $vals[] = $target['id'];
        $pdo->prepare('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals);
    }

    if ($canManageUsers && !empty($in['project_role']) && isset(PROJECT_ROLES[$in['project_role']])) {
        $curP = current_project();
        if ($curP && !empty($curP['id'])) {
            $pdo->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES (?,?,?) ON CONFLICT(project_id,user_id) DO UPDATE SET role=excluded.role')
                ->execute([$curP['id'], $target['id'], $in['project_role']]);
        }
    }

    log_activity('update', 'user', $target['id'], 'Cập nhật người dùng ' . ($in['name'] ?? $target['name']));
    respond(['ok' => true]);
}

/* ---- Xoá người dùng ---- */
if ($action === 'delete') {
    if ($me['role'] !== 'admin' && !has_perm('settings', 'delete', $me) && !has_perm('staff', 'delete', $me)) {
        fail('Bạn không có quyền xoá người dùng', 403);
    }
    if ($target['id'] === $me['id']) fail('Không thể tự xoá tài khoản của chính mình');
    if ($target['role'] === 'admin' && active_admins($target['id']) === 0) fail('Không thể xoá quản trị viên cuối cùng');

    // Gỡ liên kết khoá ngoại trước khi xoá để bảo toàn lịch sử dữ liệu
    $fkNulls = [
        ['campaigns', 'owner_id'],
        ['content_items', 'owner_id'],
        ['tasks', 'assignee_id'],
        ['tasks', 'assigner_id'],
        ['okrs', 'owner_id'],
        ['connections', 'created_by'],
        ['posts', 'created_by'],
        ['posts', 'approved_by'],
        ['comments', 'assigned_to'],
        ['automations', 'created_by'],
        ['projects', 'created_by'],
        ['ideas', 'created_by'],
        ['landing_pages', 'created_by'],
        ['inbox_threads', 'assigned_to'],
        ['ref_channels', 'created_by'],
        ['hl_jobs', 'created_by'],
        ['activity_log', 'user_id'],
        ['notifications', 'user_id'],
        ['app_settings', 'updated_by'],
    ];
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        foreach ($fkNulls as [$tbl, $col]) {
            try { $pdo->prepare("UPDATE $tbl SET $col=NULL WHERE $col=?")->execute([$target['id']]); } catch (Throwable $e) {}
        }
        $pdo->prepare('DELETE FROM project_members WHERE user_id=?')->execute([$target['id']]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$target['id']]);
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    log_activity('delete', 'user', $target['id'], 'Xoá tài khoản ' . $target['name'] . ' (' . $target['email'] . ')');
    respond(['ok' => true]);
}

if ($action === 'lock' || $action === 'unlock') {
    if (!$canManageUsers) fail('Bạn không có quyền khoá / mở khoá tài khoản', 403);
    if ($action === 'lock') {
        if ($target['id'] === $me['id']) fail('Không thể tự khoá tài khoản của chính mình');
        if ($target['role'] === 'admin' && active_admins($target['id']) === 0) fail('Không thể khoá quản trị viên cuối cùng');
    }
    $pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$action === 'lock' ? 0 : 1, $target['id']]);
    log_activity($action, 'user', $target['id'], ($action === 'lock' ? 'Khoá' : 'Mở khoá') . ' tài khoản ' . $target['name']);
    respond(['ok' => true]);
}

if ($action === 'set_password') {
    if (!$canManageUsers) fail('Bạn không có quyền đổi mật khẩu người dùng', 403);
    $pw = trim((string)($in['password'] ?? ''));
    if (strlen($pw) < 6) fail('Mật khẩu mới cần tối thiểu 6 ký tự');
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
    log_activity('reset_password', 'user', $target['id'], 'Đổi mật khẩu trực tiếp cho ' . $target['name']);
    respond(['ok' => true, 'password' => $pw]);
}

if ($action === 'reset_password') {
    if (!$canManageUsers) fail('Bạn không có quyền đặt lại mật khẩu', 403);
    $pw = gen_password();
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
    log_activity('reset_password', 'user', $target['id'], 'Đặt lại mật khẩu cho ' . $target['name']);
    respond(['ok' => true, 'password' => $pw]);
}

fail('action không hợp lệ', 404);