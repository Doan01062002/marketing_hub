<?php
require __DIR__ . '/_boot.php';

if (!current_user()) oauth_finish($CFG, ['conn_error' => 'Phiên đăng nhập đã hết — đăng nhập lại rồi kết nối lại']);
if (!empty($_GET['error'])) {
    if (!empty($_SESSION['quick_ch']['conn_id'])) { // kết nối nhanh bị huỷ → bỏ kênh tạm
        $pdo->prepare("DELETE FROM connections WHERE id=? AND external_account_id IS NULL")->execute([$_SESSION['quick_ch']['conn_id']]);
        unset($_SESSION['quick_ch']);
    }
    oauth_finish($CFG, ['conn_error' => ($_GET['error'] ?? '') === 'access_denied' ? 'Đã huỷ đăng nhập Facebook' : (string)($_GET['error_description'] ?? $_GET['error'])]);
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

[$httpCode, $tok] = http_get_json(graph_url($CFG, 'oauth/access_token', [
    'client_id' => $CFG['meta_app_id'],
    'client_secret' => $CFG['meta_app_secret'],
    'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_meta_callback.php',
    'code' => $code,
]));

if ($httpCode !== 200 || empty($tok['access_token'])) {
    $msg = $tok['error']['message'] ?? ('Không đổi được token Meta (HTTP ' . $httpCode . ')');
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    oauth_finish($CFG, ['conn_error' => $msg]);
}

// Đổi sang token dài hạn (~60 ngày) để không phải đăng nhập lại liên tục.
[, $long] = http_get_json(graph_url($CFG, 'oauth/access_token', [
    'grant_type' => 'fb_exchange_token',
    'client_id' => $CFG['meta_app_id'],
    'client_secret' => $CFG['meta_app_secret'],
    'fb_exchange_token' => $tok['access_token'],
]));
$accessToken = $long['access_token'] ?? $tok['access_token'];
$expiresIn = (int)($long['expires_in'] ?? $tok['expires_in'] ?? 5184000);

$pdo->prepare("UPDATE connections SET status='connected', access_token=?, expires_at=?, last_error=NULL,
    connected_by=?, connected_at=datetime('now') WHERE id=?")
    ->execute([$accessToken, date('Y-m-d H:i:s', time() + $expiresIn), current_user()['id'], $conn['id']]);

// 12/10/2026: Meta Ads — tự chọn tài khoản quảng cáo (đúng 1 tài khoản, hoặc tài khoản đã ghi trong dự án), đồng bộ ngay, quay về trang Chiến dịch quảng cáo.
if ($conn['provider'] === 'meta_ads') {
    $msg = 'Đã đăng nhập Facebook';
    if (empty($conn['external_account_id'])) {
        [$c, $acc] = http_get_json(graph_url($CFG, 'me/adaccounts', ['fields' => 'id,name,account_status', 'limit' => 50, 'access_token' => $accessToken]));
        $list = $acc['data'] ?? [];
        $pj = jdecode(current_project()['settings'] ?? null); $want = (string)($pj['tracking']['meta_ad_account'] ?? '');
        $pick = null;
        foreach ($list as $a) if ($want !== '' && $a['id'] === $want) $pick = $a;
        if (!$pick && count($list) === 1) $pick = $list[0];
        if ($pick) {
            $pdo->prepare('UPDATE connections SET external_account_id=?, account_name=? WHERE id=?')->execute([$pick['id'], (string)($pick['name'] ?? ''), $conn['id']]);
            $msg .= ' · tài khoản ' . ($pick['name'] ?? $pick['id']);
            try { require_once __DIR__ . '/_metrics_lib.php'; sync_connection($CFG, get_connection($conn['id'], true)); $msg .= ' · đã đồng bộ số liệu'; } catch (Throwable $e) { $msg .= ' · đồng bộ: ' . $e->getMessage(); }
        } else $msg .= ' · ' . count($list) . ' tài khoản quảng cáo — bấm "Chọn tài khoản"';
    }
    log_activity('update', 'connection', $conn['id'], 'Kết nối Meta Ads qua đăng nhập Facebook (token dài hạn tới ' . date('d/m/Y', time() + $expiresIn) . ')');
    back_to_app($CFG, ['conn_ok' => $conn['id'], 'conn_msg' => $msg], 'ads');
}
// Kết nối nhanh bằng link Fanpage: chọn đúng Page, lưu Page token (không hết hạn).
if ($conn['provider'] === 'facebook_page' && ($_SESSION['quick_ch']['conn_id'] ?? '') === $conn['id']) {
    require_once __DIR__ . '/_quick_channel.php';
    $r = qc_finish_facebook($CFG, $conn, $accessToken, $_SESSION['quick_ch']);
    unset($_SESSION['quick_ch']);
    oauth_finish($CFG, $r);
}
// Fanpage đã chọn Page: cất luôn Page token lấy từ token dài hạn → không hết hạn sau 60 ngày.
if ($conn['provider'] === 'facebook_page' && !empty($conn['external_account_id'])) {
    [$c, $pg] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']), ['fields' => 'access_token', 'access_token' => $accessToken]));
    if ($c === 200 && !empty($pg['access_token'])) $pdo->prepare('UPDATE connections SET secret=?, expires_at=NULL WHERE id=?')->execute([$pg['access_token'], $conn['id']]);
}
oauth_finish($CFG, ['conn_ok' => $conn['id']]);
