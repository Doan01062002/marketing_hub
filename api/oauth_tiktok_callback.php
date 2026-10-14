<?php
/* Callback OAuth TikTok: https://<app_url>/api/oauth_tiktok_callback.php  (đăng ký đúng URL này trong app TikTok Developer). 11/10/2026
   - Đối chiếu state (chống CSRF) + conn_id + project_id trong phiên.
   - Đổi code lấy access/refresh token bằng Client Key/Secret phía máy chủ (đúng môi trường đã bắt đầu).
   - Mã hoá token trước khi lưu; lưu open_id, tên hiển thị, ảnh, quyền đã cấp, môi trường; gắn đúng dự án.
   - Không ghi token/secret ra log hay trình duyệt. Kết quả báo về giao diện qua ?conn_ok / ?conn_error. */
require __DIR__ . '/_boot.php';
$back = fn(array $q) => back_to_app($CFG, $q, 'channels/api');

if (!current_user()) $back(['conn_error' => 'Phiên đăng nhập đã hết — đăng nhập lại rồi kết nối lại']);
if (!empty($_GET['error'])) { unset($_SESSION['oauth_state'], $_SESSION['oauth_conn_id'], $_SESSION['oauth_project_id'], $_SESSION['oauth_tiktok_env']); $back(['conn_error' => 'TikTok từ chối: ' . (string)($_GET['error_description'] ?? $_GET['error'])]); }

$state = (string)($_GET['state'] ?? '');
$connId = (string)($_SESSION['oauth_conn_id'] ?? '');
$projId = (string)($_SESSION['oauth_project_id'] ?? '');
$env = (string)($_SESSION['oauth_tiktok_env'] ?? '');
if ($state === '' || !hash_equals((string)($_SESSION['oauth_state'] ?? ''), $state) || $connId === '' || $projId === '') $back(['conn_error' => 'state_mismatch — mở lại từ nút Kết nối trong app']);
unset($_SESSION['oauth_state'], $_SESSION['oauth_conn_id'], $_SESSION['oauth_project_id'], $_SESSION['oauth_tiktok_env']);

// Tài khoản kênh phải còn tồn tại và thuộc đúng dự án đã bắt đầu; người gọi vẫn phải là chủ/quản lý dự án đó.
$st = $pdo->prepare('SELECT * FROM connections WHERE id=? AND project_id=?'); $st->execute([$connId, $projId]);
$conn = $st->fetch();
if (!$conn) $back(['conn_error' => 'Tài khoản kênh đã bị xoá hoặc đổi dự án trong lúc kết nối']);
$_SESSION['project_id'] = $projId;
$pr = current_project();
if (!$pr || $pr['id'] !== $projId || !in_array($pr['member_role'] ?? '', PROJECT_ADMIN_ROLES, true)) $back(['conn_error' => 'Bạn không còn quyền kết nối kênh cho dự án này']);

$code = (string)($_GET['code'] ?? '');
if ($code === '') $back(['conn_error' => 'missing_code']);

$app = tiktok_app($CFG, $projId);
if ($env !== '' && $env !== $app['env']) $back(['conn_error' => 'Môi trường TikTok đã đổi (' . $env . ' → ' . $app['env'] . ') trong lúc kết nối — bấm Kết nối lại']);
if (!$app['configured']) $back(['conn_error' => 'Thiếu Client Key / Secret TikTok']);

[$httpCode, $tok] = http_post_form(tiktok_url($CFG, '/v2/oauth/token/'), [
    'client_key' => $app['client_key'], 'client_secret' => $app['client_secret'],
    'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $app['redirect_uri'],
]);
$err = tiktok_error($httpCode, $tok);
if ($err !== null || empty($tok['access_token']) || empty($tok['open_id'])) {
    $msg = 'TikTok: ' . ($err ?? 'không đổi được token') . ($httpCode ? " (HTTP $httpCode)" : '');
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    log_activity('error', 'connection', $conn['id'], 'Kết nối TikTok thất bại: ' . $msg);
    $back(['conn_error' => $msg]);
}

