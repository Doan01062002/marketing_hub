# Marketing Hub — công cụ làm việc của phòng Marketing

Nơi phòng Marketing kết nối các kênh, quản trị chiến dịch và đo lường hiệu quả
Fanpage, YouTube, website và quảng cáo. Đây là công cụ riêng của phòng
Marketing của **WebSEO** (Công ty TNHH Dịch vụ và Thiết kế WebSEO), chạy tại https://mkt.webseo.vn.

Hiện chạy **local trên máy này**, chưa đẩy lên VPS. Khi phòng Marketing test
xong mới triển khai (xem mục cuối).

## Chạy ở máy này

PHP 8.1 đã cài qua winget (khớp bản trên VPS), đã bật `pdo_sqlite`, `curl`,
`openssl`, `mbstring` và trỏ `curl.cainfo` tới bộ chứng chỉ CA (thiếu cái này
PHP trên Windows không gọi được HTTPS sang Google/Meta).

```bash
cd mkt.webseo.vn
./run-local.sh        # hoặc run-local.bat (lần đầu tự tạo config.local.php từ mẫu)
```

Mở http://localhost:8080. `run-local` chạy qua `dev-router.php`: máy chủ `php -S` mặc định phục vụ
**mọi file** trong thư mục (kể cả `data/marketing.sqlite`, `data/initial-passwords.txt`, `config.local.php`),
router này chỉ cho đi qua `index.html`, `api/<tên>.php` (không phải file `_…`) và `uploads/`, còn lại trả 404.
Luôn chạy qua router khi mở đường hầm công khai (ngrok…) để thử TikTok/Meta/Google. Tài khoản tạo khi khởi tạo CSDL lần đầu lấy từ
`config.local.php` (`initial_admin_email`, `initial_admin_password`,
`demo_staff_password`); để trống thì mật khẩu được sinh ngẫu nhiên và ghi vào
`data/initial-passwords.txt` — đăng nhập, đổi mật khẩu ở Thiết lập > Tài khoản rồi
xoá file đó. **Không ghi mật khẩu vào code hay README** (repo có lịch sử git).

**Khoá:** `config.team.php` để trống; khoá thật đặt trong `config.local.php` (không vào git) hoặc nhập theo từng kênh trên giao diện.

Xoá `data/marketing.sqlite` để nạp lại dữ liệu mẫu từ đầu (danh sách kênh sẽ
trống).

## Đo lường nhiều kênh

**Thiết lập > Kết nối kênh** có 6 nhóm, mỗi nhóm thêm được bao nhiêu kênh cũng được:

| Nhóm | Đăng nhập | Số liệu 30 ngày |
|---|---|---|
| Fanpage Facebook | Facebook | Người theo dõi, lượt thích Page, người tiếp cận, tương tác bài viết, theo dõi mới, lượt xem nội dung |
| Kênh YouTube | Google | Người đăng ký, tổng lượt xem, số video, lượt xem / phút xem / đăng ký mới 30 ngày, lượt thích |
| Kênh TikTok | TikTok | Người theo dõi, tổng lượt thích, số video, số video đăng 30 ngày và lượt xem / thích / bình luận / chia sẻ của các video đó |
| Website (GA4) | Google | Phiên, người dùng, lượt xem trang, sự kiện chính (chuyển đổi), tỉ lệ tương tác |
| Quảng cáo Meta | Facebook | Chi tiêu, hiển thị, tiếp cận, click, lead, CPL |
| Quảng cáo Google Ads | Google | Chi tiêu, hiển thị, click, chuyển đổi, CPC |

**Cách 1 — nhập tay (không cần tạo app OAuth):** bấm **+ Thêm Fanpage / Thêm kênh
YouTube / Thêm website / Thêm tài khoản quảng cáo**, điền Tên + ID + token/API key
rồi **Lưu** — app lưu và thử kéo số liệu ngay để báo kết nối được hay chưa. Mỗi
form có mục "Lấy ID + token ở đâu?" hướng dẫn chi tiết.

