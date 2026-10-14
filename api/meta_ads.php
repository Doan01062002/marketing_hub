<?php
/* Meta Ads — kết nối bằng một token (System User hoặc người dùng có quyền ads_read), app tự tìm tài khoản quảng cáo + Pixel. 11/10/2026
   GET                                   → {accounts:[kết nối meta_ads của dự án], app_configured, pixel_id (đã chọn cho dự án)}
   POST {action:'lookup', token}         → hỏi Graph API thật: ai đang đăng nhập, quyền, danh sách tài khoản quảng cáo + Pixel của từng tài khoản (không lưu gì)
   POST {action:'save', token, account_id, label?, pixel_id?} → tạo/cập nhật kết nối meta_ads (nhập tay, token mã hoá), lưu Pixel vào dự án, đồng bộ ngay
   Token không bao giờ trả về trình duyệt; lỗi Graph trả nguyên văn để người dùng biết thiếu quyền gì. */
require __DIR__ . '/_boot.php';
$me = require_login();
$PID = pid();

function ma_graph(array $CFG, string $path, array $q): array { return http_get_json(graph_url($CFG, $path, $q)); }
function ma_err(int $c, $d, string $what): ?string {
    if ($c === 200 && is_array($d) && !isset($d['error'])) return null;
    $m = is_array($d) ? ($d['error']['message'] ?? null) : null;
    return $what . ': ' . ($m ?: "HTTP $c");
}
function ma_pixel_id(string $pid): string { global $pdo; $st = $pdo->prepare('SELECT settings FROM projects WHERE id=?'); $st->execute([$pid]); $s = jdecode($st->fetchColumn() ?: null); return (string)($s['tracking']['meta_pixel_id'] ?? ''); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare("SELECT * FROM connections WHERE project_id=? AND provider='meta_ads' ORDER BY created_at"); $st->execute([$PID]);
    respond(['accounts' => array_map('public_connection', $st->fetchAll()), 'app_configured' => (bool)($CFG['meta_app_id'] && $CFG['meta_app_secret']),
        'pixel_id' => ma_pixel_id($PID), 'can_manage' => in_array(project_role(), PROJECT_ADMIN_ROLES, true), 'token_encryption' => (bool)tok_key($CFG)]);
}

require_method('POST');
require_project_admin();
$in = json_input();
$action = (string)($in['action'] ?? '');
$token = preg_replace('/\s+/', '', (string)($in['token'] ?? ''));

if ($action === 'lookup') {
    if (strlen($token) < 20) fail('Dán Access Token (System User hoặc người dùng) có quyền ads_read');
    [$c, $who] = ma_graph($CFG, 'me', ['fields' => 'id,name', 'access_token' => $token]);
    if ($e = ma_err($c, $who, 'Meta không nhận token')) fail($e, 422);
    $perms = [];
    [$c, $pm] = ma_graph($CFG, 'me/permissions', ['access_token' => $token]);
    if ($c === 200) foreach (($pm['data'] ?? []) as $x) if (($x['status'] ?? '') === 'granted') $perms[] = (string)$x['permission'];
    [$c, $acc] = ma_graph($CFG, 'me/adaccounts', ['fields' => 'id,account_id,name,account_status,currency,business_name,amount_spent,timezone_name', 'limit' => 50, 'access_token' => $token]);
    if ($e = ma_err($c, $acc, 'Không đọc được danh sách tài khoản quảng cáo')) fail($e . ' — token cần quyền ads_read và được gán tài khoản quảng cáo', 422);
    $list = [];
    foreach (($acc['data'] ?? []) as $a) {
        $row = ['id' => (string)$a['id'], 'account_id' => (string)($a['account_id'] ?? ''), 'name' => (string)($a['name'] ?? ''), 'status' => (int)($a['account_status'] ?? 0),
            'currency' => (string)($a['currency'] ?? ''), 'business' => (string)($a['business_name'] ?? ''), 'spent' => (float)($a['amount_spent'] ?? 0) / 100, 'pixels' => []];
        if (count($list) < 10) { // Pixel của từng tài khoản (để chọn cho website)
            [$c2, $px] = ma_graph($CFG, rawurlencode($row['id']) . '/adspixels', ['fields' => 'id,name,last_fired_time', 'access_token' => $token]);
            if ($c2 === 200) foreach (($px['data'] ?? []) as $p) $row['pixels'][] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? ''), 'last_fired' => $p['last_fired_time'] ?? null];
        }
        $list[] = $row;
    }
    // Trang (Fanpage) mà token thấy — để báo tài khoản có gắn Page chưa (không bắt buộc)
    $pages = [];
    [$c, $pg] = ma_graph($CFG, 'me/accounts', ['fields' => 'id,name', 'limit' => 20, 'access_token' => $token]);
    if ($c === 200) foreach (($pg['data'] ?? []) as $p) $pages[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? '')];
    log_activity('read', 'connection', null, 'Tra cứu tài khoản Meta Ads bằng token (' . count($list) . ' tài khoản)', $me, $PID);
    respond(['who' => ['id' => (string)($who['id'] ?? ''), 'name' => (string)($who['name'] ?? '')], 'perms' => $perms, 'accounts' => $list, 'pages' => $pages,
        'note' => $list ? null : 'Token hợp lệ nhưng không thấy tài khoản quảng cáo nào — vào Business Settings → Người dùng hệ thống → Gán tài sản → thêm Tài khoản quảng cáo (quyền Xem hiệu suất) rồi tạo token lại.']);
}

