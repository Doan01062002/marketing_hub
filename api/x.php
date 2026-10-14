<?php
/* X (Twitter) — nhiều dự án, nhiều tài khoản, một app X Developer dùng chung. 12/10/2026
   GET                       → {app:{configured, key_hint:'…abcd', redirect_uri, scopes:[…], can_edit_keys}, accounts:[…] của dự án, can_manage, note}
   POST {action:'app', client_id?, client_secret?, clear?:[…]}  → admin hệ thống lưu khoá app (clear: ['client_id','client_secret'])
   POST {action:'connect', label?}        → tạo tài khoản kênh mới trong dự án + trả start_url (chủ/quản lý dự án)
   POST {action:'reconnect', id}          → start_url cho tài khoản đã có (chủ/quản lý)
   POST {action:'check', id}              → gọi GET /2/users/me thật: {ok, username, name, followers, tweets, error?} (không tạo dữ liệu giả)
   Ngắt kết nối / xoá / đổi tên / chuyển dự án: dùng api/connections.php (disconnect, delete, rename, set_project). */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$PID = pid();
$app = x_app($CFG);

function x_public(array $CFG, array $r): array {
    $p = public_connection($r);
    $p['token_valid'] = (bool)($r['expires_at'] && strtotime($r['expires_at']) > time() && $r['access_token']);
    $p['has_refresh'] = !empty($r['refresh_token']);
    $p['encrypted'] = str_starts_with((string)$r['access_token'], 'enc1:');
    return $p;
}

if ($method === 'GET') {
    $st = $pdo->prepare("SELECT * FROM connections WHERE project_id=? AND provider='x' ORDER BY created_at"); $st->execute([$PID]);
    $accounts = array_map(fn($r) => x_public($CFG, $r), $st->fetchAll());
    respond(['app' => [
            'configured' => $app['configured'],
            'key_hint' => $app['key_last4'] !== '' ? '…' . $app['key_last4'] : '',
            'redirect_uri' => $app['redirect_uri'],
            'scopes' => $app['scopes'],
            'can_edit_keys' => $me['role'] === 'admin',
            'token_encryption' => (bool)tok_key($CFG),
        ], 'accounts' => $accounts, 'can_manage' => in_array(project_role(), PROJECT_ADMIN_ROLES, true),
        'note' => 'App X cần bật User authentication settings: OAuth 2.0, App permissions "Read and write", Callback URI đúng như trên. '
            . 'Gói Free của X API chỉ cho đăng ~500 bài/tháng và không đọc được bài của người khác; token sống 2 giờ, tự làm mới bằng refresh token.']);
}

require_method('POST');
$in = json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'app') {
    require_role('admin');
    $map = ['client_id' => 'x.client_id', 'client_secret' => 'x.client_secret'];
    foreach ($map as $k => $sk) { $v = preg_replace('/\s+/', '', (string)($in[$k] ?? '')); if ($v !== '') setting_set($sk, $v, $me); if (in_array($k, (array)($in['clear'] ?? []), true)) setting_set($sk, null, $me); }
    log_activity('update', 'settings', 'x', 'Cập nhật cấu hình app X (' . implode(', ', array_keys(array_filter($in, fn($v, $k) => $k !== 'action' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH))) . ')', $me);
    respond(['ok' => true]);
}

require_project_admin();

if ($action === 'connect') {
    if (!$app['configured']) fail('Chưa có Client ID / Client Secret của app X');
    $label = trim((string)($in['label'] ?? '')) ?: ('X ' . date('d/m H:i'));
    $id = gen_id('CH');
    $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,env) VALUES (?,?,'x',?,'disconnected','oauth','production')")->execute([$id, $PID, $label]);
    log_activity('create', 'connection', $id, 'Thêm tài khoản X ' . $label);
    respond(['id' => $id, 'start_url' => rtrim($CFG['app_url'], '/') . '/api/oauth_x_start.php?conn_id=' . rawurlencode($id)], 201);
}

$conn = get_connection((string)($in['id'] ?? ''));
if (!$conn || $conn['provider'] !== 'x') fail('Không tìm thấy tài khoản X trong dự án này', 404);

if ($action === 'reconnect') {
    if (!$app['configured']) fail('Chưa có Client ID / Client Secret của app X');
    respond(['start_url' => rtrim($CFG['app_url'], '/') . '/api/oauth_x_start.php?conn_id=' . rawurlencode($conn['id'])]);
}

if ($action === 'check') {
    [$tok, $terr] = x_access_token($CFG, $conn);
    if (!$tok) respond(['ok' => false, 'error' => $terr ?? 'Token X hết hạn — kết nối lại']);
    [$c, $d] = http_get_json(X_API_BASE . '/2/users/me?user.fields=profile_image_url,public_metrics,username,name', ['Authorization: Bearer ' . $tok]);
    $e = x_error($c, $d);
    if ($e !== null || empty($d['data']['id'])) respond(['ok' => false, 'error' => 'X trả lời: ' . ($e ?? 'không đọc được tài khoản')
        . ($c === 401 ? ' — token không còn hiệu lực, kết nối lại' : ($c === 403 ? ' — app X chưa được cấp quyền users.read hoặc bị hạn chế' : ''))]);
    $u = $d['data']; $pm = $u['public_metrics'] ?? [];
    $username = (string)($u['username'] ?? '');
    $avatar = str_replace('_normal.', '_400x400.', (string)($u['profile_image_url'] ?? ''));
    if ($username !== '') $pdo->prepare("UPDATE connections SET account_name=?, avatar=COALESCE(NULLIF(?, ''), avatar), last_error=NULL WHERE id=?")->execute([$username, $avatar, $conn['id']]);
    respond(['ok' => true, 'username' => $username, 'name' => (string)($u['name'] ?? ''), 'user_id' => (string)$u['id'],
        'followers' => (int)($pm['followers_count'] ?? 0), 'following' => (int)($pm['following_count'] ?? 0),
        'tweets' => (int)($pm['tweet_count'] ?? $pm['post_count'] ?? 0), 'likes' => (int)($pm['like_count'] ?? 0), 'avatar' => $avatar ?: null]);
}

fail('Thao tác không hỗ trợ');
