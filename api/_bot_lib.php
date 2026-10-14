<?php
/* ROBOT DUYỆT WEB — việc tự động chạy theo lịch (cron/run.php) hoặc bấm tay (api/bot.php). Yêu cầu đã require _boot.php.
   - rank : kiểm tra thứ hạng Google của tên miền cho từng từ khoá (SerpApi — Google không cho cào trực tiếp) + ai đang đứng top 10.
   - shot : chụp màn hình website (máy tính / điện thoại) bằng Chromium chạy ngầm trên máy chủ, lưu lịch sử ảnh.
   Mỗi lần chạy lưu 1 dòng bot_runs (giữ 60 lần gần nhất / việc). */

const BOT_KINDS = ['rank' => 'Thứ hạng Google', 'shot' => 'Chụp màn hình website'];
const BOT_SCHEDULES = ['manual' => 'Chỉ chạy khi bấm', 'daily' => 'Hằng ngày (sau 07:00)', 'weekly' => 'Hằng tuần (thứ Hai, sau 07:00)'];
const BOT_MAX_KEYWORDS = 20;
const BOT_MAX_URLS = 10;

function bot_tables(): void {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_jobs (id TEXT PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL, config TEXT, schedule TEXT NOT NULL DEFAULT 'weekly',
        enabled INTEGER NOT NULL DEFAULT 1, last_run_at TEXT, last_status TEXT, last_result TEXT, created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id TEXT NOT NULL, run_at TEXT NOT NULL DEFAULT (datetime('now')), status TEXT, result TEXT)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bot_runs_job ON bot_runs(job_id, run_at)');
}

function bot_chrome(): ?string {
    foreach (glob('/opt/pw-browsers/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell') ?: [] as $p) if (is_executable($p)) return $p;
    foreach (['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'] as $p) if (is_executable($p)) return $p;
    return null;
}

