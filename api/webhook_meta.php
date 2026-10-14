<?php
/* Webhook Meta (Messenger của Fanpage) — nhận tin nhắn THỜI GIAN THỰC vào Cửa sổ chat. 12/10/2026
   URL đăng ký trong app Facebook: https://<app_url>/api/webhook_meta.php   (object: page; fields: messages, message_echoes, messaging_postbacks)
   GET  hub.mode=subscribe&hub.verify_token=…&hub.challenge=…  → trả challenge nếu đúng verify token (app_settings meta.webhook_verify)
   POST body JSON có chữ ký X-Hub-Signature-256 (HMAC SHA256 bằng app secret) → ghi tin vào inbox_threads/inbox_messages.
   Không cần đăng nhập. Luôn trả 200 nhanh (Meta huỷ đăng ký nếu lỗi nhiều). */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_inbox_lib.php';
header_remove('Content-Type'); header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $want = (string)setting_get('meta.webhook_verify', '');
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && $want !== '' && hash_equals($want, (string)($_GET['hub_verify_token'] ?? ''))) { echo (string)($_GET['hub_challenge'] ?? ''); exit; }
    http_response_code(403); echo 'verify token sai'; exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input') ?: '';
$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($CFG['meta_app_secret']) {
    $calc = 'sha256=' . hash_hmac('sha256', $raw, $CFG['meta_app_secret']);
    if ($sig === '' || !hash_equals($calc, $sig)) { http_response_code(403); echo 'chữ ký sai'; exit; }
}
$body = json_decode($raw, true);
if (!is_array($body) || ($body['object'] ?? '') !== 'page') { echo 'ignored'; exit; }

$n = 0; $errs = [];
foreach ($body['entry'] ?? [] as $entry) {
    $pageId = (string)($entry['id'] ?? '');
    $st = $pdo->prepare("SELECT * FROM connections WHERE provider='facebook_page' AND external_account_id=? AND status='connected' ORDER BY connected_at DESC LIMIT 1"); $st->execute([$pageId]);
    $conn = $st->fetch(); if (!$conn) continue;
    foreach ($entry['messaging'] ?? [] as $ev) {
        try {
            if (empty($ev['message'])) continue; // delivery/read/postback: bỏ qua (postback hiếm dùng)
            $m = $ev['message']; $echo = !empty($m['is_echo']);
            $psid = $echo ? (string)($ev['recipient']['id'] ?? '') : (string)($ev['sender']['id'] ?? '');
            if ($psid === '' || $psid === $pageId) continue;
            $att = [];
            foreach ($m['attachments'] ?? [] as $a) { $t = (string)($a['type'] ?? 'file'); $att[] = ['type' => in_array($t, ['image', 'video', 'audio'], true) ? $t : 'file', 'url' => $a['payload']['url'] ?? null, 'name' => $a['payload']['title'] ?? $t]; }
            $text = (string)($m['text'] ?? ''); if ($text === '' && !$att && !empty($m['sticker_id'])) $att[] = ['type' => 'image', 'url' => null, 'name' => 'sticker'];
            $at = gmdate('Y-m-d H:i:s', (int)round(((int)($ev['timestamp'] ?? 0) ?: time() * 1000) / 1000));
            $n += ib_store_event($CFG, $conn, $psid, (string)($m['mid'] ?? ('wh-' . md5($raw . $psid))), $echo ? 'out' : 'in', $text, $att, $at);
        } catch (Throwable $e) { $errs[] = $e->getMessage(); }
    }
}
$ai = ib_ai_run_pending($CFG);
if ($errs) error_log('[webhook_meta] ' . implode(' | ', array_map(fn($e) => mb_substr($e, 0, 200), $errs)));
echo "ok $n" . ($ai ? ' ai:' . implode(',', array_values($ai)) : '');
