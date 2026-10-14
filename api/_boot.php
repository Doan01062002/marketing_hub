<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
// Ngày thống kê / lịch sử theo giờ Việt Nam. Cột created_at của SQLite (datetime('now')) vẫn là UTC.
date_default_timezone_set('Asia/Ho_Chi_Minh');

$CFG = require __DIR__ . '/../config.php';
// Bản DEV chạy ở thư mục con (app_url …/dev): cookie phiên riêng, chỉ gửi trong /dev/ → không dính phiên bản thật.
$__path = rtrim((string)parse_url((string)$CFG['app_url'], PHP_URL_PATH), '/');
if ($__path !== '') { session_name('MKTSESS' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $__path))); }
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => ($__path ?: '') . '/']);
session_start();

header('Content-Type: application/json; charset=utf-8');

$DB_DIR = __DIR__ . '/../data';
if (!is_dir($DB_DIR)) mkdir($DB_DIR, 0777, true);
$DB_PATH = $DB_DIR . '/marketing.sqlite';
$isNew = !file_exists($DB_PATH);

$pdo = new PDO('sqlite:' . $DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 30000'); // 12/10/2026: nhiều tiến trình ghi cùng lúc (webhook, cron, máy HL, thăm dò 4s) → chờ khoá thay vì lỗi "database is locked"
try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) {}

if ($isNew) {
    $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
    require __DIR__ . '/../seed.php';
    seed_marketing_db($pdo);
}

/** Các bảng nghiệp vụ có cột project_id (mỗi bản ghi thuộc đúng một dự án). Bảng con (post_targets, automation_runs,
 *  sequence_enrollments, metrics_history) suy ra dự án qua bản ghi cha. */
const PROJECT_TABLES = ['campaigns', 'content_items', 'tasks', 'leads', 'connections', 'posts', 'comments', 'insights', 'segments',
    'trends', 'competitors', 'okrs', 'brand_items', 'automations', 'sequences', 'bot_jobs', 'reply_templates'];

