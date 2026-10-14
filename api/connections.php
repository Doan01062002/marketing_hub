<?php
require __DIR__ . '/_boot.php';
$user = require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM connections WHERE project_id=? ORDER BY provider, created_at'); $st->execute([pid()]); $rows = $st->fetchAll();
    respond(['connections' => array_map('public_connection', $rows)]);
}

if ($method !== 'POST') fail('Method không hỗ trợ', 405);

$in = json_input();
$action = $in['action'] ?? '';

// Phân quyền: cấu hình kênh dành cho chủ / quản lý DỰ ÁN; xoá kênh chỉ chủ dự án (hoặc admin hệ thống).
if ($action === 'delete') { if (project_role() !== 'owner' && $user['role'] !== 'admin') fail('Chỉ chủ dự án được xoá kênh', 403); }
else require_project_admin();

// Ghi nhật ký cho các thao tác ghi khi request kết thúc thành công.
$CONN_LOG = ['create' => 'Thêm kênh', 'quick_web' => 'Kết nối nhanh website', 'save_manual' => 'Lưu kênh nhập tay', 'rename' => 'Đổi tên kênh',
    'set_account' => 'Đổi tài khoản kênh', 'disconnect' => 'Ngắt kết nối kênh', 'delete' => 'Xoá kênh', 'set_project' => 'Chuyển kênh sang dự án khác',
    'import_accounts' => 'Nhập tài khoản cho kênh'];
if (isset($CONN_LOG[$action])) {
    $logConn = ($in['id'] ?? '') ? get_connection((string)$in['id']) : null;
    register_shutdown_function(function () use ($action, $in, $logConn, $CONN_LOG, $user) {
        $code = http_response_code();
        if ($code && $code >= 400) return;
        $label = trim((string)($in['label'] ?? '')) ?: ($logConn['label'] ?? '');
        log_activity($action === 'delete' ? 'delete' : ($action === 'create' || ($action === 'save_manual' && !$logConn) ? 'create' : 'update'),
            'connection', $in['id'] ?? null, $CONN_LOG[$action] . ($label !== '' ? ' ' . $label : ''), $user);
    });
}

if ($action === 'create') {
    $provider = $in['provider'] ?? '';
    if (!isset(PROVIDERS[$provider])) fail('Loại kênh không hợp lệ');
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '') fail('Nhập tên kênh');
    $id = gen_id('CH');
    $pdo->prepare('INSERT INTO connections(id,project_id,provider,label) VALUES (?,?,?,?)')->execute([$id, pid(), $provider, $label]);
    respond(['id' => $id], 201);
}

if ($action === 'quick_web') {
    // Kết nối nhanh: dán link website → mở đăng nhập Google (cửa sổ bật lên) → callback tự tìm Search Console + GA4.
    require_once __DIR__ . '/_quick_web.php';
    $dom = qw_domain((string)($in['url'] ?? ''));
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom)) fail('Link website không hợp lệ (VD: ten-mien.vn)');
    if (!$CFG['google_client_id'] || !$CFG['google_client_secret']) respond(['need_client' => true,
        'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_google_callback.php'], 409);
    // Dọn kênh tạm của lần kết nối nhanh trước bị huỷ giữa chừng.
    $pdo->prepare("DELETE FROM connections WHERE project_id=? AND provider='gsc' AND external_account_id IS NULL AND status<>'connected' AND auth_method='oauth' AND label LIKE '⏳ %'")->execute([pid()]);
    $id = gen_id('CH');
    $pdo->prepare('INSERT INTO connections(id,project_id,provider,label) VALUES (?,?,?,?)')->execute([$id, pid(), 'gsc', '⏳ ' . $dom]);
    $_SESSION['quick_web'] = ['conn_id' => $id, 'domain' => $dom];
    respond(['start_url' => '/api/oauth_google_start.php?popup=1&conn_id=' . $id, 'domain' => $dom]);
}

