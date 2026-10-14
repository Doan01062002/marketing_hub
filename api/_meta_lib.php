<?php
/* Meta (đăng nhập Facebook) — đồng bộ quyền đã cấp và danh sách Fanpage (Page ID, tên, Page Access Token mã hoá).
   Dùng ở: oauth_facebook_callback.php (ngay sau đăng nhập), api/meta.php (nút "Đồng bộ quyền"), cron/run.php (mỗi ngày).
   Token người dùng và Page token chỉ nằm phía máy chủ, lưu mã hoá (tok_enc); mọi lệnh gọi kèm appsecret_proof. */

class MetaAuthError extends RuntimeException {}

function meta_proof(array $CFG, string $token): string {
    return hash_hmac('sha256', $token, (string)$CFG['meta_app_secret']);
}

/** GET Graph API bằng token người dùng/Page (kèm appsecret_proof). Trả [httpCode, data]. */
function meta_get(array $CFG, string $path, array $q, string $token): array {
    [$c, $d] = http_get_json(graph_url($CFG, $path, $q + ['access_token' => $token, 'appsecret_proof' => meta_proof($CFG, $token)]));
    return [$c, is_array($d) ? $d : []];
}

/** Token hết hạn / bị thu hồi / đổi mật khẩu → phải đăng nhập lại (mã 190, hoặc 102 phiên hết hạn). */
function meta_token_invalid(array $d): bool {
    $code = (int)($d['error']['code'] ?? 0);
    return $code === 190 || $code === 102;
}

function meta_wanted_scopes(array $CFG, string $provider): array {
    return array_values(array_filter(array_map('trim', explode(',', ($CFG['meta_scope_' . $provider] ?? '') ?: (PROVIDERS[$provider]['scope'] ?? '')))));
}

