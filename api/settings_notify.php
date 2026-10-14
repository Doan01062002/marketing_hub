<?php
/* Cấu hình gửi tin: SMTP, webhook mặc định, giờ yên lặng, hotline.
   GET                              → {settings}  (mật khẩu SMTP chỉ trả dạng che)   [quản trị, trưởng nhóm xem]
   POST {smtp.host: …, …}            → lưu (smtp.password để trống = giữ nguyên)       [quản trị]
   POST ?action=test_email {to}      → gửi email thử
   POST ?action=test_webhook {url?}  → gửi webhook thử (mặc định webhook.default_url) */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_mail_lib.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

const NOTIFY_KEYS = ['smtp.host', 'smtp.port', 'smtp.secure', 'smtp.user', 'smtp.password', 'smtp.from_name', 'smtp.from_email',
    'webhook.default_url', 'notify.quiet_hours', 'brand.hotline', 'telegram.bot_token', 'telegram.chat_id', 'telegram.mirror'];

function notify_is_secret(string $k): bool { return $k === 'smtp.password' || setting_is_secret($k); }

function notify_settings_public(): array {
    global $pdo;
    $rows = $pdo->query("SELECT key,value,updated_at FROM app_settings WHERE key IN ('" . implode("','", NOTIFY_KEYS) . "')")->fetchAll();
    $map = array_column($rows, 'value', 'key');
    $out = [];
    foreach (NOTIFY_KEYS as $k) {
        $v = $map[$k] ?? '';
        $out[$k] = notify_is_secret($k) ? '' : (string)$v;
        if (notify_is_secret($k)) { $out[$k . '_set'] = $v !== '' && $v !== null; $out[$k . '_hint'] = ($v !== '' && $v !== null) ? '••••••••' : ''; }
    }
    return $out;
}

if ($method === 'GET') {
    require_role('admin', 'manager');
    respond(['settings' => notify_settings_public(), 'smtp_ready' => mail_configured(), 'in_quiet' => in_quiet_hours(), 'can_edit' => $me['role'] === 'admin']);
}

if ($method === 'POST') {
    $me = require_role('admin');
    $in = json_input();
    $action = $_GET['action'] ?? '';

    if ($action === 'test_email') {
        $to = trim((string)($in['to'] ?? '')) ?: $me['email'];
        $r = mail_send($to, 'Email thử từ Marketing Hub', "Xin chào,\n\nĐây là email thử gửi lúc " . date('H:i d/m/Y') . ". Nếu bạn nhận được, cấu hình SMTP đã đúng.\n\nPhòng Marketing");
        log_activity('update', 'settings', 'smtp', 'Gửi email thử tới ' . $to . ($r['ok'] ? ' — thành công' : ' — lỗi'));
        respond(['ok' => $r['ok'], 'error' => $r['error'], 'to' => $to]);
    }
    if ($action === 'test_telegram') {
        $r = telegram_send('✅ Tin thử từ Marketing Hub lúc ' . date('H:i d/m/Y') . ' — Telegram đã kết nối.');
        log_activity('update', 'settings', 'telegram', 'Gửi Telegram thử' . ($r['ok'] ? ' — thành công' : ' — lỗi'));
        respond(['ok' => $r['ok'], 'error' => $r['error']]);
    }
    if ($action === 'telegram_chats') { // lấy chat ID từ tin gần nhất gửi tới bot (người dùng nhắn bot / thêm bot vào nhóm trước)
        $tok = (string)setting_get('telegram.bot_token', '');
        if ($tok === '') fail('Lưu bot token trước');
        [$c, $d] = http_call('GET', 'https://api.telegram.org/bot' . $tok . '/getUpdates');
        if ($c !== 200) fail('Telegram: ' . ($d['description'] ?? "HTTP $c"), 424);
        $chats = [];
        foreach ($d['result'] ?? [] as $u) { $ch = $u['message']['chat'] ?? $u['my_chat_member']['chat'] ?? $u['channel_post']['chat'] ?? null; if ($ch) $chats[(string)$ch['id']] = ['id' => (string)$ch['id'], 'name' => $ch['title'] ?? trim(($ch['first_name'] ?? '') . ' ' . ($ch['last_name'] ?? '')), 'type' => $ch['type']]; }
        respond(['chats' => array_values($chats)]);
    }
    if ($action === 'test_webhook') {
        $url = trim((string)($in['url'] ?? ''));
        $r = webhook_send($url ?: null, 'Tin thử từ Marketing Hub lúc ' . date('H:i d/m/Y') . ' — webhook hoạt động.', ['test' => true]);
        log_activity('update', 'settings', 'webhook', 'Gửi webhook thử' . ($r['ok'] ? ' — thành công' : ' — lỗi'));
        respond(['ok' => $r['ok'], 'error' => $r['error'], 'code' => $r['code'] ?? null]);
    }

    $changed = [];
    foreach (NOTIFY_KEYS as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string)$in[$k]);
        if (notify_is_secret($k) && $v === '') continue; // để trống = giữ nguyên bí mật cũ
        if ($k === 'smtp.port' && $v !== '' && (!ctype_digit($v) || (int)$v < 1 || (int)$v > 65535)) fail('Cổng SMTP không hợp lệ');
        if ($k === 'smtp.secure' && $v !== '' && !in_array($v, ['ssl', 'tls', 'none'], true)) fail('Kiểu bảo mật SMTP chỉ là ssl, tls hoặc none');
        if ($k === 'smtp.from_email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) fail('Email người gửi không hợp lệ');
        if ($k === 'webhook.default_url' && $v !== '' && !preg_match('~^https?://~i', $v)) fail('Webhook phải bắt đầu bằng http(s)://');
        if ($k === 'notify.quiet_hours' && $v !== '' && !preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $v)) fail('Giờ yên lặng dạng HH:MM-HH:MM, ví dụ 22:00-07:00');
        setting_set($k, $v === '' ? null : $v, $me);
        $changed[] = $k;
    }
    if (!empty($in['clear_password'])) { setting_set('smtp.password', null, $me); $changed[] = 'smtp.password'; }
    log_activity('update', 'settings', 'notify', 'Cập nhật cấu hình gửi tin: ' . (implode(', ', $changed) ?: 'không đổi'));
    respond(['ok' => true, 'settings' => notify_settings_public(), 'smtp_ready' => mail_configured()]);
}

fail('Method không hỗ trợ', 405);