if ($action === 'quick_channel') {
    // Kết nối nhanh: dán link Fanpage / kênh YouTube → đăng nhập (cửa sổ bật lên) → callback tự chọn đúng Page/kênh, lưu khoá lâu dài.
    require_once __DIR__ . '/_quick_channel.php';
    try { [$prov, $key, $kind] = qc_parse((string)($in['url'] ?? '')); } catch (RuntimeException $e) { fail($e->getMessage()); }
    if ($prov === 'facebook_page' && (!$CFG['meta_app_id'] || !$CFG['meta_app_secret'])) fail('Chưa cấu hình ứng dụng Meta (App ID / App Secret) — mở mục "Ứng dụng Meta" bên dưới để dán', 409);
    if ($prov === 'youtube' && (!$CFG['google_client_id'] || !$CFG['google_client_secret'])) respond(['need_client' => true,
        'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_google_callback.php'], 409);
    $pdo->prepare("DELETE FROM connections WHERE project_id=? AND provider IN ('facebook_page','youtube') AND external_account_id IS NULL AND status<>'connected' AND label LIKE '⏳ %'")->execute([pid()]);
    $id = gen_id('CH');
    $pdo->prepare('INSERT INTO connections(id,project_id,provider,label) VALUES (?,?,?,?)')->execute([$id, pid(), $prov, '⏳ ' . $key]);
    $_SESSION['quick_ch'] = ['conn_id' => $id, 'provider' => $prov, 'key' => $key, 'kind' => $kind];
    respond(['start_url' => '/api/' . ($prov === 'youtube' ? 'oauth_google_start.php' : 'oauth_meta_start.php') . '?popup=1&conn_id=' . $id,
        'provider' => $prov, 'key' => $key]);
}

