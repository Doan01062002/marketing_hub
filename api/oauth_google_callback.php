<?php
require __DIR__ . '/_boot.php';

if (!current_user()) oauth_finish($CFG, ['conn_error' => 'Phiên đăng nhập đã hết — đăng nhập lại rồi kết nối lại']);
if (!empty($_GET['error'])) {
    if (!empty($_SESSION['quick_web']['conn_id'])) { // kết nối nhanh bị huỷ → bỏ kênh tạm
        $pdo->prepare("DELETE FROM connections WHERE id=? AND external_account_id IS NULL")->execute([$_SESSION['quick_web']['conn_id']]);
        unset($_SESSION['quick_web']);
    }
    oauth_finish($CFG, ['conn_error' => $_GET['error'] === 'access_denied' ? 'Đã huỷ đăng nhập Google' : (string)$_GET['error']]);
}

$state = $_GET['state'] ?? '';
$connId = $_SESSION['oauth_conn_id'] ?? '';
if (!$state || $state !== ($_SESSION['oauth_state'] ?? null) || !$connId) {
    oauth_finish($CFG, ['conn_error' => 'state_mismatch']);
}
unset($_SESSION['oauth_state'], $_SESSION['oauth_conn_id']);

$conn = get_connection($connId);
if (!$conn) oauth_finish($CFG, ['conn_error' => 'Kênh đã bị xoá trong lúc kết nối']);

$code = $_GET['code'] ?? '';
if (!$code) oauth_finish($CFG, ['conn_error' => 'missing_code']);

[$httpCode, $tok] = http_post_form('https://oauth2.googleapis.com/token', [
    'client_id' => $CFG['google_client_id'],
    'client_secret' => $CFG['google_client_secret'],
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_google_callback.php',
]);

if ($httpCode !== 200 || empty($tok['access_token'])) {
    $msg = $tok['error_description'] ?? $tok['error'] ?? ('Không đổi được token Google (HTTP ' . $httpCode . ')');
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    oauth_finish($CFG, ['conn_error' => $msg]);
}

$pdo->prepare("UPDATE connections SET status='connected', access_token=?, refresh_token=COALESCE(?, refresh_token),
    expires_at=?, last_error=NULL, connected_by=?, connected_at=datetime('now') WHERE id=?")
    ->execute([$tok['access_token'], $tok['refresh_token'] ?? null,
        date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600)), current_user()['id'], $conn['id']]);

if (($_SESSION['quick_web']['conn_id'] ?? '') === $conn['id']) {
    require_once __DIR__ . '/_quick_web.php';
    $r = quick_web_finish($CFG, $conn, $tok);
    unset($_SESSION['quick_web']);
    oauth_finish($CFG, $r);
}
oauth_finish($CFG, ['conn_ok' => $conn['id']]);
