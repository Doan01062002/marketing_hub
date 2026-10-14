# Marketing Hub — hướng dẫn cài cho tên miền mới

Bản mã này là Marketing Hub (một file giao diện `index.html` + API PHP + SQLite), tách sạch dữ liệu,
khoá API và tài khoản của nơi dùng trước. Cài xong, đăng nhập bằng tài khoản quản trị đầu tiên rồi
tự nối tài khoản Facebook, YouTube, TikTok, Google, Zalo… của mình trong giao diện.

## 1. Yêu cầu máy chủ

- Nginx (hoặc Apache) + PHP 8.1 trở lên (đang chạy thật trên PHP 8.3).
- PHP modules: `pdo_sqlite`, `sqlite3`, `curl`, `openssl`, `mbstring`, `json`, `fileinfo`, `gd`, `sodium` (mã hoá token), `intl`.
- HTTPS bắt buộc (Facebook/TikTok/Google chỉ nhận Redirect URI https).
- Một thư mục **ngoài** webroot để chứa mã + CSDL (ví dụ `/home/<user>/web/<ten-mien>/private/app`).

## 2. Bố trí thư mục (giống bản đang chạy)

```
/home/<user>/web/<ten-mien>/
├── private/app/              ← toàn bộ thư mục này (mã + dữ liệu), KHÔNG nằm trong webroot
│   ├── api/  cron/  docs/  schema.sql  config.php  config.team.php  config.local.php  index.html
│   ├── data/                 ← tự tạo khi chạy lần đầu: marketing.sqlite, log cron
│   └── uploads/              ← ảnh/video đăng bài
└── public_html/              ← webroot
    ├── index.html            ← bản sao (cp) của private/app/index.html
    ├── api      -> ../private/app/api        (symlink)
    └── uploads  -> ../private/app/uploads    (symlink)
```

```bash
W=/home/<user>/web/<ten-mien>
mkdir -p $W/private/app && cp -a mkt.aigara.vn/. $W/private/app/
mkdir -p $W/private/app/data $W/private/app/uploads
cp $W/private/app/index.html $W/public_html/index.html
ln -s ../private/app/api     $W/public_html/api
ln -s ../private/app/uploads $W/public_html/uploads
chown -R <user>:<user> $W/private $W/public_html
```

Mỗi lần cập nhật `index.html` phải chép lại sang `public_html/` (hoặc symlink luôn file đó).

## 3. Nginx

Thêm vào server block của tên miền (phần `location` PHP dùng đúng socket php-fpm của máy):

```nginx
client_max_body_size 220m;
location ^~ /api/_ { deny all; return 404; }                       # thư viện nội bộ api/_*.php không được gọi trực tiếp
location ^~ /uploads/ { location ~* \.(php\d?|phtml|phar)$ { deny all; return 404; } }
location ~ /\. { deny all; return 404; }
```

`.user.ini` (hoặc php.ini của pool) trong webroot:

```
upload_max_filesize=210M
post_max_size=220M
max_execution_time=300
memory_limit=512M
```

## 4. Cấu hình

```bash
cd $W/private/app
cp config.local.example.php config.local.php
chmod 640 config.local.php
```

Sửa `config.local.php`:

| Khoá | Ý nghĩa |
|---|---|
| `app_url` | `https://<ten-mien>` — dùng để ghép Redirect URI OAuth |
| `initial_admin_email`, `initial_admin_password` | Tài khoản quản trị tạo lúc khởi tạo CSDL lần đầu |
| `default_project_id/name/domain/goal` | Dự án đầu tiên (ví dụ `chungauto`, `Chung Auto`, `chungauto.vn`) |
| `token_key` | 64 ký tự hex ngẫu nhiên (`openssl rand -hex 32`) để mã hoá token kênh trong CSDL |
| `google_client_id/secret` | App Google Cloud (Search Console, GA4, YouTube, Google Ads, Maps) — Redirect URI: `<app_url>/api/oauth_google_callback.php` |
| `meta_app_id/secret` | App Meta (Fanpage, Instagram, Messenger, Ads) — Redirect URI: `<app_url>/api/oauth_meta_callback.php`; webhook `<app_url>/api/webhook_meta.php` |
| `tiktok_client_key/secret` | Hoặc nhập trên giao diện Kênh marketing → TikTok → Kết nối API; Redirect: `<app_url>/api/oauth_tiktok_callback.php` |
| X (Twitter) | Nhập trên giao diện Kênh marketing → X → Kết nối API; Redirect: `<app_url>/api/oauth_x_callback.php` |
| `anthropic_api_key` | Tuỳ chọn, cho Trợ lý dự án / AI soạn nội dung chạy trên máy chủ |
| `hl_api_key` | Tuỳ chọn, khoá cho "máy HL" (xem mục 7) |

