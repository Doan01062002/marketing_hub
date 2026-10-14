# zalo-bridge — Zalo cá nhân cho Marketing Hub

Dịch vụ Node nhỏ chạy cạnh app, giữ phiên đăng nhập Zalo cá nhân (quét QR như Zalo Web) bằng thư viện **zca-js** (API KHÔNG chính thức của Zalo — dùng nội bộ, nhắn thưa, có rủi ro bị Zalo hạn chế tài khoản).

Chức năng: tin nhắn Zalo cá nhân đến/đi hiện trong **Cửa sổ chat** (tab chat riêng `/?chat=1`), trả lời ngay trong Hub, **AI Chat** tự trả lời theo cấu hình (⚙ Cài đặt trong tab chat).

## Cài đặt (Node 20+)

```bash
cd zalo-bridge
npm install
cp config.example.json config.json   # rồi sửa key + hook_url
node server.mjs                      # nghe 127.0.0.1:8721
```

`config.json` (KHÔNG đưa lên git):

```json
{ "port": 8721, "key": "<chuỗi ngẫu nhiên dài>", "hook_url": "https://<tên-miền-app>/api/zalo_user_hook.php" }
```

Thêm vào `config.local.php` của app:

```php
'zalo_bridge_url' => 'http://127.0.0.1:8721',
'zalo_bridge_key' => '<cùng chuỗi key ở trên>',
```

Chạy thường trực (Linux, systemd):

```ini
[Service]
User=<user web>
WorkingDirectory=/đường/dẫn/zalo-bridge
ExecStart=/usr/bin/node server.mjs
Restart=always
```

Sau đó vào app: **Cài đặt dự án → Tài khoản kết nối → Zalo cá nhân → Tạo mã QR**, quét bằng app Zalo trên điện thoại.

Lưu ý:
- `session.json` (phiên Zalo) và `config.json` là bí mật — đã có trong `.gitignore`.
- Danh sách bạn bè tải MỘT lần sau khi đăng nhập 30 giây (gọi dồn dễ bị Zalo trả 429).
- AI Chat chạy bằng khoá API AI của app; không có khoá thì xếp việc cho máy HL (Claude Max) nếu có.
