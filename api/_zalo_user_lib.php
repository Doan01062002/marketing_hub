<?php
/* ZALO CÁ NHÂN (nội bộ) — qua dịch vụ cầu nối zalo-bridge (Node + zca-js, API KHÔNG CHÍNH THỨC) chạy 127.0.0.1.
   - Một số Zalo gắn với MỘT dự án (app_settings zalo_user.project); kết nối = dòng connections provider 'zalo_user'.
   - Tin nhắn cá nhân đến/đi → inbox_threads/inbox_messages (Cửa sổ chat), thread = zalo_user:<uid khách>.
   - AI Chat (tuỳ chọn): xem _zalo_ai_lib.php.
   Yêu cầu đã require _boot.php. Cấu hình cầu nối: config.local.php zalo_bridge_url / zalo_bridge_key. */

/** Gọi cầu nối. Trả về [httpCode, data]. */
function zu_bridge(string $method, string $path, ?array $body = null, int $timeout = 30): array {
    global $CFG;
    $url = rtrim((string)($CFG['zalo_bridge_url'] ?? 'http://127.0.0.1:8721'), '/') . $path;
    $opts = ['headers' => ['X-ZB-Key: ' . ($CFG['zalo_bridge_key'] ?? '')]];
    if ($body !== null) $opts['json'] = $body;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => array_merge($opts['headers'], $body !== null ? ['Content-Type: application/json'] : [])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false) return [0, ['error' => 'Dịch vụ Zalo (zalo-bridge) không chạy']];
    return [$code, json_decode((string)$raw, true) ?: []];
}

/** Kết nối Zalo cá nhân của dự án (tạo nếu chưa có). */
function zu_connection(string $projectId, bool $create = false, array $info = []): ?array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM connections WHERE provider='zalo_user' AND project_id=? ORDER BY created_at LIMIT 1"); $st->execute([$projectId]);
    $c = $st->fetch() ?: null;
    if (!$c && $create) {
        $id = gen_id('CH');
        $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,external_account_id,connected_at) VALUES (?,?,'zalo_user',?,'connected','manual',?,datetime('now'))")
            ->execute([$id, $projectId, 'Zalo cá nhân' . (!empty($info['name']) ? ' · ' . $info['name'] : ''), $info['uid'] ?? null]);
        $c = get_connection($id, true);
    } elseif ($c && $info) {
        $pdo->prepare("UPDATE connections SET status='connected', last_error=NULL, external_account_id=COALESCE(?,external_account_id), label=? WHERE id=?")
            ->execute([$info['uid'] ?? null, 'Zalo cá nhân' . (!empty($info['name']) ? ' · ' . $info['name'] : ''), $c['id']]);
        $c = get_connection($c['id'], true);
    }
    return $c;
}

/** Gửi tin cá nhân (dùng cho Cửa sổ chat). */
function zu_send(string $uid, string $text): array {
    [$c, $d] = zu_bridge('POST', '/send', ['uid' => $uid, 'text' => $text], 40);
    return ($c === 200 && !empty($d['ok'])) ? ['ok' => true, 'id' => (string)$d['msgId']] : ['ok' => false, 'error' => 'Zalo cá nhân: ' . ($d['error'] ?? "HTTP $c")];
}

/** Ghi một tin (từ cầu nối) vào Cửa sổ chat. */
function zu_store_message(array $conn, array $m): int {
    require_once __DIR__ . '/_inbox_lib.php';
    global $pdo;
    $tid = 'zalo_user:' . $m['uid'];
    $ex = $pdo->prepare('SELECT 1 FROM inbox_threads WHERE id=?'); $ex->execute([$tid]); $isNew = !$ex->fetchColumn();
    $at = gmdate('Y-m-d H:i:s', (int)floor(((int)($m['ts'] ?? 0) ?: time() * 1000) / 1000));
    $new = ib_store($conn, (string)$m['uid'], ['id' => (string)$m['uid'], 'name' => (string)($m['name'] ?? '') ?: ($isNew ? 'Bạn Zalo' : ''), 'avatar' => ($m['avatar'] ?? '') ?: null],
        [['id' => (string)$m['msgId'], 'dir' => ($m['dir'] ?? 'in') === 'out' ? 'out' : 'in', 'text' => (string)($m['text'] ?? ''), 'at' => $at, 'sender' => $m['sender'] ?? (($m['dir'] ?? 'in') === 'out' ? 'Zalo (điện thoại)' : ($m['name'] ?? null))]]);
    // Zalo cá nhân là bạn bè riêng: AI KHÔNG tự trả lời (bật tay từng cuộc nếu muốn); không đưa vào hàng AI tự động.
    if ($isNew) $pdo->prepare("UPDATE inbox_threads SET ai_mode='off' WHERE id=?")->execute([$tid]);
    unset($GLOBALS['ib_new_threads'][$tid]);
    return $new;
}

/** Lấy ảnh đại diện + tên Zalo thật của bạn bè cho các hội thoại Zalo cá nhân (danh sách bạn bè cache 10 phút ở cầu nối). */
function zu_sync_avatars(): int {
    global $pdo;
    [$c, $b] = zu_bridge('GET', '/friends', null, 60);
    $by = []; foreach ($c === 200 ? ($b['friends'] ?? []) : [] as $f) $by[(string)$f['uid']] = $f;
    // Danh sách bạn bè bị Zalo giới hạn (429) hoặc người lạ → hỏi từng người có hội thoại mà chưa có ảnh
    $miss = array_values(array_filter($pdo->query("SELECT customer_id FROM inbox_threads WHERE provider='zalo_user' AND (customer_avatar IS NULL OR customer_avatar='')")->fetchAll(PDO::FETCH_COLUMN), fn($u) => !isset($by[(string)$u])));
    if ($miss) { [$c2, $b2] = zu_bridge('GET', '/info?uids=' . implode(',', array_slice($miss, 0, 30)), null, 40);
        if ($c2 === 200) foreach ($b2['users'] ?? [] as $u => $f) if (!empty($f['avatar'])) $by[(string)$u] = $f; }
    $up = $pdo->prepare("UPDATE inbox_threads SET customer_avatar=?, customer_name=CASE WHEN customer_name IN ('', 'Bạn Zalo') AND ?<>'' THEN ? ELSE customer_name END WHERE id=?");
    $n = 0;
    foreach ($pdo->query("SELECT id, customer_id, customer_avatar FROM inbox_threads WHERE provider='zalo_user'")->fetchAll() as $t) {
        $f = $by[(string)$t['customer_id']] ?? null;
        if ($f && !empty($f['avatar']) && $f['avatar'] !== $t['customer_avatar']) { $nm = (string)($f['name'] ?? ''); $up->execute([$f['avatar'], $nm, $nm, $t['id']]); $n++; }
    }
    setting_set('zalo_user.avatars_at', (string)time(), ['id' => null]);
    return $n;
}

/** Cron: cập nhật ảnh đại diện bạn Zalo cho Cửa sổ chat (6 giờ/lần). */
function zu_tick(array $CFG = []): string {
    if ((string)setting_get('zalo_user.project', '') === '') return 'chưa kết nối Zalo cá nhân';
    if (time() - (int)setting_get('zalo_user.avatars_at', 0) < 6 * 3600) return 'ok';
    try { return 'ảnh đại diện: ' . zu_sync_avatars(); } catch (Throwable $e) { return 'lỗi: ' . $e->getMessage(); }
}
