<?php
/* Báo cáo Đo lường Click vào Form & Call-To-Action (CTA) từ GA4 — ở từng Bài viết và Toàn trang.
   GET ?conn_id=…&range=last_30d|today|yesterday|last_7d|last_14d|last_90d|this_month|last_month|custom[&since&until][&refresh=1] */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_metrics_lib.php';
require_login();

$connId = (string)($_GET['conn_id'] ?? '');
if ($connId === '') {
    $st = $pdo->prepare("SELECT * FROM connections WHERE project_id=? AND provider='ga4' AND external_account_id IS NOT NULL AND external_account_id<>'' ORDER BY last_synced_at DESC LIMIT 1");
    $st->execute([pid()]);
    $conn = $st->fetch() ?: null;
} else {
    $conn = get_connection($connId);
}
if (!$conn || $conn['provider'] !== 'ga4' || empty($conn['external_account_id'])) {
    fail('Chưa tìm thấy tài khoản Google Analytics 4 (GA4) đã chọn Property', 404);
}
session_write_close();

$today = new DateTimeImmutable('today');
$range = (string)($_GET['range'] ?? 'last_30d');
$ymd = fn(DateTimeImmutable $d) => $d->format('Y-m-d');
switch ($range) {
    case 'today': $s = $u = $today; break;
    case 'yesterday': $s = $u = $today->modify('-1 day'); break;
    case 'last_7d': $s = $today->modify('-6 day'); $u = $today; break;
    case 'last_14d': $s = $today->modify('-13 day'); $u = $today; break;
    case 'last_90d': $s = $today->modify('-89 day'); $u = $today; break;
    case 'this_month': $s = $today->modify('first day of this month'); $u = $today; break;
    case 'last_month': $s = $today->modify('first day of last month'); $u = $today->modify('last day of last month'); break;
    case 'custom':
        $s = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['since'] ?? '')) ?: null;
        $u = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['until'] ?? '')) ?: null;
        if (!$s || !$u || $s > $u) fail('Khoảng ngày không hợp lệ');
        if ($u > $today) $u = $today;
        break;
    default: $range = 'last_30d'; $s = $today->modify('-29 day'); $u = $today;
}
$since = $ymd($s);
$until = $ymd($u);

$cacheDir = __DIR__ . '/../data/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$propId = preg_replace('/\D/', '', (string)$conn['external_account_id']);
$cacheKey = "$cacheDir/ga4_cta_{$propId}_{$since}_{$until}.json";
if (empty($_GET['refresh']) && is_file($cacheKey) && (time() - filemtime($cacheKey) < 900)) {
    $cached = json_decode((string)file_get_contents($cacheKey), true);
    if (is_array($cached)) {
        $cached['from_cache'] = true;
        respond($cached);
    }
}

/** GA4 không trả số liệu (hết hạn token, lỗi API): trả dữ liệu đã lưu gần nhất của property này, đánh dấu stale. */
$fallback = function (string $why, int $code = 502) use ($cacheDir, $propId) {
    $files = glob("$cacheDir/ga4_cta_{$propId}_*.json") ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($files as $fl) {
        $d = json_decode((string)file_get_contents($fl), true);
        if (is_array($d) && !empty($d['pages'])) respond($d + ['stale' => true, 'stale_reason' => $why, 'stale_at' => date('Y-m-d H:i', filemtime($fl))]);
    }
    fail($why, $code);
};
$manual = ($conn['auth_method'] ?? 'oauth') === 'manual';
if ($manual) {
    [$token, $err] = google_sa_token($conn['secret'], PROVIDERS['ga4']['scope']);
    if (!$token) $fallback('GA4 Service Account: ' . $err);
} else {
    $token = google_access_token($CFG, $conn);
    if (!$token) $fallback('Kết nối Google Analytics 4 đã hết hạn — bấm Kết nối lại GA4 để lấy số liệu mới');
}
$auth = ['Authorization: Bearer ' . $token];
$apiUrl = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propId) . ':runReport';

$run = function (array $body) use ($apiUrl, $auth, $fallback): array {
    $c = 0; $r = [];
    [$c, $r] = http_call('POST', $apiUrl, ['headers' => $auth, 'json' => $body]);
    if ($c !== 200) $fallback('GA4 API: ' . ($r['error']['message'] ?? "HTTP $c"));
    return $r['rows'] ?? [];
};

