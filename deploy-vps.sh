#!/usr/bin/env bash
# Đưa Marketing Hub lên VPS để thử. Chạy trong Git Bash:  ./deploy-vps.sh
# Chỉ thêm một thư mục + một vhost Nginx mới; không sửa site nào đang chạy. Chỉ hỏi mật khẩu SSH một lần.
#   HOST=root@157.10.201.134  PORT=8081  DOMAIN=mkt.aigara.vn  ./deploy-vps.sh   (đặt DOMAIN thì nghe cổng 80 theo tên miền)
#   DRY=1 ./deploy-vps.sh   chỉ dựng gói cài đặt, không kết nối
set -euo pipefail
HOST="${HOST:-root@157.10.201.134}"
PORT="${PORT:-8081}"
DOMAIN="${DOMAIN:-}"
APP_DIR="${APP_DIR:-/var/www/mkt-hub}"
cd "$(dirname "$0")"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
tar czf "$TMP/app.tgz" --exclude=.git --exclude='*.bak' --exclude=deploy-vps.sh \
  --exclude='data/*.log' --exclude='data/initial-passwords.txt' .

{
  printf 'PORT=%q\nDOMAIN=%q\nAPP_DIR=%q\n' "$PORT" "$DOMAIN" "$APP_DIR"
  cat <<'REMOTE'
set -euo pipefail
SELF=/tmp/mkt-hub-install.sh
say() { printf '\n== %s\n' "$*"; }

say "Kiểm tra máy chủ"
command -v nginx >/dev/null || { echo "Không có nginx"; exit 1; }
if [ -z "$DOMAIN" ] && ss -ltn | awk '{print $4}' | grep -qE "[:.]$PORT\$"; then echo "Cổng $PORT đã có dịch vụ khác dùng — đổi PORT=..."; exit 1; fi
SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
[ -n "$SOCK" ] || { echo "Không thấy php-fpm socket trong /run/php — cần cài php-fpm trước"; exit 1; }
PHPV="$(basename "$SOCK" | sed -E 's/^php([0-9.]+)-fpm\.sock$/\1/')"
echo "nginx: $(nginx -v 2>&1) | php-fpm: $PHPV ($SOCK)"

say "PHP extension cần cho app"
MISSING=""
for pair in pdo_sqlite:sqlite3 curl:curl mbstring:mbstring gd:gd; do
  ext="${pair%%:*}"; pkg="${pair##*:}"
  php -m 2>/dev/null | grep -qix "$ext" || MISSING="$MISSING php$PHPV-$pkg"
done
php -m 2>/dev/null | grep -qix sodium || echo "CẢNH BÁO: thiếu extension sodium (token sẽ lưu không mã hoá)"
if [ -n "$MISSING" ]; then echo "Cài thêm:$MISSING"; apt-get install -y $MISSING; systemctl reload "php$PHPV-fpm"; else echo "Đủ extension"; fi

say "Đặt mã nguồn vào $APP_DIR"
if [ -d "$APP_DIR" ]; then mv "$APP_DIR" "$APP_DIR.bak-$(date +%Y%m%d-%H%M%S)"; echo "Đã sao lưu bản cũ"; fi
mkdir -p "$APP_DIR"
LINE="$(grep -an '^__ARCHIVE_BELOW__$' "$SELF" | tail -1 | cut -d: -f1)"
tail -n +$((LINE + 1)) "$SELF" | tar xz -C "$APP_DIR"
mkdir -p "$APP_DIR/data" "$APP_DIR/uploads"
if [ -n "$DOMAIN" ]; then URL="http://$DOMAIN"; else URL="http://$(hostname -I | awk '{print $1}'):$PORT"; fi
sed -i "s#'app_url' => '[^']*'#'app_url' => '$URL'#" "$APP_DIR/config.local.php"
chown -R root:root "$APP_DIR"
chown -R www-data:www-data "$APP_DIR/data" "$APP_DIR/uploads"
chown root:www-data "$APP_DIR/config.local.php"; chmod 640 "$APP_DIR/config.local.php"
echo "app_url = $URL"

say "Vhost Nginx"
CONF=/etc/nginx/sites-available/mkt-hub.conf
if [ -n "$DOMAIN" ]; then LISTEN="listen 80;"; NAME="$DOMAIN"; else LISTEN="listen $PORT;"; NAME="_"; fi
cat > "$CONF" <<'NGX'
server {
    __LISTEN__
    server_name __NAME__;
    root __ROOT__;
    index index.html;
    client_max_body_size 220m;

    location = / { try_files /index.html =404; }
    location = /index.html { }
    location ~ ^/(privacy|terms|data-deletion)\.html$ { }
    location ~ ^/api/[a-z0-9][a-z0-9_]*\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PHP_VALUE "upload_max_filesize=210M\npost_max_size=220M\nmax_execution_time=300\nmemory_limit=512M";
        fastcgi_pass unix:__SOCK__;
        fastcgi_read_timeout 300;
    }
    location ^~ /uploads/ { location ~* \.(php[0-9]?|phtml|phar)$ { return 404; } }
    location / { return 404; }
}
NGX
sed -i "s#__LISTEN__#$LISTEN#; s#__NAME__#$NAME#; s#__ROOT__#$APP_DIR#; s#__SOCK__#$SOCK#" "$CONF"
ln -sfn "$CONF" /etc/nginx/sites-enabled/mkt-hub.conf
if ! nginx -t; then rm -f /etc/nginx/sites-enabled/mkt-hub.conf; echo "nginx -t lỗi — đã gỡ vhost mới, các site khác không bị ảnh hưởng"; exit 1; fi
systemctl reload nginx
if [ -z "$DOMAIN" ] && command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then ufw allow "$PORT/tcp"; fi