if ($action === 'save') {
    $accountId = preg_replace('/\s+/', '', (string)($in['account_id'] ?? ''));
    if (ctype_digit($accountId)) $accountId = 'act_' . $accountId;
    if (!preg_match('/^act_\d+$/', $accountId)) fail('Chọn tài khoản quảng cáo (act_…)');
    if (strlen($token) < 20) fail('Thiếu token');
    [$c, $a] = ma_graph($CFG, rawurlencode($accountId), ['fields' => 'id,name,account_status,currency', 'access_token' => $token]);
    if ($e = ma_err($c, $a, 'Không truy cập được ' . $accountId)) fail($e, 422);
    $label = trim((string)($in['label'] ?? '')) ?: ('Meta Ads ' . ((string)($a['name'] ?? $accountId)));
    $enc = tok_enc($CFG, $token);
    $st = $pdo->prepare("SELECT id FROM connections WHERE project_id=? AND provider='meta_ads' AND external_account_id=?"); $st->execute([$PID, $accountId]);
    $id = (string)($st->fetchColumn() ?: '');
    if ($id === '') {
        $id = gen_id('CH');
        $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,secret,external_account_id,account_name,connected_by,connected_at) VALUES (?,?,'meta_ads',?,'connected','manual',?,?,?,?,datetime('now'))")
            ->execute([$id, $PID, $label, $enc, $accountId, (string)($a['name'] ?? ''), $me['id']]);
    } else {
        $pdo->prepare("UPDATE connections SET label=?, secret=?, status='connected', auth_method='manual', last_error=NULL, account_name=?, connected_by=?, connected_at=datetime('now') WHERE id=?")
            ->execute([$label, $enc, (string)($a['name'] ?? ''), $me['id'], $id]);
    }
    // Pixel chọn cho website của dự án (dùng khi cài mã đo / Conversions API)
    $pixel = preg_replace('/\D/', '', (string)($in['pixel_id'] ?? ''));
    if ($pixel !== '') {
        $st = $pdo->prepare('SELECT settings FROM projects WHERE id=?'); $st->execute([$PID]); $sj = jdecode($st->fetchColumn() ?: null);
        $sj['tracking'] = array_merge((array)($sj['tracking'] ?? []), ['meta_pixel_id' => $pixel, 'meta_ad_account' => $accountId, 'updated_at' => date('Y-m-d H:i:s')]);
        $pdo->prepare("UPDATE projects SET settings=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode($sj, JSON_UNESCAPED_UNICODE), $PID]);
    }
    log_activity('create', 'connection', $id, 'Kết nối Meta Ads ' . $accountId . ' (' . ($a['name'] ?? '') . ')' . ($pixel ? ' · Pixel ' . $pixel : ''), $me, $PID);
    $sync = null; $syncErr = null;
    try { require_once __DIR__ . '/_metrics_lib.php'; $sync = sync_connection($CFG, get_connection($id, true)); } catch (Throwable $e) { $syncErr = $e->getMessage(); }
    respond(['id' => $id, 'account' => public_connection(get_connection($id, true)), 'pixel_id' => $pixel ?: ma_pixel_id($PID), 'sync' => $sync, 'sync_error' => $syncErr], 201);
}

fail('Thao tác không hỗ trợ');