if ($action === 'save_manual') {
    // Nhập tay: dán ID + token/API key, không cần đăng nhập OAuth.
    $manualProviders = ['facebook_page', 'youtube', 'ga4', 'gsc', 'gbp', 'meta_ads', 'zalo_oa'];
    $id = (string)($in['id'] ?? '');
    $existing = $id ? get_connection($id) : null;
    if ($id && !$existing) fail('Không tìm thấy kênh', 404);
    $provider = $existing ? $existing['provider'] : (string)($in['provider'] ?? '');
    if (!in_array($provider, $manualProviders, true)) fail('Loại kênh này chưa hỗ trợ nhập tay');

    $label = trim((string)($in['label'] ?? ''));
    $account = preg_replace('/\s+/', '', (string)($in['external_account_id'] ?? ''));
    if ($provider === 'gsc') { // Search Console: 'sc-domain:ten-mien.vn' hoặc URL tiền tố 'https://ten-mien.vn/'
        if (preg_match('#^https?://#i', $account)) $account = rtrim($account, '/') . '/';
        elseif (!str_starts_with($account, 'sc-domain:')) $account = 'sc-domain:' . preg_replace('#^www\.#', '', $account);
    }
    if ($provider === 'gbp' && ctype_digit($account)) $account = 'locations/' . $account;
    if ($provider === 'gbp' && !preg_match('#^(accounts/\d+/)?locations/\d+$#', $account)) fail('Location ID dạng locations/123… (hoặc accounts/…/locations/…)');
    $secret = trim((string)($in['secret'] ?? ''));
    if ($label === '') fail('Nhập tên kênh');
    if ($account === '') fail('Nhập ID của kênh');
    if ($provider === 'meta_ads' && ctype_digit($account)) $account = 'act_' . $account;
    if ($provider === 'ga4') $account = preg_replace('/^properties\//', '', $account);
    if (in_array($provider, ['facebook_page', 'ga4'], true) && !ctype_digit($account)) fail('ID phải là dãy số');
    if (!in_array($provider, ['ga4', 'gsc'], true)) $secret = preg_replace('/\s+/', '', $secret);
    // YouTube: OAuth riêng của kênh (Client ID + Client Secret + Refresh Token) → đọc được cả YouTube Analytics;
    // API key chỉ đọc số công khai. Cần ít nhất một trong hai.
    $ytCid = $ytSec = $ytRt = '';
    if ($provider === 'zalo_oa' && !ctype_digit($account)) fail('OA ID là dãy số (xem ở oa.zalo.me → Thông tin OA)');
    if (in_array($provider, ['youtube', 'gbp', 'zalo_oa'], true)) {
        $ytCid = preg_replace('/\s+/', '', (string)($in['client_id'] ?? ''));
        $ytSec = preg_replace('/\s+/', '', (string)($in['client_secret'] ?? ''));
        $ytRt = preg_replace('/\s+/', '', (string)($in['refresh_token'] ?? ''));
        if ($provider === 'youtube' && !preg_match('/^UC[A-Za-z0-9_-]{22}$/', $account)) fail('Channel ID phải có dạng UC… (24 ký tự)');
        $hasOauth = ($ytCid !== '' || !empty($existing['client_id'])) && ($ytSec !== '' || !empty($existing['client_secret']))
            && ($ytRt !== '' || !empty($existing['refresh_token']));
        if (($ytCid !== '' || $ytSec !== '' || $ytRt !== '') && !$hasOauth) fail('Điền đủ cả OAuth Client ID, Client Secret và Refresh Token');
        if (!$hasOauth && $provider === 'zalo_oa') fail('Zalo OA cần đủ App ID, Secret key và Refresh token');
        if (!$hasOauth && $provider === 'gbp') fail('Google Business Profile cần OAuth Client ID + Client Secret + Refresh Token (quyền business.manage)');
        if (!$hasOauth && $secret === '' && empty($existing['secret'])) fail('Điền OAuth Client ID + Client Secret + Refresh Token (hoặc ít nhất YouTube Data API key)');
    } elseif (!$existing && $secret === '') fail('Dán token / API key');
    if (in_array($provider, ['ga4', 'gsc'], true) && $secret !== '') {
        $sa = json_decode($secret, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            fail('Nội dung JSON service account không hợp lệ — dán toàn bộ file .json tải từ Google Cloud');
        }
    }

    $dup = $pdo->prepare('SELECT id FROM connections WHERE project_id=? AND provider=? AND external_account_id=? AND id<>?');
    $dup->execute([pid(), $provider, $account, $id]);
    if ($dup->fetch()) fail('Kênh có ID này đã có trong dự án');

    if ($existing) {
        $pdo->prepare("UPDATE connections SET label=?, external_account_id=?, secret=COALESCE(?, secret), auth_method='manual',
            status='connected', last_error=NULL, last_metrics=CASE WHEN external_account_id=? THEN last_metrics ELSE NULL END WHERE id=?")
            ->execute([$label, $account, $secret !== '' ? $secret : null, $account, $existing['id']]);
        if (in_array($provider, ['youtube', 'gbp', 'zalo_oa'], true) && ($ytCid !== '' || $ytSec !== '' || $ytRt !== '')) {
            // Đổi bộ OAuth → bỏ access token cũ để lần đồng bộ sau xin lại bằng refresh token mới.
            $pdo->prepare('UPDATE connections SET client_id=COALESCE(?, client_id), client_secret=COALESCE(?, client_secret),
                refresh_token=COALESCE(?, refresh_token), access_token=NULL, expires_at=NULL WHERE id=?')
                ->execute([$ytCid ?: null, $ytSec ?: null, $ytRt ?: null, $existing['id']]);
        }
        respond(['id' => $existing['id']]);
    }
    $newId = gen_id('CH');
    $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,external_account_id,secret,client_id,client_secret,refresh_token,connected_by,connected_at)
        VALUES (?,?,?,?,'connected','manual',?,?,?,?,?,?,datetime('now'))")
        ->execute([$newId, pid(), $provider, $label, $account, $secret !== '' ? $secret : null, $ytCid ?: null, $ytSec ?: null, $ytRt ?: null, $user['id']]);
    respond(['id' => $newId], 201);
}

$conn = get_connection((string)($in['id'] ?? ''));
if (!$conn) fail('Không tìm thấy kênh', 404);

if ($action === 'set_project') {
    // Chuyển kênh sang dự án khác: phải là chủ/quản lý của CẢ HAI dự án (hoặc admin hệ thống). Số liệu lịch sử, bình luận đi theo kênh.
    $to = (string)($in['project_id_to'] ?? '');
    $okTo = false; foreach (projects_for_user($user) as $p) if ($p['id'] === $to && in_array($p['member_role'], PROJECT_ADMIN_ROLES, true)) $okTo = true;
    if (!$okTo) fail('Bạn không phải chủ / quản lý của dự án đích', 403);
    $pdo->prepare('UPDATE connections SET project_id=? WHERE id=?')->execute([$to, $conn['id']]);
    $pdo->prepare('UPDATE comments SET project_id=? WHERE connection_id=?')->execute([$to, $conn['id']]);
    respond(['ok' => true]);
}

if ($action === 'rename') {
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '') fail('Nhập tên kênh');
    $pdo->prepare('UPDATE connections SET label=? WHERE id=?')->execute([$label, $conn['id']]);
    respond(['ok' => true]);
}

