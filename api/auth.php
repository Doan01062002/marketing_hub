<?php
require __DIR__ . '/_boot.php';

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    require_method('GET');
    $u = current_user();
    respond(['user' => $u]);
}

if ($action === 'login') {
    require_method('POST');
    $in = json_input();
    $email = trim((string)($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');
    if ($email === '' || $password === '') fail('Thiếu email hoặc mật khẩu');

    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        fail('Email hoặc mật khẩu không đúng', 401);
    }
    if (!(int)$u['active']) fail('Tài khoản của bạn đã bị khoá. Liên hệ quản trị viên phòng Marketing để mở lại.', 403);
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    $pdo->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=?")->execute([$u['id']]);
    log_activity('login', 'user', $u['id'], 'Đăng nhập', $u);
    unset($u['password_hash']);
    respond(['user' => $u]);
}

if ($action === 'logout') {
    require_method('POST');
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true]);
}

if ($action === 'change_password') {
    require_method('POST');
    $u = require_login();
    $in = json_input();
    $cur = (string)($in['current'] ?? '');
    $new = (string)($in['new'] ?? '');
    if (strlen($new) < 8) fail('Mật khẩu mới cần tối thiểu 8 ký tự');
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!password_verify($cur, $row['password_hash'])) fail('Mật khẩu hiện tại không đúng', 401);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    log_activity('change_password', 'user', $u['id'], 'Đổi mật khẩu');
    respond(['ok' => true]);
}

fail('action không hợp lệ', 404);