/** Phân loại trang: post (Bài viết), product (Sản phẩm), category (Danh mục), home (Trang chủ), form_page (Trang Liên hệ/Giỏ hàng/Đặt hàng), other */
function ga4_page_kind(string $path): string {
    $p = parse_url($path, PHP_URL_PATH) ?: $path;
    if ($p === '/' || $p === '') return 'home';
    if (preg_match('#/(?:gio-hang|dat-hang|thanh-toan|contact|lien-he|checkout|order)#i', $p)) return 'form_page';
    if (preg_match('#-p\d+\.html$#i', $p)) return 'product';
    if (preg_match('#-c\d+\.html$#i', $p) || preg_match('#^/tin-tuc/?$#i', $p) || preg_match('#^/tim-kiem#i', $p)) return 'category';
    if (preg_match('#-\d+\.html$#i', $p) || preg_match('#\.html$#i', $p)) return 'post';
    return 'post';
}

/** Phân loại hành động Click / Form / CTA: form, zalo, messenger, hotline, maps, download, other_cta */
function ga4_cta_kind(string $ev, string $url): array {
    $u = strtolower(trim($url));
    if (in_array($ev, ['form_submit', 'form_start', 'generate_lead', 'contact', 'submit_form'], true)) {
        return ['form', $ev === 'form_start' ? 'Mở/Bắt đầu điền Form (' . $ev . ')' : 'Gửi Form đăng ký (' . $ev . ')'];
    }
    if (preg_match('#docs\.google\.com/forms|forms\.gle|/contact|/lien-he|/dang-ky|/dat-lich|/dat-hang|form#i', $u)) {
        return ['form', 'Click Form đăng ký / Tư vấn'];
    }
    if (str_contains($u, 'zalo.me')) {
        $num = preg_replace('#^https?://zalo\.me/#i', '', $url);
        return ['zalo', 'Click Zalo CTA (' . $num . ')'];
    }
    if (str_contains($u, 'm.me/') || str_contains($u, 'messenger.com') || str_contains($u, 'facebook.com')) {
        return ['messenger', str_contains($u, 'facebook.com') ? 'Click Fanpage Facebook CTA' : 'Click Messenger CTA'];
    }
    if (str_starts_with($u, 'tel:') || str_contains($u, '0925588666') || str_contains($u, '0929371666')) {
        return ['hotline', 'Click Gọi Hotline'];
    }
    if (str_contains($u, 'maps.app.goo.gl') || str_contains($u, 'google.com/maps') || str_contains($u, 'maps.google')) {
        return ['maps', 'Click Chỉ đường Google Maps'];
    }
    if ($ev === 'file_download' || preg_match('#\.(apk|zip|rar|pdf|exe|dmg)(\?|$)#i', $u) || str_contains($u, 'searchwarranty') || str_contains($u, 'download')) {
        return ['download', str_contains($u, 'searchwarranty') ? 'Click Tra cứu bảo hành' : 'Click Tải ứng dụng / Tài liệu'];
    }
    return ['other_cta', 'Click Link / CTA ngoài trang'];
}

// 1) Tổng quan lượt xem toàn trang + theo từng trang (top 500 trang để ghép views tính CTR)
$pageViewsRows = $run([
    'dateRanges' => [['startDate' => $since, 'endDate' => $until]],
    'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
    'metrics' => [['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'totalUsers']],
    'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
    'limit' => 5000,
]);
$pageStats = [];
$totalSiteViews = 0;
$totalPostViews = 0;
foreach ($pageViewsRows as $r) {
    $path = (string)($r['dimensionValues'][0]['value'] ?? '/');
    $title = (string)($r['dimensionValues'][1]['value'] ?? '');
    $v = (int)($r['metricValues'][0]['value'] ?? 0);
    $sess = (int)($r['metricValues'][1]['value'] ?? 0);
    $usr = (int)($r['metricValues'][2]['value'] ?? 0);
    $totalSiteViews += $v;
    $kind = ga4_page_kind($path);
    if (in_array($kind, ['post', 'product'], true)) $totalPostViews += $v;
    if (!isset($pageStats[$path])) {
        $pageStats[$path] = ['path' => $path, 'title' => $title, 'kind' => $kind, 'views' => 0, 'sessions' => 0, 'users' => 0];
    }
    $pageStats[$path]['views'] += $v;
    $pageStats[$path]['sessions'] += $sess;
    $pageStats[$path]['users'] += $usr;
    if ($pageStats[$path]['title'] === '' && $title !== '') $pageStats[$path]['title'] = $title;
}

// 2) Tất cả sự kiện Click CTA, Form, Download theo pagePath + eventName + linkUrl
$clickRows = $run([
    'dateRanges' => [['startDate' => $since, 'endDate' => $until]],
    'dimensions' => [['name' => 'pagePath'], ['name' => 'eventName'], ['name' => 'linkUrl']],
    'dimensionFilter' => [
        'filter' => [
            'fieldName' => 'eventName',
            'inListFilter' => ['values' => ['click', 'form_submit', 'form_start', 'generate_lead', 'contact', 'submit_form', 'file_download']]
        ]
    ],
    'metrics' => [['name' => 'eventCount'], ['name' => 'totalUsers']],
    'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
    'limit' => 2500,
]);

// 3) Các trang đích Form / Giỏ hàng / Đặt hàng thành công / Liên hệ nội bộ (đo lượt mở form & gửi đơn nội bộ trên chungauto.vn)
$formPageRows = $run([
    'dateRanges' => [['startDate' => $since, 'endDate' => $until]],
    'dimensions' => [['name' => 'pagePath'], ['name' => 'pageReferrer']],
    'dimensionFilter' => [
        'orGroup' => ['expressions' => [
            ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'CONTAINS', 'value' => 'dat-hang-thanh-cong']]],
            ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'CONTAINS', 'value' => 'gio-hang']]],
            ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'CONTAINS', 'value' => 'contact']]],
            ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'CONTAINS', 'value' => 'lien-he']]],
            ['filter' => ['fieldName' => 'pagePath', 'stringFilter' => ['matchType' => 'CONTAINS', 'value' => 'thanh-toan']]],
        ]]
    ],
    'metrics' => [['name' => 'screenPageViews'], ['name' => 'totalUsers']],
    'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
    'limit' => 500,
]);

