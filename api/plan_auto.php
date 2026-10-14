<?php
/* TỰ ĐỘNG LẬP KẾ HOẠCH QUÝ từ dữ liệu thật (không cần AI):
   - Search Console: nhóm từ khoá theo dịch vụ WebSEO, từ khoá vị trí 4–20 (cơ hội lên top), trang CTR thấp.
   - Bình luận kênh: số chưa trả lời từng kênh.
   - Kênh / nguồn đo còn thiếu.
   GET  → đề xuất {quarter, period, okrs[], campaigns[{…, tasks[]}]} (chưa ghi gì)
   POST {campaigns:[chỉ số], okrs:[chỉ số]} → tạo chiến dịch (trạng thái Lên kế hoạch, chờ duyệt) + công việc + OKR đã chọn. */
require __DIR__ . '/_boot.php';
$user = require_login();

const PA_TOPICS = [
    'Thiết kế website' => ['thiết kế web', 'thiet ke web', 'website', 'làm web', 'lam web', 'web bán hàng', 'landing', 'giao diện web', 'wordpress'],
    'Hosting / VPS' => ['hosting', 'vps', 'máy chủ', 'may chu', 'server', 'cloud'],
    'Email doanh nghiệp' => ['email', 'mail', 'gmail', 'workspace', 'outlook'],
    'Tên miền' => ['tên miền', 'ten mien', 'domain', '.vn', '.com'],
    'SEO / quảng cáo' => ['seo', 'marketing', 'quảng cáo', 'quang cao', 'google ads', 'từ khoá', 'tu khoa'],
    'Thương hiệu WebSEO' => ['webseo', 'web seo'],
];
function pa_topic(string $q): string {
    $q = mb_strtolower($q);
    foreach (PA_TOPICS as $t => $ws) foreach ($ws as $w) if (str_contains($q, $w)) return $t;
    return 'Khác';
}
function pa_quarter(): array {
    $y = (int)date('Y'); $q = intdiv((int)date('n') - 1, 3) + 1;
    $end = date('Y-m-t', strtotime("$y-" . str_pad((string)($q * 3), 2, '0', STR_PAD_LEFT) . '-01'));
    return ["$y-Q$q", date('Y-m-d'), $end];
}
function pa_due(string $start, int $weeks): string { return date('Y-m-d', strtotime("$start +$weeks week")); }

