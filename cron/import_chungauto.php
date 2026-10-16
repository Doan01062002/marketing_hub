<?php
/* Kéo khách từ chungauto.vn (liên hệ + đơn hàng) vào CSKH — chạy tay, CLI.
   Email + mật khẩu quản trị đọc từ STDIN (2 dòng), chỉ dùng trong lần chạy này, không lưu.
     printf '%s\n%s\n' "$EMAIL" "$PASS" | php cron/import_chungauto.php [--full] [--project=chungauto] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Chỉ chạy từ dòng lệnh.\n"); }
require __DIR__ . '/../api/_boot.php';
require_once __DIR__ . '/../api/_chungauto_sync_lib.php';
$args = getopt('', ['full', 'project::']);
$email = trim((string)fgets(STDIN)); $pass = rtrim((string)fgets(STDIN), "\r\n");
if ($email !== '' && $pass !== '') { putenv("CHUNGAUTO_ADMIN_EMAIL=$email"); putenv("CHUNGAUTO_ADMIN_PASSWORD=$pass"); }
$pid = (string)($args['project'] ?? 'chungauto');
$t = microtime(true);
try {
    $r = ca_sync($CFG, $pid, ['full' => isset($args['full']), 'max_seconds' => 3000, 'log' => fn($m) => print('[' . date('H:i:s') . "] $m\n")]);
} catch (CaSyncError $e) { fwrite(STDERR, 'LỖI: ' . $e->getMessage() . "\n"); exit(1); }
printf("[%s] XONG sau %.0fs: liên hệ %d mới / %d cập nhật · đơn hàng %d mới / %d cập nhật · %d trang%s\n", date('H:i:s'), microtime(true) - $t,
    $r['contacts']['new'], $r['contacts']['upd'], $r['orders']['new'], $r['orders']['upd'], $r['pages'], $r['complete'] ? '' : ' · CHƯA HẾT (chạy lại để làm tiếp)');
log_activity('import', 'care', null, "Kéo khách chungauto.vn: {$r['contacts']['new']} liên hệ, {$r['orders']['new']} đơn", ['id' => null, 'name' => 'Hệ thống'], $pid);