$openId = (string)$tok['open_id'];
if ($conn['external_account_id'] && $conn['external_account_id'] !== $openId) {
    $back(['conn_error' => 'Kênh "' . $conn['label'] . '" đang gắn với một tài khoản TikTok khác — thêm tài khoản mới rồi kết nối']);
}
// Cùng open_id đã có trong dự án này → trùng; ở dự án khác → báo rõ để "Chuyển dự án" thay vì kết nối trùng.
$dup = $pdo->prepare("SELECT c.label, c.project_id, p.name pname FROM connections c LEFT JOIN projects p ON p.id=c.project_id WHERE c.provider='tiktok' AND c.external_account_id=? AND c.id<>?");
$dup->execute([$openId, $conn['id']]);
if ($d = $dup->fetch()) {
    $pdo->prepare("DELETE FROM connections WHERE id=? AND external_account_id IS NULL AND status<>'connected'")->execute([$conn['id']]); // dọn kênh trống vừa tạo
    $back(['conn_error' => 'Tài khoản TikTok này đã kết nối ở kênh "' . $d['label'] . '" (dự án ' . ($d['pname'] ?: $d['project_id']) . '). Dùng nút "Chuyển dự án" ở đó nếu muốn đưa sang dự án này.']);
}

$granted = array_values(array_filter(array_map('trim', explode(',', (string)($tok['scope'] ?? '')))));
$missing = array_values(array_diff($app['scopes'], $granted));
$warn = $missing ? 'Người dùng chưa cấp: ' . implode(', ', $missing) . ' — kết nối lại và cho phép đủ nếu cần' : null;

// Tên hiển thị + ảnh (user.info.basic). Lỗi ở bước này không làm hỏng kết nối.
$name = null; $avatar = null;
[$c2, $u] = http_get_json(tiktok_url($CFG, '/v2/user/info/?fields=open_id,union_id,avatar_url,display_name'), ['Authorization: Bearer ' . $tok['access_token']]);
if ($c2 === 200 && tiktok_error($c2, $u) === null) { $name = (string)($u['data']['user']['display_name'] ?? ''); $avatar = (string)($u['data']['user']['avatar_url'] ?? ''); }

$pdo->prepare("UPDATE connections SET status='connected', auth_method='oauth', access_token=?, refresh_token=?, expires_at=?, external_account_id=?,
    env=?, scopes=?, account_name=?, avatar=?, last_error=?, connected_by=?, connected_at=datetime('now') WHERE id=?")
    ->execute([tok_enc($CFG, $tok['access_token']), tok_enc($CFG, $tok['refresh_token'] ?? null), date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 86400)),
        $openId, $app['env'], implode(',', $granted), $name ?: null, $avatar ?: null, $warn, current_user()['id'], $conn['id']]);
if ($name && $conn['label'] === '' ) $pdo->prepare('UPDATE connections SET label=? WHERE id=?')->execute([$name, $conn['id']]);
log_activity('update', 'connection', $conn['id'], 'Kết nối TikTok ' . ($name ? "@$name " : '') . '(' . $app['env'] . ') — quyền: ' . implode(',', $granted), null, $projId);

// Thử đồng bộ ngay để báo kết quả thật (không bắt buộc).
try { require_once __DIR__ . '/_metrics_lib.php'; sync_connection($CFG, get_connection($conn['id'], true)); } catch (Throwable $e) {}

$back(['conn_ok' => $conn['id'], 'conn_msg' => 'Đã kết nối TikTok ' . ($name ? "@$name" : $openId) . ' (' . ($app['env'] === 'sandbox' ? 'Sandbox — chỉ tài khoản test, đăng video ở chế độ riêng tư' : 'Production') . ')' . ($warn ? ' · ' . $warn : '')]);