// 4) Chuỗi thời gian theo ngày của Click CTA & Form
$seriesRows = $run([
    'dateRanges' => [['startDate' => $since, 'endDate' => $until]],
    'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
    'dimensionFilter' => [
        'filter' => [
            'fieldName' => 'eventName',
            'inListFilter' => ['values' => ['click', 'form_submit', 'form_start', 'generate_lead', 'contact', 'file_download']]
        ]
    ],
    'metrics' => [['name' => 'eventCount'], ['name' => 'totalUsers']],
    'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
    'limit' => 500,
]);

$byDay = [];
foreach ($seriesRows as $r) {
    $rawD = (string)($r['dimensionValues'][0]['value'] ?? '');
    if (strlen($rawD) === 8) $d = substr($rawD, 0, 4) . '-' . substr($rawD, 4, 2) . '-' . substr($rawD, 6, 2);
    else $d = $rawD;
    $cnt = (int)($r['metricValues'][0]['value'] ?? 0);
    $usr = (int)($r['metricValues'][1]['value'] ?? 0);
    if (!isset($byDay[$d])) $byDay[$d] = ['d' => $d, 'clicks' => 0, 'users' => 0];
    $byDay[$d]['clicks'] += $cnt;
    $byDay[$d]['users'] += $usr;
}

// Tổng hợp theo Trang/Bài viết và theo Nút/Link CTA
$byPage = [];
$byCta = [];
$summary = [
    'total_cta_clicks' => 0,
    'total_cta_users' => 0,
    'post_cta_clicks' => 0,
    'form_clicks' => 0,
    'form_page_views' => 0,
    'order_success_views' => 0,
    'cart_views' => 0,
    'contact_views' => 0,
    'zalo_clicks' => 0,
    'messenger_clicks' => 0,
    'hotline_clicks' => 0,
    'maps_clicks' => 0,
    'download_clicks' => 0,
    'other_cta_clicks' => 0,
    'site_views' => $totalSiteViews,
    'post_views' => $totalPostViews,
];

$ensurePage = function (string $path) use (&$byPage, $pageStats): void {
    if (!isset($byPage[$path])) {
        $ps = $pageStats[$path] ?? null;
        $byPage[$path] = [
            'path' => $path,
            'title' => $ps['title'] ?? '',
            'kind' => $ps['kind'] ?? ga4_page_kind($path),
            'views' => $ps['views'] ?? 0,
            'users' => $ps['users'] ?? 0,
            'total_cta' => 0,
            'form' => 0,
            'zalo' => 0,
            'messenger' => 0,
            'hotline' => 0,
            'maps' => 0,
            'download' => 0,
            'other_cta' => 0,
            'top_links' => [],
        ];
    }
};

