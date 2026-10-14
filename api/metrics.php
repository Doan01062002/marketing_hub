<?php
/* Đồng bộ MỘT kênh theo yêu cầu người dùng: GET ?conn_id=…
   Logic gọi từng nền tảng nằm ở _metrics_lib.php (dùng chung với cron/sync-all.php).
   Mỗi lần thành công ghi connections.last_metrics + metrics_history (1 bản/kênh/ngày). */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_metrics_lib.php';
$user = require_login();
require_method('GET');

require_project_write();
$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn) fail('Không tìm thấy kênh trong dự án này', 404);

try {
    $m = sync_connection($CFG, $conn);
} catch (SyncError $e) {
    fail($e->getMessage(), $e->http);
}
log_activity('sync', 'connection', $conn['id'], $conn['label'] ?: $conn['provider'], $user);
respond(['metrics' => $m]);
