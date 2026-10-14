<?php
/* KẾT NỐI NHANH BẰNG LINK: dán link Fanpage / kênh YouTube → đăng nhập (cửa sổ bật lên) → tự chọn đúng Page / kênh → đồng bộ.
   - Fanpage: lưu PAGE ACCESS TOKEN (lấy từ token người dùng dài hạn) vào connections.secret — Page token loại này KHÔNG có hạn
     (chỉ mất khi đổi mật khẩu Facebook, mất quyền quản trị Page hoặc gỡ app).
   - YouTube: lưu refresh token (access_type=offline). Không hết hạn khi OAuth consent screen ở chế độ "In production".
   Phiên: $_SESSION['quick_ch'] = {conn_id, provider, key, kind}. */
require_once __DIR__ . '/_metrics_lib.php';

/** Phân tích link → [provider, key, kind] hoặc ném lỗi. kind: id | slug | handle | legacy */
function qc_parse(string $url): array {
    $u = trim($url);
    if ($u === '') throw new RuntimeException('Dán link Fanpage hoặc kênh YouTube');
    if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $u)) return ['youtube', $u, 'id'];
    if (str_starts_with($u, '@')) return ['youtube', substr($u, 1), 'handle'];
    if (!preg_match('#^[a-z]+://#i', $u)) $u = 'https://' . $u;
    $p = parse_url($u); $host = strtolower(preg_replace('/^(www|m|web|mbasic)\./', '', (string)($p['host'] ?? '')));
    $path = trim((string)($p['path'] ?? ''), '/'); parse_str((string)($p['query'] ?? ''), $q);
    $seg = $path === '' ? [] : explode('/', $path);
    if (in_array($host, ['facebook.com', 'fb.com', 'fb.me'], true)) {
        if (!empty($q['id']) && ctype_digit((string)$q['id'])) return ['facebook_page', (string)$q['id'], 'id'];
        if (($seg[0] ?? '') === 'people' || ($seg[0] ?? '') === 'pages') { foreach (array_reverse($seg) as $s) if (ctype_digit($s)) return ['facebook_page', $s, 'id']; }
        if (preg_match('/-(\d{8,})$/', $seg[0] ?? '', $m)) return ['facebook_page', $m[1], 'id'];
        $s = $seg[0] ?? '';
        if ($s === '' || in_array($s, ['groups', 'share', 'watch', 'events', 'profile.php', 'reel', 'photo'], true)) throw new RuntimeException('Link chưa phải trang Fanpage — mở Fanpage rồi copy link trên thanh địa chỉ');
        return ['facebook_page', ctype_digit($s) ? $s : strtolower($s), ctype_digit($s) ? 'id' : 'slug'];
    }
    if (in_array($host, ['youtube.com', 'youtu.be'], true)) {
        $s0 = $seg[0] ?? '';
        if ($s0 === 'channel' && preg_match('/^UC[A-Za-z0-9_-]{22}$/', $seg[1] ?? '')) return ['youtube', $seg[1], 'id'];
        if (str_starts_with($s0, '@')) return ['youtube', substr(rawurldecode($s0), 1), 'handle'];
        if (in_array($s0, ['c', 'user'], true) && !empty($seg[1])) return ['youtube', $seg[1], 'legacy'];
        throw new RuntimeException('Link YouTube cần dạng youtube.com/@ten-kenh hoặc youtube.com/channel/UC… (mở trang kênh rồi copy link)');
    }
    throw new RuntimeException('Chỉ hỗ trợ link Facebook (Fanpage) hoặc YouTube');
}

function qc_norm(string $s): string { return strtolower(preg_replace('/[^a-z0-9]/i', '', $s)); }