| Nhóm | ID | Token / key |
|---|---|---|
| Fanpage | Page ID | Page Access Token dài hạn (tốt nhất: token System User) |
| YouTube | Channel ID (UC…) | **OAuth Client ID + Client Secret + Refresh Token riêng của kênh** → đủ báo cáo 30 ngày (YouTube Analytics); hoặc chỉ API key → số công khai |
| Website | GA4 Property ID | Nội dung file JSON của service account (thêm email service account vào GA4 quyền Người xem) |
| Meta Ads | Ad Account ID | Token System User có quyền `ads_read` |

Google Ads và TikTok chỉ kết nối bằng đăng nhập (TikTok không có cách nhập tay vì token chỉ sống 24 giờ). Token/key lưu trong CSDL phía máy
chủ, không bao giờ gửi ngược ra trình duyệt (giao diện chỉ hiện 4 ký tự đầu/cuối).

**Cách 2 — đăng nhập (OAuth), cần key ở mục dưới:**
1. **Thêm kênh** → "hoặc đăng nhập…" (đăng nhập bằng tài khoản quản trị kênh đó).
2. Quay về app, hộp **Chọn tài khoản** tự mở, liệt kê mọi Fanpage / kênh / website /
   tài khoản quảng cáo mà tài khoản vừa đăng nhập quản lý. Tick bao nhiêu mục thì
   thành bấy nhiêu kênh — **hơn chục Fanpage chỉ cần đăng nhập một lần**.
3. Kênh thuộc tài khoản khác: bấm Thêm kênh lần nữa và đăng nhập tài khoản đó.
   Với YouTube, mỗi lần đăng nhập Google chọn đúng một kênh (kênh thương hiệu
   chọn ở màn hình của Google).
4. **Đồng bộ** từng kênh hoặc **Đồng bộ tất cả**. Bảng tổng hợp ở menu
   **Đo lường kênh** (tách tab Fanpage / YouTube / Website / Quảng cáo, có dòng tổng).

Chỉ số nào nền tảng không trả được (Meta hay đổi/ngừng chỉ số Page Insights theo
phiên bản) sẽ bị bỏ qua và ghi chú "n chỉ số không lấy được", không làm hỏng cả
lần đồng bộ.

### Lấy key kết nối (điền vào `config.local.php`, cạnh `index.html`)

**Google** — dùng chung cho Website, YouTube, Google Ads:
1. https://console.cloud.google.com/apis/credentials → tạo project.
2. Bật API: *Google Analytics Data API*, *Google Analytics Admin API*,
   *YouTube Data API v3*, *YouTube Analytics API*, *Google Ads API* (nếu đo Ads).
3. OAuth consent screen: thêm email của người trong phòng Marketing vào *Test users*.
4. Tạo **OAuth Client ID** loại *Web application*, redirect URI
   `http://localhost:8080/api/oauth_google_callback.php`.
5. Điền `google_client_id`, `google_client_secret`.
6. Google Ads cần thêm **Developer Token** đã duyệt (API Center trong tài khoản MCC)
   → `google_ads_developer_token`, `google_ads_login_customer_id`.
8. Bật **YouTube Data API v3** và **YouTube Analytics API** trong project (Library). Khi OAuth consent screen còn
   ở chế độ **Testing**: chỉ email trong *Test users* đăng nhập được, màn hình báo "Google chưa xác minh ứng dụng"
   (Nâng cao → Tiếp tục) và **refresh token hết hạn sau 7 ngày** — kênh sẽ báo lỗi và phải bấm Kết nối lại. Chuyển
   sang *In production* (app chưa xác minh vẫn dùng được với ≤100 người) để hết giới hạn 7 ngày.

**Meta** — dùng chung cho Fanpage và quảng cáo Meta:
1. https://developers.facebook.com/apps → tạo app loại *Business*.
2. Thêm *Facebook Login for Business*, Valid OAuth Redirect URI
   `http://localhost:8080/api/oauth_meta_callback.php`.
3. Điền `meta_app_id`, `meta_app_secret`.
4. Chưa qua App Review thì chỉ Admin/Developer/Tester của app đăng nhập được —
   thêm tài khoản Facebook của người quản trị các Fanpage vào vai trò của app.

**TikTok** — chỉ đọc số liệu kênh (chưa đăng video, chưa đo TikTok Ads):
1. https://developers.tiktok.com/apps → tạo app, thêm sản phẩm **Login Kit** và **Display API**,
   xin quyền `user.info.basic`, `user.info.profile`, `user.info.stats`, `video.list`.