function pa_proposal(PDO $pdo): array {
    [$quarter, $start, $end] = pa_quarter();
    $PQ = $pdo->quote(pid());
    $conns = $pdo->query("SELECT * FROM connections WHERE project_id=$PQ AND status='connected' AND COALESCE(external_account_id,'')<>''")->fetchAll();
    $byP = []; foreach ($conns as $c) $byP[$c['provider']][] = $c;
    $camps = []; $okrs = []; $notes = [];
    $co = fn(array $c) => str_contains(mb_strtolower($c['label'] . ' ' . $c['external_account_id']), 'webseo') ? 'co1' : 'co2';

    // ---- SEO từ Search Console ----
    foreach ($byP['gsc'] ?? [] as $g) {
        $m = jdecode($g['last_metrics']);
        $rowsOf = function (string $lbl) use ($m) { foreach ($m['top'] ?? [] as $t) if (str_starts_with($t['label'], $lbl)) return $t['rows']; return []; };
        $qs = $rowsOf('Từ khoá'); $pages = $rowsOf('Trang');
        $val = fn(string $k) => (function () use ($m, $k) { foreach ($m['items'] ?? [] as $i) if (($i['key'] ?? '') === $k) return $i['value']; return 0; })();
        $clicks = (int)$val('clicks'); $imp = (int)$val('impressions'); $ctr = (float)$val('ctr');
        if (!$qs) continue;
        $site = $g['label'];
        // Gom nhóm dịch vụ theo hiển thị.
        $topic = [];
        foreach ($qs as $r) { $t = pa_topic($r[0]); if (in_array($t, ['Khác', 'Thương hiệu WebSEO'], true)) continue; $topic[$t][] = $r; }
        uasort($topic, fn($a, $b) => array_sum(array_column($b, 2)) <=> array_sum(array_column($a, 2)));
        foreach (array_slice($topic, 0, 3, true) as $t => $rows) {
            $opp = array_values(array_filter($rows, fn($r) => $r[4] > 3 && $r[4] <= 20));
            usort($opp, fn($a, $b) => $b[2] <=> $a[2]);
            $hi = array_sum(array_column($rows, 2));
            $tasks = [];
            foreach (array_slice($opp, 0, 6) as $i => $r) $tasks[] = ['title' => "Viết / tối ưu bài cho từ khoá \"{$r[0]}\" (vị trí " . round($r[4], 1) . ", {$r[2]} hiển thị)", 'board' => 'content', 'pri' => $i < 2 ? 'P1' : 'P2', 'due' => pa_due($start, $i + 1)];
            if (!$tasks) foreach (array_slice($rows, 0, 3) as $i => $r) $tasks[] = ['title' => "Bổ sung nội dung chuyên sâu cho \"{$r[0]}\" (vị trí " . round($r[4], 1) . ')', 'board' => 'content', 'pri' => 'P2', 'due' => pa_due($start, $i + 1)];
            $tasks[] = ['title' => "Liên kết nội bộ: trỏ các bài liên quan về trang dịch vụ $t", 'board' => 'content', 'pri' => 'P2', 'due' => pa_due($start, 3)];
            $camps[] = ['name' => "Nội dung SEO: $t — $quarter", 'subject' => $t, 'co' => 'co1', 'channels' => ['Google', 'Website'], 'budget' => 0,
                'start' => $start, 'end' => $end,
                'obj' => "Nhóm \"$t\" có $hi lượt hiển thị Google từ " . count($rows) . " từ khoá ($site, 28 ngày). Mục tiêu: đưa " . count($opp) . ' từ khoá ở vị trí 4–20 lên top 3.',
                'why' => count($opp) . " từ khoá vị trí 4–20 · $hi hiển thị", 'tasks' => $tasks];
        }
        // Trang hiển thị nhiều nhưng CTR thấp.
        $low = array_values(array_filter($pages, fn($r) => $r[2] >= 30 && (float)$r[3] < 2));
        if ($low || $ctr < 1) {
            $tasks = [];
            foreach (array_slice($low ?: $pages, 0, 5) as $i => $r) $tasks[] = ['title' => 'Viết lại tiêu đề + mô tả Google cho trang ' . (parse_url($r[0], PHP_URL_PATH) ?: '/') . " (CTR {$r[3]}, {$r[2]} hiển thị)", 'board' => 'content', 'pri' => 'P1', 'due' => pa_due($start, 1)];
            $camps[] = ['name' => "Tăng CTR Google Search — $quarter", 'subject' => 'SEO / quảng cáo', 'co' => 'co1', 'channels' => ['Google', 'Website'], 'budget' => 0,
                'start' => $start, 'end' => $end, 'obj' => "CTR toàn site $ctr% trên $imp hiển thị ($clicks click/28 ngày). Mục tiêu CTR ≥ 2% bằng tối ưu tiêu đề/mô tả.", 'why' => "CTR $ctr%", 'tasks' => $tasks];
        }
        $top10 = count(array_filter($qs, fn($r) => $r[4] <= 10));
        $okrs[] = ['objective' => 'Tăng khách tìm thấy WebSEO trên Google', 'kr' => "Click Google Search / 28 ngày ($site)", 'current' => $clicks, 'target' => max($clicks * 2, $clicks + 20), 'unit' => 'click', 'metric' => 'manual'];
        $okrs[] = ['objective' => 'Tăng khách tìm thấy WebSEO trên Google', 'kr' => "Số từ khoá ở top 10 ($site)", 'current' => $top10, 'target' => $top10 + 5, 'unit' => 'từ khoá', 'metric' => 'manual'];
    }

    // ---- Kênh mạng xã hội: bình luận chưa trả lời + nhịp đăng ----
    $open = []; foreach ($pdo->query("SELECT connection_id, COUNT(*) n FROM comments WHERE project_id=$PQ AND status IN ('new','escalated') GROUP BY connection_id")->fetchAll() as $r) $open[$r['connection_id']] = (int)$r['n'];
    foreach (array_merge($byP['facebook_page'] ?? [], $byP['youtube'] ?? [], $byP['tiktok'] ?? []) as $c) {
        $pn = ['facebook_page' => 'Facebook', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'][$c['provider']];
        $tasks = [];
        if (!empty($open[$c['id']])) $tasks[] = ['title' => "Trả lời {$open[$c['id']]} bình luận đang chờ trên {$c['label']}", 'board' => 'lead', 'pri' => 'P1', 'due' => pa_due($start, 0)];
        $tasks[] = ['title' => "Lịch nội dung 4 tuần cho {$c['label']} (3 bài/tuần)", 'board' => 'content', 'pri' => 'P2', 'due' => pa_due($start, 1)];
        $tasks[] = ['title' => "Đánh giá 10 bài/video gần nhất của {$c['label']}: chủ đề nào giữ chân tốt nhất", 'board' => 'research', 'pri' => 'P2', 'due' => pa_due($start, 2)];
        $camps[] = ['name' => "Nuôi kênh $pn {$c['label']} — $quarter", 'subject' => $pn, 'co' => $co($c), 'channels' => [$pn], 'budget' => 0, 'start' => $start, 'end' => $end,
            'obj' => "Tăng tương tác và tốc độ phản hồi trên {$c['label']}" . (!empty($open[$c['id']]) ? " (đang có {$open[$c['id']]} bình luận chờ)" : '') . '.',
            'why' => !empty($open[$c['id']]) ? $open[$c['id']] . ' bình luận chờ' : 'kênh đang hoạt động', 'tasks' => $tasks];
    }
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE project_id=$PQ")->fetchColumn();
    if ($tot) {
        $rep = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE project_id=$PQ AND status='replied'")->fetchColumn();
        $okrs[] = ['objective' => 'Chăm sóc khách trên kênh nhanh & đầy đủ', 'kr' => 'Tỉ lệ bình luận được trả lời', 'current' => round($rep / $tot * 100), 'target' => 90, 'unit' => '%', 'metric' => 'manual'];
    }
    $okrs[] = ['objective' => 'Tạo khách tiềm năng từ marketing', 'kr' => 'Số đầu mối trong quý (tự tính)', 'current' => 0, 'target' => 30, 'unit' => 'lead', 'metric' => 'leads'];

    // ---- Đo lường còn thiếu ----
    $gap = [];
    if (empty($byP['ga4'])) $gap[] = ['title' => 'Kết nối Google Analytics 4 cho webseo.com.vn (bật Analytics Admin + Data API rồi Chọn tài khoản)', 'pri' => 'P0'];
    if (empty($byP['google_ads'])) $gap[] = ['title' => 'Xin Google Ads Developer Token + kết nối tài khoản 710-410-691 để đo chi phí / chuyển đổi thật', 'pri' => 'P1'];
    if (empty($byP['meta_ads'])) $gap[] = ['title' => 'Kết nối tài khoản quảng cáo Meta (token System User quyền ads_read)', 'pri' => 'P1'];
    if (empty($byP['gbp'])) $gap[] = ['title' => 'Nộp đơn Business Profile API + kết nối Google Maps của WebSEO', 'pri' => 'P2'];
    if (!setting_get('data.serpapi_key')) $gap[] = ['title' => 'Tạo khoá SerpApi để theo dõi Google Trends tự động', 'pri' => 'P3'];
    $gap[] = ['title' => 'Gắn sự kiện chuyển đổi (gửi form, bấm gọi, Zalo) trong GA4 / GTM', 'pri' => 'P1'];
    $camps[] = ['name' => "Hoàn thiện đo lường — $quarter", 'subject' => 'Đo lường', 'co' => 'co1', 'channels' => ['Website', 'Google', 'Facebook'], 'budget' => 0, 'start' => $start, 'end' => $end,
        'obj' => 'Đo được đủ chi phí → truy cập → đầu mối để báo cáo CPL / ROAS thật.', 'why' => count($gap) . ' nguồn đo còn thiếu',
        'tasks' => array_map(fn($g, $i) => ['title' => $g['title'], 'board' => 'research', 'pri' => $g['pri'], 'due' => pa_due($start, min(3, $i))], $gap, array_keys($gap))];

    // Bỏ chiến dịch đã có cùng tên.
    $have = array_flip(array_column($pdo->query("SELECT name FROM campaigns WHERE project_id=$PQ")->fetchAll(), 'name'));
    foreach ($camps as &$c) $c['exists'] = isset($have[$c['name']]);
    $haveK = array_flip(array_map(fn($r) => $r['quarter'] . '|' . $r['key_result'], $pdo->query("SELECT quarter,key_result FROM okrs WHERE project_id=$PQ")->fetchAll()));
    foreach ($okrs as &$o) $o['exists'] = isset($haveK[$quarter . '|' . $o['kr']]);
    return ['quarter' => $quarter, 'period' => [$start, $end], 'campaigns' => $camps, 'okrs' => $okrs];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') respond(pa_proposal($pdo));

require_method('POST');
require_project_admin();
$in = json_input();
$PID = pid();
$P = pa_proposal($pdo);
$made = ['campaigns' => 0, 'tasks' => 0, 'okrs' => 0];
$pdo->beginTransaction();
foreach ((array)($in['campaigns'] ?? []) as $i) {
    $c = $P['campaigns'][(int)$i] ?? null;
    if (!$c || $c['exists']) continue;
    $cid = gen_id('CMP');
    $pdo->prepare("INSERT INTO campaigns(id,project_id,name,subject,co,br,state,approval,health,owner_id,channels,budget,spent,leads,roas,start_date,end_date,objective)
        VALUES (?,?,?,?,?,?,'PLANNED','PENDING','ON_TRACK',?,?,?,0,0,0,?,?,?)")
        ->execute([$cid, $PID, $c['name'], $c['subject'], $c['co'], 'all', $user['id'], json_encode($c['channels'], JSON_UNESCAPED_UNICODE), $c['budget'], $c['start'], $c['end'], $c['obj']]);
    $made['campaigns']++;
    $ins = $pdo->prepare("INSERT INTO tasks(id,project_id,title,co,br,board,col,type,assignee_id,assigner_id,due_date,pri,life,apr,health,campaign_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT','NA','ON_TRACK',?)");
    $first = ['content' => 'IDEA', 'design' => 'BRIEF', 'ads' => 'PLAN', 'research' => 'REQUEST', 'lead' => 'CAPTURE'];
    foreach ($c['tasks'] as $t) {
        $ins->execute([gen_id('T'), $PID, mb_substr($t['title'], 0, 200), $c['co'], 'all', $t['board'], $first[$t['board']] ?? 'IDEA', $t['board'], $user['id'], $user['id'], $t['due'], $t['pri'], $cid]);
        $made['tasks']++;
    }
}
foreach ((array)($in['okrs'] ?? []) as $i) {
    $o = $P['okrs'][(int)$i] ?? null;
    if (!$o || $o['exists']) continue;
    $pdo->prepare('INSERT INTO okrs(id,project_id,quarter,objective,key_result,target,current,unit,direction,metric,owner_id) VALUES (?,?,?,?,?,?,?,?,1,?,?)')
        ->execute([gen_id('OK'), $PID, $P['quarter'], $o['objective'], $o['kr'], $o['target'], $o['current'], $o['unit'], $o['metric'], $user['id']]);
    $made['okrs']++;
}
$pdo->commit();
log_activity('create', 'plan', null, "Tự động lập kế hoạch {$P['quarter']}: {$made['campaigns']} chiến dịch, {$made['tasks']} công việc, {$made['okrs']} OKR", $user);
respond(['ok' => true, 'made' => $made]);
