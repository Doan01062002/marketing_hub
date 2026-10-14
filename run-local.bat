@echo off
REM Chạy backend thật ở local: http://localhost:8080
REM Cần PHP 8.1+ (cài bằng: winget install --id PHP.PHP.8.3)
REM Các tham số -d bên dưới thay cho việc sửa php.ini: bộ chứng chỉ CA (bắt buộc để gọi HTTPS sang
REM Google/Meta/TikTok/Claude), gd (đổi ảnh PNG sang JPEG cho Instagram) và giới hạn tải video.
cd /d "%~dp0"
if not exist config.local.php (
  copy config.local.example.php config.local.php
  echo Đã tạo config.local.php từ mẫu — điền Client ID/Secret trước khi dùng OAuth thật.
)
set CA=C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt
if not exist "%CA%" echo CẢNH BÁO: không thấy %CA% — sửa đường dẫn CA trong run-local.bat, nếu không PHP không gọi được HTTPS.
set PHP=php
where php >nul 2>nul || set PHP=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe
cd /d "%~dp0"
"%PHP%" -d "curl.cainfo=%CA%" -d "openssl.cafile=%CA%" -d upload_max_filesize=210M -d post_max_size=220M -S localhost:8080 dev-router.php
