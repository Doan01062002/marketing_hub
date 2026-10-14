# Nâng cấp Marketing Hub theo dự án — bàn giao giai đoạn 1 & 2 (11/10/2026)

Nhánh: `nang-cap-du-an` tại `/home/wsvn/web/mkt.webseo.vn/private/dev/app` (git). Bản DEV chạy tại **https://mkt.webseo.vn/dev/**
(nginx `nginx.ssl.conf_dev`, cùng pool PHP, CSDL riêng `private/dev/app/data/marketing.sqlite` — bản sao của production lúc 08:07 11/10/2026).
Production (`/home/wsvn/web/mkt.webseo.vn/public_html` + `private/app`) **chưa đổi gì**.

## 1. Đã làm

### Giai đoạn 1 — nền tảng dự án (backend)
- Bảng mới `projects`, `project_members` (vai trò theo dự án: owner / manager / content / ads / client / viewer). `schema.sql`, `api/_boot.php` v8–v9.
- Cột `project_id` trên mọi bảng nghiệp vụ (`PROJECT_TABLES` trong `_boot.php`), có index. Migration tự chạy khi `PRAGMA user_version < 9`:
  dữ liệu cũ `co='co2'` → dự án `share123`, còn lại → `webseo`; kênh theo nhãn; bình luận/bài đăng theo kênh; mọi tài khoản thành thành viên cả hai dự án.
- Helper: `current_project()` (header `X-Project` → `?project_id` → body → phiên → dự án đầu), `pid()`, `project_role()`, `require_project_write()`,
  `require_project_admin()`, `can_approve_project()`, `scoped($table,$id)`, `need()`. `get_connection()` chỉ trả kênh cùng dự án trong request web.
- Mọi endpoint lọc/ghi theo dự án và chặn IDOR (id dự án khác → 404): campaigns, content, tasks, leads, connections, insights, segments, trends,
  competitors, brand, okrs, posts, comments, metrics, stats, automations, sequences, bot, plan_auto, users (stats), activity, ai_chat, quick_web, OAuth start.
- Thư viện: bình luận/lead tạo từ bình luận mang `project_id` của kênh; kịch bản tự động chỉ chạy với kịch bản **cùng dự án** với đối tượng gây sự kiện;
  kịch bản theo lịch quét đúng dự án; người nhận việc/thông báo là thành viên dự án; `notify()` ghi `project_id`, Telegram riêng theo dự án nếu đặt.
- `api/projects.php`: danh sách, tạo, sửa, lưu trữ, thành viên (`member_set`, `member_remove`), chọn dự án cho phiên.
- `connections.php`: thêm `set_project` (chuyển kênh sang dự án khác, cần chủ/quản lý cả hai bên).
- Phiên DEV tách riêng (cookie `MKTSESSDEV`, path `/dev/`) — đăng nhập DEV không ảnh hưởng bản thật.

### Giai đoạn 2 — giao diện
- `index.html`: lớp đường dẫn `APP_BASE`/`API()` (chạy được ở `/` lẫn `/dev/`), header `X-Project` trên mọi request, bộ chọn dự án ở sidebar + thanh trên,
  `switchProject()` huỷ cache mọi module và nạp lại; request về muộn bị bỏ (`S.projSeq`).
- Sidebar 11 menu theo đặc tả + Trợ lý dự án + Tất cả dự án. Route hash `#menu/tab`. Trang cũ nằm trong tab của menu mới (bảng `LEGACY`).
- Kênh marketing: tab Website / Facebook / YouTube / TikTok / Zalo OA / Google Maps / Email / Google Ads; tab con Tổng quan & hiệu suất, Nội dung & lịch, Tương tác, Kết nối API.
- Chiến dịch quảng cáo: tab Google Ads / Facebook Ads / TikTok Ads (lọc chiến dịch theo nền tảng; hiện rõ "chưa kết nối tài khoản" thay vì số 0).
- Chăm sóc dự án (4 tab), Khách hàng & lead (danh sách + pipeline kéo trạng thái), Tiện ích (landing 3 chế độ, học hỏi đối thủ, ý tưởng → chiến dịch nháp idempotent),
  Cài đặt dự án (thông tin, thành viên & quyền, kết nối, nhân sự, hệ thống), Tất cả dự án + tạo dự án.