/** Đánh dấu kênh cần đăng nhập lại rồi ném MetaAuthError. */
function meta_need_reconnect(array $conn, string $why): void {
    global $pdo;
    $msg = 'Facebook: ' . $why . ' — bấm Kết nối lại';
    // Fanpage còn Page token (không hết hạn) thì vẫn đăng bài / nhận tin được — chỉ ghi lỗi, không chuyển trạng thái.
    $pdo->prepare(!empty($conn['secret']) && $conn['provider'] === 'facebook_page' ? 'UPDATE connections SET last_error=? WHERE id=?' : "UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$msg, $conn['id']]);
    throw new MetaAuthError($msg);
}

/** Quyền đã cấp / đã bỏ chọn / còn thiếu so với quyền app xin. Ghi vào connections.scopes, scopes_declined. */
function meta_sync_permissions(array $CFG, array $conn, string $token): array {
    global $pdo;
    [$c, $d] = meta_get($CFG, 'me/permissions', [], $token);
    if ($c !== 200) {
        if (meta_token_invalid($d)) meta_need_reconnect($conn, 'token hết hạn hoặc đã bị thu hồi');
        throw new RuntimeException('Không đọc được quyền Facebook: ' . ($d['error']['message'] ?? "HTTP $c"));
    }
    $granted = []; $declined = [];
    foreach ($d['data'] ?? [] as $p) {
        if (($p['status'] ?? '') === 'granted') $granted[] = (string)$p['permission'];
        elseif (in_array($p['status'] ?? '', ['declined', 'expired'], true)) $declined[] = (string)$p['permission'];
    }
    $missing = array_values(array_diff(meta_wanted_scopes($CFG, $conn['provider']), $granted));
    $pdo->prepare('UPDATE connections SET scopes=?, scopes_declined=? WHERE id=?')
        ->execute([implode(',', $granted), implode(',', $declined), $conn['id']]);
    return ['granted' => $granted, 'declined' => $declined, 'missing' => $missing];
}

/** Fanpage người dùng đã cấp cho app: lưu Page ID, tên, nhóm, quyền trên Page, Page Access Token (mã hoá), Instagram gắn kèm.
 *  Page không còn được cấp sẽ bị xoá khỏi danh sách. Trả về danh sách KHÔNG kèm token. */
function meta_sync_pages(array $CFG, array $conn, string $token): array {
    global $pdo;
    $pages = [];
    [$c, $d] = meta_get($CFG, 'me/accounts', ['fields' => 'id,name,category,tasks,access_token,instagram_business_account{id,username}', 'limit' => 100], $token);
    for ($i = 0; $i < 20; $i++) {
        if ($c !== 200) {
            if (meta_token_invalid($d)) meta_need_reconnect($conn, 'token hết hạn hoặc đã bị thu hồi');
            throw new RuntimeException('Không lấy được danh sách Fanpage: ' . ($d['error']['message'] ?? "HTTP $c"));
        }
        foreach ($d['data'] ?? [] as $p) $pages[(string)$p['id']] = $p;
        $next = $d['paging']['next'] ?? null;
        if (!$next) break;
        [$c, $d] = http_get_json($next); $d = is_array($d) ? $d : [];
    }

    $up = $pdo->prepare("INSERT INTO meta_pages(connection_id,project_id,page_id,name,category,tasks,page_token,ig_user_id,ig_username,synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(connection_id,page_id) DO UPDATE SET project_id=excluded.project_id, name=excluded.name, category=excluded.category, tasks=excluded.tasks,
        page_token=excluded.page_token, ig_user_id=excluded.ig_user_id, ig_username=excluded.ig_username, synced_at=excluded.synced_at");
    foreach ($pages as $id => $p) {
        $up->execute([$conn['id'], $conn['project_id'] ?? null, $id, (string)($p['name'] ?? $id), $p['category'] ?? null,
            json_encode(array_values($p['tasks'] ?? [])), tok_enc($CFG, $p['access_token'] ?? null),
            $p['instagram_business_account']['id'] ?? null, $p['instagram_business_account']['username'] ?? null]);
    }
    if ($pages) {
        $in = implode(',', array_fill(0, count($pages), '?'));
        $pdo->prepare("DELETE FROM meta_pages WHERE connection_id=? AND page_id NOT IN ($in)")->execute(array_merge([$conn['id']], array_map('strval', array_keys($pages))));
    } else $pdo->prepare('DELETE FROM meta_pages WHERE connection_id=?')->execute([$conn['id']]);
    return meta_pages_list($conn['id']);
}

/** Danh sách Page đã lưu của một kết nối (không có token). */
function meta_pages_list(string $connId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT page_id,name,category,tasks,ig_username,page_token IS NOT NULL AS has_token,synced_at FROM meta_pages WHERE connection_id=? ORDER BY name');
    $st->execute([$connId]);
    return array_map(fn($r) => ['page_id' => $r['page_id'], 'name' => $r['name'], 'category' => $r['category'], 'tasks' => jdecode($r['tasks']),
        'ig_username' => $r['ig_username'], 'has_token' => (bool)$r['has_token'], 'synced_at' => $r['synced_at']], $st->fetchAll());
}

/** Page Access Token đã lưu (giải mã) cho Page của kết nối; ưu tiên bản của chính kết nối, rồi bản cùng dự án. */
function meta_stored_page_token(array $CFG, array $conn): ?string {
    global $pdo;
    if (empty($conn['external_account_id'])) return null;
    $st = $pdo->prepare('SELECT page_token FROM meta_pages WHERE page_id=? AND (connection_id=? OR project_id IS ?) AND page_token IS NOT NULL
        ORDER BY (connection_id=?) DESC, synced_at DESC LIMIT 1');
    $st->execute([(string)$conn['external_account_id'], $conn['id'], $conn['project_id'] ?? null, $conn['id']]);
    $v = $st->fetchColumn();
    return $v ? tok_dec($CFG, (string)$v) : null;
}

/** Đồng bộ đầy đủ một kết nối Meta OAuth: quyền + (Fanpage) danh sách Page. Ghi lỗi rõ ràng để người dùng biết cần làm gì. */
function meta_sync_account(array $CFG, array $conn): array {
    global $pdo;
    if (($conn['auth_method'] ?? 'oauth') !== 'oauth' || (PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'meta') throw new RuntimeException('Không phải kết nối đăng nhập Facebook');
    if (!$CFG['meta_app_id'] || !$CFG['meta_app_secret']) throw new RuntimeException('Chưa cấu hình META_APP_ID / META_APP_SECRET');
    $token = meta_user_token($CFG, $conn);
    if (!$token) meta_need_reconnect($conn, 'chưa có token hoặc không giải mã được');
    $out = ['perms' => meta_sync_permissions($CFG, $conn, $token), 'pages' => null, 'warning' => null];
    if ($conn['provider'] === 'facebook_page') {
        $out['pages'] = meta_sync_pages($CFG, $conn, $token);
        $cur = (string)($conn['external_account_id'] ?? '');
        if ($cur !== '' && !in_array($cur, array_column($out['pages'], 'page_id'), true)) {
            $out['warning'] = 'Tài khoản đã đăng nhập không còn cấp quyền Page ' . $cur . ' — kết nối lại bằng tài khoản quản trị Page đó và tick Page ở bước "Chọn Trang"';
            $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute([$out['warning'], $conn['id']]);
            return $out;
        }
        if ($cur !== '') foreach ($out['pages'] as $p) if ($p['page_id'] === $cur) $pdo->prepare('UPDATE connections SET account_name=? WHERE id=?')->execute([$p['name'], $conn['id']]);
    }
    if ($out['perms']['missing']) $out['warning'] = 'Thiếu quyền: ' . implode(', ', $out['perms']['missing']) . ' — bấm Kết nối lại và tick đủ quyền';
    $pdo->prepare("UPDATE connections SET status='connected', last_error=? WHERE id=?")->execute([$out['warning'], $conn['id']]);
    return $out;
}
