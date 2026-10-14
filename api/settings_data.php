<?php
/* Thiết lập > Nguồn dữ liệu thị trường: khoá SerpApi (Google Trends) và Google Places API (đối thủ trên Google Maps).
   GET  → trạng thái (khoá chỉ hiện dạng che)
   POST {serpapi_key?, places_key?, clear?:['serpapi'|'places'], trends_geo?} — chỉ quản trị. Ô để trống = giữ khoá cũ. */
require __DIR__ . '/_boot.php';
$u = require_login();

const DATA_KEYS = ['serpapi' => 'data.serpapi_key', 'places' => 'data.places_key'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = ['can_edit' => $u['role'] === 'admin', 'trends_geo' => setting_get('data.trends_geo', 'VN'),
        'google' => ['has_client' => (bool)($CFG['google_client_id'] && $CFG['google_client_secret']), 'client_id' => $CFG['google_client_id'] ?: '',
            'from_config' => (bool)(require __DIR__ . '/../config.php')['google_client_id'], 'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_google_callback.php'],
        'meta' => ['has_client' => (bool)($CFG['meta_app_id'] && $CFG['meta_app_secret']), 'app_id' => $CFG['meta_app_id'] ?: '',
            'from_config' => (bool)(require __DIR__ . '/../config.php')['meta_app_id'], 'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_meta_callback.php'],
        'tiktok' => ['has_client' => (bool)tiktok_app($CFG)['configured']]];
    foreach (DATA_KEYS as $k => $sk) { $v = setting_get($sk); $out[$k] = ['has_key' => (bool)$v, 'hint' => $v ? mask_secret($v) : '']; }
    respond($out);
}

require_method('POST');
require_role('admin');
$in = json_input();
foreach (DATA_KEYS as $k => $sk) {
    $v = trim((string)($in[$k . '_key'] ?? ''));
    if ($v !== '') setting_set($sk, $v, $u);
    if (in_array($k, (array)($in['clear'] ?? []), true)) setting_set($sk, null, $u);
}
$gid = trim((string)($in['google_client_id'] ?? '')); $gsec = trim((string)($in['google_client_secret'] ?? ''));
if ($gid !== '') { if (!str_ends_with($gid, '.apps.googleusercontent.com')) fail('Client ID phải kết thúc bằng .apps.googleusercontent.com'); setting_set('google.client_id', $gid, $u); }
if ($gsec !== '') setting_set('google.client_secret', $gsec, $u);
$mid = trim((string)($in['meta_app_id'] ?? '')); $msec = trim((string)($in['meta_app_secret'] ?? ''));
if ($mid !== '') { if (!ctype_digit($mid)) fail('App ID của Meta chỉ gồm chữ số'); setting_set('meta.app_id', $mid, $u); }
if ($msec !== '') setting_set('meta.app_secret', $msec, $u);
if (isset($in['trends_geo'])) setting_set('data.trends_geo', strtoupper(preg_replace('/[^A-Za-z-]/', '', (string)$in['trends_geo'])) ?: 'VN', $u);
log_activity('update', 'settings', 'data', 'Cập nhật khoá nguồn dữ liệu thị trường', $u);
respond(['ok' => true]);