- API mới: `api/ideas.php`, `api/landings.php` (đọc link tham chiếu chống SSRF, xem thử trong khung CSP `sandbox`, không script; AI viết nội dung khi có khoá).

## 2. Kiểm thử đã chạy
- `php -l` toàn bộ; `node --check` khối JS.
- Script `kiem-thu-1.sh`: migration v8, bootstrap theo dự án, `X-Project` lạ → 403, sửa bản ghi dự án khác → 404, viewer tạo chiến dịch → 403,
  content tạo được nhưng không xoá được (403), 19 endpoint GET trả 200 theo dự án.
- Playwright (desktop 1366 + iPhone 390): đăng nhập, 23 màn hình/tab, đổi dự án — **0 lỗi JS**. Ảnh chụp trong `scratchpad/mkt/shots`.
- Tài khoản thử trên DEV (chỉ DB dev): `dev.admin@mkt.local` (admin) và `dev.staff@mkt.local` (staff), mật khẩu `Dev-Mkt-2026!`. Tài khoản thật cũng đăng nhập được (DB sao chép).

## 3. Giới hạn còn lại / việc tiếp theo
- `comments.id` vẫn là `provider:external_id` → cùng một Page gắn vào hai dự án sẽ đụng khoá (hiện mỗi kênh thuộc một dự án nên chưa xảy ra).
- Cấu hình SMTP/Telegram/AI còn toàn cục (`app_settings`); dự án mới chỉ có `telegram_chat_id`, `hotline` riêng.
- Chưa có `publications` tách khỏi `posts` (một nội dung nhiều phiên bản kênh) — posts/post_targets hiện làm vai trò này.
- Chưa có `metrics_daily` dạng chuẩn hoá; vẫn `metrics_history` (JSON/ngày/kênh).
- Token kênh lưu rõ trong SQLite (như trước) — cần mã hoá ở giai đoạn bảo mật.
- Kênh Email chưa có provider; chiến dịch email đang chạy ở máy HL (app :8686) — giai đoạn 3 nối API.
- Google Ads/TikTok Ads: chỉ đọc số liệu khi có token; chưa ghi lên nền tảng (đúng đặc tả: tạo ở nền tảng, hub theo dõi/duyệt).
- OAuth trên DEV: redirect URI `https://mkt.webseo.vn/dev/api/oauth_*_callback.php` phải được thêm vào app Google/Meta/TikTok thì mới đăng nhập được từ DEV.

## 4. Đưa lên production (khi anh Tập duyệt)
1. Sao lưu `private/app/data/marketing.sqlite` và `public_html/index.html`.
2. `rsync` từ `private/dev/app/{api,schema.sql,docs}` sang `private/app/`, `index.html` sang `public_html/`; `systemctl reload php8.3-fpm`.
3. Mở app một lần (migration v9 tự chạy, có `PRAGMA user_version`). Kiểm tra: đăng nhập, bộ chọn dự án, Tổng quan, Kênh, Cài đặt → Thành viên.
4. Rollback: chép lại hai file sao lưu; CSDL cũ dùng được với mã cũ vì chỉ thêm cột/bảng.

## 5. Giai đoạn 3 (11/10/2026, chiều) — dữ liệu thật & máy HL
- **CRM webseo.vn (chỉ đọc):** `api/_crm_webseo_lib.php` + `api/crm.php`. User MySQL `wsvn_mkt_ro` chỉ có SELECT trên `wsvn_app` (mật khẩu ở `config.local.php`: `crm_webseo_dsn/user/pass`, bản sao ở `/root/.mkt-crm-ro-pass`).
  Dự án bật bằng `projects.settings.crm = 'webseo'` (migration v10 bật sẵn cho dự án webseo; đổi ở Cài đặt dự án → Nguồn CRM).
  Trả: lead (ht_hoi_thoai) theo kỳ/kênh/ngày, khách mới, dịch vụ đang hoạt động & sắp hết hạn 7/30 ngày, tiền vào/ra theo tháng (payments), đơn hàng theo trạng thái/nguồn, ticket. SĐT che giữa. Cache 10 phút (`app_settings crm.cache.*`). Vai trò client/viewer chỉ xem tổng hợp.
