<?php
/**
 * Sao chép file này thành config.local.php (cùng thư mục) rồi điền giá trị thật.
 * config.local.php KHÔNG được commit lên git (đã có trong .gitignore).
 *
 * Mã tài khoản từng kênh (GA4 property, kênh YouTube, Fanpage, tài khoản quảng cáo…)
 * KHÔNG khai ở đây — mỗi kênh được thêm và chọn trong giao diện: Thiết lập > Kết nối kênh.
 */
return [
  // Đổi thành https://mkt.webseo.vn khi triển khai lên VPS.
  'app_url' => 'http://localhost:8080',

  // --- Google: dùng chung cho Website (GA4), YouTube và Google Ads ---
  // Tạo tại https://console.cloud.google.com/apis/credentials
  // Bật các API: "Google Analytics Data API", "Google Analytics Admin API",
  //   "YouTube Data API v3", "YouTube Analytics API", và "Google Ads API" (nếu đo quảng cáo Google).
  // Tạo OAuth 2.0 Client ID loại "Web application",
  //   Authorized redirect URI: <app_url>/api/oauth_google_callback.php
  'google_client_id' => '',
  'google_client_secret' => '',

  // --- Google Ads (tuỳ chọn) — cần Developer Token đã được Google duyệt ---
  // Xin tại mục "API Center" trong tài khoản Google Ads (MCC).
  'google_ads_developer_token' => '',
  'google_ads_login_customer_id' => '', // customer id MCC, chỉ số, không dấu gạch
  'google_ads_api_version' => 'v22',    // Google ngừng hỗ trợ bản cũ sau ~1 năm — nâng khi báo lỗi version

  // --- Meta: dùng chung cho Fanpage Facebook và Meta Ads ---
  // Tạo app tại https://developers.facebook.com/apps (loại "Business"),
  // thêm sản phẩm "Facebook Login for Business",
  //   Valid OAuth Redirect URI: <app_url>/api/oauth_meta_callback.php
  // Quyền dùng: pages_show_list, pages_read_engagement, read_insights (Fanpage) và ads_read (quảng cáo).
  // Chế độ phát triển (chưa App Review): chỉ admin/tester của app đăng nhập được — đủ để phòng Marketing tự dùng.
  'meta_app_id' => '',
  'meta_app_secret' => '',
  'meta_graph_version' => 'v23.0',

  // --- TikTok: số liệu kênh TikTok (người theo dõi, lượt xem/thích/bình luận/chia sẻ video) ---
  // Tạo app tại https://developers.tiktok.com/apps → thêm sản phẩm "Login Kit" và "Display API",
  //   xin quyền (scope): user.info.basic, user.info.profile, user.info.stats, video.list.
  //   Redirect URI (BẮT BUỘC https, không nhận http://localhost): <app_url>/api/oauth_tiktok_callback.php
  //   → thử ở máy này phải qua đường hầm https (Cloudflare Tunnel / ngrok) và đặt app_url bằng địa chỉ đó.
  // App chưa được TikTok duyệt (sandbox): chỉ tài khoản được thêm vào "Target users" của app mới đăng nhập được.
  'tiktok_client_key' => '',     // "Client key" của app (TikTok gọi là client_key, không phải client_id)
  'tiktok_client_secret' => '',
  // Hai dòng dưới chỉ đổi khi kiểm thử bằng máy chủ TikTok giả; để mặc định khi dùng thật.
  // 'tiktok_api_base' => 'https://open.tiktokapis.com',
  // 'tiktok_auth_base' => 'https://www.tiktok.com',

  // --- AI (Claude của Anthropic / ChatGPT của OpenAI) ---
  // Cách khuyến nghị: quản trị viên nhập khoá trong giao diện Thiết lập > AI & khoá API
  // (lưu ở CSDL, được ưu tiên). Các dòng dưới đây chỉ là dự phòng khi chưa nhập trên giao diện.
  // Claude: https://console.anthropic.com → API Keys (cần nạp credit ở mục Billing).
  // ChatGPT: https://platform.openai.com → API keys (cần nạp tiền ở mục Billing).
  // Khoá chỉ dùng ở máy chủ (api/_ai_lib.php), không bao giờ gửi xuống trình duyệt.
  'anthropic_api_key' => '',
  // 'anthropic_model' => 'claude-opus-5-5', // tuỳ chọn — mặc định lấy theo config.php
  // 'openai_api_key' => '',
  // 'openai_model' => 'gpt-6.1-sol',       // tuỳ chọn

  // --- Tài khoản tạo khi khởi tạo CSDL lần đầu (seed.php) ---
  // Để trống mật khẩu thì hệ thống tự sinh và ghi vào data/initial-passwords.txt.
  'initial_admin_email' => '',      // mặc định admin@marketing.local
  'initial_admin_password' => '',

  // --- Khởi tạo lần đầu (chỉ dùng khi tạo CSDL) ---
  'initial_admin_name' => '',          // tên hiển thị quản trị
  'default_project_id' => '',          // slug dự án mặc định, vd 'chungauto'
  'default_project_name' => '',        // vd 'Chung Auto'
  'default_project_domain' => '',      // vd 'chungauto.vn'
  'default_project_goal' => '',        // mục tiêu marketing một câu
  'brand_hotline' => '',
  // --- Bảo mật ---
  'token_key' => '',                   // openssl rand -hex 32 — mã hoá token kênh (bắt buộc nên có)
  'hl_api_key' => '',                  // khoá máy HL (tuỳ chọn)
    // Zalo cá nhân (zalo-bridge/README.md)
    'zalo_bridge_url' => 'http://127.0.0.1:8721',
    'zalo_bridge_key' => '',
];
