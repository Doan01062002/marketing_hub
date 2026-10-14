<?php
/* Callback OAuth 2.0 của Meta (Facebook Login) — Fanpage và Meta Ads.
   Redirect URI khai trong Meta Developers: <app_url>/api/oauth_facebook_callback.php (meta_redirect_uri()).
   - state: ngẫu nhiên, dùng 1 lần, gắn người dùng + kênh + dự án, hết hạn 15 phút (oauth_meta_start.php).
   - Đổi code → token ngắn hạn → token dài hạn (~60 ngày) bằng POST phía máy chủ; App ID/Secret lấy từ biến môi trường
     META_APP_ID / META_APP_SECRET (xem _boot.php); App Secret không ra URL, log hay giao diện; gọi Graph kèm appsecret_proof.
   - Token người dùng và Page Access Token của mọi Page được cấp quyền lưu mã hoá (tok_enc, enc1:) — bảng meta_pages.
   - Fanpage: tự gắn Page nếu chỉ có 1 Page (hoặc đúng Page đã chọn trước đó); nhiều Page → giao diện mở hộp chọn. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_meta_lib.php';

$ctx = $_SESSION['oauth_meta'] ?? null;
unset($_SESSION['oauth_meta']); // state chỉ dùng được 1 lần, kể cả khi lỗi

if (!current_user()) back_to_app($CFG, ['conn_error' => 'Phiên đăng nhập đã hết — đăng nhập lại rồi bấm Kết nối lại']);

$state = (string)($_GET['state'] ?? '');
if (!is_array($ctx) || $state === '' || !hash_equals((string)$ctx['state'], $state)
    || ($ctx['user_id'] ?? null) !== current_user()['id']) {
    back_to_app($CFG, ['conn_error' => 'Phiên kết nối Facebook không hợp lệ (state) — bấm Kết nối lại']);
}
if (time() > (int)($ctx['exp'] ?? 0)) back_to_app($CFG, ['conn_error' => 'Phiên kết nối Facebook đã quá 15 phút — bấm Kết nối lại']);

$conn = get_connection((string)$ctx['conn_id']);
if (!$conn || ($conn['project_id'] ?? null) !== ($ctx['project_id'] ?? null) || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'meta') {
    back_to_app($CFG, ['conn_error' => 'Kênh đã bị xoá hoặc đổi dự án trong lúc kết nối']);
}
$hash = $conn['provider'] === 'meta_ads' ? 'ads' : 'settings';
$failConn = function (string $msg) use ($pdo, $conn, $CFG, $hash): void {
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    log_activity('update', 'connection', $conn['id'], 'Kết nối Facebook lỗi: ' . mb_substr($msg, 0, 300));
    back_to_app($CFG, ['conn_error' => $msg], $hash);
};

// Người dùng huỷ / Meta báo lỗi: không đổi token đang có (kênh đang chạy vẫn chạy).
if (!empty($_GET['error'])) {
    if (!empty($_SESSION['quick_ch']['conn_id'])) { // kết nối nhanh bị huỷ → bỏ kênh tạm
        $pdo->prepare('DELETE FROM connections WHERE id=? AND external_account_id IS NULL')->execute([$_SESSION['quick_ch']['conn_id']]);
        unset($_SESSION['quick_ch']);
    }
    $e = (string)$_GET['error'];
    $msg = $e === 'access_denied' ? 'Bạn đã huỷ đăng nhập Facebook — chưa kết nối' : 'Facebook báo lỗi: ' . mb_substr((string)($_GET['error_description'] ?? $e), 0, 300);
    back_to_app($CFG, ['conn_error' => $msg], $hash);
}
$code = (string)($_GET['code'] ?? '');
if ($code === '' || strlen($code) > 2048) back_to_app($CFG, ['conn_error' => 'Facebook không trả về mã xác thực — bấm Kết nối lại'], $hash);
if (!$CFG['meta_app_id'] || !$CFG['meta_app_secret']) back_to_app($CFG, ['conn_error' => 'Chưa cấu hình Meta App ID / App Secret'], $hash);

// 1) code → token ngắn hạn. POST để App Secret không nằm trên URL.
[$http, $tok] = http_post_form(graph_url($CFG, 'oauth/access_token'), [
    'client_id' => $CFG['meta_app_id'],
    'client_secret' => $CFG['meta_app_secret'],
    'redirect_uri' => meta_redirect_uri($CFG),
    'code' => $code,
]);
if ($http !== 200 || empty($tok['access_token'])) {
    $failConn('Không đổi được mã xác thực Facebook: ' . ($tok['error']['message'] ?? ($http ? "HTTP $http" : 'không kết nối được tới Facebook')) . ' — bấm Kết nối lại');
}

// 2) token dài hạn (~60 ngày); cron/run.php tự gia hạn khi còn dưới 20 ngày.
[$h2, $long] = http_post_form(graph_url($CFG, 'oauth/access_token'), [
    'grant_type' => 'fb_exchange_token',
    'client_id' => $CFG['meta_app_id'],
    'client_secret' => $CFG['meta_app_secret'],
    'fb_exchange_token' => $tok['access_token'],
]);
$accessToken = ($h2 === 200 && !empty($long['access_token'])) ? $long['access_token'] : $tok['access_token'];
$expiresIn = (int)(($h2 === 200 ? ($long['expires_in'] ?? null) : null) ?? $tok['expires_in'] ?? 5184000);

$pdo->prepare("UPDATE connections SET status='connected', access_token=?, expires_at=?, last_error=NULL,
    connected_by=?, connected_at=datetime('now') WHERE id=?")
    ->execute([tok_enc($CFG, $accessToken), date('Y-m-d H:i:s', time() + $expiresIn), current_user()['id'], $conn['id']]);
$conn = get_connection($conn['id'], true);
$until = date('d/m/Y', time() + $expiresIn);

// 3) quyền thực sự được cấp (granted / declined / thiếu) → connections.scopes, scopes_declined
try { $perm = meta_sync_permissions($CFG, $conn, $accessToken); }
catch (MetaAuthError $e) { back_to_app($CFG, ['conn_error' => $e->getMessage()], $hash); }
catch (Throwable $e) { $perm = ['granted' => [], 'declined' => [], 'missing' => []]; }
$missing = $perm['missing'];
$missNote = $missing ? ' · thiếu quyền: ' . implode(', ', $missing) . ' (bấm Kết nối lại và tick đủ quyền)' : '';

// Kết nối nhanh bằng link Fanpage: chọn đúng Page theo link, lưu Page token (mã hoá, không hết hạn).
if ($conn['provider'] === 'facebook_page' && ($_SESSION['quick_ch']['conn_id'] ?? '') === $conn['id']) {
    require_once __DIR__ . '/_quick_channel.php';
    $r = qc_finish_facebook($CFG, $conn, $accessToken, $_SESSION['quick_ch']);
    unset($_SESSION['quick_ch']);
    oauth_finish($CFG, $r);
}

// 4a) Fanpage: lưu mọi Page được cấp quyền (Page ID, tên, Page Access Token mã hoá), gắn Page cho kênh
if ($conn['provider'] === 'facebook_page') {
    try { $list = meta_sync_pages($CFG, $conn, $accessToken); }
    catch (MetaAuthError $e) { back_to_app($CFG, ['conn_error' => $e->getMessage()]); }
    catch (Throwable $e) { $failConn($e->getMessage() . ' — bấm Kết nối lại'); }
    $pages = array_column($list, 'name', 'page_id');
    if (!$pages) $failConn('Tài khoản Facebook này không cấp quyền Fanpage nào — bấm Kết nối lại, chọn Page ở bước "Chọn Trang"' . $missNote);

    $cur = (string)($conn['external_account_id'] ?? '');
    if ($cur !== '' && !isset($pages[$cur])) {
        $failConn('Tài khoản vừa đăng nhập không được cấp quyền Page đang gắn (' . $cur . ') — kết nối lại bằng tài khoản quản trị Page đó, hoặc tick Page ở bước "Chọn Trang"');
    }
    $msg = 'Đã kết nối Facebook (token tới ' . $until . ')';
    if ($cur === '' && count($pages) === 1) {
        $cur = (string)array_key_first($pages);
        $pdo->prepare('UPDATE connections SET external_account_id=?, last_metrics=NULL WHERE id=?')->execute([$cur, $conn['id']]);
    }
    if ($cur !== '') {
        $pt = meta_stored_page_token($CFG, ['external_account_id' => $cur] + $conn); // Page token (không hết hạn) → secret, mã hoá
        $pdo->prepare('UPDATE connections SET account_name=?, last_error=?, secret=COALESCE(?, secret) WHERE id=?')->execute([$pages[$cur], $missing ? ltrim($missNote, ' ·') : null, $pt ? tok_enc($CFG, $pt) : null, $conn['id']]);
        $msg .= ' · Page ' . $pages[$cur];
        try { require_once __DIR__ . '/_metrics_lib.php'; sync_connection($CFG, get_connection($conn['id'], true)); } catch (Throwable $e) { /* đồng bộ lỗi đã ghi vào last_error */ }
    } else $msg .= ' · ' . count($pages) . ' Page được cấp quyền — chọn Page cần quản lý';
    log_activity('update', 'connection', $conn['id'], 'Kết nối Fanpage qua đăng nhập Facebook (' . count($pages) . ' Page, token tới ' . $until . ')' . $missNote);
    back_to_app($CFG, ['conn_ok' => $conn['id'], 'conn_msg' => $msg . $missNote]);
}

