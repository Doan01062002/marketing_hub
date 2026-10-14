<?php
/* Kết nối Meta (đăng nhập Facebook) — trạng thái và đồng bộ quyền. 14/10/2026
   GET  ?conn_id=…                     → {app, perms:{granted,declined,missing}, pages:[{page_id,name,category,tasks,ig_username,has_token}], token_until}
   POST {action:'sync', conn_id}       → đồng bộ lại quyền + danh sách Fanpage (Page token mã hoá) bằng token đã lưu, không cần đăng nhập lại
   Không bao giờ trả token / App Secret về trình duyệt. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_meta_lib.php';
$me = require_login();

$app = ['configured' => (bool)($CFG['meta_app_id'] && $CFG['meta_app_secret']), 'source' => $CFG['meta_app_source'] ?: ($CFG['meta_app_id'] ? 'config' : ''),
    'app_id' => (string)$CFG['meta_app_id'], 'redirect_uri' => meta_redirect_uri($CFG), 'token_encryption' => (bool)tok_key($CFG)];

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_input() : [];
$conn = get_connection((string)($_GET['conn_id'] ?? $in['conn_id'] ?? ''));
if (!$conn) { if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['conn_id'])) respond(['app' => $app]); fail('Không tìm thấy kênh', 404); }
if ((PROVIDERS[$conn['provider']]['auth'] ?? '') !== 'meta' || ($conn['auth_method'] ?? 'oauth') !== 'oauth') fail('Kênh này không kết nối bằng đăng nhập Facebook');

$perms = fn(array $c) => ['granted' => array_values(array_filter(explode(',', (string)($c['scopes'] ?? '')))),
    'declined' => array_values(array_filter(explode(',', (string)($c['scopes_declined'] ?? '')))),
    'missing' => array_values(array_diff(meta_wanted_scopes($CFG, $c['provider']), array_filter(explode(',', (string)($c['scopes'] ?? '')))))];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['app' => $app, 'status' => $conn['status'], 'last_error' => $conn['last_error'], 'token_until' => $conn['expires_at'],
        'perms' => $perms($conn), 'pages' => $conn['provider'] === 'facebook_page' ? meta_pages_list($conn['id']) : null]);
}

require_method('POST');
require_project_admin();
if (($in['action'] ?? '') !== 'sync') fail('action không hợp lệ', 404);
session_write_close();
try {
    $r = meta_sync_account($CFG, $conn);
} catch (MetaAuthError $e) {
    fail($e->getMessage(), 401);
} catch (Throwable $e) {
    $pdo->prepare('UPDATE connections SET last_error=? WHERE id=?')->execute([$e->getMessage(), $conn['id']]);
    fail($e->getMessage(), 502);
}
log_activity('update', 'connection', $conn['id'], 'Đồng bộ quyền Facebook' . ($r['pages'] !== null ? ' (' . count($r['pages']) . ' Page)' : ''), $me);
respond(['ok' => true, 'perms' => $r['perms'], 'pages' => $r['pages'], 'warning' => $r['warning']]);
