<?php
/* Bắt đầu OAuth 2.0 X (Twitter) cho MỘT tài khoản kênh (connections.id) thuộc dự án hiện hành. 12/10/2026
   Một app X Developer dùng chung (x_app()); mỗi tài khoản X = một kênh, token riêng, thuộc đúng dự án.
   Authorization Code + PKCE (S256): code_verifier giữ trong phiên, callback dùng để đổi code lấy token.
   Quyền: chủ / quản lý dự án (hoặc admin hệ thống). Phiên giữ state + conn_id + project_id để callback đối chiếu. */
require __DIR__ . '/_boot.php';
if (!current_user()) { header('Location: ' . rtrim($CFG['app_url'], '/') . '/'); exit; }
$pr = current_project();
if (!$pr || !in_array($pr['member_role'] ?? '', PROJECT_ADMIN_ROLES, true)) back_to_app($CFG, ['conn_error' => 'Chỉ chủ / quản lý dự án được kết nối tài khoản X'], 'channels/api');

$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'x') back_to_app($CFG, ['conn_error' => 'Không tìm thấy tài khoản X cần kết nối trong dự án này'], 'channels/api');

$app = x_app($CFG);
if (!$app['configured']) back_to_app($CFG, ['conn_error' => 'Chưa có Client ID / Client Secret của app X — quản trị nhập ở Kênh marketing → X → Kết nối API'], 'channels/api');
if (!str_starts_with($app['redirect_uri'], 'https://')) back_to_app($CFG, ['conn_error' => 'X chỉ nhận Callback URI https — app_url phải là https'], 'channels/api');

$state = bin2hex(random_bytes(16));
$verifier = bin2hex(random_bytes(32)); // 64 ký tự, nằm trong khoảng 43–128 của PKCE
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_conn_id'] = $conn['id'];
$_SESSION['oauth_project_id'] = $pr['id'];
$_SESSION['oauth_x_verifier'] = $verifier;
log_activity('update', 'connection', $conn['id'], 'Bắt đầu kết nối X cho tài khoản ' . $conn['label']);

// Scope cách nhau bằng dấu cách → mã hoá %20 (RFC 3986) cho chắc.
header('Location: ' . X_AUTH_URL . '?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $app['client_id'],
    'redirect_uri' => $app['redirect_uri'],
    'scope' => implode(' ', $app['scopes']),
    'state' => $state,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
], '', '&', PHP_QUERY_RFC3986));
exit;