// 4b) Meta Ads — tự chọn tài khoản quảng cáo (đúng 1 tài khoản, hoặc tài khoản đã ghi trong dự án), đồng bộ ngay.
$msg = 'Đã đăng nhập Facebook';
if (empty($conn['external_account_id'])) {
    [$c, $acc] = meta_get($CFG, 'me/adaccounts', ['fields' => 'id,name,account_status', 'limit' => 50], $accessToken);
    if ($c !== 200) $failConn('Không lấy được tài khoản quảng cáo: ' . ($acc['error']['message'] ?? "HTTP $c") . ' — cần quyền ads_read, bấm Kết nối lại');
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
} else {
    // Kết nối lại: kiểm tra tài khoản quảng cáo đang gắn vẫn truy cập được, rồi đồng bộ thống kê.
    [$c, $a] = meta_get($CFG, rawurlencode($conn['external_account_id']), ['fields' => 'id,name,account_status'], $accessToken);
    if ($c !== 200) $failConn('Tài khoản vừa đăng nhập không truy cập được ' . $conn['external_account_id'] . ': ' . ($a['error']['message'] ?? "HTTP $c"));
    $pdo->prepare('UPDATE connections SET account_name=? WHERE id=?')->execute([(string)($a['name'] ?? ''), $conn['id']]);
    $msg .= ' · tài khoản ' . ($a['name'] ?? $conn['external_account_id']);
    try { require_once __DIR__ . '/_metrics_lib.php'; sync_connection($CFG, get_connection($conn['id'], true)); $msg .= ' · đã đồng bộ số liệu'; } catch (Throwable $e) { $msg .= ' · đồng bộ: ' . $e->getMessage(); }
}
log_activity('update', 'connection', $conn['id'], 'Kết nối Meta Ads qua đăng nhập Facebook (token dài hạn tới ' . $until . ')' . $missNote);
back_to_app($CFG, ['conn_ok' => $conn['id'], 'conn_msg' => $msg . $missNote], 'ads');