// Tránh cộng trùng file_download khi cùng 1 URL đã có event click
$seenDownloadOnPage = [];
foreach ($clickRows as $r) {
    $path = (string)($r['dimensionValues'][0]['value'] ?? '/');
    $ev = (string)($r['dimensionValues'][1]['value'] ?? 'click');
    $url = (string)($r['dimensionValues'][2]['value'] ?? '');
    $cnt = (int)($r['metricValues'][0]['value'] ?? 0);
    $usr = (int)($r['metricValues'][1]['value'] ?? 0);
    if ($cnt <= 0) continue;

    if ($ev === 'click' && preg_match('#\.(apk|zip|rar|pdf|exe)(\?|$)#i', $url)) {
        $seenDownloadOnPage[$path . '|' . $url] = true;
    }
    if ($ev === 'file_download' && !empty($seenDownloadOnPage[$path . '|' . $url])) {
        continue; // đã tính ở sự kiện click của cùng link download
    }

    [$cat, $catLabel] = ga4_cta_kind($ev, $url);
    $ensurePage($path);
    $byPage[$path]['total_cta'] += $cnt;
    $byPage[$path][$cat] = ($byPage[$path][$cat] ?? 0) + $cnt;
    $lk = $url ?: ('[' . $ev . ']');
    $byPage[$path]['top_links'][$lk] = ($byPage[$path]['top_links'][$lk] ?? 0) + $cnt;

    $summary['total_cta_clicks'] += $cnt;
    $summary['total_cta_users'] += $usr;
    if (in_array($byPage[$path]['kind'], ['post', 'product'], true)) {
        $summary['post_cta_clicks'] += $cnt;
    }
    if (isset($summary[$cat . '_clicks'])) {
        $summary[$cat . '_clicks'] += $cnt;
    }

    $ctaKey = $cat . '|' . $lk . '|' . $path;
    if (!isset($byCta[$ctaKey])) {
        $byCta[$ctaKey] = [
            'cat' => $cat,
            'cat_label' => $catLabel,
            'url' => $lk,
            'page' => $path,
            'page_title' => $byPage[$path]['title'],
            'page_kind' => $byPage[$path]['kind'],
            'clicks' => 0,
            'users' => 0,
        ];
    }
    $byCta[$ctaKey]['clicks'] += $cnt;
    $byCta[$ctaKey]['users'] += $usr;
}

// Xử lý dữ liệu các trang Form / Giỏ hàng / Đặt hàng thành công / Liên hệ nội bộ
$internalFormItems = [];
foreach ($formPageRows as $r) {
    $destPath = (string)($r['dimensionValues'][0]['value'] ?? '');
    $ref = (string)($r['dimensionValues'][1]['value'] ?? '');
    $cnt = (int)($r['metricValues'][0]['value'] ?? 0);
    $usr = (int)($r['metricValues'][1]['value'] ?? 0);
    if ($cnt <= 0) continue;

    $summary['form_page_views'] += $cnt;
    if (str_contains($destPath, 'dat-hang-thanh-cong')) $summary['order_success_views'] += $cnt;
    elseif (str_contains($destPath, 'gio-hang')) $summary['cart_views'] += $cnt;
    elseif (str_contains($destPath, 'lien-he') || str_contains($destPath, 'contact')) $summary['contact_views'] += $cnt;

    // Nếu referrer là một bài viết / sản phẩm nội bộ chungauto.vn thì ghi nhận bài viết đó dẫn khách vào Form/Giỏ hàng!
    $refPath = '';
    if ($ref !== '' && str_contains($ref, 'chungauto.vn')) {
        $refPath = parse_url($ref, PHP_URL_PATH) ?: '/';
    }
    $targetPage = ($refPath !== '' && $refPath !== $destPath) ? $refPath : $destPath;
    $ensurePage($targetPage);
    $byPage[$targetPage]['form'] += $cnt;
    $byPage[$targetPage]['total_cta'] += $cnt;
    $byPage[$targetPage]['top_links'][$destPath] = ($byPage[$targetPage]['top_links'][$destPath] ?? 0) + $cnt;
    $summary['total_cta_clicks'] += $cnt;
    if (in_array($byPage[$targetPage]['kind'], ['post', 'product'], true)) {
        $summary['post_cta_clicks'] += $cnt;
    }
    $lbl = str_contains($destPath, 'dat-hang-thanh-cong') ? 'Hoàn tất Đặt hàng (/dat-hang-thanh-cong)'
        : (str_contains($destPath, 'gio-hang') ? 'Click Mua hàng / Giỏ hàng (/gio-hang)' : 'Mở Form Liên hệ / Tư vấn (' . $destPath . ')');
    $ctaKey = 'form|' . $destPath . '|' . $targetPage;
    if (!isset($byCta[$ctaKey])) {
        $byCta[$ctaKey] = [
            'cat' => 'form',
            'cat_label' => $lbl,
            'url' => 'https://chungauto.vn' . $destPath,
            'page' => $targetPage,
            'page_title' => $byPage[$targetPage]['title'],
            'page_kind' => $byPage[$targetPage]['kind'],
            'clicks' => 0,
            'users' => 0,
        ];
    }
    $byCta[$ctaKey]['clicks'] += $cnt;
    $byCta[$ctaKey]['users'] += $usr;
    $internalFormItems[] = [
        'dest' => $destPath,
        'ref_page' => $refPath ?: ($ref ?: '(trực tiếp / nội bộ)'),
        'views' => $cnt,
        'users' => $usr,
    ];
}

