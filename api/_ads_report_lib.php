<?php
/* Báo cáo Facebook Ads (Meta Marketing API) — số liệu trực tiếp theo khoảng ngày. 14/10/2026
   ads_report($CFG, $conn, $P): $P = range=today|yesterday|last_7d|last_14d|last_30d|this_month|last_month|custom [since, until], level=campaign|adset|ad, refresh
   → {account, range, summary:{cur,prev}, series, rows, platforms, positions, age_gender, regions, devices, insights, cached_at}
   Kênh chưa chọn tài khoản → {need_account:true, accounts:[…]}. Lỗi → AdsReportError (mã HTTP trong getCode()).
   Token chỉ dùng phía máy chủ (giải mã khi gọi), không trả về trình duyệt. Cache 15 phút trong data/cache (ngoài webroot). */
require_once __DIR__ . '/_meta_lib.php';

class AdsReportError extends RuntimeException {}

/* ---------- chuẩn hoá chỉ số ---------- */
const AR_LEAD = ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'leadgen_grouped'];
const AR_MSG = ['onsite_conversion.messaging_conversation_started_7d'];
const AR_PUR = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];
function ar_max(array $list, array $types): float { $v = 0.0; foreach ($list as $a) if (in_array($a['action_type'] ?? '', $types, true)) $v = max($v, (float)$a['value']); return $v; }
function ar_norm(array $r): array {
    $act = $r['actions'] ?? []; $val = $r['action_values'] ?? [];
    $m = ['spend' => (float)($r['spend'] ?? 0), 'impressions' => (int)($r['impressions'] ?? 0), 'reach' => (int)($r['reach'] ?? 0),
        'clicks' => (int)($r['clicks'] ?? 0), 'link_clicks' => (int)($r['inline_link_clicks'] ?? 0),
        'leads' => (int)ar_max($act, AR_LEAD), 'messages' => (int)ar_max($act, AR_MSG), 'purchases' => (int)ar_max($act, AR_PUR),
        'revenue' => ar_max($val, AR_PUR), 'engagement' => (int)ar_max($act, ['post_engagement']), 'video_views' => (int)ar_max($act, ['video_view']),
        'lpv' => (int)ar_max($act, ['landing_page_view'])];
    return ar_derive($m);
}
function ar_derive(array $m): array {
    $m['frequency'] = $m['reach'] ? round($m['impressions'] / $m['reach'], 2) : 0;
    $m['ctr'] = $m['impressions'] ? round($m['clicks'] / $m['impressions'] * 100, 2) : 0;
    $m['link_ctr'] = $m['impressions'] ? round($m['link_clicks'] / $m['impressions'] * 100, 2) : 0;
    $m['cpc'] = $m['link_clicks'] ? $m['spend'] / $m['link_clicks'] : ($m['clicks'] ? $m['spend'] / $m['clicks'] : 0);
    $m['cpm'] = $m['impressions'] ? $m['spend'] / $m['impressions'] * 1000 : 0;
    $m['cpl'] = $m['leads'] ? $m['spend'] / $m['leads'] : 0;
    $m['cost_per_msg'] = $m['messages'] ? $m['spend'] / $m['messages'] : 0;
    $m['cpa'] = $m['purchases'] ? $m['spend'] / $m['purchases'] : 0;
    $m['roas'] = $m['spend'] > 0 ? round($m['revenue'] / $m['spend'], 2) : 0;
    $m['results'] = $m['leads'] + $m['messages'] + $m['purchases'];
    $m['cost_per_result'] = $m['results'] ? $m['spend'] / $m['results'] : 0;
    return $m;
}
/** Kết quả chính theo mục tiêu chiến dịch (giống cột "Kết quả" trong Trình quản lý quảng cáo). */
function ar_result(array $m, string $obj): array {
    $o = strtoupper($obj);
    if (in_array($o, ['OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES'], true) && $m['purchases']) return ['Mua hàng', $m['purchases']];
    if (in_array($o, ['OUTCOME_LEADS', 'LEAD_GENERATION'], true)) return $m['leads'] || !$m['messages'] ? ['Lead', $m['leads']] : ['Tin nhắn', $m['messages']];
    if (in_array($o, ['OUTCOME_ENGAGEMENT', 'MESSAGES', 'POST_ENGAGEMENT', 'PAGE_LIKES', 'EVENT_RESPONSES'], true)) return $m['messages'] ? ['Tin nhắn', $m['messages']] : ['Tương tác', $m['engagement']];
    if (in_array($o, ['OUTCOME_TRAFFIC', 'LINK_CLICKS', 'OUTCOME_APP_PROMOTION', 'APP_INSTALLS'], true)) return $m['lpv'] ? ['Xem trang đích', $m['lpv']] : ['Click liên kết', $m['link_clicks']];
    if (in_array($o, ['OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS'], true)) return ['Tiếp cận', $m['reach']];
    if ($o === 'VIDEO_VIEWS') return ['Lượt xem video', $m['video_views']];
    if ($m['purchases']) return ['Mua hàng', $m['purchases']];
    if ($m['leads']) return ['Lead', $m['leads']];
    if ($m['messages']) return ['Tin nhắn', $m['messages']];
    return ['Click liên kết', $m['link_clicks']];
}
function ar_add(array $a, array $b): array { foreach (['spend', 'impressions', 'reach', 'clicks', 'link_clicks', 'leads', 'messages', 'purchases', 'revenue', 'engagement', 'video_views', 'lpv'] as $k) $a[$k] = ($a[$k] ?? 0) + ($b[$k] ?? 0); return $a; }

