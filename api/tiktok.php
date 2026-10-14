<?php
/* TikTok — nhiều dự án, nhiều tài khoản, một app TikTok Developer dùng chung. 11/10/2026
   GET                       → {app:{env, configured, has_sandbox, has_production, redirect_uri, scopes, can_edit_keys}, accounts:[…] của dự án, publish_note}
   POST {action:'connect', label?}        → tạo tài khoản kênh mới trong dự án + trả start_url (chủ/quản lý dự án)
   POST {action:'reconnect', id}          → start_url cho tài khoản đã có (chủ/quản lý)
   POST {action:'check_publish', id}      → hỏi TikTok creator_info: có đăng được không, chế độ riêng tư cho phép (không tạo dữ liệu giả)
   POST {action:'app', env?, client_key?, client_secret?, sandbox_client_key?, sandbox_client_secret?, scopes?, clear?:[…]}  → admin hệ thống lưu khoá app
   Ngắt kết nối / xoá / đổi tên / chuyển dự án: dùng api/connections.php (disconnect, delete, rename, set_project). */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$PID = pid();
$app = tiktok_app($CFG, $PID);

function tiktok_public(array $CFG, array $r): array {
    $p = public_connection($r);
    $p['token_valid'] = (bool)($r['expires_at'] && strtotime($r['expires_at']) > time() && $r['access_token']);
    $p['has_refresh'] = !empty($r['refresh_token']);
    $p['encrypted'] = str_starts_with((string)$r['access_token'], 'enc1:');
    return $p;
}

if ($method === 'GET') {
    $st = $pdo->prepare("SELECT * FROM connections WHERE project_id=? AND provider='tiktok' ORDER BY created_at"); $st->execute([$PID]);
    $accounts = array_map(fn($r) => tiktok_public($CFG, $r), $st->fetchAll());
    $out = $app; unset($out['client_key'], $out['client_secret']);
    $out['key_hint'] = $app['key_last4'] !== '' ? '…' . $app['key_last4'] : '';
    $pjs = jdecode(current_project()['settings'] ?? null); $out['project_sandbox'] = !empty($pjs['tiktok_sandbox']['key']) ? ['key_last4' => substr((string)$pjs['tiktok_sandbox']['key'], -4), 'label' => (string)($pjs['tiktok_sandbox']['label'] ?? '')] : null;
    $out['can_edit_keys'] = $me['role'] === 'admin';
    $out['token_encryption'] = (bool)tok_key($CFG);
    respond(['app' => $out, 'project_sandbox' => $out['project_sandbox'], 'accounts' => $accounts, 'can_manage' => in_array(project_role(), PROJECT_ADMIN_ROLES, true),
        'publish_note' => $app['env'] === 'sandbox'
            ? 'Ứng dụng đang ở SANDBOX: chỉ tài khoản trong danh sách Target Users đăng nhập được; video đăng qua API chỉ ở chế độ riêng tư (SELF_ONLY) cho tới khi TikTok duyệt Production.'
            : 'Production: khả năng đăng công khai phụ thuộc kết quả xét duyệt Content Posting API của TikTok — bấm "Kiểm tra quyền đăng" để hỏi TikTok thật.']);
}

require_method('POST');
$in = json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'app') {
    require_role('admin');
    $map = ['client_key' => 'tiktok.client_key', 'client_secret' => 'tiktok.client_secret', 'sandbox_client_key' => 'tiktok.sandbox_client_key', 'sandbox_client_secret' => 'tiktok.sandbox_client_secret'];
    foreach ($map as $k => $sk) { $v = preg_replace('/\s+/', '', (string)($in[$k] ?? '')); if ($v !== '') setting_set($sk, $v, $me); if (in_array($k, (array)($in['clear'] ?? []), true)) setting_set($sk, null, $me); }
    if (isset($in['env'])) { if (!in_array($in['env'], ['sandbox', 'production'], true)) fail('env phải là sandbox hoặc production'); setting_set('tiktok.env', $in['env'], $me); }
    if (isset($in['scopes'])) {
        $sc = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$in['scopes'])))));
        foreach ($sc as $s) if (!preg_match('/^[a-z]+(\.[a-z_]+)+$/', $s)) fail('Scope không hợp lệ: ' . $s);
        setting_set('tiktok.scopes', implode(',', $sc), $me);
    }
    log_activity('update', 'settings', 'tiktok', 'Cập nhật cấu hình app TikTok (' . implode(', ', array_keys(array_filter($in, fn($v, $k) => $k !== 'action' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH))) . ')', $me);
    respond(['ok' => true]);
}

require_project_admin();