2. **Redirect URI bắt buộc `https`** — TikTok không nhận `http://localhost`. Thử ở máy này phải qua
   đường hầm https (Cloudflare Tunnel, ngrok…): đặt `app_url` bằng địa chỉ https đó, mở app qua địa chỉ đó,
   và đăng ký `<app_url>/api/oauth_tiktok_callback.php` trong app TikTok.
3. Điền `tiktok_client_key` (TikTok gọi là *Client key*) và `tiktok_client_secret`.
4. App ở chế độ sandbox (chưa được TikTok duyệt): chỉ tài khoản được thêm vào *Target users* của app
   mới đăng nhập được — đủ để phòng Marketing tự dùng. Muốn người ngoài đăng nhập phải nộp app để duyệt.
5. Mỗi lần đăng nhập là **một** tài khoản TikTok; muốn đo thêm tài khoản khác thì thêm kênh mới.
   Token truy cập TikTok chỉ sống 24 giờ — app tự làm mới bằng refresh token (hạn 365 ngày, sau đó phải
   kết nối lại). Đồng bộ hằng ngày bằng cron là đủ để giữ kết nối.
6. Lượt xem / thích / bình luận / chia sẻ là **số hiện tại của các video đăng trong 30 ngày qua** (TikTok chỉ
   trả số cộng dồn từng video), không phải mức tăng trong 30 ngày. Mỗi lần đồng bộ đọc tối đa 100 video mới nhất.

Phiên bản API (`meta_graph_version`, `google_ads_api_version`) nằm trong config —
nền tảng báo lỗi "version deprecated" thì nâng số đó lên.

## Nhân sự & phân quyền

Menu **Nhân sự**: danh sách tài khoản (thêm, sửa, khoá/mở, cấp lại mật khẩu —
mật khẩu tạm chỉ hiện một lần), **Hiệu suất** từng người trong 7/30/90 ngày (việc
được giao, đã xong, trễ hạn, chờ duyệt, nội dung, chiến dịch phụ trách, số thao
tác) và **Nhật ký** ai làm gì lúc nào.

| Vai trò | Được làm |
|---|---|
| Quản trị | Mọi thứ, gồm quản lý tài khoản và xoá kênh đo lường |
| Trưởng nhóm | Thêm/sửa kênh, xoá chiến dịch/công việc/nội dung/đầu mối, sửa nhân viên cùng công ty, sửa OKR & thương hiệu |
| Nhân viên | Làm việc được giao, chỉ xem hiệu suất và nhật ký của chính mình |

Quyền **duyệt** (chiến dịch, nội dung, công việc, nhận đầu mối) là cờ riêng trên
từng tài khoản; Quản trị luôn có. Không ai tự duyệt việc của chính mình (trừ Quản
trị). Mọi quy tắc được kiểm tra ở máy chủ, không chỉ ẩn nút. Tài khoản bị khoá bị
đăng xuất ngay ở lần thao tác tiếp theo.

Dữ liệu mẫu chưa có Trưởng nhóm và chưa gán công ty/chi nhánh cho ai — vào Nhân
sự để đặt vai trò và phạm vi thật cho từng người.

## Thống kê, lịch sử số liệu, đồng bộ tự động

- **Báo cáo** (30/90/180/365 ngày, lọc theo công ty/chi nhánh ở thanh trên): hiệu
  quả chiến dịch, phễu và nguồn đầu mối, biểu đồ kênh theo thời gian, nội dung &
  công việc; xuất CSV. **Tổng quan** và **Đo lường kênh** cũng dùng số thật; bấm một
  kênh để xem lịch sử theo ngày.
- Mỗi lần đồng bộ lưu một bản chụp/kênh/ngày (`metrics_history`) — **muốn có biểu
  đồ theo thời gian phải đồng bộ hằng ngày**:
  xem mục **Tác vụ định kỳ** bên dưới (đồng bộ số liệu chạy kèm, 1 lần/ngày sau 06:00).