if ($action === 'set_account') {
    $acc = trim((string)($in['external_account_id'] ?? '')) ?: null;
    $pdo->prepare('UPDATE connections SET external_account_id=?, last_metrics=NULL WHERE id=?')->execute([$acc, $conn['id']]);
    if ($conn['provider'] === 'facebook_page' && ($conn['auth_method'] ?? 'oauth') !== 'manual' && $acc && $conn['access_token']) {
        // Cất Page token (không hết hạn) ngay khi chọn Page.
        [$c, $pg] = http_get_json(graph_url($CFG, rawurlencode($acc), ['fields' => 'access_token', 'access_token' => $conn['access_token']]));
        $pdo->prepare('UPDATE connections SET secret=?, expires_at=CASE WHEN ? IS NULL THEN expires_at ELSE NULL END WHERE id=?')
            ->execute([$c === 200 ? ($pg['access_token'] ?? null) : null, $c === 200 ? ($pg['access_token'] ?? null) : null, $conn['id']]);
    }
    respond(['ok' => true]);
}

if ($action === 'disconnect') {
    $pdo->prepare("UPDATE connections SET status='disconnected', access_token=NULL, refresh_token=NULL,
        expires_at=NULL, last_error=NULL, secret=CASE WHEN auth_method='manual' THEN secret ELSE NULL END WHERE id=?")->execute([$conn['id']]);
    respond(['ok' => true]);
}

if ($action === 'delete') {
    $pdo->prepare('DELETE FROM connections WHERE id=?')->execute([$conn['id']]);
    respond(['ok' => true]);
}

if ($action === 'list_accounts') {
    if ($conn['status'] !== 'connected') fail('Kênh chưa kết nối');
    respond(['accounts' => list_accounts($CFG, $conn)]);
}

if ($action === 'import_accounts') {
    // Tài khoản đầu tiên gán cho chính kênh này; các tài khoản còn lại thành kênh mới dùng chung token.
    if ($conn['status'] !== 'connected') fail('Kênh chưa kết nối');
    if (PROVIDERS[$conn['provider']]['auth'] === 'tiktok') fail('TikTok chỉ có một tài khoản cho mỗi lần đăng nhập');
    $accounts = array_values(array_filter($in['accounts'] ?? [], fn($a) => !empty($a['id'])));
    if (!$accounts) fail('Chưa chọn tài khoản nào');

    $existing = $pdo->prepare('SELECT external_account_id FROM connections WHERE project_id=? AND provider=? AND external_account_id IS NOT NULL AND id<>?');
    $existing->execute([pid(), $conn['provider'], $conn['id']]);
    $taken = array_column($existing->fetchAll(), 'external_account_id');

    // keep_own: kênh nguồn đã có tài khoản riêng → mọi mục được chọn đều thành kênh mới.
    if (empty($in['keep_own']) || !$conn['external_account_id']) {
        $first = array_shift($accounts);
        $pdo->prepare('UPDATE connections SET external_account_id=?, label=?, last_metrics=NULL WHERE id=?')
            ->execute([(string)$first['id'], (string)($first['name'] ?: $conn['label']), $conn['id']]);
    }

    $ins = $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,external_account_id,access_token,refresh_token,expires_at,connected_by,connected_at)
        VALUES (?,?,?,?,'connected',?,?,?,?,?,datetime('now'))");
    $created = 0;
    foreach ($accounts as $a) {
        if (in_array((string)$a['id'], $taken, true)) continue;
        $ins->execute([gen_id('CH'), pid(), $conn['provider'], (string)($a['name'] ?: $a['id']), (string)$a['id'],
            $conn['access_token'], $conn['refresh_token'], $conn['expires_at'], $user['id']]);
        $created++;
    }
    respond(['ok' => true, 'created' => $created]);
}

fail('action không hợp lệ');