// Nâng cấp CSDL đã có mà không mất dữ liệu. Tăng SCHEMA_VERSION mỗi khi đổi schema.sql.
const SCHEMA_VERSION = 16; // v16 (12/10/2026): ref_channels + hl_jobs + kênh X · v15: inbox_threads.kind/post_id/permalink (bình luận trong Cửa sổ chat) · // v14: inbox_ai_jobs (AI chạy trên máy HL bằng Claude Max) · // v12: inbox_threads/inbox_messages · v13 (12/10/2026): AI chăm sóc (ai_mode, needs_human)
if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < SCHEMA_VERSION) {
    $addCols = function (string $table, array $cols) use ($pdo): void {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!$have) return; // bảng chưa tồn tại (vd bot_jobs do _bot_lib tạo khi cần) → bỏ qua
        foreach ($cols as $name => $def) if (!in_array($name, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $name $def");
    };
    $addCols('connections', ['auth_method' => "TEXT NOT NULL DEFAULT 'oauth'", 'secret' => 'TEXT', 'client_id' => 'TEXT', 'client_secret' => 'TEXT']);
    $addCols('users', ['active' => 'INTEGER NOT NULL DEFAULT 1', 'phone' => 'TEXT', 'scope' => 'TEXT',
        'co' => 'TEXT', 'br' => 'TEXT', 'last_login_at' => 'TEXT']);
    $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql')); // chỉ có CREATE … IF NOT EXISTS: tạo bảng mới còn thiếu
    $addCols('okrs', ['metric' => "TEXT NOT NULL DEFAULT 'manual'"]);
    $addCols('competitors', ['place_id' => 'TEXT', 'place_name' => 'TEXT', 'place_rating' => 'REAL', 'place_reviews' => 'INTEGER',
        'place_address' => 'TEXT', 'place_url' => 'TEXT', 'place_synced_at' => 'TEXT',
        'website' => 'TEXT', 'web_snapshot' => 'TEXT', 'web_checked_at' => 'TEXT', 'web_change' => 'TEXT']);
    $addCols('leads', ['email' => 'TEXT', 'notes' => 'TEXT', 'source_ref' => 'TEXT']);
    // v11 (11/10/2026): tài khoản kênh nhiều môi trường (sandbox/production), quyền đã cấp, tên/ảnh tài khoản
    $addCols('connections', ['env' => 'TEXT', 'scopes' => 'TEXT', 'account_name' => 'TEXT', 'avatar' => 'TEXT']);
    $addCols('inbox_threads', ['ai_mode' => "TEXT NOT NULL DEFAULT 'on'", 'needs_human' => 'INTEGER NOT NULL DEFAULT 0', 'kind' => "TEXT NOT NULL DEFAULT 'message'", 'post_id' => 'TEXT', 'permalink' => 'TEXT']);

    /* v8 (11/10/2026) — lớp DỰ ÁN: mọi bản ghi nghiệp vụ thuộc một project_id; backend lọc theo dự án. */
    foreach (PROJECT_TABLES as $t) $addCols($t, ['project_id' => 'TEXT']);
    $addCols('activity_log', ['project_id' => 'TEXT']);
    $addCols('notifications', ['project_id' => 'TEXT']);
    if (!(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()) {
        // 13/10/2026: CSDL chưa có dự án → tạo dự án mặc định từ config (default_project_*), gán mọi bản ghi cũ vào đó.
        $dpId = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($CFG['default_project_id'] ?? ''))) ?: 'main';
        $pdo->prepare("INSERT INTO projects(id,name,type,domain,color,status,goal) VALUES (?,?,?,?,?,'active',?)")
            ->execute([$dpId, (string)($CFG['default_project_name'] ?? '') ?: 'Dự án chính', 'internal', (string)($CFG['default_project_domain'] ?? '') ?: null, '#168164', (string)($CFG['default_project_goal'] ?? '') ?: null]);
        foreach (PROJECT_TABLES as $t) { if (!$pdo->query("PRAGMA table_info($t)")->fetchAll()) continue; $pdo->prepare("UPDATE $t SET project_id=? WHERE project_id IS NULL")->execute([$dpId]); }
        $pdo->prepare("UPDATE activity_log SET project_id=? WHERE project_id IS NULL")->execute([$dpId]);
        // Thành viên: mọi tài khoản đang có vào dự án mặc định; admin = chủ dự án, manager = quản lý, staff = nội dung.
        $mem = $pdo->prepare('INSERT OR IGNORE INTO project_members(project_id,user_id,role) VALUES (?,?,?)');
        foreach ($pdo->query('SELECT id, role FROM users')->fetchAll() as $u) {
            $mem->execute([$dpId, $u['id'], $u['role'] === 'admin' ? 'owner' : ($u['role'] === 'manager' ? 'manager' : 'content')]);
        }
    }
    foreach (PROJECT_TABLES as $t) { if ($pdo->query("PRAGMA table_info($t)")->fetchAll()) $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_project ON $t(project_id)"); }
    // v10: dự án 'webseo' (nếu có) gắn nguồn CRM webseo.vn — tích hợp riêng, nơi khác không có dự án này nên bỏ qua
    $pr = $pdo->query("SELECT settings FROM projects WHERE id='webseo'")->fetchColumn();
    if ($pr !== false) { $sj = json_decode((string)$pr, true) ?: []; if (empty($sj['crm'])) { $sj['crm'] = 'webseo'; $pdo->prepare('UPDATE projects SET settings=? WHERE id=?')->execute([json_encode($sj, JSON_UNESCAPED_UNICODE), 'webseo']); } }
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

// OAuth client Google dán trên giao diện (Thiết lập → Nguồn dữ liệu thị trường) dùng khi config để trống.
foreach (['google_client_id' => 'google.client_id', 'google_client_secret' => 'google.client_secret', 'meta_app_id' => 'meta.app_id', 'meta_app_secret' => 'meta.app_secret'] as $__ck => $__sk) {
    if (empty($CFG[$__ck]) && ($__v = setting_get($__sk))) $CFG[$__ck] = $__v;
}

/** Kết thúc luồng OAuth: cửa sổ bật lên (kết nối nhanh) thì báo về trang chính rồi tự đóng; không thì quay về app như cũ. */
function oauth_finish(array $CFG, array $q): void {
    if (empty($_SESSION['oauth_popup'])) back_to_app($CFG, $q);
    unset($_SESSION['oauth_popup']);
    header('Content-Type: text/html; charset=utf-8');
    $msg = json_encode($q, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $txt = htmlspecialchars($q['conn_error'] ?? $q['conn_msg'] ?? 'Đã kết nối', ENT_QUOTES);
    echo "<!doctype html><meta charset=utf-8><title>Kết nối Google</title><body style=\"font-family:system-ui;padding:24px\"><p>$txt</p><p>Cửa sổ sẽ tự đóng…</p>"
        . "<script>try{window.opener&&window.opener.postMessage({mktOauth:$msg},location.origin)}catch(e){}setTimeout(function(){window.close()},400)</script>";
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function respond($data, int $code = 200): void {
    // Cloudflare thay thân phản hồi 502/503/504 bằng trang lỗi của nó → người dùng mất lời nhắn. Lỗi từ nền tảng ngoài trả 424.
    if (in_array($code, [502, 503, 504], true)) $code = 424;
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): void {
    respond(['error' => $msg], $code);
}

function current_user(): ?array {
    global $pdo;
    static $cache = null;
    if (empty($_SESSION['uid'])) return null;
    if ($cache && $cache['id'] === $_SESSION['uid']) return $cache;
    $st = $pdo->prepare('SELECT id,name,email,role,title,approve,active,co,br FROM users WHERE id=?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || !(int)$u['active']) { unset($_SESSION['uid']); return null; } // tài khoản bị khoá → đăng xuất ngay
    $u['approve'] = (int)$u['approve'];
    return $cache = $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) fail('Chưa đăng nhập', 401);
    return $u;
}

/* ---- Phân quyền ----
   admin   : toàn quyền, quản lý tài khoản nhân viên và cấu hình kết nối.
   manager : quản lý công việc của nhóm, xem thống kê nhân viên.
   staff   : làm việc được giao.
   Quyền DUYỆT (chiến dịch, nội dung, công việc) là cờ riêng `approve`, admin luôn có. */
const ROLES = ['admin' => 'Quản trị', 'manager' => 'Trưởng nhóm', 'staff' => 'Nhân viên'];

function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) fail('Bạn không có quyền thực hiện thao tác này', 403);
    return $u;
}

function can_approve(array $u): bool { return $u['role'] === 'admin' || (int)$u['approve'] === 1; }

function require_approver(): array {
    $u = require_login();
    if (!can_approve($u)) fail('Chỉ người có quyền duyệt mới được duyệt / từ chối', 403);
    return $u;
}

/* ---- DỰ ÁN (project) ----
   Người dùng chọn dự án trước; mọi API đọc/ghi trong phạm vi dự án đó. Dự án hiện hành lấy theo thứ tự:
   header X-Project → ?project_id → body JSON project_id → phiên → dự án đầu tiên người này thuộc.
   Kiểm tra thành viên ở backend; admin hệ thống là chủ mọi dự án. Job nền (CLI) không có dự án hiện hành:
   mỗi hàm cron tự lặp theo project_id của bản ghi. */
const PROJECT_ROLES = ['owner' => 'Chủ dự án', 'manager' => 'Quản lý dự án', 'content' => 'Nhân sự nội dung',
    'ads' => 'Nhân sự quảng cáo', 'client' => 'Khách hàng', 'viewer' => 'Chỉ xem'];
const PROJECT_WRITE_ROLES = ['owner', 'manager', 'content', 'ads'];
const PROJECT_ADMIN_ROLES = ['owner', 'manager'];

/** Dự án người này thuộc (kèm vai trò). Admin hệ thống thấy mọi dự án đang hoạt động với vai trò owner. */
function projects_for_user(array $u): array {
    global $pdo;
    if ($u['role'] === 'admin') {
        $rows = $pdo->query("SELECT p.*, 'owner' AS member_role FROM projects p WHERE p.status<>'archived' ORDER BY p.type='internal' DESC, p.rowid")->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT p.*, m.role AS member_role FROM projects p JOIN project_members m ON m.project_id=p.id
            WHERE m.user_id=? AND p.status<>'archived' ORDER BY p.type='internal' DESC, p.rowid");
        $st->execute([$u['id']]); $rows = $st->fetchAll();
    }
    return $rows;
}

function public_project(array $p): array {
    return ['id' => $p['id'], 'name' => $p['name'], 'type' => $p['type'], 'domain' => $p['domain'], 'timezone' => $p['timezone'],
        'color' => $p['color'], 'goal' => $p['goal'], 'budget_month' => (int)$p['budget_month'], 'status' => $p['status'],
        'settings' => jdecode($p['settings'] ?? null), 'role' => $p['member_role'] ?? null, 'created_at' => $p['created_at']];
}

/** Dự án hiện hành của request (null nếu chưa đăng nhập hoặc không thuộc dự án nào). Kết quả kèm 'member_role'. */
function current_project(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $u = current_user();
    if (!$u) return $cache = null;
    $want = trim((string)($_SERVER['HTTP_X_PROJECT'] ?? $_GET['project_id'] ?? ''));
    if ($want === '' && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT', 'PATCH'], true)) $want = (string)(json_input()['project_id'] ?? '');
    if ($want === '') $want = (string)($_SESSION['project_id'] ?? '');
    $list = projects_for_user($u);
    $found = null;
    foreach ($list as $p) if ($p['id'] === $want) { $found = $p; break; }
    if (!$found && $want !== '' && ($_SERVER['HTTP_X_PROJECT'] ?? $_GET['project_id'] ?? null)) fail('Bạn không thuộc dự án này', 403);
    if (!$found) $found = $list[0] ?? null;
    if ($found) $_SESSION['project_id'] = $found['id'];
    return $cache = $found;
}

/** project_id hiện hành; 403 nếu người dùng chưa thuộc dự án nào. */
function pid(): string {
    $p = current_project();
    if (!$p) fail('Bạn chưa được thêm vào dự án nào — nhờ chủ workspace thêm', 403);
    return $p['id'];
}
function project_role(): ?string { $p = current_project(); return $p['member_role'] ?? null; }
function require_project_role(string ...$roles): array {
    $u = require_login(); $r = project_role();
    if (!$r || !in_array($r, $roles, true)) fail('Vai trò của bạn trong dự án này không được thực hiện thao tác đó', 403);
    return $u;
}
/** Được sửa dữ liệu dự án (owner/manager/content/ads). client/viewer chỉ xem. */
function require_project_write(): array { return require_project_role(...PROJECT_WRITE_ROLES); }
function require_project_admin(): array { return require_project_role(...PROJECT_ADMIN_ROLES); }
/** Quyền DUYỆT trong dự án: chủ/quản lý dự án, khách hàng (duyệt nội dung được cấp), hoặc cờ approve hệ thống. */
function can_approve_project(array $u): bool {
    $r = project_role();
    return in_array($r, ['owner', 'manager', 'client'], true) || can_approve($u);
}

/** Lấy một bản ghi trong dự án hiện hành; không thấy (hoặc thuộc dự án khác) → null. Chống IDOR. */
function scoped(string $table, ?string $id, string $col = 'id'): ?array {
    global $pdo;
    if ($id === null || $id === '') return null;
    $st = $pdo->prepare("SELECT * FROM $table WHERE $col=? AND project_id=?");
    $st->execute([$id, pid()]);
    return $st->fetch() ?: null;
}
/** Như scoped() nhưng 404 kèm thông điệp khi không có. */
function need(string $table, ?string $id, string $what): array {
    $r = scoped($table, $id);
    if (!$r) fail("Không tìm thấy $what trong dự án này", 404);
    return $r;
}
/** Mảnh WHERE cho truy vấn lọc theo dự án hiện hành: [' AND p.project_id=?', [pid]]. */
function project_where(string $alias = ''): array { return [($alias !== '' ? "$alias." : '') . 'project_id=?', [pid()]]; }

/** Ghi nhật ký thao tác. Không bao giờ làm hỏng request chính nếu ghi lỗi. */
function log_activity(string $action, string $entity, ?string $entityId = null, ?string $detail = null, ?array $u = null, ?string $projectId = null): void {
    global $pdo;
    try {
        $u = $u ?? current_user();
        $projectId = $projectId ?? (PHP_SAPI === 'cli' ? null : (current_project()['id'] ?? null));
        $pdo->prepare('INSERT INTO activity_log(user_id,action,entity,entity_id,detail,project_id) VALUES (?,?,?,?,?,?)')
            ->execute([$u['id'] ?? null, $action, $entity, $entityId, $detail !== null ? mb_substr($detail, 0, 500) : null, $projectId]);
    } catch (Throwable $e) {}
}

function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        fail('Method không hỗ trợ', 405);
    }
}

function gen_id(string $prefix): string {
    return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/** Điều hướng trình duyệt về lại app (dùng cho các trang OAuth start/callback —
 *  không phải endpoint JSON gọi bằng fetch, nên không dùng fail()/respond() ở đây,
 *  mà đưa người dùng quay lại đúng chỗ kèm thông báo lỗi/thành công qua query string. */
function back_to_app(array $CFG, array $q, string $hash = 'settings'): void {
    header('Location: ' . rtrim($CFG['app_url'], '/') . '/?' . http_build_query($q) . '#' . $hash);
    exit;
}

function jdecode(?string $s, array $default = []): array {
    if (!$s) return $default;
    $d = json_decode($s, true);
    return is_array($d) ? $d : $default;
}

/** Gọi HTTP thật ra bên ngoài (Google / Meta APIs). Trả về [statusCode, decodedJsonOrNull, rawBody]. */
function http_call(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if (!empty($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    if (isset($opts['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    if (isset($opts['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json'], JSON_UNESCAPED_UNICODE));
        $h = $opts['headers'] ?? [];
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, null, $err];
    $decoded = json_decode($body, true);
    return [$code, $decoded, $body];
}

function http_get_json(string $url, array $headers = []): array { return http_call('GET', $url, ['headers' => $headers]); }
function http_post_form(string $url, array $form, array $headers = []): array { return http_call('POST', $url, ['form' => $form, 'headers' => $headers]); }

/** Các loại kênh. 'auth' cho biết đăng nhập qua Google hay Meta.
 *  Quyền xin thêm để ĐĂNG BÀI và TRẢ LỜI BÌNH LUẬN (không chỉ đọc số liệu):
 *  - Fanpage: pages_manage_posts (đăng), pages_read_user_content + pages_manage_engagement (đọc/trả lời/ẩn bình luận),
 *    instagram_basic + instagram_content_publish + instagram_manage_comments (Instagram Business gắn với Page).
 *  - YouTube: youtube.upload (đăng video), youtube.force-ssl (trả lời bình luận).
 *  - TikTok: chỉ đọc số liệu (user.info.* + video.list). Thêm video.publish khi làm đăng video — scope chưa được
 *    TikTok cấp cho app thì trang xác thực báo lỗi, nên không xin trước. */
const PROVIDERS = [
    'ga4' => ['auth' => 'google', 'scope' => 'https://www.googleapis.com/auth/analytics.readonly'],
    'gsc' => ['auth' => 'google', 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly'],
    'gbp' => ['auth' => 'google', 'scope' => 'https://www.googleapis.com/auth/business.manage'],
    'youtube' => ['auth' => 'google', 'scope' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl'],
    'google_ads' => ['auth' => 'google', 'scope' => 'https://www.googleapis.com/auth/adwords'],
    'facebook_page' => ['auth' => 'meta', 'scope' => 'pages_show_list,pages_read_engagement,read_insights,pages_manage_posts,pages_read_user_content,pages_manage_engagement,instagram_basic,instagram_content_publish,instagram_manage_comments'],
    'meta_ads' => ['auth' => 'meta', 'scope' => 'ads_read,ads_management,business_management,pages_show_list,pages_read_engagement'], // 12/10: đủ quyền tạo quảng cáo + Conversions API
    'zalo_oa' => ['auth' => 'zalo', 'scope' => ''], // Zalo Official Account: nhập tay App ID + Secret key + Refresh token
    'tiktok' => ['auth' => 'tiktok', 'scope' => 'user.info.basic,user.info.profile,user.info.stats,video.list'],
    // 12/10/2026: X (Twitter) — OAuth 2.0 PKCE; scope cách nhau bằng dấu cách. offline.access để có refresh_token, media.write để tải ảnh/video.
    'x' => ['auth' => 'x', 'scope' => 'tweet.read tweet.write users.read offline.access media.write'],
];

/* ---- Cấu hình chỉnh trên giao diện (bảng app_settings) ----
   Khoá có tên bắt đầu bằng 'secret.' hoặc kết thúc '_key' / '_password' / '_token' là bí mật:
   không bao giờ trả về trình duyệt, chỉ trả dạng che (mask_secret). */
function setting_get(string $key, $default = null) {
    global $pdo;
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare('SELECT value FROM app_settings WHERE key=?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $cache[$key] = ($v === false || $v === null || $v === '') ? $default : $v;
}
function setting_set(string $key, ?string $value, ?array $u = null): void {
    global $pdo;
    $pdo->prepare("INSERT INTO app_settings(key,value,updated_by,updated_at) VALUES (?,?,?,datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_by=excluded.updated_by, updated_at=excluded.updated_at")
        ->execute([$key, $value, ($u ?? current_user())['id'] ?? null]);
}
function setting_is_secret(string $key): bool {
    return str_starts_with($key, 'secret.') || (bool)preg_match('/[._](key|password|token|secret)$/', $key);
}

/* ---- Sự kiện cho kịch bản tự động ----
   Gọi fire_event('lead.new', ['lead_id'=>…]) ở nơi xảy ra sự kiện. Thư viện tự động hoá
   (api/_automation_lib.php, hàm automation_event) xử lý; lỗi ở đó không làm hỏng request chính. */
function fire_event(string $event, array $payload = []): void {
    static $loaded = null;
    try {
        if ($loaded === null) {
            $f = __DIR__ . '/_automation_lib.php';
            $loaded = is_file($f) ? (require_once $f) !== false : false;
        }
        if ($loaded && function_exists('automation_event')) automation_event($event, $payload);
    } catch (Throwable $e) {
        log_activity('error', 'automation', null, $event . ': ' . $e->getMessage());
    }
}

/** Access token Zalo OA (sống ~25 giờ). Refresh token Zalo chỉ dùng ĐƯỢC MỘT LẦN — luôn lưu refresh token mới trả về. */
function zalo_access_token(array $conn): array {
    global $pdo;
    if ($conn['access_token'] && $conn['expires_at'] && strtotime($conn['expires_at']) > time() + 120) return [$conn['access_token'], null];
    if (!$conn['refresh_token'] || !$conn['client_id'] || !$conn['client_secret']) return [null, 'Zalo OA: thiếu App ID / Secret key / Refresh token'];
    [$c, $d] = http_call('POST', 'https://oauth.zaloapp.com/v4/oa/access_token', ['headers' => ['secret_key: ' . $conn['client_secret']],
        'form' => ['app_id' => $conn['client_id'], 'grant_type' => 'refresh_token', 'refresh_token' => $conn['refresh_token']]]);
    if (empty($d['access_token'])) {
        $now = get_connection($conn['id']); // tiến trình khác vừa làm mới → dùng token đó
        if ($now && $now['access_token'] && $now['expires_at'] && strtotime($now['expires_at']) > time() + 120) return [$now['access_token'], null];
        $msg = 'Zalo OA: không làm mới được token (' . ($d['error_description'] ?? $d['error_name'] ?? $d['message'] ?? "HTTP $c") . ') — tạo Refresh token mới rồi dán lại';
        $pdo->prepare('UPDATE connections SET last_error=? WHERE id=?')->execute([$msg, $conn['id']]);
        return [null, $msg];
    }
    $pdo->prepare('UPDATE connections SET access_token=?, refresh_token=?, expires_at=? WHERE id=?')
        ->execute([$d['access_token'], $d['refresh_token'] ?? $conn['refresh_token'], date('Y-m-d H:i:s', time() + (int)($d['expires_in'] ?? 90000)), $conn['id']]);
    return [$d['access_token'], null];
}

/** Gửi tin Telegram qua bot (Thiết lập → Gửi tin & thông báo). $chat rỗng = nhóm/chat mặc định. */
function telegram_send(string $text, ?string $chat = null): array {
    $tok = (string)setting_get('telegram.bot_token', '');
    $chat = trim((string)($chat ?: setting_get('telegram.chat_id', '')));
    if ($tok === '' || $chat === '') return ['ok' => false, 'error' => 'Chưa cấu hình Telegram (bot token + chat ID) ở Thiết lập → Gửi tin & thông báo'];
    [$c, $d] = http_call('POST', 'https://api.telegram.org/bot' . $tok . '/sendMessage', ['json' => [
        'chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true]]);
    return ($c === 200 && !empty($d['ok'])) ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'Telegram: ' . ($d['description'] ?? "HTTP $c")];
}

/** Thông báo trong app. $userId = null → gửi mọi Quản trị + Trưởng nhóm đang hoạt động.
 *  Có cấu hình Telegram và bật "chuyển thông báo" thì gửi kèm 1 tin vào nhóm Telegram (một lần cho mỗi thông báo). */
function notify(?string $userId, string $title, ?string $body = null, ?string $link = null, ?string $projectId = null): void {
    global $pdo, $CFG;
    static $sent = [];
    try { $projectId = $projectId ?? (PHP_SAPI === 'cli' ? null : (current_project()['id'] ?? null)); } catch (Throwable $e) { $projectId = null; }
    try {
        // Telegram: dự án có chat_id riêng (projects.settings.telegram_chat_id) thì gửi vào đó, không thì nhóm chung.
        $chat = null; $pname = '';
        if ($projectId) { $st = $pdo->prepare('SELECT name, settings FROM projects WHERE id=?'); $st->execute([$projectId]); if ($pr = $st->fetch()) { $pname = $pr['name']; $chat = jdecode($pr['settings'])['telegram_chat_id'] ?? null; } }
        if (setting_get('telegram.mirror', '1') === '1' && setting_get('telegram.bot_token') && ($chat || setting_get('telegram.chat_id'))) {
            $k = md5($title . '|' . $body . '|' . $projectId);
            if (!isset($sent[$k])) {
                $sent[$k] = 1;
                telegram_send('🔔 ' . ($pname ? "[$pname] " : '') . $title . ($body ? "\n" . $body : '') . ($link ? "\n" . rtrim($CFG['app_url'], '/') . '/#' . $link : ''), $chat);
            }
        }
    } catch (Throwable $e) {}
    try {
        if ($userId) $ids = [$userId];
        elseif ($projectId) { // chủ/quản lý DỰ ÁN + admin hệ thống
            $st = $pdo->prepare("SELECT u.id FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')))");
            $st->execute([$projectId]); $ids = array_column($st->fetchAll(), 'id');
        } else $ids = array_column($pdo->query("SELECT id FROM users WHERE active=1 AND role IN ('admin','manager')")->fetchAll(), 'id');
        $st = $pdo->prepare('INSERT INTO notifications(user_id,title,body,link,project_id) VALUES (?,?,?,?,?)');
        foreach ($ids as $id) $st->execute([$id, mb_substr($title, 0, 200), $body !== null ? mb_substr($body, 0, 1000) : null, $link, $projectId]);
    } catch (Throwable $e) {}
}

/** Thư mục ảnh/video tải lên để đăng bài (được web server phục vụ tĩnh tại /uploads/). */
const UPLOAD_DIR = __DIR__ . '/../uploads';
const UPLOAD_EXT = ['jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image', 'gif' => 'image', 'mp4' => 'video', 'mov' => 'video'];

/** Kênh theo id. Trong request có người dùng, chỉ trả kênh thuộc dự án hiện hành (chống IDOR);
 *  job nền (CLI) và thư viện nội bộ gọi với $anyProject=true. */
function get_connection(string $id, bool $anyProject = false): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM connections WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch() ?: null;
    if ($r && !$anyProject && PHP_SAPI !== 'cli' && current_user() && ($r['project_id'] ?? null) !== (current_project()['id'] ?? null)) return null;
    return $r;
}

function graph_url(array $CFG, string $path, array $q = []): string {
    return 'https://graph.facebook.com/' . $CFG['meta_graph_version'] . '/' . ltrim($path, '/') . ($q ? '?' . http_build_query($q) : '');
}

/** Access token Google còn hạn cho một kênh; tự làm mới bằng refresh_token khi sắp hết hạn. */
function google_access_token(array $CFG, array $conn): ?string {
    global $pdo;
    if ($conn['expires_at'] && strtotime($conn['expires_at']) > time() + 60) return $conn['access_token'];
    if (!$conn['refresh_token']) return $conn['access_token'];
    [$code, $tok] = http_post_form('https://oauth2.googleapis.com/token', [
        // Kênh YouTube nhập tay mang OAuth client riêng; kênh đăng nhập dùng client chung trong config.
        'client_id' => ($conn['client_id'] ?? '') ?: $CFG['google_client_id'],
        'client_secret' => ($conn['client_secret'] ?? '') ?: $CFG['google_client_secret'],
        'refresh_token' => $conn['refresh_token'],
        'grant_type' => 'refresh_token',
    ]);
    if ($code !== 200 || empty($tok['access_token'])) {
        // Kênh nhập tay (OAuth riêng) giữ trạng thái connected để sửa xong bấm Đồng bộ lại được; chỉ ghi lỗi.
        $pdo->prepare(($conn['auth_method'] ?? 'oauth') === 'manual' ? 'UPDATE connections SET last_error=? WHERE id=?'
            : "UPDATE connections SET status='error', last_error=? WHERE id=?")
            ->execute([$tok['error_description'] ?? 'Không làm mới được token Google — hãy kết nối lại', $conn['id']]);
        return null;
    }
    $pdo->prepare('UPDATE connections SET access_token=?, expires_at=? WHERE id=?')
        ->execute([$tok['access_token'], date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600)), $conn['id']]);
    return $tok['access_token'];
}

function tiktok_url(array $CFG, string $path): string {
    return rtrim($CFG['tiktok_api_base'], '/') . '/' . ltrim($path, '/');
}

/* ---- TikTok: MỘT app TikTok Developer dùng chung cho mọi dự án / nhiều tài khoản (11/10/2026) ----
   Khoá app: ưu tiên app_settings (nhập trên giao diện, quản trị) rồi tới config.local.php.
   Hai môi trường tách riêng: sandbox (tiktok.sandbox_client_key/secret) và production (tiktok.client_key/secret).
   Môi trường đang dùng: tiktok.env (sandbox|production); không đặt thì suy ra từ tiền tố khoá (sbaw… = sandbox). */
const TIKTOK_DEFAULT_SCOPES = 'user.info.basic,video.list,video.upload,video.publish';
function tiktok_app(array $CFG, ?string $projectId = null): array {
    global $pdo;
    $g = fn(string $k, string $cfgKey) => (string)(setting_get($k) ?: ($CFG[$cfgKey] ?? ''));
    // Sandbox RIÊNG của dự án (projects.settings.tiktok_sandbox: key + secret mã hoá) — mỗi sandbox TikTok có key riêng,
    // tài khoản Target User chỉ đăng nhập được với key của đúng sandbox đó (lỗi non_sandbox_target nếu sai).
    $pj = null;
    if ($projectId) { $st = $pdo->prepare('SELECT settings FROM projects WHERE id=?'); $st->execute([$projectId]); $pj = jdecode($st->fetchColumn() ?: null)['tiktok_sandbox'] ?? null; }
    if (is_array($pj) && !empty($pj['key']) && !empty($pj['secret'])) {
        $sec = tok_dec($CFG, (string)$pj['secret']);
        if ($sec) {
            $scopes = (string)(setting_get('tiktok.scopes') ?: ($CFG['tiktok_scopes'] ?? TIKTOK_DEFAULT_SCOPES));
            return ['env' => 'sandbox', 'client_key' => (string)$pj['key'], 'client_secret' => $sec, 'configured' => true, 'source' => 'du_an', 'source_label' => 'Sandbox riêng của dự án' . (!empty($pj['label']) ? ' (' . $pj['label'] . ')' : ''),
                'key_last4' => substr((string)$pj['key'], -4), 'has_sandbox' => true, 'has_production' => false,
                'scopes' => array_values(array_unique(array_filter(array_map('trim', explode(',', $scopes))))), 'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_tiktok_callback.php'];
        }
    }
    $prod = ['key' => $g('tiktok.client_key', 'tiktok_client_key'), 'secret' => $g('tiktok.client_secret', 'tiktok_client_secret')];
    $sand = ['key' => $g('tiktok.sandbox_client_key', 'tiktok_sandbox_client_key'), 'secret' => $g('tiktok.sandbox_client_secret', 'tiktok_sandbox_client_secret')];
    // Khoá sandbox dán nhầm vào ô production (tiền tố sbaw) → coi là sandbox.
    if ($sand['key'] === '' && str_starts_with($prod['key'], 'sbaw')) { $sand = $prod; $prod = ['key' => '', 'secret' => '']; }
    $env = (string)(setting_get('tiktok.env') ?: ($CFG['tiktok_env'] ?? ''));
    if (!in_array($env, ['sandbox', 'production'], true)) $env = ($prod['key'] !== '' && $sand['key'] === '') ? 'production' : 'sandbox';
    $use = $env === 'production' ? $prod : $sand;
    $scopes = (string)(setting_get('tiktok.scopes') ?: ($CFG['tiktok_scopes'] ?? TIKTOK_DEFAULT_SCOPES));
    return ['env' => $env, 'client_key' => $use['key'], 'client_secret' => $use['secret'], 'configured' => $use['key'] !== '' && $use['secret'] !== '', 'source' => 'chung', 'source_label' => 'App chung (' . $env . ')', 'key_last4' => $use['key'] !== '' ? substr($use['key'], -4) : '',
        'has_sandbox' => $sand['key'] !== '' && $sand['secret'] !== '', 'has_production' => $prod['key'] !== '' && $prod['secret'] !== '',
        'scopes' => array_values(array_unique(array_filter(array_map('trim', explode(',', $scopes))))),
        'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_tiktok_callback.php'];
}

/* ---- Mã hoá token lưu CSDL (libsodium secretbox, khoá 32 byte hex trong config.local.php 'token_key') ----
   Giá trị mã hoá có tiền tố 'enc1:'; giá trị cũ không tiền tố vẫn đọc được. Không có token_key → lưu rõ và ghi cảnh báo. */
function tok_key(array $CFG): ?string {
    $h = (string)($CFG['token_key'] ?? '');
    return (strlen($h) === 64 && ctype_xdigit($h) && function_exists('sodium_crypto_secretbox')) ? hex2bin($h) : null;
}
function tok_enc(array $CFG, ?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $k = tok_key($CFG);
    if (!$k) { log_activity('warn', 'security', null, 'Chưa có token_key trong config.local.php — token lưu rõ'); return $plain; }
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc1:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, $k));
}
function tok_dec(array $CFG, ?string $stored): ?string {
    if ($stored === null || !str_starts_with($stored, 'enc1:')) return $stored;
    $k = tok_key($CFG); if (!$k) return null;
    $raw = base64_decode(substr($stored, 5), true); if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $k);
    return $p === false ? null : $p;
}

/** Thông điệp lỗi trong phản hồi TikTok, hoặc null nếu thành công. TikTok báo lỗi theo hai kiểu:
 *  API dữ liệu: {"error":{"code":"…","message":"…"}} (code 'ok' = thành công); OAuth: {"error":"…","error_description":"…"}. */
function tiktok_error(int $http, ?array $d): ?string {
    if ($d === null) return $http === 0 ? 'không kết nối được tới TikTok' : "HTTP $http";
    $e = $d['error'] ?? null;
    if (is_string($e) && $e !== '') return (string)(($d['error_description'] ?? '') ?: $e);
    if (is_array($e) && ($e['code'] ?? 'ok') !== 'ok') return (string)(($e['message'] ?? '') ?: $e['code']);
    return $http >= 400 ? "HTTP $http" : null;
}

/** Access token TikTok còn hạn cho một kênh. Token chỉ sống 24 giờ nên gần như mỗi lần đồng bộ hằng ngày đều phải làm mới;
 *  TikTok có thể trả refresh_token mới (hạn 365 ngày) — luôn lưu lại giá trị mới nhất. Trả về [token|null, lỗi|null]. */
function tiktok_access_token(array $CFG, array $conn): array {
    global $pdo;
    $acc = tok_dec($CFG, $conn['access_token']); $rt = tok_dec($CFG, $conn['refresh_token']);
    if ($conn['expires_at'] && strtotime($conn['expires_at']) > time() + 60 && $acc) return [$acc, null];
    if (!$rt) return $acc ? [$acc, null] : [null, 'Tài khoản TikTok chưa có token — hãy kết nối lại'];
    // Làm mới bằng đúng app/môi trường đã dùng khi kết nối (sandbox hay production) — tài khoản ghi ở cột env.
    $app = tiktok_app($CFG, $conn['project_id'] ?? null);
    if (($app['source'] ?? '') !== 'du_an' && !empty($conn['env']) && $conn['env'] !== $app['env']) {
        $k = $conn['env'] === 'production' ? ['tiktok.client_key', 'tiktok_client_key', 'tiktok.client_secret', 'tiktok_client_secret'] : ['tiktok.sandbox_client_key', 'tiktok_sandbox_client_key', 'tiktok.sandbox_client_secret', 'tiktok_sandbox_client_secret'];
        $app = ['client_key' => (string)(setting_get($k[0]) ?: ($CFG[$k[1]] ?? '')), 'client_secret' => (string)(setting_get($k[2]) ?: ($CFG[$k[3]] ?? ''))];
    }
    [$code, $tok] = http_post_form(tiktok_url($CFG, '/v2/oauth/token/'), [
        'client_key' => $app['client_key'],
        'client_secret' => $app['client_secret'],
        'grant_type' => 'refresh_token',
        'refresh_token' => $rt,
    ]);
    $err = tiktok_error($code, $tok);
    if ($err !== null || empty($tok['access_token'])) {
        // Hai tiến trình cùng làm mới (người dùng bấm Đồng bộ đúng lúc cron chạy): bên thua bị báo token cũ vô hiệu,
        // nhưng bên thắng đã lưu token mới — dùng lại token đó thay vì báo lỗi oan.
        $now = get_connection($conn['id'], true);
        if ($now && $now['refresh_token'] !== $conn['refresh_token'] && $now['access_token']
            && $now['expires_at'] && strtotime($now['expires_at']) > time() + 60) return [tok_dec($CFG, $now['access_token']), null];
        if ($code === 0) return [null, 'TikTok: ' . ($err ?? 'không kết nối được')];
        $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")
            ->execute(['TikTok từ chối làm mới token (' . ($err ?? 'không rõ lý do') . ') — hãy kết nối lại', $conn['id']]);
        return [null, 'TikTok từ chối làm mới token (' . ($err ?? 'không rõ lý do') . ') — hãy kết nối lại tài khoản'];
    }
    $pdo->prepare('UPDATE connections SET access_token=?, refresh_token=COALESCE(?, refresh_token), expires_at=?, last_error=NULL WHERE id=?')
        ->execute([tok_enc($CFG, $tok['access_token']), isset($tok['refresh_token']) ? tok_enc($CFG, $tok['refresh_token']) : null, date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 86400)), $conn['id']]);
    return [$tok['access_token'], null];
}

/* ---- X (Twitter): MỘT app X Developer dùng chung cho mọi dự án / nhiều tài khoản (12/10/2026) ----
   Khoá app: app_settings x.client_id / x.client_secret (quản trị nhập trên giao diện), dự phòng config x_client_id / x_client_secret.
   OAuth 2.0 Authorization Code + PKCE (S256); client confidential xác thực token endpoint bằng header Basic base64(client_id:client_secret).
   Access token sống ~2 giờ; refresh token XOAY VÒNG (mỗi lần làm mới trả refresh_token mới, cái cũ vô hiệu) → luôn lưu cái mới. */
const X_API_BASE = 'https://api.x.com';
const X_AUTH_URL = 'https://x.com/i/oauth2/authorize';
function x_app(array $CFG): array {
    $g = fn(string $k, string $cfgKey) => trim((string)(setting_get($k) ?: ($CFG[$cfgKey] ?? '')));
    $id = $g('x.client_id', 'x_client_id'); $sec = $g('x.client_secret', 'x_client_secret');
    return ['configured' => $id !== '' && $sec !== '', 'client_id' => $id, 'client_secret' => $sec,
        'key_last4' => $id !== '' ? substr($id, -4) : '',
        'redirect_uri' => rtrim($CFG['app_url'], '/') . '/api/oauth_x_callback.php',
        'scopes' => array_values(array_filter(explode(' ', PROVIDERS['x']['scope'])))];
}

/** Header xác thực client confidential cho endpoint token/revoke của X. */
function x_basic_header(array $app): string {
    return 'Authorization: Basic ' . base64_encode($app['client_id'] . ':' . $app['client_secret']);
}

/** Thông điệp lỗi trong phản hồi X, hoặc null nếu thành công.
 *  OAuth: {"error":"…","error_description":"…"}; API v2: {"title":"…","detail":"…","status":403} hoặc {"errors":[{"message":"…"}]}. */
function x_error(int $http, ?array $d): ?string {
    if ($d === null) return $http === 0 ? 'không kết nối được tới X' : "HTTP $http";
    if (!empty($d['error']) && is_string($d['error'])) return (string)(($d['error_description'] ?? '') ?: $d['error']);
    if (!empty($d['detail']) || !empty($d['title'])) return trim((string)($d['detail'] ?? '') ?: (string)$d['title']);
    if (!empty($d['errors']) && is_array($d['errors'])) {
        $m = $d['errors'][0]['message'] ?? $d['errors'][0]['detail'] ?? $d['errors'][0]['title'] ?? null;
        if ($m) return (string)$m;
    }
    return $http >= 400 ? "HTTP $http" : null;
}

/** Access token X còn hạn cho một kênh; tự làm mới khi còn < 5 phút. Trả về [token|null, lỗi|null]. */
function x_access_token(array $CFG, array $conn): array {
    global $pdo;
    $acc = tok_dec($CFG, $conn['access_token']); $rt = tok_dec($CFG, $conn['refresh_token']);
    if ($conn['expires_at'] && strtotime($conn['expires_at']) > time() + 300 && $acc) return [$acc, null];
    if (!$rt) return $acc ? [$acc, null] : [null, 'Tài khoản X chưa có token — hãy kết nối lại'];
    $app = x_app($CFG);
    if (!$app['configured']) return [null, 'Chưa có Client ID / Client Secret của app X — quản trị nhập ở Kênh marketing → X'];
    [$code, $tok] = http_post_form(X_API_BASE . '/2/oauth2/token', [
        'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $app['client_id'],
    ], [x_basic_header($app)]);
    $err = x_error($code, $tok);
    if ($err !== null || empty($tok['access_token'])) {
        // Hai tiến trình cùng làm mới (refresh token chỉ dùng được một lần): bên thua dùng lại token bên thắng vừa lưu.
        $now = get_connection($conn['id'], true);
        if ($now && $now['refresh_token'] !== $conn['refresh_token'] && $now['access_token']
            && $now['expires_at'] && strtotime($now['expires_at']) > time() + 60) return [tok_dec($CFG, $now['access_token']), null];
        if ($code === 0) return [null, 'X: ' . ($err ?? 'không kết nối được')];
        $pdo->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")
            ->execute(['X từ chối làm mới token (' . ($err ?? 'không rõ lý do') . ') — hãy kết nối lại', $conn['id']]);
        return [null, 'X từ chối làm mới token (' . ($err ?? 'không rõ lý do') . ') — hãy kết nối lại tài khoản'];
    }
    // Refresh token xoay vòng: X trả cái mới → phải lưu, cái cũ đã vô hiệu.
    $pdo->prepare('UPDATE connections SET access_token=?, refresh_token=COALESCE(?, refresh_token), expires_at=?, last_error=NULL WHERE id=?')
        ->execute([tok_enc($CFG, $tok['access_token']), !empty($tok['refresh_token']) ? tok_enc($CFG, $tok['refresh_token']) : null,
            date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 7200)), $conn['id']]);
    return [$tok['access_token'], null];
}

/** Access token từ file JSON service account (GA4 nhập thủ công) — ký JWT RS256 rồi đổi lấy token. */
function google_sa_token(string $json, string $scope): array {
    $sa = json_decode($json, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        return [null, 'File JSON service account không hợp lệ (thiếu client_email / private_key)'];
    }
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => $scope, 'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now, 'exp' => $now + 3600,
    ]));
    $key = openssl_pkey_get_private($sa['private_key']);
    if (!$key || !openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) return [null, 'Không đọc được private_key trong file JSON'];
    [$code, $tok] = http_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $unsigned . '.' . $b64($sig),
    ]);
    if ($code !== 200 || empty($tok['access_token'])) return [null, 'Google từ chối service account: ' . ($tok['error_description'] ?? $tok['error'] ?? "HTTP $code")];
    return [$tok['access_token'], null];
}