- **Máy HL ↔ hub:** `api/hl.php` (POST với `X-HL-Key` = `hl_api_key` trong config.local.php, bản sao `/root/.mkt-hl-key`; từ chối payload chứa SĐT/email thô). Bảng `hl_reports(project_id, kind, day, data)`.
  Máy HL (D:\App MARKETING WEBSEO, `src/mkt-hub.js`, cấu hình `mktHub` trong data/config.json) gửi 5 loại báo cáo: tim_khach, email_chien_dich, hop_thu, seo_hang_ngay (kèm danh sách bài đã đăng/cập nhật + việc dự kiến từ Search Console), kinh_doanh — lúc 20:10, mỗi 3 giờ, và ngay khi có khách trả lời thư. Route tay: `GET /api/mkt-hub/tong-hop`, `POST /api/mkt-hub/gui` trên app :8686.
- **Giao diện:** Tổng quan có thẻ "Dữ liệu thật từ CRM webseo.vn" và "Máy HL"; Khách hàng & lead có tab "CRM webseo.vn (thật)" (lead gần đây + dịch vụ sắp hết hạn); Kênh → Email = báo cáo thư chào hàng/hộp thư từ máy HL; Chăm sóc dự án → Lịch đăng mở mặc định ở Hàng chờ & lịch và có bảng "Bài SEO/GEO đăng & cập nhật tự động" (anh Tập chỉ theo dõi).
- Khi lên production: đổi `mktHub.url` trên máy HL từ `https://mkt.webseo.vn/dev` sang `https://mkt.webseo.vn`, chép 4 khoá (`crm_webseo_*`, `hl_api_key`) sang `private/app/config.local.php`.

## 6. TikTok — một app dùng chung, nhiều dự án, nhiều tài khoản (11/10/2026, chiều)
- Mỗi tài khoản TikTok = một dòng `connections` (provider tiktok) thuộc đúng `project_id`; token access/refresh mã hoá libsodium (`enc1:` + `token_key` trong config.local.php — mất khoá là mất token đã lưu); cột mới `env`, `scopes`, `account_name`, `avatar` (schema v11).
- `tiktok_app($CFG)` (_boot.php): khoá theo môi trường — app_settings `tiktok.env`, `tiktok.client_key/secret` (production), `tiktok.sandbox_client_key/secret` (sandbox); fallback config.local.php (`tiktok_client_key` tiền tố `sbaw` tự coi là sandbox). Scope: `tiktok.scopes` (mặc định user.info.basic,video.list,video.upload,video.publish).
- `oauth_tiktok_start.php` → chỉ chủ/quản lý dự án; `oauth_tiktok_callback.php` → đối chiếu state + conn + dự án, đổi code bằng secret phía máy chủ, lưu token mã hoá, tên/ảnh, quyền đã cấp, env; trùng open_id ở dự án khác → báo dùng "Chuyển dự án". Làm mới token theo đúng env của tài khoản (`tiktok_access_token`).
- `api/tiktok.php`: GET trạng thái app + tài khoản của dự án; POST connect / reconnect / check_publish (hỏi creator_info thật) / app (admin lưu khoá). Ngắt / xoá / đổi tên / chuyển dự án dùng `connections.php` (`set_project` cần chủ/quản lý cả hai dự án).
- Đồng bộ (`sync_tiktok`): chỉ hỏi trường theo scope đã cấp (không có user.info.stats → không hiện theo dõi/thích; không có video.list → bỏ nhóm video, ghi chú).
- Giao diện: Kênh marketing → TikTok → Kết nối API: bảng tài khoản (trạng thái, hạn token, mã hoá, quyền, đồng bộ, nút Kết nối lại / Đồng bộ / Kiểm tra quyền đăng / Ngắt / Chuyển dự án / Xoá) + form cấu hình app (quản trị). Thông báo sandbox: chỉ Target Users, video đăng riêng tư cho tới khi TikTok duyệt Production.
- Callback production: `https://mkt.webseo.vn/api/oauth_tiktok_callback.php` (DEV: `/dev/api/...` — muốn thử OAuth ở DEV phải đăng ký thêm URI này trong app TikTok).
- Đã kiểm thử (DEV): tok_enc/tok_dec; tạo tài khoản + URL authorize đúng client_key/scope/redirect/state; callback state sai → lỗi rõ; TikTok từ chối → lỗi rõ; vai trò nội dung không kết nối được (403); chuyển dự án. Chưa kiểm được bước TikTok trả code → cần tài khoản @tongkhobearvietnam đăng nhập thật.