/** Chụp 1 trang. Trả về đường dẫn tương đối trong uploads/ hoặc ném lỗi. */
function bot_screenshot(string $url, string $device, string $dest): string {
    $bin = bot_chrome();
    if (!$bin) throw new RuntimeException('Máy chủ chưa có Chromium chạy ngầm');
    [$w, $h, $ua] = $device === 'mobile'
        ? [412, 2400, 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36']
        : [1440, 2600, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'];
    @mkdir(dirname(UPLOAD_DIR . '/' . $dest), 0775, true);
    $out = UPLOAD_DIR . '/' . $dest;
    $tmp = sys_get_temp_dir() . '/mkt-bot-' . bin2hex(random_bytes(4));
    $cmd = 'timeout 60 ' . escapeshellarg($bin) . ' --no-sandbox --disable-gpu --disable-dev-shm-usage --hide-scrollbars --mute-audio'
        . ' --user-data-dir=' . escapeshellarg($tmp) . ' --window-size=' . $w . ',' . $h . ' --user-agent=' . escapeshellarg($ua)
        . ' --virtual-time-budget=6000 --screenshot=' . escapeshellarg($out) . ' ' . escapeshellarg($url) . ' 2>&1';
    exec($cmd, $o, $code);
    exec('rm -rf ' . escapeshellarg($tmp));
    if (!is_file($out) || filesize($out) < 2000) throw new RuntimeException('Không chụp được ' . $url . ' (mã ' . $code . ')');
    return 'uploads/' . $dest;
}

/** Kết quả Google cho 1 từ khoá qua SerpApi: vị trí của tên miền + top 10. */
function bot_serp(string $kw, string $domain, string $location, int $depth = 1): array {
    $key = setting_get('data.serpapi_key');
    if (!$key) throw new RuntimeException('Chưa có khoá SerpApi (Thiết lập → Nguồn dữ liệu thị trường)');
    $dom = preg_replace('/^www\./', '', strtolower($domain));
    $pos = null; $url = null; $top = []; $all = []; $first = null;
    // Google chỉ trả ~10 kết quả / trang: đọc thêm trang 2, 3 (mỗi trang = 1 lượt SerpApi) khi chưa thấy tên miền.
    for ($pg = 0; $pg < max(1, min(3, $depth)); $pg++) {
        $p = ['engine' => 'google', 'q' => $kw, 'google_domain' => 'google.com.vn', 'gl' => 'vn', 'hl' => 'vi', 'start' => $pg * 10, 'api_key' => $key];
        if ($location !== '') $p['location'] = $location;
        [$c, $d] = http_call('GET', 'https://serpapi.com/search.json?' . http_build_query($p));
        if ($c !== 200 || !empty($d['error'])) { if ($pg === 0) throw new RuntimeException('SerpApi: ' . ($d['error'] ?? "HTTP $c")); break; }
        if ($first === null) $first = $d;
        $found = false;
        foreach ($d['organic_results'] ?? [] as $r) {
            $r['position'] = count($all) + 1; $all[] = $r;
            $h = preg_replace('/^www\./', '', strtolower((string)parse_url($r['link'] ?? '', PHP_URL_HOST)));
            if ($h === $dom || str_ends_with($h, '.' . $dom)) $found = true;
        }
        if ($found || empty($d['serpapi_pagination']['next'])) break;
    }
    $d = $first;
    foreach ($all as $r) {
        $h = preg_replace('/^www\./', '', strtolower((string)parse_url($r['link'] ?? '', PHP_URL_HOST)));
        if ($pos === null && ($h === $dom || str_ends_with($h, '.' . $dom))) { $pos = (int)$r['position']; $url = $r['link']; }
        if (count($top) < 10) $top[] = ['pos' => (int)$r['position'], 'domain' => $h, 'title' => mb_substr((string)($r['title'] ?? ''), 0, 120), 'url' => $r['link'] ?? ''];
    }
    $feat = [];
    if (!empty($d['ads'])) $feat[] = count($d['ads']) . ' quảng cáo';
    if (!empty($d['local_results'])) $feat[] = 'Google Maps';
    if (!empty($d['related_questions'])) $feat[] = 'Câu hỏi liên quan';
    if (!empty($d['ai_overview'])) $feat[] = 'AI Overview';
    return ['kw' => $kw, 'pos' => $pos, 'url' => $url, 'top' => $top, 'features' => $feat, 'total' => count($all)];
}

function bot_run_job(array $job): array {
    global $pdo;
    $cfg = jdecode($job['config']);
    $status = 'ok'; $res = [];
    try {
        if ($job['kind'] === 'rank') {
            $rows = []; $errs = [];
            foreach (array_slice($cfg['keywords'] ?? [], 0, BOT_MAX_KEYWORDS) as $kw) {
                try { $rows[] = bot_serp($kw, (string)($cfg['domain'] ?? ''), (string)($cfg['location'] ?? ''), (int)($cfg['depth'] ?? 1)); }
                catch (Throwable $e) { $errs[] = "$kw: " . $e->getMessage(); if (str_contains($e->getMessage(), 'khoá SerpApi')) break; }
            }
            $found = array_filter($rows, fn($r) => $r['pos'] !== null);
            $doms = [];
            foreach ($rows as $r) foreach ($r['top'] as $t) $doms[$t['domain']] = ($doms[$t['domain']] ?? 0) + 1;
            arsort($doms);
            $res = ['rows' => $rows, 'errors' => $errs, 'depth' => (int)($cfg['depth'] ?? 1) * 10, 'found' => count($found), 'avg' => $found ? round(array_sum(array_column($found, 'pos')) / count($found), 1) : null,
                'top10' => count(array_filter($found, fn($r) => $r['pos'] <= 10)), 'competitors' => array_slice($doms, 0, 12, true)];
            if (!$rows && $errs) { $status = 'error'; $res['error'] = $errs[0]; }
            elseif ($errs) $status = 'partial';
        } elseif ($job['kind'] === 'shot') {
            $shots = []; $errs = [];
            $devs = ($cfg['device'] ?? 'both') === 'both' ? ['desktop', 'mobile'] : [$cfg['device']];
            foreach (array_slice($cfg['urls'] ?? [], 0, BOT_MAX_URLS) as $i => $u) foreach ($devs as $dv) {
                try {
                    $f = bot_screenshot($u, $dv, 'bot/' . $job['id'] . '/' . date('Ymd-His') . "-$i-$dv.png");
                    $shots[] = ['url' => $u, 'device' => $dv, 'img' => $f];
                } catch (Throwable $e) { $errs[] = $e->getMessage(); }
            }
            $res = ['shots' => $shots, 'errors' => $errs];
            if (!$shots && $errs) { $status = 'error'; $res['error'] = $errs[0]; } elseif ($errs) $status = 'partial';
        } else throw new RuntimeException('Loại việc không hỗ trợ');
    } catch (Throwable $e) { $status = 'error'; $res = ['error' => $e->getMessage()]; }
    $json = json_encode($res, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE bot_jobs SET last_run_at=datetime('now'), last_status=?, last_result=? WHERE id=?")->execute([$status, $json, $job['id']]);
    $pdo->prepare('INSERT INTO bot_runs(job_id,status,result) VALUES (?,?,?)')->execute([$job['id'], $status, $json]);
    // Giữ 60 lần chạy gần nhất; xoá ảnh của lần cũ hơn.
    $old = $pdo->prepare('SELECT id,result FROM bot_runs WHERE job_id=? ORDER BY id DESC LIMIT -1 OFFSET 60'); $old->execute([$job['id']]);
    foreach ($old->fetchAll() as $o) { foreach (jdecode($o['result'])['shots'] ?? [] as $s) @unlink(UPLOAD_DIR . '/' . substr($s['img'], 8)); $pdo->prepare('DELETE FROM bot_runs WHERE id=?')->execute([$o['id']]); }
    if ($status === 'error') notify(null, 'Robot duyệt web lỗi: ' . $job['name'], $res['error'] ?? '', 'robot');
    // Thứ hạng tụt mạnh so với lần trước → báo.
    if ($job['kind'] === 'rank' && $status !== 'error') {
        $prev = $pdo->prepare('SELECT result FROM bot_runs WHERE job_id=? ORDER BY id DESC LIMIT 1 OFFSET 1'); $prev->execute([$job['id']]);
        $pm = []; foreach (jdecode($prev->fetchColumn() ?: null)['rows'] ?? [] as $r) $pm[$r['kw']] = $r['pos'];
        $drops = [];
        foreach ($res['rows'] as $r) { $p0 = $pm[$r['kw']] ?? null; if ($p0 !== null && ($r['pos'] === null || $r['pos'] - $p0 >= 5)) $drops[] = "\"{$r['kw']}\": $p0 → " . ($r['pos'] ?? 'ngoài top 100'); }
        if ($drops) notify(null, 'Thứ hạng Google giảm (' . $job['name'] . ')', implode("\n", $drops), 'robot');
    }
    return ['status' => $status] + $res;
}

/** Cron: chạy các việc đến hạn (tối đa 2 việc mỗi lần để không kéo dài tác vụ 5 phút). */
function bot_tick(array $CFG = []): string {
    global $pdo;
    bot_tables();
    if ((int)date('G') < 7) return 'chưa tới giờ chạy (07:00)';
    $done = [];
    foreach ($pdo->query('SELECT * FROM bot_jobs WHERE enabled=1 AND schedule<>\'manual\' ORDER BY COALESCE(last_run_at,\'\')')->fetchAll() as $j) {
        $last = $j['last_run_at'] ? strtotime($j['last_run_at'] . ' UTC') : 0;
        $due = $j['schedule'] === 'daily' ? date('Y-m-d', $last) !== date('Y-m-d')
            : ((int)date('N') === 1 && date('o-W', $last) !== date('o-W')) || time() - $last > 8 * 86400;
        if (!$due) continue;
        $r = bot_run_job($j);
        $done[] = $j['name'] . ': ' . $r['status'];
        if (count($done) >= 2) break;
    }
    return $done ? implode('; ', $done) : 'không có việc đến hạn';
}
