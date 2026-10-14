<?php
/**
 * Thiết lập > AI & khoá API.
 *
 * GET  /api/settings_ai.php                — mọi người đăng nhập: trạng thái; quản trị thấy thêm khoá đã che.
 * POST /api/settings_ai.php                — chỉ quản trị: lưu
 *      { provider?:'auto'|'anthropic'|'openai', preference?:'anthropic'|'openai',
 *        anthropic_key?, openai_key? (để trống = giữ khoá cũ), clear_anthropic_key?, clear_openai_key? (true = xoá),
 *        anthropic_model?, openai_model? (rỗng = dùng mặc định) }
 * POST /api/settings_ai.php?action=test    — chỉ quản trị: { provider } → gọi thử thật, báo ok/lỗi.
 *
 * Khoá API không bao giờ được trả về trình duyệt (chỉ dạng che sk-a…1234).
 */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_ai_lib.php';

function sai_status(bool $admin): array {
    $prov = ai_setting('ai.provider', 'auto');
    $out = [
        'can_edit' => $admin,
        'provider' => in_array($prov, ['auto', 'anthropic', 'openai'], true) ? $prov : 'auto',
        'preference' => ai_setting('ai.provider_preference', 'anthropic') === 'openai' ? 'openai' : 'anthropic',
        'active' => ai_available(),
    ];
    foreach (AI_PROVIDERS as $p) {
        [$k, $src] = ai_key($p);
        $row = ['name' => AI_PROVIDER_NAMES[$p], 'has_key' => (bool)$k, 'source' => $src,
            'model' => ai_model($p), 'default_model' => ai_default_model($p), 'custom_model' => ai_setting("ai.{$p}_model") !== null];
        if ($admin) {
            $row['key_hint'] = $k ? mask_secret($k) : '';
            $row['updated_at'] = null;
            try {
                $st = $GLOBALS['pdo']->prepare('SELECT s.updated_at, u.name FROM app_settings s LEFT JOIN users u ON u.id=s.updated_by WHERE s.key=?');
                $st->execute(["ai.{$p}_key"]);
                if ($r = $st->fetch()) { $row['updated_at'] = $r['updated_at']; $row['updated_by'] = $r['name']; }
            } catch (Throwable $e) {}
        }
        $out[$p] = $row;
    }
    return $out;
}

function sai_delete(string $key): void {
    global $pdo;
    $pdo->prepare('DELETE FROM app_settings WHERE key=?')->execute([$key]);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $u = require_login();
    respond(sai_status($u['role'] === 'admin'));
}
require_method('POST');
$u = require_role('admin');
$in = json_input();
$action = (string)($_GET['action'] ?? $in['action'] ?? 'save');

if ($action === 'test') {
    $p = (string)($in['provider'] ?? '');
    if (!in_array($p, AI_PROVIDERS, true)) fail('Chọn nhà cung cấp cần kiểm tra (anthropic hoặc openai)');
    [$k] = ai_key($p);
    if (!$k) respond(['ok' => false, 'provider' => $p, 'code' => 'no_api_key', 'error' => 'Chưa có khoá API cho ' . AI_PROVIDER_NAMES[$p] . ' — dán khoá rồi bấm Lưu trước khi kiểm tra.']);
    session_write_close();
    @set_time_limit(90);
    $r = ai_test($p);
    log_activity('sync', 'settings', 'ai.' . $p, 'Kiểm tra kết nối ' . AI_PROVIDER_NAMES[$p] . ': ' . ($r['ok'] ? 'thành công' : ($r['code'] ?? 'lỗi')), $u);
    respond($r); // luôn 200: kết quả kiểm tra nằm trong ok/error
}

if ($action !== 'save') fail('Thao tác không hợp lệ');

$changed = [];
$pdo->beginTransaction(); // lỗi kiểm tra giữa chừng (fail) → không lưu dở dang
if (array_key_exists('provider', $in)) {
    $v = (string)$in['provider'];
    if (!in_array($v, ['auto', 'anthropic', 'openai'], true)) fail('Nhà cung cấp AI không hợp lệ');
    if ($v !== ai_setting('ai.provider', 'auto')) { setting_set('ai.provider', $v, $u); $changed[] = 'nhà cung cấp=' . $v; }
}
if (array_key_exists('preference', $in)) {
    $v = (string)$in['preference'];
    if (!in_array($v, AI_PROVIDERS, true)) fail('Thứ tự ưu tiên không hợp lệ');
    if ($v !== ai_setting('ai.provider_preference', 'anthropic')) { setting_set('ai.provider_preference', $v, $u); $changed[] = 'ưu tiên=' . $v; }
}
foreach (AI_PROVIDERS as $p) {
    $label = $p === 'openai' ? 'ChatGPT' : 'Claude';
    if (!empty($in["clear_{$p}_key"])) {
        if (ai_setting("ai.{$p}_key") !== null) { sai_delete("ai.{$p}_key"); $changed[] = "xoá khoá $label"; }
    } else {
        $key = preg_replace('/\s+/', '', (string)($in["{$p}_key"] ?? ''));
        if ($key !== '') {
            if (strlen($key) < 20 || strlen($key) > 400 || !preg_match('/^[\x21-\x7e]+$/', $key)) fail("Khoá $label không đúng định dạng (dán nguyên chuỗi khoá, không có dấu cách)");
            if ($p === 'anthropic' && str_starts_with($key, 'sk-') && !str_starts_with($key, 'sk-ant-')) fail('Đây có vẻ là khoá OpenAI — khoá Claude bắt đầu bằng "sk-ant-". Hãy dán vào ô ChatGPT.');
            if ($p === 'openai' && str_starts_with($key, 'sk-ant-')) fail('Đây là khoá Anthropic (Claude) — hãy dán vào ô Claude.');
            setting_set("ai.{$p}_key", $key, $u);
            $changed[] = "khoá $label mới (" . mask_secret($key) . ')';
        }
    }
    if (array_key_exists("{$p}_model", $in)) {
        $m = trim((string)$in["{$p}_model"]);
        if ($m === '' || $m === ai_default_model($p)) {
            if (ai_setting("ai.{$p}_model") !== null) { sai_delete("ai.{$p}_model"); $changed[] = "model $label=mặc định"; }
        } else {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{1,79}$/', $m)) fail("Tên model $label không hợp lệ");
            if ($m !== ai_setting("ai.{$p}_model")) { setting_set("ai.{$p}_model", $m, $u); $changed[] = "model $label=$m"; }
        }
    }
}
$pdo->commit();
if ($changed) log_activity('update', 'settings', 'ai', 'AI & khoá API: ' . implode(', ', $changed), $u);
respond(['ok' => true, 'changed' => count($changed)] + sai_status(true));
