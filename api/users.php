<?php
/* Quản lý tài khoản nhân viên + thống kê hiệu suất làm việc.
   GET                         → danh sách (admin/manager: đầy đủ; staff: chỉ bản thân)
   GET  ?action=stats&period=7|30|90[&user_id=] → thống kê công việc theo người
   POST {action:'create', …}   → admin tạo tài khoản
   POST {action:'update', id,…}→ admin; manager chỉ sửa nhân viên (staff) cùng công ty
   POST {action:'lock'|'unlock', id}      → admin
   POST {action:'reset_password', id}     → admin, trả mật khẩu tạm 1 lần */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$isMgr = in_array($me['role'], ['admin', 'manager'], true);

/** Cột "hoàn thành" của từng bảng kanban (khớp BOARDS ở index.html). */
const DONE_COLS = ['content' => 'PUBLISHED', 'design' => 'DONE', 'ads' => 'REPORT', 'research' => 'INSIGHT', 'lead' => 'ACCEPTED'];

function public_user(array $r, bool $full): array {
    $o = [
        'id' => $r['id'], 'name' => $r['name'], 'title' => $r['title'] ?? '', 'role' => $r['role'],
        'approve' => (int)$r['approve'] === 1, 'active' => (int)$r['active'] === 1,
        'scope' => $r['scope'] ?? '', 'co' => $r['co'] ?? '', 'br' => $r['br'] ?? '',
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
    if (!$u) fail('Không tìm thấy nhân viên', 404);
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

/* ------------------------------ GET ------------------------------ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'stats') {
        $period = (int)($_GET['period'] ?? 30);
        if (!in_array($period, [7, 30, 90], true)) $period = 30;
        $since = gmdate('Y-m-d H:i:s', time() - $period * 86400); // created_at lưu theo datetime('now') = UTC
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

    if ($isMgr) {
        $rows = $pdo->query('SELECT * FROM users ORDER BY active DESC, id')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$me['id']]);
        $rows = $st->fetchAll();
    }
    respond(['users' => array_map(fn($r) => public_user($r, true), $rows), 'roles' => ROLES]);
}

if ($method !== 'POST') fail('Method không hỗ trợ', 405);

/* ------------------------------ POST ------------------------------ */
$in = json_input();
$action = $in['action'] ?? (!empty($in['id']) ? 'update' : 'create');

if ($action === 'create') {
    require_role('admin');
    $name = trim((string)($in['name'] ?? ''));
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $role = (string)($in['role'] ?? 'staff');
    if ($name === '') fail('Nhập họ tên nhân viên');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email không hợp lệ');
    if (!isset(ROLES[$role])) fail('Vai trò không hợp lệ');
    $st = $pdo->prepare('SELECT 1 FROM users WHERE lower(email)=?'); $st->execute([$email]);
    if ($st->fetch()) fail('Email này đã có tài khoản');
    $pw = (string)($in['password'] ?? '');
    $generated = false;
    if ($pw === '') { $pw = gen_password(); $generated = true; }
    if (strlen($pw) < 8) fail('Mật khẩu ban đầu cần tối thiểu 8 ký tự');

    do { $id = gen_id('U'); $st = $pdo->prepare('SELECT 1 FROM users WHERE id=?'); $st->execute([$id]); } while ($st->fetch());
    $pdo->prepare('INSERT INTO users(id,name,email,password_hash,role,title,approve,active,phone,scope,co,br) VALUES (?,?,?,?,?,?,?,1,?,?,?,?)')
        ->execute([$id, $name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, trim((string)($in['title'] ?? '')),
            !empty($in['approve']) ? 1 : 0, trim((string)($in['phone'] ?? '')), trim((string)($in['scope'] ?? '')),
            (string)($in['co'] ?? ''), (string)($in['br'] ?? '')]);
    log_activity('create', 'user', $id, "Tạo tài khoản $name (" . ROLES[$role] . ')');
    respond(['id' => $id, 'password' => $generated ? $pw : null], 201);
}

$target = find_user((string)($in['id'] ?? ''));

if ($action === 'update') {
    if ($me['role'] === 'manager') {
        if ($target['role'] !== 'staff' || !$me['co'] || $target['co'] !== $me['co']) {
            fail('Trưởng nhóm chỉ sửa được nhân viên thuộc công ty mình', 403);
        }
        $allowed = ['name', 'title', 'phone', 'scope', 'br'];
    } elseif ($me['role'] === 'admin') {
        $allowed = ['name', 'email', 'title', 'role', 'approve', 'phone', 'scope', 'co', 'br'];
    } else {
        fail('Bạn không có quyền sửa thông tin nhân viên', 403);
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
            if (!isset(ROLES[$v])) fail('Vai trò không hợp lệ');
            if ($target['role'] === 'admin' && $v !== 'admin' && (int)$target['active'] && active_admins($target['id']) === 0) {
                fail('Không thể hạ quyền quản trị viên cuối cùng');
            }
        }
        if ($f === 'approve') $v = !empty($v) ? 1 : 0;
        if (is_string($v)) $v = trim($v);
        $set[] = "$f=?"; $vals[] = $v;
    }
    if (!$set) fail('Không có gì để cập nhật');
    $vals[] = $target['id'];
    $pdo->prepare('UPDATE users SET ' . implode(',', $set) . ' WHERE id=?')->execute($vals);
    log_activity('update', 'user', $target['id'], 'Sửa thông tin ' . ($in['name'] ?? $target['name']));
    respond(['ok' => true]);
}

if ($action === 'lock' || $action === 'unlock') {
    require_role('admin');
    if ($action === 'lock') {
        if ($target['id'] === $me['id']) fail('Không thể tự khoá tài khoản của chính mình');
        if ($target['role'] === 'admin' && active_admins($target['id']) === 0) fail('Không thể khoá quản trị viên cuối cùng');
    }
    $pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$action === 'lock' ? 0 : 1, $target['id']]);
    log_activity($action, 'user', $target['id'], ($action === 'lock' ? 'Khoá' : 'Mở khoá') . ' tài khoản ' . $target['name']);
    respond(['ok' => true]);
}

if ($action === 'reset_password') {
    require_role('admin');
    $pw = gen_password();
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
    log_activity('reset_password', 'user', $target['id'], 'Đặt lại mật khẩu cho ' . $target['name']);
    respond(['ok' => true, 'password' => $pw]);
}

fail('action không hợp lệ', 404);
