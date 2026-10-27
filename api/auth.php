<?php
require __DIR__ . '/_boot.php';

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    require_method('GET');
    $u = current_user();
    session_write_close();
    respond(['user' => $u]);
}

if ($action === 'login') {
    require_method('POST');
    $in = json_input();
    $rawEmail = trim((string)($in['email'] ?? ''));
    $email = mb_strtolower($rawEmail);
    $rawPass = (string)($in['password'] ?? '');
    $password = trim($rawPass);
    if ($email === '' || $password === '') {
        fail('Vui lòng nhập đầy đủ Email và Mật khẩu');
    }

    // 1. Tìm chính xác theo email (không phân biệt hoa/thường)
    $st = $pdo->prepare('SELECT * FROM users WHERE lower(trim(email)) = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    // 2. Hỗ trợ tự động ánh xạ tên miền @chunggroup.vn <-> @chungauto.vn (tránh nhầm lẫn tên miền web mkt.chunggroup.vn)
    if (!$u && str_contains($email, '@')) {
        [$localPart, $domainPart] = explode('@', $email, 2);
        $altEmail = null;
        if ($domainPart === 'chunggroup.vn') {
            $altEmail = $localPart . '@chungauto.vn';
        } elseif ($domainPart === 'chungauto.vn') {
            $altEmail = $localPart . '@chunggroup.vn';
        }
        if ($altEmail) {
            $st->execute([$altEmail]);
            $u = $st->fetch();
        }
        // Nếu vẫn chưa thấy, thử khớp theo tiền tố trước @ nếu chỉ có duy nhất 1 tài khoản trùng tiền tố
        if (!$u && $localPart !== '') {
            $stLocal = $pdo->prepare("SELECT * FROM users WHERE lower( substr(trim(email), 1, instr(trim(email), '@') - 1) ) = ?");
            $stLocal->execute([$localPart]);
            $matches = $stLocal->fetchAll();
            if (count($matches) === 1) {
                $u = $matches[0];
            }
        }
    }

    // 3. Hỗ trợ đăng nhập bằng tên tài khoản (trước @) hoặc số điện thoại nếu người dùng nhập không có @
    if (!$u && !str_contains($email, '@')) {
        $st2 = $pdo->prepare("SELECT * FROM users WHERE lower( substr(trim(email), 1, instr(trim(email), '@') - 1) ) = ? OR trim(phone) = ?");
        $st2->execute([$email, $rawEmail]);
        $matches = $st2->fetchAll();
        if (count($matches) === 1) {
            $u = $matches[0];
        }
    }

    if (!$u) {
        log_activity('login_failed', 'user', null, 'Đăng nhập thất bại: không tìm thấy tài khoản "' . $rawEmail . '"');
        fail('Tài khoản "' . $rawEmail . '" không tồn tại trong hệ thống. Vui lòng kiểm tra lại email (VD: thu@chungauto.vn hoặc admin@chungauto.vn).', 401);
    }

    if (!password_verify($password, $u['password_hash']) && !password_verify($rawPass, $u['password_hash'])) {
        log_activity('login_failed', 'user', $u['id'], 'Đăng nhập thất bại: sai mật khẩu cho ' . $u['email']);
        fail('Mật khẩu không chính xác cho tài khoản "' . $u['email'] . '". Vui lòng bấm biểu tượng con mắt 👁️ để kiểm tra lại mật khẩu hoặc nhờ Quản trị viên đặt lại.', 401);
    }

    if (!(int)$u['active']) {
        fail('Tài khoản của bạn đã bị khoá. Liên hệ quản trị viên phòng Marketing để mở lại.', 403);
    }

    // Không dùng session_regenerate_id(true) để tránh xoá phiên của các tab/request đang chạy song song
    $_SESSION['uid'] = $u['id'];
    session_write_close();

    $pdo->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=?")->execute([$u['id']]);
    log_activity('login', 'user', $u['id'], 'Đăng nhập (' . $rawEmail . ')', $u);
    unset($u['password_hash']);
    respond(['user' => $u]);
}

if ($action === 'logout') {
    require_method('POST');
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
    respond(['ok' => true]);
}

if ($action === 'change_password') {
    require_method('POST');
    $u = require_login();
    session_write_close();
    $in = json_input();
    $cur = trim((string)($in['current'] ?? ''));
    $new = trim((string)($in['new'] ?? ''));
    if ($cur === '') fail('Vui lòng nhập mật khẩu hiện tại');
    if (strlen($new) < 6) fail('Mật khẩu mới cần tối thiểu 6 ký tự');
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!password_verify($cur, $row['password_hash']) && !password_verify((string)($in['current'] ?? ''), $row['password_hash'])) {
        fail('Mật khẩu hiện tại không đúng', 401);
    }
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    log_activity('change_password', 'user', $u['id'], 'Đổi mật khẩu cá nhân');
    respond(['ok' => true]);
}

fail('action không hợp lệ', 404);