function ads_report(array $CFG, array $conn, array $P): array {
    $manual = ($conn['auth_method'] ?? 'oauth') === 'manual';
    $token = $manual ? (tok_dec($CFG, $conn['secret']) ?? $conn['secret']) : meta_user_token($CFG, $conn);
    if (!$token) throw new AdsReportError('Không đọc được token Meta Ads — bấm Kết nối lại', 409);
    $useProof = !$manual && $CFG['meta_app_secret'];

    /** GET Graph; lỗi token → 401 để giao diện mời kết nối lại. */
    $g = function (string $path, array $q) use ($CFG, $token, $useProof, $conn): array {
        $q['access_token'] = $token;
        if ($useProof) $q['appsecret_proof'] = meta_proof($CFG, $token);
        [$c, $d] = http_get_json(graph_url($CFG, $path, $q));
        $d = is_array($d) ? $d : [];
        if ($c !== 200 && meta_token_invalid($d)) {
            $GLOBALS['pdo']->prepare("UPDATE connections SET status='error', last_error=? WHERE id=?")->execute(['Meta Ads: token hết hạn hoặc bị thu hồi — bấm Kết nối lại', $conn['id']]);
            throw new AdsReportError('Token Meta Ads hết hạn hoặc bị thu hồi — bấm Kết nối lại', 401);
        }
        return [$c, $d];
    };
    $gAll = function (string $path, array $q, int $max = 1000) use ($g): array {
        $out = []; [$c, $d] = $g($path, $q);
        if ($c !== 200) return [$c, $d, []];
        for ($i = 0; $i < 10; $i++) {
            foreach ($d['data'] ?? [] as $x) $out[] = $x;
            $next = $d['paging']['next'] ?? null;
            if (!$next || count($out) >= $max) break;
            [$c2, $d] = http_get_json($next); $d = is_array($d) ? $d : [];
            if ($c2 !== 200) break;
        }
        return [200, [], $out];
    };

    // Chưa chọn tài khoản quảng cáo → trả danh sách để chọn.
    if (empty($conn['external_account_id'])) {
        [$c, , $list] = $gAll('me/adaccounts', ['fields' => 'id,name,account_status,currency,amount_spent,business{name}', 'limit' => 100], 200);
        if ($c !== 200) throw new AdsReportError('Không lấy được tài khoản quảng cáo — cần quyền ads_read, bấm Kết nối lại', 502);
        return (['need_account' => true, 'accounts' => array_map(fn($a) => ['id' => $a['id'], 'name' => (string)($a['name'] ?? $a['id']), 'status' => (int)($a['account_status'] ?? 0),
            'currency' => (string)($a['currency'] ?? ''), 'business' => (string)($a['business']['name'] ?? '')], $list)]);
    }

    /* ---------- khoảng ngày ---------- */
    $today = new DateTimeImmutable('today');
    $range = (string)($P['range'] ?? 'last_30d');
    $ymd = fn(DateTimeImmutable $d) => $d->format('Y-m-d');
    switch ($range) {
        case 'today': $s = $u = $today; break;
        case 'yesterday': $s = $u = $today->modify('-1 day'); break;
        case 'last_7d': $s = $today->modify('-7 day'); $u = $today->modify('-1 day'); break;
        case 'last_14d': $s = $today->modify('-14 day'); $u = $today->modify('-1 day'); break;
        case 'this_month': $s = $today->modify('first day of this month'); $u = $today; break;
        case 'last_month': $s = $today->modify('first day of last month'); $u = $today->modify('last day of last month'); break;
        case 'custom':
            $s = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($P['since'] ?? '')) ?: null;
            $u = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($P['until'] ?? '')) ?: null;
            if (!$s || !$u || $s > $u) throw new AdsReportError('Khoảng ngày không hợp lệ');
            if ($u > $today) $u = $today;
            if ($s < $today->modify('-37 months')) throw new AdsReportError('Meta chỉ giữ số liệu 37 tháng gần nhất');
            break;
        default: $range = 'last_30d'; $s = $today->modify('-30 day'); $u = $today->modify('-1 day');
    }
    $days = (int)$s->diff($u)->days + 1;
    $pu = $s->modify('-1 day'); $ps = $pu->modify('-' . ($days - 1) . ' day');
    $level = in_array($P['level'] ?? '', ['campaign', 'adset', 'ad'], true) ? $P['level'] : 'campaign';
    $tr = fn($a, $b) => json_encode(['since' => $ymd($a), 'until' => $ymd($b)]);

    /* ---------- cache ---------- */
    $cacheDir = __DIR__ . '/../data/cache';
    $key = $cacheDir . '/ads_' . md5(implode('|', [$conn['id'], $conn['external_account_id'], $ymd($s), $ymd($u), $level])) . '.json';
    if (empty($P['refresh']) && is_file($key) && filemtime($key) > time() - 900 && ($hit = json_decode((string)file_get_contents($key), true))) return ($hit + ['from_cache' => true]);

    $F = 'spend,impressions,reach,clicks,inline_link_clicks,actions,action_values';
    $acct = rawurlencode((string)$conn['external_account_id']);
    $warn = [];

    /* ---------- tài khoản ---------- */
    [$c, $acc] = $g($acct, ['fields' => 'id,name,currency,account_status,disable_reason,timezone_name,amount_spent,spend_cap,business{name}']);
    if ($c !== 200) throw new AdsReportError('Không truy cập được tài khoản quảng cáo ' . $conn['external_account_id'] . ': ' . ($acc['error']['message'] ?? "HTTP $c"), 502);
    $cur = (string)($acc['currency'] ?? 'VND');
    $div = in_array($cur, ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'], true) ? 1 : 100; // ngân sách Meta tính theo đơn vị nhỏ nhất
    $ACC_STATUS = [1 => 'Hoạt động', 2 => 'Bị vô hiệu hoá', 3 => 'Chưa thanh toán', 7 => 'Đang xét duyệt rủi ro', 8 => 'Chờ thanh toán', 9 => 'Trong thời gian ân hạn', 100 => 'Chờ đóng', 101 => 'Đã đóng'];

    /* ---------- tổng quan kỳ này / kỳ trước ---------- */
    $sum = function ($a, $b) use ($g, $acct, $F, $tr) {
        [$c, $d] = $g($acct . '/insights', ['fields' => $F, 'time_range' => $tr($a, $b)]);
        return $c === 200 ? ar_norm($d['data'][0] ?? []) : null;
    };
    $curSum = $sum($s, $u);
    if ($curSum === null) throw new AdsReportError('Meta không trả số liệu tổng quan — thử lại sau ít phút', 502);
    $prevSum = $sum($ps, $pu) ?? ar_derive(ar_add([], []));

    /* ---------- theo ngày ---------- */
    $series = [];
    [$c, , $daily] = $gAll($acct . '/insights', ['fields' => $F, 'time_range' => $tr($s, $u), 'time_increment' => 1, 'limit' => 100], 400);
    if ($c === 200) foreach ($daily as $x) { $m = ar_norm($x); $series[] = ['d' => $x['date_start'], 'spend' => round($m['spend']), 'impressions' => $m['impressions'], 'clicks' => $m['clicks'], 'link_clicks' => $m['link_clicks'], 'results' => $m['results'], 'leads' => $m['leads'], 'messages' => $m['messages'], 'purchases' => $m['purchases'], 'revenue' => round($m['revenue'])]; }
    else $warn[] = 'Không lấy được số liệu theo ngày';

    /* ---------- bảng chiến dịch / nhóm / quảng cáo ---------- */
    $idF = ['campaign' => 'campaign_id,campaign_name', 'adset' => 'campaign_name,adset_id,adset_name', 'ad' => 'campaign_name,adset_name,ad_id,ad_name'][$level];
    [$c, , $ins] = $gAll($acct . '/insights', ['level' => $level, 'fields' => $F . ',objective,' . $idF, 'time_range' => $tr($s, $u), 'limit' => 200], 1000);
    if ($c !== 200) $warn[] = 'Không lấy được bảng ' . $level;
    $entF = ['campaign' => 'id,name,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time',
        'adset' => 'id,name,effective_status,daily_budget,lifetime_budget,optimization_goal,campaign{name,objective}',
        'ad' => 'id,name,effective_status,adset{name},campaign{name,objective},creative{thumbnail_url}'][$level];
    [, , $ents] = $gAll($acct . '/' . $level . 's', ['fields' => $entF, 'limit' => 200, 'effective_status' => json_encode(['campaign' => ['ACTIVE', 'PAUSED', 'IN_PROCESS', 'WITH_ISSUES'], 'adset' => ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'IN_PROCESS', 'WITH_ISSUES'],
        'ad' => ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES', 'DISAPPROVED', 'PENDING_REVIEW']][$level])], 600);
    $byId = []; foreach ($ents as $e) $byId[(string)$e['id']] = $e;
    $rows = []; $seen = [];
    foreach ($ins as $x) {
        $id = (string)($x[$level . '_id'] ?? ''); $e = $byId[$id] ?? [];
        $m = ar_norm($x); $obj = (string)($x['objective'] ?? $e['objective'] ?? $e['campaign']['objective'] ?? '');
        [$rl, $rv] = ar_result($m, $obj);
        $bud = (float)($e['daily_budget'] ?? 0) ?: (float)($e['lifetime_budget'] ?? 0);
        $rows[] = $m + ['id' => $id, 'name' => (string)($x[$level . '_name'] ?? $e['name'] ?? $id), 'campaign' => (string)($x['campaign_name'] ?? ''), 'adset' => (string)($x['adset_name'] ?? ''),
            'status' => (string)($e['effective_status'] ?? ''), 'objective' => $obj, 'budget' => $bud ? $bud / $div : null, 'budget_type' => !empty($e['daily_budget']) ? 'ngày' : (!empty($e['lifetime_budget']) ? 'trọn đời' : null),
            'result_label' => $rl, 'result' => $rv, 'cost_per' => $rv ? $m['spend'] / $rv : 0, 'thumb' => $e['creative']['thumbnail_url'] ?? null];
        $seen[$id] = true;
    }
    foreach ($byId as $id => $e) if (!isset($seen[$id]) && ($e['effective_status'] ?? '') === 'ACTIVE') { // đang bật nhưng chưa chi trong kỳ
        $m = ar_derive(ar_add([], [])); $bud = (float)($e['daily_budget'] ?? 0) ?: (float)($e['lifetime_budget'] ?? 0);
        $rows[] = $m + ['id' => $id, 'name' => (string)$e['name'], 'campaign' => (string)($e['campaign']['name'] ?? ''), 'adset' => (string)($e['adset']['name'] ?? ''), 'status' => 'ACTIVE',
            'objective' => (string)($e['objective'] ?? $e['campaign']['objective'] ?? ''), 'budget' => $bud ? $bud / $div : null, 'budget_type' => !empty($e['daily_budget']) ? 'ngày' : (!empty($e['lifetime_budget']) ? 'trọn đời' : null),
            'result_label' => '—', 'result' => 0, 'cost_per' => 0, 'thumb' => $e['creative']['thumbnail_url'] ?? null];
    }
    usort($rows, fn($a, $b) => $b['spend'] <=> $a['spend']);

    /* ---------- phân tích theo kênh hiển thị / nhân khẩu / khu vực / thiết bị ---------- */
    $brk = function (string $b, int $max = 200) use ($gAll, $acct, $F, $tr, $s, $u): array {
        [$c, , $l] = $gAll($acct . '/insights', ['fields' => $F, 'breakdowns' => $b, 'time_range' => $tr($s, $u), 'limit' => 200], $max);
        return $c === 200 ? $l : [];
    };
    $PLAT = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'messenger' => 'Messenger', 'audience_network' => 'Audience Network', 'threads' => 'Threads'];
    $platforms = []; $positions = [];
    foreach ($brk('publisher_platform,platform_position') as $x) {
        $m = ar_norm($x); $p = (string)($x['publisher_platform'] ?? 'khác');
        $platforms[$p] = ar_add($platforms[$p] ?? ['key' => $p, 'name' => $PLAT[$p] ?? $p], $m);
        $positions[] = ['platform' => $PLAT[$p] ?? $p, 'position' => str_replace('_', ' ', (string)($x['platform_position'] ?? '')), 'spend' => $m['spend'], 'impressions' => $m['impressions'], 'clicks' => $m['clicks'], 'ctr' => $m['ctr'], 'cpm' => $m['cpm'], 'results' => $m['results'], 'cost_per_result' => $m['cost_per_result']];
    }
    $platforms = array_values(array_map(fn($p) => ar_derive($p), $platforms));
    usort($platforms, fn($a, $b) => $b['spend'] <=> $a['spend']); usort($positions, fn($a, $b) => $b['spend'] <=> $a['spend']);
    $slim = fn(array $m, array $extra) => $extra + ['spend' => $m['spend'], 'impressions' => $m['impressions'], 'reach' => $m['reach'], 'clicks' => $m['clicks'], 'ctr' => $m['ctr'], 'cpm' => $m['cpm'], 'results' => $m['results'], 'cost_per_result' => $m['cost_per_result']];
    $GEN = ['male' => 'Nam', 'female' => 'Nữ', 'unknown' => 'Không rõ'];
    $ageGender = array_map(fn($x) => $slim(ar_norm($x), ['age' => (string)($x['age'] ?? ''), 'gender' => $GEN[$x['gender'] ?? ''] ?? (string)($x['gender'] ?? '')]), $brk('age,gender'));
    $regions = array_map(fn($x) => $slim(ar_norm($x), ['region' => (string)($x['region'] ?? '')]), $brk('region', 400));
    usort($regions, fn($a, $b) => $b['spend'] <=> $a['spend']); $regions = array_slice($regions, 0, 15);
    $DEV = ['desktop' => 'Máy tính', 'iphone' => 'iPhone', 'ipad' => 'iPad', 'android_smartphone' => 'Android (điện thoại)', 'android_tablet' => 'Android (máy tính bảng)', 'other' => 'Khác'];
    $devices = array_map(fn($x) => $slim(ar_norm($x), ['device' => $DEV[$x['impression_device'] ?? ''] ?? (string)($x['impression_device'] ?? '')]), $brk('impression_device'));
    usort($devices, fn($a, $b) => $b['spend'] <=> $a['spend']);

    /* ---------- nhận xét tự động ---------- */
    $ins2 = []; $fmt = fn($v) => number_format((float)$v, 0, ',', '.') . ' ' . $cur;
    $chg = fn($a, $b) => $b > 0 ? ($a - $b) / $b * 100 : null;
    if ((int)($acc['account_status'] ?? 1) !== 1) $ins2[] = ['bad', 'Tài khoản quảng cáo đang ở trạng thái "' . ($ACC_STATUS[(int)$acc['account_status']] ?? $acc['account_status']) . '" — quảng cáo có thể không phân phối.'];
    if ($curSum['spend'] <= 0) $ins2[] = ['info', 'Không có chi tiêu trong khoảng ngày đã chọn.'];
    else {
        if (($p = $chg($curSum['spend'], $prevSum['spend'])) !== null && abs($p) >= 20) $ins2[] = [$p > 0 ? 'info' : 'warn', 'Chi tiêu ' . ($p > 0 ? 'tăng' : 'giảm') . ' ' . round(abs($p)) . '% so với kỳ trước.'];
        if ($curSum['cost_per_result'] && $prevSum['cost_per_result'] && ($p = $chg($curSum['cost_per_result'], $prevSum['cost_per_result'])) !== null && abs($p) >= 15)
            $ins2[] = [$p > 0 ? 'warn' : 'good', 'Chi phí / kết quả ' . ($p > 0 ? 'tăng' : 'giảm') . ' ' . round(abs($p)) . '% (' . $fmt($curSum['cost_per_result']) . ' so với ' . $fmt($prevSum['cost_per_result']) . ').'];
        if ($curSum['impressions'] > 2000 && $curSum['link_ctr'] < 0.8) $ins2[] = ['warn', 'CTR liên kết chỉ ' . $curSum['link_ctr'] . '% — nên làm mới nội dung / hình ảnh hoặc thu hẹp đối tượng.'];
        if ($curSum['frequency'] >= 3.5 && $days <= 31) $ins2[] = ['warn', 'Tần suất ' . $curSum['frequency'] . ' lần/người — dấu hiệu bão hoà, cân nhắc mở rộng đối tượng hoặc thay mẫu quảng cáo.'];
        if ($prevSum['cpm'] > 0 && ($p = $chg($curSum['cpm'], $prevSum['cpm'])) !== null && $p >= 25) $ins2[] = ['warn', 'CPM tăng ' . round($p) . '% so với kỳ trước (' . $fmt($curSum['cpm']) . ') — cạnh tranh đấu thầu cao hơn.'];
        $wasted = array_filter($rows, fn($r) => $r['spend'] >= max($div === 1 ? 100000 : 5, $curSum['spend'] * 0.1) && !$r['results'] && in_array($r['result_label'], ['Lead', 'Tin nhắn', 'Mua hàng'], true));
        foreach (array_slice($wasted, 0, 3) as $r) $ins2[] = ['bad', '"' . $r['name'] . '" đã chi ' . $fmt($r['spend']) . ' nhưng chưa có ' . mb_strtolower($r['result_label']) . ' nào.'];
        $withRes = array_values(array_filter($rows, fn($r) => $r['result'] > 0 && $r['spend'] > 0 && in_array($r['result_label'], ['Lead', 'Tin nhắn', 'Mua hàng'], true)));
        if (count($withRes) >= 2) { usort($withRes, fn($a, $b) => $a['cost_per'] <=> $b['cost_per']); $b = $withRes[0];
            $ins2[] = ['good', 'Hiệu quả nhất: "' . $b['name'] . '" — ' . $fmt($b['cost_per']) . ' / ' . mb_strtolower($b['result_label']) . '.']; }
        if (count($platforms) >= 2) { $pp = array_values(array_filter($platforms, fn($p) => $p['results'] > 0)); usort($pp, fn($a, $b) => $a['cost_per_result'] <=> $b['cost_per_result']);
            if (count($pp) >= 2 && $pp[count($pp) - 1]['cost_per_result'] > $pp[0]['cost_per_result'] * 1.5) $ins2[] = ['info', $pp[0]['name'] . ' cho chi phí / kết quả thấp nhất (' . $fmt($pp[0]['cost_per_result']) . '), ' . $pp[count($pp) - 1]['name'] . ' cao nhất (' . $fmt($pp[count($pp) - 1]['cost_per_result']) . ').']; }
        if ($curSum['purchases'] && $curSum['roas'] < 1) $ins2[] = ['bad', 'ROAS ' . $curSum['roas'] . ' — doanh thu theo Pixel thấp hơn chi tiêu.'];
        elseif ($curSum['roas'] >= 3) $ins2[] = ['good', 'ROAS ' . $curSum['roas'] . ' — chiến dịch bán hàng đang có lãi tốt.'];
    }

    $out = [
        'account' => ['id' => $acc['id'] ?? $conn['external_account_id'], 'name' => (string)($acc['name'] ?? ''), 'currency' => $cur, 'status' => (int)($acc['account_status'] ?? 0),
            'status_label' => $ACC_STATUS[(int)($acc['account_status'] ?? 0)] ?? '', 'timezone' => (string)($acc['timezone_name'] ?? ''), 'business' => (string)($acc['business']['name'] ?? ''),
            'amount_spent' => (float)($acc['amount_spent'] ?? 0) / $div, 'spend_cap' => !empty($acc['spend_cap']) ? (float)$acc['spend_cap'] / $div : null],
        'range' => ['key' => $range, 'since' => $ymd($s), 'until' => $ymd($u), 'days' => $days, 'prev_since' => $ymd($ps), 'prev_until' => $ymd($pu)],
        'level' => $level, 'summary' => ['cur' => $curSum, 'prev' => $prevSum], 'series' => $series, 'rows' => $rows,
        'platforms' => $platforms, 'positions' => array_slice($positions, 0, 20), 'age_gender' => $ageGender, 'regions' => $regions, 'devices' => $devices,
        'insights' => $ins2, 'warnings' => $warn, 'cached_at' => date('Y-m-d H:i:s'),
    ];
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    @file_put_contents($key, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return ($out);
}