$summary['total_form_and_lead_actions'] = $summary['form_clicks'] + $summary['order_success_views'] + $summary['contact_views'] + $summary['cart_views'];
$summary['site_cta_ctr'] = $summary['site_views'] > 0 ? round($summary['total_cta_clicks'] / $summary['site_views'] * 100, 2) : 0;
$summary['post_cta_ctr'] = $summary['post_views'] > 0 ? round($summary['post_cta_clicks'] / $summary['post_views'] * 100, 2) : 0;

// Chuẩn hoá danh sách trang / bài viết
$pagesOut = [];
foreach ($byPage as $p) {
    arsort($p['top_links']);
    $topArr = [];
    foreach (array_slice($p['top_links'], 0, 5, true) as $k => $v) {
        $topArr[] = ['url' => $k, 'clicks' => $v];
    }
    $p['top_links'] = $topArr;
    $p['ctr'] = $p['views'] > 0 ? round($p['total_cta'] / $p['views'] * 100, 2) : 0;
    $pagesOut[] = $p;
}
usort($pagesOut, fn($a, $b) => $b['total_cta'] <=> $a['total_cta']);

$ctasOut = array_values($byCta);
usort($ctasOut, fn($a, $b) => $b['clicks'] <=> $a['clicks']);

// Bảng tổng hợp theo nhóm CTA toàn trang
$breakdown = [
    ['cat' => 'zalo', 'label' => 'Click Zalo Tư vấn (zalo.me)', 'clicks' => $summary['zalo_clicks'], 'note' => 'Nút chat Zalo nổi & trong bài viết'],
    ['cat' => 'maps', 'label' => 'Click Chỉ đường Google Maps', 'clicks' => $summary['maps_clicks'], 'note' => 'Link bản đồ chi nhánh / đại lý ChungAuto'],
    ['cat' => 'messenger', 'label' => 'Click Messenger / Fanpage FB', 'clicks' => $summary['messenger_clicks'], 'note' => 'Nút chat Messenger (m.me) & Fanpage'],
    ['cat' => 'download', 'label' => 'Click Tải App / File / Tra cứu BH', 'clicks' => $summary['download_clicks'], 'note' => 'Tải APK, bản đồ VietMap, tra cứu bảo hành 3M'],
    ['cat' => 'form', 'label' => 'Click Form đăng ký (Google Forms / Sự kiện Form)', 'clicks' => $summary['form_clicks'], 'note' => 'Click vào biểu mẫu đăng ký trên bài viết'],
    ['cat' => 'cart', 'label' => 'Mở Giỏ hàng (/gio-hang)', 'clicks' => $summary['cart_views'], 'note' => 'Bấm nút Mua hàng / Giỏ hàng trên trang'],
    ['cat' => 'contact', 'label' => 'Mở Trang Liên hệ / Tư vấn (/p/lien-he, /contact)', 'clicks' => $summary['contact_views'], 'note' => 'Vào trang liên hệ & gửi form tư vấn'],
    ['cat' => 'order_ok', 'label' => 'Đặt hàng thành công (/dat-hang-thanh-cong)', 'clicks' => $summary['order_success_views'], 'note' => 'Hoàn tất gửi form đặt hàng trên web'],
    ['cat' => 'other_cta', 'label' => 'Click Link / CTA khác', 'clicks' => $summary['other_cta_clicks'], 'note' => 'Các liên kết ngoài khác trong bài viết'],
];

$out = [
    'connection' => ['id' => $conn['id'], 'label' => $conn['label'], 'property_id' => $propId],
    'range' => ['key' => $range, 'since' => $since, 'until' => $until],
    'summary' => $summary,
    'breakdown' => $breakdown,
    'series' => array_values($byDay),
    'pages' => array_slice($pagesOut, 0, 300),
    'ctas' => array_slice($ctasOut, 0, 300),
    'internal_forms' => array_slice($internalFormItems, 0, 100),
    'cached_at' => date('Y-m-d H:i:s'),
    'from_cache' => false,
];

@file_put_contents($cacheKey, json_encode($out, JSON_UNESCAPED_UNICODE));
respond($out);