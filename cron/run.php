<?php
/* Tác vụ định kỳ — chạy MỖI 5 PHÚT (Task Scheduler / crontab):  php cron/run.php
   Mỗi phần do một thư viện riêng đảm nhận; thư viện nào chưa có thì bỏ qua.
   - Đăng các bài đến giờ:            publish_due_posts()        (api/_publish_lib.php)
   - Kéo bình luận mới về hộp chăm sóc: fetch_new_comments()       (api/_comments_lib.php)
   - Chạy kịch bản tự động & chuỗi chăm sóc: run_automations_tick() (api/_automation_lib.php)
   - Đồng bộ số liệu kênh 1 lần/ngày sau 06:00: sync-all (api/_metrics_lib.php) */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Chỉ chạy từ dòng lệnh.\n"); }
require __DIR__ . '/../api/_boot.php';
header_remove('Content-Type');
set_time_limit(0);

// Không chạy chồng: nếu lần trước chưa xong thì thoát.
$lock = fopen(__DIR__ . '/../data/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo '[' . date('Y-m-d H:i:s') . "] Lần chạy trước chưa xong — bỏ qua.\n"; exit(0); }

$log = function (string $part, string $msg) { echo '[' . date('Y-m-d H:i:s') . "] $part: $msg\n"; };
$steps = [
    'Đăng bài' => [__DIR__ . '/../api/_publish_lib.php', 'publish_due_posts'],
    'Bình luận' => [__DIR__ . '/../api/_comments_lib.php', 'fetch_new_comments'],
    'Cửa sổ chat' => [__DIR__ . '/../api/_inbox_lib.php', 'inbox_sync_all'],
    'Tự động hoá' => [__DIR__ . '/../api/_automation_lib.php', 'run_automations_tick'],
    'Robot duyệt web' => [__DIR__ . '/../api/_bot_lib.php', 'bot_tick'],
];
$exit = 0;
foreach ($steps as $name => [$file, $fn]) {
    if (!is_file($file)) { $log($name, 'chưa có thư viện, bỏ qua'); continue; }
    require_once $file;
    if (!function_exists($fn)) { $log($name, "thiếu hàm $fn, bỏ qua"); continue; }
    try { $log($name, (string)($fn($CFG) ?? 'xong')); }
    catch (Throwable $e) { $log($name, 'LỖI ' . $e->getMessage()); $exit = 1; }
}

// Đồng bộ số liệu kênh: một lần mỗi ngày, sau 06:00.
if ((int)date('G') >= 6 && setting_get('cron.last_daily_sync') !== date('Y-m-d')) {
    require_once __DIR__ . '/../api/_metrics_lib.php';
    $rows = $pdo->query("SELECT * FROM connections WHERE status='connected' AND COALESCE(external_account_id,'')<>''")->fetchAll();
    $ok = 0; $bad = 0;
    foreach ($rows as $c) { try { sync_connection($CFG, $c); $ok++; } catch (Throwable $e) { $bad++; } }
    setting_set('cron.last_daily_sync', date('Y-m-d'), ['id' => null]);
    $log('Đồng bộ số liệu', "$ok thành công, $bad lỗi");
}
// Dữ liệu thị trường (Google Trends): 1 lần/tuần, sáng thứ Hai sau 07:00.
if ((int)date('N') === 1 && (int)date('G') >= 7 && setting_get('cron.last_market_week') !== date('o-W')) {
    require_once __DIR__ . '/../api/_market_lib.php';
    try { $log('Thị trường', market_weekly()); } catch (Throwable $e) { $log('Thị trường', 'LỖI ' . $e->getMessage()); }
    setting_set('cron.last_market_week', date('o-W'), ['id' => null]);
}
// 12/10/2026: Token Meta (đăng nhập Facebook) dài hạn 60 ngày — tự đổi token mới khi còn dưới 20 ngày, mỗi ngày kiểm tra 1 lần.
if ($CFG['meta_app_id'] && $CFG['meta_app_secret'] && setting_get('cron.last_meta_refresh') !== date('Y-m-d')) {
    $rows = $pdo->query("SELECT * FROM connections WHERE auth_method='oauth' AND status='connected' AND access_token IS NOT NULL AND expires_at IS NOT NULL AND expires_at < datetime('now','+20 days')")->fetchAll();
    $n = 0; $err = 0;
    foreach ($rows as $c) {
        if ((PROVIDERS[$c['provider']]['auth'] ?? '') !== 'meta') continue;
        [$code, $d] = http_get_json(graph_url($CFG, 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $CFG['meta_app_id'], 'client_secret' => $CFG['meta_app_secret'], 'fb_exchange_token' => $c['access_token']]));
        if ($code === 200 && !empty($d['access_token'])) { $pdo->prepare("UPDATE connections SET access_token=?, expires_at=?, last_error=NULL WHERE id=?")->execute([$d['access_token'], date('Y-m-d H:i:s', time() + (int)($d['expires_in'] ?? 5184000)), $c['id']]); $n++; }
        else { $err++; $pdo->prepare("UPDATE connections SET last_error=? WHERE id=?")->execute(['Không gia hạn được token Meta: ' . ($d['error']['message'] ?? "HTTP $code") . ' — bấm Kết nối lại', $c['id']]); }
    }
    if ($rows) $log('Gia hạn token Meta', "$n gia hạn, $err lỗi");
    setting_set('cron.last_meta_refresh', date('Y-m-d'), ['id' => null]);
}
setting_set('cron.last_run', date('Y-m-d H:i:s'), ['id' => null]);
exit($exit);
