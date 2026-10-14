<?php
/* Zalo cá nhân (nội bộ) + AI Chat.
   GET                         → {bridge (trạng thái/QR), connection, settings, counts}
   POST {action:'login'}       → tạo mã QR (chủ/quản lý dự án)        POST {action:'logout'}
   POST {action:'test', text} (gửi cho chính mình) */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_zalo_user_lib.php';
$u = require_login();
$P = pid();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['ai'])) { // cấu hình AI Chat + trạng thái máy AI + gợi ý người (hội thoại Zalo gần đây)
        require_once __DIR__ . '/_zalo_ai_lib.php'; require_once __DIR__ . '/_inbox_lib.php';
        $th = $pdo->prepare("SELECT customer_id uid, customer_name name, customer_avatar avatar, last_time FROM inbox_threads WHERE provider='zalo_user' AND project_id=? ORDER BY last_time DESC LIMIT 50"); $th->execute([$P]);
        $av = ai_available();
        respond(['ai' => zu_ai_cfg(), 'sales_default' => zu_ai_sales_default(), 'recent' => $th->fetchAll(), 'engine' => $av['ok'] ? ['via' => 'api', 'label' => 'Khoá API ' . ($av['provider'] ?? '')] : (ib_hl_alive() ? ['via' => 'hl', 'label' => 'Máy HL (Claude Max) đang kết nối'] : ['via' => 'none', 'label' => 'Chưa có máy AI — máy HL chưa kết nối, chưa có khoá API']),
            'can_admin' => in_array(project_role(), PROJECT_ADMIN_ROLES, true)]);
    }
    if (!empty($_GET['friends'])) {
        [$c, $b] = zu_bridge('GET', '/friends', null, 60);
        if ($c !== 200) fail($b['error'] ?? 'Zalo chưa đăng nhập', 424);
        respond(['friends' => array_map(fn($f) => ['uid' => $f['uid'], 'name' => $f['name'], 'avatar' => $f['avatar']], $b['friends'] ?? [])]);
    }
    [$c, $b] = zu_bridge('GET', '/status', null, 8);
    $bound = (string)setting_get('zalo_user.project', '');
    respond(['bridge' => $c === 200 ? $b : ['state' => 'down', 'error' => $b['error'] ?? 'Dịch vụ Zalo không chạy'],
        'bound_project' => $bound, 'this_project' => $bound === $P, 'connection' => zu_connection($P) ? public_connection(zu_connection($P)) : null,
        'can_admin' => in_array(project_role(), PROJECT_ADMIN_ROLES, true)]);
}

require_method('POST');
$in = json_input();
$a = (string)($in['action'] ?? '');

if ($a === 'ai_check') { // thử: tin này từ người này thì AI có trả lời không
    require_once __DIR__ . '/_zalo_ai_lib.php';
    $uid = preg_replace('/\D/', '', (string)($in['uid'] ?? ''));
    [$ok, $why] = zu_ai_check($uid, (string)($in['text'] ?? ''), $uid !== '' ? 'zalo_user:' . $uid : null);
    respond(['ok' => $ok, 'reason' => $why]);
}
if ($a === 'ai_find') { // tìm người Zalo theo số điện thoại (khi danh sách bạn bè chưa tải được)
    require_project_write();
    $ph = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
    if (strlen($ph) < 9) fail('Nhập số điện thoại đủ 10 số');
    if (str_starts_with($ph, '84')) $ph = '0' . substr($ph, 2);
    [$c, $b] = zu_bridge('POST', '/find', ['phones' => [$ph]], 30);
    if ($c !== 200) fail($b['error'] ?? 'Zalo chưa đăng nhập', 424);
    $f = $b['users'][$ph] ?? null;
    if (!$f) fail('Không tìm thấy tài khoản Zalo của số ' . $ph . ' (hoặc người đó chặn tìm bằng số điện thoại)', 404);
    respond(['user' => ['uid' => $f['uid'], 'name' => $f['name'], 'avatar' => $f['avatar'] ?? '']]);
}
if ($a === 'ai_person') { // bật/tắt AI cho một người (từ khung chat)
    require_project_write();
    require_once __DIR__ . '/_zalo_ai_lib.php';
    $c = zu_ai_cfg(); $uid = preg_replace('/\D/', '', (string)($in['uid'] ?? '')); if ($uid === '') fail('Thiếu người');
    $on = !empty($in['on']); $name = mb_substr((string)($in['name'] ?? ''), 0, 80);
    $rm = fn($l) => array_values(array_filter($l, fn($p) => $p['uid'] !== $uid));
    $c['people'] = $rm($c['people']); $c['block'] = $rm($c['block']);
    if ($c['scope'] === 'all') { if (!$on) $c['block'][] = ['uid' => $uid, 'name' => $name]; }
    elseif ($on) $c['people'][] = ['uid' => $uid, 'name' => $name];
    $c = zu_ai_save(['people' => $c['people'], 'block' => $c['block']]);
    log_activity('update', 'zalo_ai', 'zalo_user:' . $uid, ($on ? 'Bật' : 'Tắt') . ' AI Zalo cho ' . $name, $u);
    respond(['ok' => true, 'allowed' => zu_ai_person_allowed($uid, $c)]);
}

require_project_admin();

if ($a === 'ai_save') {
    require_once __DIR__ . '/_zalo_ai_lib.php';
    $c = zu_ai_save((array)($in['ai'] ?? []));
    log_activity('update', 'zalo_ai', null, 'Lưu cấu hình AI Chat Zalo (' . ($c['enabled'] ? 'BẬT' : 'tắt') . ', ' . ($c['scope'] === 'all' ? 'mọi người' : count($c['people']) . ' người') . ')', $u);
    respond(['ai' => $c]);
}

if ($a === 'login') {
    $bound = (string)setting_get('zalo_user.project', '');
    if ($bound !== '' && $bound !== $P) {
        [$c, $b] = zu_bridge('GET', '/status', null, 8);
        if (($b['state'] ?? '') === 'online') fail('Số Zalo đang gắn với dự án khác — đăng xuất ở dự án đó trước', 409);
    }
    setting_set('zalo_user.project', $P, $u);
    [$c, $b] = zu_bridge('POST', '/login', [], 20);
    if ($c !== 200) fail($b['error'] ?? 'Dịch vụ Zalo không chạy', 424);
    respond(['bridge' => $b]);
}
if ($a === 'logout') {
    zu_bridge('POST', '/logout', [], 10);
    $pdo->prepare("UPDATE connections SET status='disconnected' WHERE provider='zalo_user' AND project_id=?")->execute([$P]);
    log_activity('update', 'zalo_user', null, 'Đăng xuất Zalo cá nhân', $u);
    respond(['ok' => true]);
}
if ($a === 'test') {
    [$c, $b] = zu_bridge('GET', '/status', null, 8);
    if (($b['state'] ?? '') !== 'online') fail('Zalo chưa đăng nhập', 409);
    $r = zu_send((string)$b['uid'], trim((string)($in['text'] ?? '')) ?: 'Tin thử từ Marketing Hub lúc ' . date('H:i d/m'));
    $r['ok'] ? respond(['ok' => true]) : fail($r['error'], 424);
}
fail('action không hợp lệ');