- Cách tính gần đúng (ghi rõ dưới trang Báo cáo): chi tiêu/lead theo tháng chia đều
  theo số ngày chạy của chiến dịch (bảng chiến dịch chỉ lưu tổng); nội dung xuất bản
  và việc hoàn thành theo tuần lấy theo `updated_at`; ngày thiếu dữ liệu kênh lấy
  giá trị gần nhất; GA4 và quảng cáo là số 30 ngày cuốn chiếu của mỗi lần đồng bộ.

## Kế hoạch, thương hiệu, chi nhánh

- **Kế hoạch**: OKR theo quý; mỗi kết quả then chốt nhập tay hoặc **tự tính** từ dữ
  liệu thật (lead, chi tiêu, ROAS, CPL, nội dung xuất bản, việc hoàn thành, chiến dịch
  đang chạy — chiến dịch tính vào quý nếu thời gian chạy giao với quý). Kế hoạch theo
  quý và ngân sách theo công ty/chi nhánh lấy từ chiến dịch.
- **Thương hiệu**: thông điệp, giọng văn, quy tắc, tài sản — có nút sao chép.
- **Chi nhánh**: so sánh từng chi nhánh từ chiến dịch + đầu mối, xuất CSV.
- **Chiến dịch → Lịch**: lịch theo tháng từ ngày chạy thật; **Sẵn sàng**: 6 điều kiện
  kiểm tra thật cho từng chiến dịch.
- Không còn số minh hoạ hay nút giả. Mục chưa có nguồn dữ liệu (social listening)
  hiện trạng thái trống và trỏ sang Đo lường kênh. Đầu mối chưa có API CRM — dùng
  "Xuất danh sách đầu mối (CSV)".

## AI (Claude hoặc ChatGPT)

**Thiết lập → AI & khoá API** (chỉ Quản trị sửa): dán key Claude
(console.anthropic.com → API Keys) và/hoặc ChatGPT (platform.openai.com → API keys),
chọn nhà cung cấp *Tự động / Claude / ChatGPT*, đổi model, bấm **Kiểm tra kết nối**.
Key chỉ lưu ở máy chủ, không gửi ra trình duyệt; phí do từng nhà cung cấp tính.
Model mặc định: `claude-opus-5-5`, `gpt-6.1-sol` (sửa được trên giao diện).

AI được dùng ở: **Trợ lý AI** (hỏi dựa trên dữ liệu thật của phòng, tạo chiến dịch
nháp / giao việc / xuất kế hoạch), **viết caption** từng kênh khi đăng bài, và nội
dung tin nhắn trong **chuỗi chăm sóc**. Giọng văn và quy tắc ở trang Thương hiệu được
đưa vào mọi lời nhắc. Giới hạn mỗi người: 20 câu hỏi trợ lý và 40 lần viết nội dung
mỗi 10 phút.

## Đăng bài đa kênh