function mask_secret(?string $s): string {
    if (!$s) return '';
    $d = json_decode($s, true);
    if (is_array($d) && !empty($d['client_email'])) return $d['client_email'];
    return strlen($s) > 10 ? substr($s, 0, 4) . '…' . substr($s, -4) : '••••';
}

function public_connection(array $r): array {
    return [
        'id' => $r['id'], 'provider' => $r['provider'], 'label' => $r['label'], 'status' => $r['status'],
        'auth_method' => $r['auth_method'] ?? 'oauth', 'secret_hint' => mask_secret($r['secret'] ?? null),
        'client_id_hint' => mask_secret($r['client_id'] ?? null),
        'has_refresh' => ($r['auth_method'] ?? 'oauth') === 'manual' && !empty($r['refresh_token']),
        'external_account_id' => $r['external_account_id'], 'last_error' => $r['last_error'],
        'metrics' => jdecode($r['last_metrics'], []), 'connected_at' => $r['connected_at'],
        'last_synced_at' => $r['last_synced_at'],
        // 11/10/2026: tài khoản nhiều môi trường (TikTok sandbox/production), quyền đã cấp, hạn token, tên hiển thị
        'env' => $r['env'] ?? null, 'scopes' => array_values(array_filter(explode(',', (string)($r['scopes'] ?? '')))),
        'token_until' => $r['expires_at'] ?? null, 'account_name' => $r['account_name'] ?? null, 'avatar' => $r['avatar'] ?? null,
        'project_id' => $r['project_id'] ?? null,
    ];
}
