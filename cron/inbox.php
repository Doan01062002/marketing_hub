<?php
/* Kéo tin nhắn Messenger/Zalo OA MỖI PHÚT (dự phòng khi webhook Meta chưa gửi — app còn ở chế độ phát triển chỉ nhận webhook từ người có vai trò trong app).
   crontab (wsvn):  * * * * * cd .../private/app && /usr/bin/php8.3 cron/inbox.php >> data/cron-inbox.log 2>&1
   Có khoá riêng để không chạy chồng; AI tự trả lời chạy ngay sau khi có tin mới. 12/10/2026 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Chỉ chạy từ dòng lệnh.\n"); }
require __DIR__ . '/../api/_boot.php';
require_once __DIR__ . '/../api/_inbox_lib.php';
header_remove('Content-Type');
set_time_limit(0);
$lock = fopen(__DIR__ . '/../data/cron-inbox.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try { $r = inbox_sync_all($CFG); if (!str_contains($r, ' 0 tin mới') || str_contains($r, 'lỗi')) echo '[' . date('Y-m-d H:i:s') . "] $r\n"; }
catch (Throwable $e) { echo '[' . date('Y-m-d H:i:s') . '] LỖI ' . $e->getMessage() . "\n"; }