Menu **Đăng bài đa kênh**: soạn một bài → chọn nhiều Fanpage, Instagram (gắn với
Page), kênh YouTube → caption riêng từng kênh (có bộ đếm ký tự, "Viết caption bằng
AI") → gắn chiến dịch / nội dung → **Gửi duyệt** → người có quyền duyệt **Duyệt &
đăng** ngay hoặc hẹn giờ. Link tự gắn UTM theo kênh và chiến dịch. Tab *Hàng chờ &
lịch* và *Đã đăng* (trạng thái từng kênh, link bài, lỗi, Thử lại). Từ một nội dung ở
trang Nội dung bấm **Soạn bài đăng** để soạn sẵn.

| Kênh | Hỗ trợ | Điều kiện |
|---|---|---|
| Fanpage | chữ, link, 1 ảnh, nhiều ảnh, video | token có quyền `pages_manage_posts` |
| Instagram | ảnh, carousel ≤10, Reels | tài khoản Business gắn với Page; ảnh/video phải có **link công khai** → chỉ chạy khi app đã ở tên miền thật |
| YouTube | đăng video (công khai / không công khai / riêng tư) | chỉ kênh kết nối bằng đăng nhập Google (API key không đăng được) |

Kênh đã kết nối OAuth trước đây phải **kết nối lại** để cấp thêm quyền đăng bài; token
nhập tay phải được tạo với các quyền trên. Lỗi thiếu quyền hiện rõ "Cần cấp quyền đăng
bài — kết nối lại kênh".

## Kịch bản tự động & chuỗi chăm sóc

Menu **Kịch bản tự động**:
- **Kịch bản**: *Khi* (bình luận mới, đầu mối mới / đổi trạng thái, bài đăng xong /
  lỗi, chiến dịch được duyệt, hằng ngày / tuần, việc trễ hạn, đầu mối chưa xử lý sau N
  giờ, chỉ số kênh vượt ngưỡng, bình luận chưa trả lời) → *Nếu* (điều kiện) → *Thì*
  (giao việc chia đều, báo trong app, gửi email, gửi webhook tới Google Chat/Slack/
  Zalo bot qua Make/Zapier, đưa vào chuỗi chăm sóc, đổi trạng thái / ghi chú đầu mối…).
  Có **Chạy thử** (không làm gì thật) và **Nhật ký chạy**.
- **8 kịch bản mẫu** bật bằng một nút: bình luận hỏi giá, bình luận tiêu cực, đầu mối
  mới (giao gọi lại 2h + chuỗi 7 ngày), đầu mối chưa xử lý 24h, bình luận quá 60 phút
  chưa trả lời, bài đăng lỗi, CPL vượt ngưỡng, báo cáo sáng 8:00. Tất cả đang **tắt**.
- **Chuỗi chăm sóc** (mẫu "Chăm sóc 7 ngày"): các bước cách nhau N giờ, gửi email /
  webhook / tạo việc / ghi chú, có thể dùng AI soạn; tự dừng khi đầu mối được CRM nhận
  hoặc từ chối. Ở trang Đầu mối mở một khách → **Đưa vào chuỗi chăm sóc**.
- An toàn: mỗi kịch bản chỉ chạy 1 lần cho mỗi sự kiện; giới hạn số lần/giờ; giờ yên
  lặng không gửi gì cho khách; không tự trả lời bình luận tiêu cực/khiếu nại (chuyển
  trưởng nhóm); chặn tin còn chỗ "[ĐIỀN …]" hoặc thiếu hotline.
- **Thiết lập → Gửi tin & thông báo**: máy chủ SMTP (Gmail/Workspace dùng App
  Password, cổng 465 hoặc 587), webhook mặc định, giờ yên lặng, hotline; có nút gửi thử.
  Chuông ở thanh trên hiện thông báo trong app.

**Chưa có: chăm sóc bình luận** (kéo bình luận về một hộp, trả lời/ẩn, AI gợi ý, tự
tạo đầu mối từ bình luận có SĐT) — phần này đang chờ quyết định. Các bước "trả lời
bình luận" trong kịch bản sẽ ghi *bỏ qua* cho đến khi có.

## Tác vụ định kỳ (bắt buộc để đăng hẹn giờ và kịch bản chạy)

`cron/run.php` chạy **mỗi 5 phút**: đăng các bài đến giờ, chạy kịch bản tự động và
chuỗi chăm sóc, đồng bộ số liệu kênh một lần mỗi ngày sau 06:00.
- Windows: Task Scheduler → Create Task → Trigger: Daily, *Repeat task every 5
  minutes* for *Indefinitely* → Action: Start a program `<thư mục app>\cron\run.bat`
  (kết quả ghi vào `data\cron.log`).
- VPS: `*/5 * * * * cd /var/www/mkt-marketing-app && php cron/run.php >> data/cron.log 2>&1`

PHP trên máy này đã chỉnh `upload_max_filesize=210M`, `post_max_size=220M`, bật
`fileinfo`, `gd` (đăng video, đổi ảnh PNG sang JPEG cho Instagram) — trên VPS cần chỉnh
tương tự.

## Cấu trúc

```
index.html              Giao diện
dev-router.php          Router cho php -S (chỉ local): chặn data/, config, schema… không cho tải về
schema.sql, seed.php    Cấu trúc bảng + dữ liệu mẫu (chạy khi chưa có CSDL)
config.php              Giá trị mặc định; config.local.php (không commit) ghi đè
api/_boot.php           DB, session, tự nâng cấp CSDL (SCHEMA_VERSION), phân quyền, nhật ký, helper gọi API ngoài
api/auth.php            Đăng nhập / đăng xuất / đổi mật khẩu
api/bootstrap.php       Nạp toàn bộ dữ liệu khi mở trang
api/users.php, api/activity.php   Nhân sự, hiệu suất, nhật ký
api/connections.php     Thêm / sửa / chọn tài khoản / nhập hàng loạt / ngắt / xoá kênh
api/oauth_*_start.php, api/oauth_*_callback.php   Đăng nhập Google / Meta / TikTok cho từng kênh
api/_metrics_lib.php, api/metrics.php   Kéo số liệu 30 ngày một kênh + lưu lịch sử
api/stats.php           Số liệu cho Báo cáo / Tổng quan
api/okrs.php, api/brand.php   OKR (kể cả tự tính), thương hiệu
api/_ai_lib.php, api/ai_chat.php, api/ai_generate.php, api/settings_ai.php   AI (Claude / ChatGPT)
api/_publish_lib.php, api/posts.php, api/upload.php   Đăng bài đa kênh
api/_automation_lib.php, api/_mail_lib.php, api/automations.php, api/sequences.php,
  api/notifications.php, api/settings_notify.php   Kịch bản tự động, chuỗi chăm sóc, thông báo, email/webhook
api/campaigns|content|tasks|leads|insights|segments|trends|competitors.php   CRUD
cron/run.php, cron/run.bat   Tác vụ 5 phút (đăng bài đến giờ, kịch bản, đồng bộ hằng ngày)
cron/sync-all.php, cron/sync-all.bat   Chỉ đồng bộ số liệu kênh (chạy tay khi cần)
uploads/                Ảnh/video tải lên để đăng bài
data/marketing.sqlite   CSDL (tự tạo, không commit)
```

Đổi cấu trúc CSDL: sửa `schema.sql`, tăng `SCHEMA_VERSION` trong `api/_boot.php`, thêm
cột mới cho CSDL cũ bằng `$addCols(...)` trong khối nâng cấp — dữ liệu cũ được giữ.

## Triển khai lên mkt.webseo.vn — CHƯA làm, còn 3 việc phải xử lý trước

Kế hoạch đã thống nhất: app này thành trang gốc `mkt.webseo.vn`; app "đổi màu
sản phẩm" đang ở gốc chuyển vào `/tien-ich/doi-mau-san-pham/`; `/demo-business/`
giữ nguyên; không đụng site nào khác trên VPS.

Trước khi đẩy lên phải sửa:

1. **Trùng tên file API.** App đổi màu đang chạy có `public/api/auth.php` và
   `public/api/_boot.php` — chép thư mục `api/` của app này lên sẽ ghi đè và làm
   hỏng app đó. Phải đổi đường dẫn API của app này (ví dụ tiền tố `mkt_`, hoặc
   thư mục riêng kèm một khối `location` trong vhost).
2. **Không để CSDL và cấu hình trong web root.** Vhost hiện phục vụ mọi file tĩnh
   trong `public/`, nên `data/marketing.sqlite` (mật khẩu, token OAuth),
   `schema.sql`, `seed.php`, `README.md` sẽ tải về được nếu nằm trong `public/`.
   `config.php`, `config.local.php`, `seed.php`, `schema.sql`, `data/`, `cron/` phải đặt
   ngoài `public/` (ví dụ `/var/www/mkt.webseo.vn/mkt-app/`), chỉ `index.html` và
   các file API mỏng nằm trong `public/`.

3. **Thư mục `uploads/`** phải nằm trong `public/` (Instagram cần tải ảnh qua link
   công khai) nhưng **không được chạy PHP**: thêm vào vhost khối
   `location ^~ /uploads/ { location ~ \.php$ { deny all; } }` (file `.htaccess` trong
   đó chỉ có tác dụng với Apache). Nâng `client_max_body_size` của nginx lên ~220m
   để tải được video.

Khi triển khai: backup, dời app đổi màu (xác nhận trước JS của nó gọi API bằng
đường dẫn tuyệt đối), tạo `config.local.php` thật trên server với
`app_url = https://mkt.webseo.vn`, và đổi redirect URI ở Google Cloud / Meta App / TikTok
sang `https://mkt.webseo.vn/api/...callback.php` (TikTok đã yêu cầu https nên dùng luôn được địa chỉ thật).
