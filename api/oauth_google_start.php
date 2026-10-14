<?php
require __DIR__ . '/_boot.php';
if (!current_user()) { header('Location: ' . rtrim($CFG['app_url'], '/') . '/'); exit; }

$_SESSION['oauth_popup'] = !empty($_GET['popup']); // mở trong cửa sổ bật lên (kết nối nhanh)
if (!in_array(project_role(), PROJECT_ADMIN_ROLES, true)) oauth_finish($CFG, ['conn_error' => 'Chỉ chủ / quản lý dự án được kết nối kênh']);
$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'google') {
    oauth_finish($CFG, ['conn_error' => 'Không tìm thấy kênh cần kết nối']);
}
if (!$CFG['google_client_id'] || !$CFG['google_client_secret']) {
    oauth_finish($CFG, ['conn_error' => 'Chưa có OAuth Client Google — quản trị dán Client ID / Client Secret ở Thiết lập → Nguồn dữ liệu thị trường.']);
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_conn_id'] = $conn['id'];
$_SESSION['oauth_project_id'] = $conn['project_id'] ?? null;

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $CFG['google_client_id'],
    'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_google_callback.php',
    'response_type' => 'code',
    // Kết nối nhanh website: xin cùng lúc quyền Search Console + Google Analytics để tự nhận cả hai.
    'scope' => ($_SESSION['quick_web']['conn_id'] ?? '') === $conn['id']
        ? PROVIDERS['gsc']['scope'] . ' ' . PROVIDERS['ga4']['scope'] : PROVIDERS[$conn['provider']]['scope'],
    'access_type' => 'offline',
    // select_account: mỗi kênh YouTube/website có thể thuộc một tài khoản Google khác nhau.
    'prompt' => 'consent select_account',
    'include_granted_scopes' => 'true',
    'state' => $state,
]));
exit;