Để trống khoá nào thì tính năng đó chỉ báo "chưa cấu hình", không lỗi.

## 5. Khởi tạo lần đầu

Mở `https://<ten-mien>/` — lần đầu `api/_boot.php` tự tạo `data/marketing.sqlite` theo `schema.sql`, chạy `seed.php`
để tạo tài khoản quản trị + dự án mặc định (không có dữ liệu mẫu). Nếu không điền mật khẩu trong config, mật khẩu
ngẫu nhiên được ghi vào `data/initial-passwords.txt` — đọc xong đổi mật khẩu ở Cài đặt → Tài khoản và xoá file.

## 6. Cron (bắt buộc để đăng hẹn giờ, đồng bộ, kịch bản, cửa sổ chat)

```cron
*/5 * * * * cd /home/<user>/web/<ten-mien>/private/app && /usr/bin/php8.3 cron/run.php   >> data/cron.log 2>&1
*   * * * * cd /home/<user>/web/<ten-mien>/private/app && /usr/bin/php8.3 cron/inbox.php >> data/cron-inbox.log 2>&1
```

Chạy bằng user sở hữu thư mục (không chạy root để tránh sai quyền file SQLite).

## 7. Những phần phụ thuộc "máy HL" (tuỳ chọn)

Một số tính năng gọi sang một máy tính riêng (gọi là máy HL) chạy ứng dụng Node riêng, dùng Claude Code
gói Max thay cho API trả phí: AI trả lời Cửa sổ chat, lấy số liệu Kho kênh tham khảo, nút "Làm theo" soạn
carousel. Bản mã này KHÔNG kèm ứng dụng đó. Không có máy HL thì:
- Cửa sổ chat vẫn nhận/gửi tin, chỉ không có AI gợi ý/trả lời tự động (hoặc điền `anthropic_api_key` để dùng AI trên máy chủ ở phần Trợ lý).
- Kho kênh tham khảo vẫn lưu kênh, format, ảnh mẫu; số liệu nhập tay; nút "Làm theo" sẽ xếp việc và chờ.
Endpoint máy HL: `api/hl.php`, `api/hl_jobs.php` (xác thực header `X-HL-Key` = `hl_api_key`).

## 8. Sau khi cài

1. Đăng nhập → Cài đặt → Tài khoản: đổi mật khẩu, thêm nhân sự.
2. Tất cả dự án → sửa dự án mặc định (tên, tên miền, mục tiêu, giọng thương hiệu).
3. Kênh marketing → từng tab → Kết nối API: nối Fanpage, YouTube, TikTok, Google (GA4/Search Console), Zalo OA, X.
4. Cài đặt → Gửi tin & thông báo: Telegram bot (tuỳ chọn), email.
5. Phần "CRM webseo.vn" trong Cài đặt dự án là tích hợp riêng của nơi dùng trước (đọc MySQL của webseo.vn); để "— Không —".

## 9. Cập nhật bản mới

Chép đè `api/`, `cron/`, `schema.sql`, `docs/`, `index.html` (rồi cp sang webroot). Không đụng `data/`, `uploads/`,
`config.local.php`. `api/_boot.php` tự nâng cấp CSDL theo `SCHEMA_VERSION`, không mất dữ liệu. Sau khi đổi PHP:
`systemctl reload php8.3-fpm`.