if ($action === 'project_app') {
    // Sandbox riêng của dự án này (key + secret của đúng sandbox mà tài khoản là Target User). Secret mã hoá trước khi lưu.
    $st = $pdo->prepare('SELECT settings FROM projects WHERE id=?'); $st->execute([$PID]); $sj = jdecode($st->fetchColumn() ?: null);
    if (!empty($in['clear'])) { unset($sj['tiktok_sandbox']); }
    else {
        $k = preg_replace('/\s+/', '', (string)($in['client_key'] ?? '')); $sec = preg_replace('/\s+/', '', (string)($in['client_secret'] ?? ''));
        if ($k === '' || $sec === '') fail('Nhập cả Client Key và Client Secret của sandbox');
        if (!str_starts_with($k, 'sbaw')) fail('Client Key sandbox của TikTok bắt đầu bằng "sbaw"');
        $enc = tok_enc($CFG, $sec); if ($enc === $sec) fail('Máy chủ chưa có token_key để mã hoá secret — báo quản trị', 500);
        $sj['tiktok_sandbox'] = ['key' => $k, 'secret' => $enc, 'label' => mb_substr(trim((string)($in['label'] ?? '')), 0, 60)];
    }
    $pdo->prepare("UPDATE projects SET settings=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode($sj, JSON_UNESCAPED_UNICODE), $PID]);
    log_activity('update', 'project', $PID, !empty($in['clear']) ? 'Bỏ sandbox TikTok riêng của dự án' : 'Đặt sandbox TikTok riêng của dự án (…' . substr((string)$in['client_key'], -4) . ')', null, $PID);
    respond(['ok' => true]);
}

if ($action === 'connect') {
    if (!$app['configured']) fail('Chưa có Client Key / Secret cho môi trường ' . $app['env']);
    $label = trim((string)($in['label'] ?? '')) ?: ('TikTok ' . date('d/m H:i'));
    $id = gen_id('CH');
    $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,env) VALUES (?,?,'tiktok',?,'disconnected','oauth',?)")->execute([$id, $PID, $label, $app['env']]);
    log_activity('create', 'connection', $id, 'Thêm tài khoản TikTok ' . $label);
    respond(['id' => $id, 'start_url' => rtrim($CFG['app_url'], '/') . '/api/oauth_tiktok_start.php?conn_id=' . rawurlencode($id)], 201);
}

$conn = get_connection((string)($in['id'] ?? ''));
if (!$conn || $conn['provider'] !== 'tiktok') fail('Không tìm thấy tài khoản TikTok trong dự án này', 404);

if ($action === 'reconnect') {
    if (!$app['configured']) fail('Chưa có Client Key / Secret cho môi trường ' . $app['env']);
    respond(['start_url' => rtrim($CFG['app_url'], '/') . '/api/oauth_tiktok_start.php?conn_id=' . rawurlencode($conn['id'])]);
}

if ($action === 'check_publish') {
    [$tok, $terr] = tiktok_access_token($CFG, $conn);
    if (!$tok) fail($terr ?? 'Token TikTok hết hạn — kết nối lại', 424);
    [$c, $d] = http_call('POST', tiktok_url($CFG, '/v2/post/publish/creator_info/query/'), ['headers' => ['Authorization: Bearer ' . $tok], 'json' => new stdClass()]);
    $e = tiktok_error($c, $d);
    if ($e !== null) respond(['can_publish' => false, 'reason' => 'TikTok trả lời: ' . $e . ' (cần quyền video.publish và sản phẩm Content Posting API được duyệt)']);
    $opts = $d['data']['privacy_level_options'] ?? [];
    respond(['can_publish' => true, 'privacy_options' => $opts, 'public_allowed' => in_array('PUBLIC_TO_EVERYONE', $opts, true),
        'comment_disabled' => !empty($d['data']['comment_disabled']), 'duet_disabled' => !empty($d['data']['duet_disabled']), 'stitch_disabled' => !empty($d['data']['stitch_disabled']),
        'creator' => ['username' => $d['data']['creator_username'] ?? null, 'nickname' => $d['data']['creator_nickname'] ?? null, 'avatar' => $d['data']['creator_avatar_url'] ?? null, 'max_video_sec' => $d['data']['max_video_post_duration_sec'] ?? null],
        'note' => in_array('PUBLIC_TO_EVERYONE', $opts, true) ? 'TikTok cho phép đăng công khai từ app này.' : 'TikTok chỉ cho đăng ở chế độ: ' . implode(', ', $opts) . ' — app chưa được duyệt Production/Content Posting.']);
}

fail('Thao tác không hỗ trợ');