/** Danh sách tài khoản/kênh mà người đã đăng nhập OAuth quản lý, để chọn thay vì gõ ID tay. */
function list_accounts(array $CFG, array $conn): array {
    $p = $conn['provider'];

    // Mỗi lần đăng nhập TikTok là đúng một tài khoản (đã gắn vào kênh ở bước callback) — không có danh sách để chọn.
    if (PROVIDERS[$p]['auth'] === 'tiktok') fail('TikTok: mỗi lần đăng nhập chỉ kết nối một tài khoản. Muốn thêm tài khoản TikTok khác, bấm Thêm kênh TikTok rồi đăng nhập bằng tài khoản đó', 400);

    if (PROVIDERS[$p]['auth'] === 'google') {
        $token = google_access_token($CFG, $conn);
        if (!$token) fail('Token Google hết hạn — hãy kết nối lại', 502);
        $auth = ['Authorization: Bearer ' . $token];

        if ($p === 'ga4') {
            [$code, $d] = http_get_json('https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', $auth);
            if ($code !== 200) fail('GA4: ' . ($d['error']['message'] ?? 'không lấy được danh sách property'), 502);
            $out = [];
            foreach ($d['accountSummaries'] ?? [] as $acc) {
                foreach ($acc['propertySummaries'] ?? [] as $prop) {
                    $out[] = ['id' => str_replace('properties/', '', $prop['property']),
                        'name' => $prop['displayName'] . ' (' . ($acc['displayName'] ?? '') . ')'];
                }
            }
            return $out;
        }
        if ($p === 'gsc') {
            [$code, $d] = http_get_json('https://www.googleapis.com/webmasters/v3/sites', $auth);
            if ($code !== 200) fail('Search Console: ' . ($d['error']['message'] ?? 'không lấy được danh sách website'), 502);
            return array_map(fn($x) => ['id' => $x['siteUrl'], 'name' => $x['siteUrl'] . ' (' . ($x['permissionLevel'] ?? '') . ')'], $d['siteEntry'] ?? []);
        }
        if ($p === 'gbp') {
            [$code, $d] = http_get_json('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $auth);
            if ($code !== 200) fail('Google Business Profile: ' . ($d['error']['message'] ?? 'không lấy được tài khoản'), 502);
            $out = [];
            foreach ($d['accounts'] ?? [] as $acc) {
                [$c2, $l] = http_get_json('https://mybusinessbusinessinformation.googleapis.com/v1/' . $acc['name'] . '/locations?readMask=name,title,storefrontAddress&pageSize=100', $auth);
                foreach ($l['locations'] ?? [] as $loc) $out[] = ['id' => $acc['name'] . '/' . $loc['name'], 'name' => ($loc['title'] ?? $loc['name']) . ' — ' . ($acc['accountName'] ?? '')];
            }
            return $out;
        }
        if ($p === 'youtube') {
            [$code, $d] = http_get_json('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', $auth);
            if ($code !== 200) fail('YouTube: ' . ($d['error']['message'] ?? 'không lấy được kênh'), 502);
            return array_map(fn($c) => ['id' => $c['id'], 'name' => $c['snippet']['title'] ?? $c['id']], $d['items'] ?? []);
        }
        if ($p === 'google_ads') {
            if (!$CFG['google_ads_developer_token']) fail('Cần google_ads_developer_token trong config.local.php');
            [$code, $d] = http_get_json('https://googleads.googleapis.com/' . $CFG['google_ads_api_version'] . '/customers:listAccessibleCustomers',
                array_merge($auth, ['developer-token: ' . $CFG['google_ads_developer_token']]));
            if ($code !== 200) fail('Google Ads: ' . ($d['error']['message'] ?? 'không lấy được tài khoản'), 502);
            return array_map(fn($r) => ['id' => str_replace('customers/', '', $r), 'name' => 'Google Ads ' . str_replace('customers/', '', $r)], $d['resourceNames'] ?? []);
        }
    }

    $path = $p === 'facebook_page' ? 'me/accounts' : 'me/adaccounts';
    $out = [];
    $url = graph_url($CFG, $path, ['fields' => 'id,name', 'limit' => 100, 'access_token' => $conn['access_token']]);
    for ($page = 0; $url && $page < 20; $page++) {
        [$code, $d] = http_get_json($url);
        if ($code !== 200) fail('Meta: ' . ($d['error']['message'] ?? 'không lấy được danh sách'), 502);
        foreach ($d['data'] ?? [] as $a) $out[] = ['id' => $a['id'], 'name' => $a['name'] ?? $a['id']];
        $url = $d['paging']['next'] ?? null;
    }
    return $out;
}
