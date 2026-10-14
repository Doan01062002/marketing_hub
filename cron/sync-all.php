<?php
/* Đồng bộ tự động mọi kênh đã kết nối (chạy mỗi ngày bằng Task Scheduler / crontab).
   Dùng:  php cron/sync-all.php
   In một dòng kết quả cho từng kênh; mã thoát 1 nếu có kênh lỗi, 0 nếu tất cả thành công. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Chỉ chạy được từ dòng lệnh (CLI).\n");
}
require __DIR__ . '/../api/_boot.php';
require_once __DIR__ . '/../api/_metrics_lib.php';
header_remove('Content-Type');

$rows = $pdo->query("SELECT * FROM connections WHERE status='connected' AND COALESCE(external_account_id,'')<>'' ORDER BY provider, created_at")->fetchAll();
$tz = ini_get('date.timezone') ?: 'Asia/Ho_Chi_Minh';
$stamp = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');
if (!$rows) { echo "[$stamp] Không có kênh nào đã kết nối và chọn tài khoản — không có gì để đồng bộ.\n"; exit(0); }

$ok = 0; $bad = 0;
foreach ($rows as $conn) {
    $name = sprintf('%-14s %s (%s)', $conn['provider'], $conn['label'] ?: '—', $conn['id']);
    try {
        $m = sync_connection($CFG, $conn);
        $def = METRIC_KEYS[$conn['provider']] ?? null;
        $kv = metric_key_value($conn['provider'], $m);
        echo "[$stamp] OK   $name" . ($def && $kv !== null ? ' · ' . $def['label'] . ': ' . number_format($kv, 0, ',', '.') : '') . "\n";
        $ok++;
    } catch (Throwable $e) {
        echo "[$stamp] LỖI $name · " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n";
        $bad++;
    }
}
echo "[$stamp] Xong: $ok thành công, $bad lỗi / " . count($rows) . " kênh.\n";
exit($bad ? 1 : 0);
