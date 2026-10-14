<?php
/* Bắt đầu OAuth TikTok cho MỘT tài khoản kênh (connections.id) thuộc dự án hiện hành. 11/10/2026
   Một app TikTok Developer dùng chung (tiktok_app()); mỗi tài khoản TikTok = một kênh, token riêng, thuộc đúng dự án.
   Quyền: chủ / quản lý dự án (hoặc admin hệ thống). Phiên giữ state + conn_id + project_id để callback đối chiếu. */
require __DIR__ . '/_boot.php';
if (!current_user()) { header('Location: ' . rtrim($CFG['app_url'], '/') . '/'); exit; }
$_SESSION['oauth_popup'] = !empty($_GET['popup']); // mở trong cửa sổ bật lên
$pr = current_project();
if (!$pr || !in_array($pr['member_role'] ?? '', PROJECT_ADMIN_ROLES, true)) back_to_app($CFG, ['conn_error' => 'Chỉ chủ / quản lý dự án được kết nối tài khoản TikTok'], 'channels/api');

$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'tiktok') back_to_app($CFG, ['conn_error' => 'Không tìm thấy tài khoản TikTok cần kết nối trong dự án này'], 'channels/api');

$app = tiktok_app($CFG, $pr['id']);
if (!$app['configured']) back_to_app($CFG, ['conn_error' => 'Chưa có Client Key / Client Secret cho môi trường ' . $app['env'] . ' — quản trị nhập ở Kênh marketing → TikTok → Kết nối API'], 'channels/api');
if (!str_starts_with($app['redirect_uri'], 'https://') && str_starts_with($CFG['tiktok_auth_base'], 'https://www.tiktok.com')) {
    back_to_app($CFG, ['conn_error' => 'TikTok chỉ nhận Redirect URI https — app_url phải là https'], 'channels/api');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_conn_id'] = $conn['id'];
$_SESSION['oauth_project_id'] = $pr['id'];
$_SESSION['oauth_tiktok_env'] = $app['env'];
log_activity('update', 'connection', $conn['id'], 'Bắt đầu kết nối TikTok (' . $app['env'] . ') cho tài khoản ' . $conn['label']);

header('Location: ' . rtrim($CFG['tiktok_auth_base'], '/') . '/v2/auth/authorize/?' . http_build_query([
    'client_key' => $app['client_key'],
    'scope' => implode(',', $app['scopes']),
    'response_type' => 'code',
    'redirect_uri' => $app['redirect_uri'],
    'state' => $state,
]));
exit;
