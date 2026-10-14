<?php
/* Robot duyệt web.
   GET                       → {jobs[], kinds, schedules, chrome, serpapi}
   GET ?id=…&history=1       → {job, runs[]} (60 lần chạy gần nhất)
   POST {action:'save', id?, kind, name, schedule, enabled, config}
   POST {action:'run', id}   → chạy ngay
   POST {action:'delete', id}
   POST {action:'suggest'}   → từ khoá gợi ý từ Search Console để theo dõi thứ hạng */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_bot_lib.php';
$user = require_login();
bot_tables();
$method = $_SERVER['REQUEST_METHOD'];

function bot_row(array $j): array {
    return ['id' => $j['id'], 'kind' => $j['kind'], 'name' => $j['name'], 'config' => jdecode($j['config']), 'schedule' => $j['schedule'],
        'enabled' => (bool)$j['enabled'], 'last_run_at' => $j['last_run_at'], 'last_status' => $j['last_status'], 'last' => jdecode($j['last_result'])];
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM bot_jobs WHERE id=? AND project_id=?'); $st->execute([$_GET['id'], pid()]);
        $j = $st->fetch(); if (!$j) fail('Không tìm thấy việc', 404);
        $r = $pdo->prepare('SELECT run_at,status,result FROM bot_runs WHERE job_id=? ORDER BY id DESC LIMIT 60'); $r->execute([$j['id']]);
        respond(['job' => bot_row($j), 'runs' => array_map(fn($x) => ['at' => $x['run_at'], 'status' => $x['status'], 'result' => jdecode($x['result'])], $r->fetchAll())]);
    }
    $stJ = $pdo->prepare('SELECT * FROM bot_jobs WHERE project_id=? ORDER BY created_at'); $stJ->execute([pid()]);
    respond(['jobs' => array_map('bot_row', $stJ->fetchAll()),
        'kinds' => BOT_KINDS, 'schedules' => BOT_SCHEDULES, 'chrome' => (bool)bot_chrome(), 'serpapi' => (bool)setting_get('data.serpapi_key')]);
}

require_method('POST');
$in = json_input();
$a = (string)($in['action'] ?? '');

if ($a === 'suggest') {
    $kw = [];
    $stG = $pdo->prepare("SELECT label,external_account_id,last_metrics FROM connections WHERE project_id=? AND provider='gsc' AND status='connected'"); $stG->execute([pid()]);
    foreach ($stG->fetchAll() as $c) {
        foreach (jdecode($c['last_metrics'])['top'] ?? [] as $t) if (str_starts_with($t['label'], 'Từ khoá')) foreach ($t['rows'] as $r) $kw[$r[0]] = ($kw[$r[0]] ?? 0) + $r[2];
        $dom = preg_replace('#^(sc-domain:|https?://)#', '', rtrim($c['external_account_id'], '/'));
    }
    arsort($kw);
    respond(['keywords' => array_slice(array_keys($kw), 0, 15), 'domain' => $dom ?? '']);
}

require_project_admin();

if ($a === 'save') {
    $kind = (string)($in['kind'] ?? '');
    if (!isset(BOT_KINDS[$kind])) fail('Loại việc không hợp lệ');
    $name = trim((string)($in['name'] ?? '')) ?: BOT_KINDS[$kind];
    $sch = isset(BOT_SCHEDULES[$in['schedule'] ?? '']) ? $in['schedule'] : 'weekly';
    $c = (array)($in['config'] ?? []);
    $lines = fn($v) => array_values(array_unique(array_filter(array_map('trim', is_array($v) ? $v : preg_split('/[\r\n,]+/', (string)$v)))));
    if ($kind === 'rank') {
        $cfg = ['keywords' => array_slice($lines($c['keywords'] ?? ''), 0, BOT_MAX_KEYWORDS), 'domain' => preg_replace('#^(https?://)?(www\.)?#', '', rtrim(trim((string)($c['domain'] ?? '')), '/')),
            'location' => trim((string)($c['location'] ?? 'Hanoi,Hanoi,Vietnam')), 'depth' => max(1, min(3, (int)($c['depth'] ?? 1)))];
        if (!$cfg['keywords']) fail('Nhập ít nhất 1 từ khoá');
        if ($cfg['domain'] === '') fail('Nhập tên miền cần theo dõi');
    } else {
        $urls = array_map(fn($u) => preg_match('#^https?://#i', $u) ? $u : 'https://' . $u, $lines($c['urls'] ?? ''));
        foreach ($urls as $u) if (!filter_var($u, FILTER_VALIDATE_URL)) fail('Địa chỉ không hợp lệ: ' . $u);
        if (!$urls) fail('Nhập ít nhất 1 địa chỉ website');
        $cfg = ['urls' => array_slice($urls, 0, BOT_MAX_URLS), 'device' => in_array($c['device'] ?? '', ['desktop', 'mobile', 'both'], true) ? $c['device'] : 'both'];
    }
    $id = (string)($in['id'] ?? '');
    if ($id) { need('bot_jobs', $id, 'việc robot'); $pdo->prepare('UPDATE bot_jobs SET name=?, schedule=?, enabled=?, config=? WHERE id=? AND project_id=?')->execute([$name, $sch, empty($in['enabled']) ? 0 : 1, json_encode($cfg, JSON_UNESCAPED_UNICODE), $id, pid()]); }
    else { $id = gen_id('BOT'); $pdo->prepare('INSERT INTO bot_jobs(id,project_id,kind,name,config,schedule,enabled,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$id, pid(), $kind, $name, json_encode($cfg, JSON_UNESCAPED_UNICODE), $sch, 1, $user['id']]); }
    log_activity(!empty($in['id']) ? 'update' : 'create', 'bot', $id, 'Robot duyệt web: ' . $name, $user);
    respond(['id' => $id]);
}

$job = need('bot_jobs', (string)($in['id'] ?? ''), 'việc robot');

if ($a === 'run') {
    // Chạy nền: trả lời ngay (Cloudflare cắt kết nối sau 100 giây), rồi chạy tiếp trên máy chủ; giao diện tự tải lại khi xong.
    session_write_close(); @set_time_limit(900); ignore_user_abort(true);
    $pdo->prepare("UPDATE bot_jobs SET last_status='running' WHERE id=?")->execute([$job['id']]);
    log_activity('run', 'bot', $job['id'], 'Chạy robot: ' . $job['name'], $user);
    http_response_code(202);
    echo json_encode(['started' => true, 'prev_run_at' => $job['last_run_at']], JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }
    bot_run_job($job);
    exit;
}
if ($a === 'delete') {
    foreach ($pdo->query("SELECT result FROM bot_runs WHERE job_id=" . $pdo->quote($job['id']))->fetchAll() as $o) foreach (jdecode($o['result'])['shots'] ?? [] as $s) @unlink(UPLOAD_DIR . '/' . substr($s['img'], 8));
    $pdo->prepare('DELETE FROM bot_runs WHERE job_id=?')->execute([$job['id']]);
    $pdo->prepare('DELETE FROM bot_jobs WHERE id=?')->execute([$job['id']]);
    respond(['ok' => true]);
}
fail('action không hợp lệ');
