#!/usr/bin/env bash
# Chạy backend thật ở local: http://localhost:8080
# Cần PHP 8.1 (cài bằng: winget install --id PHP.PHP.8.1)
set -e
cd "$(dirname "$0")"
if [ ! -f config.local.php ]; then
  cp config.local.example.php config.local.php
  echo "Đã tạo config.local.php từ mẫu — điền Client ID/Secret trước khi dùng OAuth thật."
fi
php -S localhost:8080 dev-router.php   # router chặn data/, config, schema… (php -S mặc định phục vụ mọi file)
