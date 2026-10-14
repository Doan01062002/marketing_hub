<?php
/* Callback OAuth 2.0 X (Twitter): https://<app_url>/api/oauth_x_callback.php  (đăng ký đúng URL này trong app X Developer Portal). 12/10/2026
   - Đối chiếu state (chống CSRF) + conn_id + project_id + code_verifier (PKCE) trong phiên.
   - Đổi code lấy access/refresh token tại api.x.com/2/oauth2/token (client confidential: header Basic + code_verifier).
   - Gọi GET /2/users/me lấy user id, username, ảnh; mã hoá token trước khi lưu; lưu quyền đã cấp; gắn đúng dự án.
   - Không ghi token/secret ra log hay trình duyệt. Kết quả báo về giao diện qua ?conn_ok / ?conn_error. */
require __DIR__ . '/_boot.php';
$back = fn(array $q) => back_to_app($CFG, $q, 'channels/api');
$clear = function (): void { unset($_SESSION['oauth_state'], $_SESSION['oauth_conn_id'], $_SESSION['oauth_project_id'], $_SESSION['oauth_x_verifier']); };

if (!current_user()) $back(['conn_error' => 'Phiên đăng nhập đã hết — đăng nhập lại rồi kết nối lại']);
if (!empty($_GET['error'])) { $clear(); $back(['conn_error' => 'X từ chối: ' . (string)($_GET['error_description'] ?? $_GET['error'])]); }

$state = (string)($_GET['state'] ?? '');
$connId = (string)($_SESSION['oauth_conn_id'] ?? '');
$projId = (string)($_SESSION['oauth_project_id'] ?? '');
$verifier = (string)($_SESSION['oauth_x_verifier'] ?? '');
if ($state === '' || !hash_equals((string)($_SESSION['oauth_state'] ?? ''), $state) || $connId === '' || $projId === '' || $verifier === '') { $clear(); $back(['conn_error' => 'state_mismatch — mở lại từ nút Kết nối trong app']); }
$clear();

// Tài khoản kênh phải còn tồn tại và thuộc đúng dự án đã bắt đầu; người gọi vẫn phải là chủ/quản lý dự án đó.
$st = $pdo->prepare('SELECT * FROM connections WHERE id=? AND project_id=?'); $st->execute([$connId, $projId]);
$conn = $st->fetch();
if (!$conn) $back(['conn_error' => 'Tài khoản kênh đã bị xoá hoặc đổi dự án trong lúc kết nối']);
$_SESSION['project_id'] = $projId;
$pr = current_project();
if (!$pr || $pr['id'] !== $projId || !in_array($pr['member_role'] ?? '', PROJECT_ADMIN_ROLES, true)) $back(['conn_error' => 'Bạn không còn quyền kết nối kênh cho dự án này']);

$code = (string)($_GET['code'] ?? '');
if ($code === '') $back(['conn_error' => 'missing_code']);

$app = x_app($CFG);
if (!$app['configured']) $back(['conn_error' => 'Thiếu Client ID / Client Secret của app X']);

[$httpCode, $tok] = http_post_form(X_API_BASE . '/2/oauth2/token', [
    'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $app['redirect_uri'],
    'code_verifier' => $verifier, 'client_id' => $app['client_id'],
], [x_basic_header($app)]);
$err = x_error($httpCode, $tok);
if ($err !== null || empty($tok['access_token'])) {
    $msg = 'X: ' . ($err ?? 'không đổi được token') . ($httpCode ? " (HTTP $httpCode)" : '');
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    log_activity('error', 'connection', $conn['id'], 'Kết nối X thất bại: ' . $msg);
    $back(['conn_error' => $msg]);
}

// Ai vừa đăng nhập: id, username, tên, ảnh (users.read).
[$c2, $u] = http_get_json(X_API_BASE . '/2/users/me?user.fields=profile_image_url,public_metrics,username,name', ['Authorization: Bearer ' . $tok['access_token']]);
$uerr = x_error($c2, $u);
if ($uerr !== null || empty($u['data']['id'])) {
    $msg = 'X: không đọc được tài khoản vừa đăng nhập (' . ($uerr ?? "HTTP $c2") . ') — app cần quyền users.read';
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    $back(['conn_error' => $msg]);
}
$userId = (string)$u['data']['id'];
$username = (string)($u['data']['username'] ?? '');
$avatar = (string)($u['data']['profile_image_url'] ?? '');
if ($avatar !== '') $avatar = str_replace('_normal.', '_400x400.', $avatar); // X trả ảnh 48px (_normal) → lấy bản 400px

if ($conn['external_account_id'] && $conn['external_account_id'] !== $userId) {
    $back(['conn_error' => 'Kênh "' . $conn['label'] . '" đang gắn với một tài khoản X khác — thêm tài khoản mới rồi kết nối']);
}
// Cùng user id đã có trong dự án này → trùng; ở dự án khác → báo rõ để "Chuyển dự án" thay vì kết nối trùng.
$dup = $pdo->prepare("SELECT c.label, c.project_id, p.name pname FROM connections c LEFT JOIN projects p ON p.id=c.project_id WHERE c.provider='x' AND c.external_account_id=? AND c.id<>?");
$dup->execute([$userId, $conn['id']]);
if ($d = $dup->fetch()) {
    $pdo->prepare("DELETE FROM connections WHERE id=? AND external_account_id IS NULL AND status<>'connected'")->execute([$conn['id']]); // dọn kênh trống vừa tạo
    $back(['conn_error' => 'Tài khoản X @' . $username . ' đã kết nối ở kênh "' . $d['label'] . '" (dự án ' . ($d['pname'] ?: $d['project_id']) . '). Dùng nút "Chuyển dự án" ở đó nếu muốn đưa sang dự án này.']);
}

$granted = array_values(array_filter(array_map('trim', explode(' ', (string)($tok['scope'] ?? '')))));
$missing = array_values(array_diff($app['scopes'], $granted));
$warn = $missing ? 'Người dùng chưa cấp: ' . implode(', ', $missing) . ' — kết nối lại và cho phép đủ nếu cần' : null;
if (empty($tok['refresh_token'])) $warn = trim(($warn ? $warn . ' · ' : '') . 'X không trả refresh token (thiếu offline.access) — token chỉ sống ~2 giờ, sẽ phải kết nối lại');

$pdo->prepare("UPDATE connections SET status='connected', auth_method='oauth', access_token=?, refresh_token=?, expires_at=?, external_account_id=?,
    env=?, scopes=?, account_name=?, avatar=?, last_error=?, connected_by=?, connected_at=datetime('now') WHERE id=?")
    ->execute([tok_enc($CFG, $tok['access_token']), tok_enc($CFG, $tok['refresh_token'] ?? null), date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 7200)),
        $userId, 'production', implode(',', $granted), $username ?: null, $avatar ?: null, $warn, current_user()['id'], $conn['id']]);
if ($username && $conn['label'] === '') $pdo->prepare('UPDATE connections SET label=? WHERE id=?')->execute(['@' . $username, $conn['id']]);
log_activity('update', 'connection', $conn['id'], 'Kết nối X ' . ($username ? "@$username " : '') . '— quyền: ' . implode(',', $granted), null, $projId);

// Thử đồng bộ ngay để báo kết quả thật (không bắt buộc).
try { require_once __DIR__ . '/_metrics_lib.php'; sync_connection($CFG, get_connection($conn['id'], true)); } catch (Throwable $e) {}

$back(['conn_ok' => $conn['id'], 'conn_msg' => 'Đã kết nối X ' . ($username ? "@$username" : $userId) . ($warn ? ' · ' . $warn : '')]);