/** Sau khi đăng nhập Facebook: tìm đúng Page trong danh sách Page tài khoản quản trị, lưu Page token vĩnh viễn. */
function qc_finish_facebook(array $CFG, array $conn, string $userToken, array $q): array {
    global $pdo;
    $pages = []; $url = graph_url($CFG, 'me/accounts', ['fields' => 'id,name,username,link,access_token,tasks', 'limit' => 100, 'access_token' => $userToken]);
    for ($i = 0; $url && $i < 10; $i++) { [$c, $d] = http_get_json($url); if ($c !== 200) break; foreach ($d['data'] ?? [] as $p) $pages[] = $p; $url = $d['paging']['next'] ?? null; }
    $key = (string)$q['key']; $pick = null;
    foreach ($pages as $p) {
        if ($q['kind'] === 'id' && (string)$p['id'] === $key) $pick = $p;
        elseif ($q['kind'] === 'slug' && (strtolower((string)($p['username'] ?? '')) === $key || str_contains(strtolower((string)($p['link'] ?? '')), '/' . $key) || qc_norm($p['name']) === qc_norm($key))) $pick = $p;
        if ($pick) break;
    }
    if (!$pick && $q['kind'] === 'slug') { // username riêng không trả về: hỏi Graph theo tên đường dẫn
        [$c, $d] = http_get_json(graph_url($CFG, $key, ['fields' => 'id,name', 'access_token' => $userToken]));
        if ($c === 200 && !empty($d['id'])) foreach ($pages as $p) if ((string)$p['id'] === (string)$d['id']) { $pick = $p; break; }
    }
    if (!$pick) {
        $pdo->prepare('DELETE FROM connections WHERE id=? AND external_account_id IS NULL')->execute([$conn['id']]);
        return ['conn_error' => 'Tài khoản Facebook vừa đăng nhập không quản trị Page "' . $key . '"' . ($pages ? ' — các Page tìm thấy: ' . implode(', ', array_slice(array_column($pages, 'name'), 0, 8)) : ' (không có Page nào — có thể đã bỏ tick Page khi cấp quyền)') . '. Đăng nhập đúng tài khoản quản trị Page rồi thử lại.'];
    }
    $dup = $pdo->prepare("SELECT id FROM connections WHERE provider='facebook_page' AND external_account_id=? AND project_id=? AND id<>?"); $dup->execute([$pick['id'], $conn['project_id'], $conn['id']]);
    $target = $dup->fetchColumn() ?: $conn['id'];
    if ($target !== $conn['id']) $pdo->prepare('DELETE FROM connections WHERE id=?')->execute([$conn['id']]);
    $pdo->prepare("UPDATE connections SET external_account_id=?, label=?, secret=?, access_token=?, expires_at=NULL, status='connected', last_error=NULL WHERE id=?")
        ->execute([$pick['id'], $pick['name'], $pick['access_token'], $userToken, $target]);
    $msg = 'Đã kết nối Fanpage ' . $pick['name'] . ' (khoá Page không hết hạn)';
    try { sync_connection($CFG, get_connection($target, true)); $msg .= ' · đã đồng bộ số liệu'; } catch (Throwable $e) { $msg .= ' · đồng bộ: ' . $e->getMessage(); }
    log_activity('create', 'connection', $target, 'Kết nối nhanh Fanpage ' . $pick['name']);
    return ['conn_msg' => $msg, 'conn_ok' => $target];
}

/** Sau khi đăng nhập Google: kiểm tra đúng kênh YouTube theo link. */
function qc_finish_youtube(array $CFG, array $conn, array $tok, array $q): array {
    global $pdo;
    [$c, $d] = http_get_json('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', ['Authorization: Bearer ' . $tok['access_token']]);
    $list = $d['items'] ?? [];
    $key = (string)$q['key']; $pick = null;
    foreach ($list as $ch) {
        $cu = strtolower(ltrim((string)($ch['snippet']['customUrl'] ?? ''), '@'));
        if (($q['kind'] === 'id' && $ch['id'] === $key) || ($q['kind'] !== 'id' && ($cu === strtolower($key) || qc_norm($ch['snippet']['title'] ?? '') === qc_norm($key)))) { $pick = $ch; break; }
    }
    if (!$pick) {
        $pdo->prepare('DELETE FROM connections WHERE id=? AND external_account_id IS NULL')->execute([$conn['id']]);
        $got = $list ? implode(', ', array_map(fn($x) => ($x['snippet']['title'] ?? $x['id']) . ($x['snippet']['customUrl'] ?? '' ? ' (' . $x['snippet']['customUrl'] . ')' : ''), $list)) : 'không có kênh nào';
        return ['conn_error' => ($c !== 200 ? 'YouTube: ' . ($d['error']['message'] ?? "HTTP $c") : 'Tài khoản Google vừa chọn là kênh: ' . $got . ' — không phải "' . $key . '". Kênh thương hiệu: ở màn hình Google chọn đúng KÊNH (không phải tài khoản Gmail) rồi thử lại.')];
    }
    $dup = $pdo->prepare("SELECT id FROM connections WHERE provider='youtube' AND external_account_id=? AND project_id=? AND id<>?"); $dup->execute([$pick['id'], $conn['project_id'], $conn['id']]);
    $target = $dup->fetchColumn() ?: $conn['id'];
    if ($target !== $conn['id']) {
        $pdo->prepare("UPDATE connections SET auth_method='oauth', access_token=?, refresh_token=COALESCE(?, refresh_token), expires_at=?, status='connected', last_error=NULL WHERE id=?")
            ->execute([$tok['access_token'], $tok['refresh_token'] ?? null, date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600)), $target]);
        $pdo->prepare('DELETE FROM connections WHERE id=?')->execute([$conn['id']]);
    } else $pdo->prepare('UPDATE connections SET external_account_id=?, label=? WHERE id=?')->execute([$pick['id'], $pick['snippet']['title'], $target]);
    $msg = 'Đã kết nối kênh YouTube ' . $pick['snippet']['title'] . (empty($tok['refresh_token']) ? ' (Google không trả refresh token — vào myaccount.google.com/permissions gỡ quyền app rồi kết nối lại)' : '');
    try { sync_connection($CFG, get_connection($target, true)); $msg .= ' · đã đồng bộ số liệu'; } catch (Throwable $e) { $msg .= ' · đồng bộ: ' . $e->getMessage(); }
    log_activity('create', 'connection', $target, 'Kết nối nhanh YouTube ' . $pick['snippet']['title']);
    return ['conn_msg' => $msg, 'conn_ok' => $target];
}