say "Cron trên VPS (đăng hẹn giờ, đồng bộ số liệu, hộp chat) — file riêng /etc/cron.d/mkt-hub"
PHPBIN="$(command -v php)"
printf '%s\n%s\n' \
  "*/5 * * * * www-data cd $APP_DIR && $PHPBIN cron/run.php >> data/cron.log 2>&1" \
  "* * * * * www-data cd $APP_DIR && $PHPBIN cron/inbox.php >> data/cron-inbox.log 2>&1" > /etc/cron.d/mkt-hub
chmod 644 /etc/cron.d/mkt-hub

say "Kiểm tra"
BASE="http://127.0.0.1:$([ -n "$DOMAIN" ] && echo 80 || echo "$PORT")"
H=(); [ -n "$DOMAIN" ] && H=(-H "Host: $DOMAIN")
code() { curl -s -o /dev/null -w '%{http_code}' "${H[@]}" "$BASE$1"; }
echo "/                       -> $(code /)  (mong 200)"
echo "/api/bootstrap.php      -> $(code /api/bootstrap.php)  (mong 200 hoặc 401 dạng JSON)"
echo "/data/marketing.sqlite  -> $(code /data/marketing.sqlite)  (mong 404)"
echo "/config.local.php       -> $(code /config.local.php)  (mong 404)"
echo "/api/_boot.php          -> $(code /api/_boot.php)  (mong 404)"
say "Xong. Mở: $URL"
[ -n "$DOMAIN" ] && echo "Bật https: certbot --nginx -d $DOMAIN   (rồi đổi app_url sang https trong $APP_DIR/config.local.php)"
rm -f "$SELF"
exit 0
__ARCHIVE_BELOW__
REMOTE
  cat "$TMP/app.tgz"
} > "$TMP/installer.sh"

echo "Gói cài đặt: $(du -h "$TMP/installer.sh" | cut -f1) — đích: $HOST (thư mục $APP_DIR, $([ -n "$DOMAIN" ] && echo "tên miền $DOMAIN" || echo "cổng $PORT"))"
bash -n <(sed '/^__ARCHIVE_BELOW__$/q' "$TMP/installer.sh") && echo "Cú pháp script cài đặt: OK"
[ "${DRY:-0}" = "1" ] && { echo "DRY=1: dừng, chưa kết nối."; exit 0; }
echo "Nhập mật khẩu SSH của $HOST khi được hỏi (chỉ một lần)."
ssh "$HOST" 'cat > /tmp/mkt-hub-install.sh && bash /tmp/mkt-hub-install.sh' < "$TMP/installer.sh"
