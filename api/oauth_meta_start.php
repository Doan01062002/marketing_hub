<?php
require __DIR__ . '/_boot.php';
if (!current_user()) { header('Location: ' . rtrim($CFG['app_url'], '/') . '/'); exit; }
$_SESSION['oauth_popup'] = !empty($_GET['popup']); // mở trong cửa sổ bật lên

if (!in_array(project_role(), PROJECT_ADMIN_ROLES, true)) back_to_app($CFG, ['conn_error' => 'Chỉ chủ / quản lý dự án được kết nối kênh']);
$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'meta') {
    back_to_app($CFG, ['conn_error' => 'Không tìm thấy kênh cần kết nối']);
}
if (!$CFG['meta_app_id'] || !$CFG['meta_app_secret']) {
    back_to_app($CFG, ['conn_error' => 'Chưa cấu hình meta_app_id/meta_app_secret trong config.local.php (cạnh index.html) — xem README mục Meta.']);
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_conn_id'] = $conn['id'];
$_SESSION['oauth_project_id'] = $conn['project_id'] ?? null;

header('Location: https://www.facebook.com/' . $CFG['meta_graph_version'] . '/dialog/oauth?' . http_build_query([
    'client_id' => $CFG['meta_app_id'],
    'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_meta_callback.php',
    'scope' => ($CFG['meta_scope_' . $conn['provider']] ?? '') ?: PROVIDERS[$conn['provider']]['scope'],
    'response_type' => 'code',
    'auth_type' => 'rerequest',
    'state' => $state,
]));
exit;
