<?php
/* Cổng nhận sự kiện từ zalo-bridge (tin nhắn đến/đi, đăng nhập, đăng xuất). Xác thực X-ZB-Key = zalo_bridge_key. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_zalo_user_lib.php';
if (!hash_equals((string)($CFG['zalo_bridge_key'] ?? ''), (string)($_SERVER['HTTP_X_ZB_KEY'] ?? '')) || empty($CFG['zalo_bridge_key'])) fail('sai khoá', 403);
$in = json_input();
$proj = (string)setting_get('zalo_user.project', '');
if ($proj === '') respond(['ok' => false, 'reason' => 'chưa gắn dự án']);
$ev = (string)($in['event'] ?? '');

if ($ev === 'online') {
    $c = zu_connection($proj, true, ['uid' => (string)($in['uid'] ?? ''), 'name' => (string)($in['name'] ?? '')]);
    log_activity('update', 'zalo_user', $c['id'] ?? null, 'Zalo cá nhân đã đăng nhập: ' . ($in['name'] ?? ''));
    try { zu_sync_avatars(); } catch (Throwable $e) {}
    respond(['ok' => true]);
}
if ($ev === 'logout') {
    $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE provider='zalo_user' AND project_id=?")->execute(['Zalo cá nhân bị đăng xuất — quét QR lại', $proj]);
    notify(null, 'Zalo cá nhân bị đăng xuất', 'Vào Cài đặt dự án → Tài khoản kết nối → Zalo cá nhân để quét QR lại. ' . mb_substr((string)($in['reason'] ?? ''), 0, 200), '#settings/integ', $proj);
    respond(['ok' => true]);
}
if ($ev === 'message') {
    $conn = zu_connection($proj);
    if (!$conn || empty($in['uid'])) respond(['ok' => false]);
    $new = zu_store_message($conn, $in);
    $nk = 'zalo_user.notified.' . preg_replace('/\D/', '', (string)$in['uid']);
    if ($new > 0 && ($in['dir'] ?? 'in') === 'in' && time() - (int)setting_get($nk, 0) > 600) {
        setting_set($nk, (string)time(), ['id' => null]);
        $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$proj]);
        foreach ($ms->fetchAll() as $m) notify($m['user_id'], 'Zalo: ' . ($in['name'] ?: 'Bạn Zalo') . ' vừa nhắn', mb_substr((string)$in['text'], 0, 200), '#inbox', $proj);
    }
    // AI Chat: trả lời bridge ngay, xử lý AI sau (chờ gộp tin, máy HL có thể mất vài chục giây)
    if ($new > 0 && ($in['dir'] ?? 'in') === 'in') {
        require_once __DIR__ . '/_zalo_ai_lib.php';
        if (zu_ai_person_allowed((string)$in['uid'])) {
            ignore_user_abort(true); @set_time_limit(180);
            echo json_encode(['ok' => true, 'new' => $new, 'ai' => 'queued']);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $r = zu_ai_handle($CFG, (string)$in['uid'], (string)$in['msgId']);
            log_activity('reply', 'zalo_ai', 'zalo_user:' . $in['uid'], 'AI Zalo: ' . ($in['name'] ?? '') . ' → ' . $r, null, $proj);
            exit;
        }
    }
    respond(['ok' => true, 'new' => $new]);
}
respond(['ok' => false, 'reason' => 'sự kiện lạ']);
