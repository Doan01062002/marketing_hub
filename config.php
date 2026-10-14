<?php
/* Thứ tự ưu tiên (sau đè trước): giá trị mặc định dưới đây < config.team.php (khóa dùng chung của team, có trong git)
   < config.local.php (riêng từng máy, KHÔNG có trong git). Ô để trống ('') không đè giá trị của lớp trước,
   nên file config.local.php tạo từ bản mẫu (toàn ô trống) không làm mất khóa chung. */
$cfg = [];
foreach (['config.team.php', 'config.local.php'] as $__f) {
  $__p = __DIR__ . '/' . $__f;
  $__layer = file_exists($__p) ? (require $__p) : [];
  if (is_array($__layer)) $cfg = array_merge($cfg, array_filter($__layer, fn($v) => $v !== '' && $v !== null));
}
return array_merge([
  'app_url' => 'http://localhost:8080',
  'google_client_id' => '',
  'google_client_secret' => '',
  'google_ads_developer_token' => '',
  'google_ads_login_customer_id' => '',
  'google_ads_api_version' => 'v22',
  'meta_app_id' => '',
  'meta_app_secret' => '',
  'meta_graph_version' => 'v23.0',
  'tiktok_client_key' => '',
  'tiktok_client_secret' => '',
  'tiktok_api_base' => 'https://open.tiktokapis.com',
  'tiktok_auth_base' => 'https://www.tiktok.com',
  'tiktok_publish' => true,
  'anthropic_api_key' => '',
  // Khởi tạo lần đầu (seed.php): tài khoản quản trị + dự án mặc định. 13/10/2026
  'initial_admin_email' => '',
  'initial_admin_name' => '',
  'initial_admin_password' => '',
  'default_project_id' => '',
  'default_project_name' => '',
  'default_project_domain' => '',
  'default_project_goal' => '',
  'brand_hotline' => '',
  'token_key' => '',          // 64 ký tự hex (openssl rand -hex 32): mã hoá token kênh trong CSDL
  'hl_api_key' => '',         // khoá cho máy HL (tuỳ chọn, xem CAI-DAT.md mục 7)
  'anthropic_model' => 'claude-opus-5-5',
], $cfg);
