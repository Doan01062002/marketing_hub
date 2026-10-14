<?php
/* Thư viện đồng bộ số liệu kênh — dùng chung cho api/metrics.php (người dùng bấm "Đồng bộ")
   và cron/sync-all.php (chạy tự động mỗi ngày). Yêu cầu đã require _boot.php ($pdo, $CFG, PROVIDERS, http_*).

   sync_connection($CFG, $conn) → trả về mảng metrics {range, items:[{label,value,fmt,key}], notes}
   đã lưu vào connections.last_metrics và metrics_history (1 bản/kênh/ngày, bản mới nhất trong ngày thắng).
   Lỗi → ném SyncError (đã ghi connections.last_error nếu lỗi đến từ nền tảng). */

class SyncError extends RuntimeException {
    public int $http;
    public function __construct(string $msg, int $http = 502) { parent::__construct($msg); $this->http = $http; }
}

/** Chỉ số chính của từng loại kênh dùng cho biểu đồ theo thời gian (api/stats.php). */
const METRIC_KEYS = [
    'facebook_page' => ['key' => 'followers', 'label' => 'Người theo dõi'],
    'youtube' => ['key' => 'subscribers', 'label' => 'Người đăng ký'],
    'tiktok' => ['key' => 'followers', 'label' => 'Người theo dõi'],
    'x' => ['key' => 'followers', 'label' => 'Người theo dõi'], // 12/10/2026
    'zalo_oa' => ['key' => 'followers', 'label' => 'Người quan tâm OA'],
    'ga4' => ['key' => 'sessions', 'label' => 'Phiên (30 ngày)'],
    'gsc' => ['key' => 'clicks', 'label' => 'Click Google Search (28 ngày)'],
    'gbp' => ['key' => 'views', 'label' => 'Lượt xem Google Maps + Search (30 ngày)'],
    'google_ads' => ['key' => 'spend', 'label' => 'Chi tiêu (30 ngày)'],
    'meta_ads' => ['key' => 'spend', 'label' => 'Chi tiêu (30 ngày)'],
];

/** Số trang video (20 video/trang) tối đa đọc cho mỗi lần đồng bộ TikTok. */
const TIKTOK_VIDEO_PAGES = 5;

function mitem(string $label, $value, string $fmt = 'num', ?string $key = null): array {
    $it = ['label' => $label, 'value' => $value, 'fmt' => $fmt];
    if ($key) $it['key'] = $key;
    return $it;
}

/** Ngày lưu lịch sử theo giờ Việt Nam nếu máy chủ chưa đặt múi giờ (cron 06:00 sáng không bị tính sang hôm trước). */
function metrics_today(): string {
    $tz = ini_get('date.timezone') ?: 'Asia/Ho_Chi_Minh';
    try { return (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d'); } catch (Throwable $e) { return date('Y-m-d'); }
}

function metrics_save(array $conn, array $items, array $notes = [], array $extra = []): array {
    global $pdo;
    $m = ['range' => '30 ngày gần nhất', 'items' => $items, 'notes' => $notes] + $extra; // extra: top (bảng xếp hạng: từ khoá, trang…)
    $json = json_encode($m, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE connections SET last_metrics=?, last_synced_at=datetime('now'), last_error=NULL, status='connected' WHERE id=?")
        ->execute([$json, $conn['id']]);
    $pdo->prepare("INSERT INTO metrics_history(connection_id,provider,day,metrics) VALUES (?,?,?,?)
        ON CONFLICT(connection_id,day) DO UPDATE SET metrics=excluded.metrics, provider=excluded.provider, created_at=datetime('now')")
        ->execute([$conn['id'], $conn['provider'], metrics_today(), json_encode(['range' => $m['range'], 'items' => $items, 'notes' => $notes], JSON_UNESCAPED_UNICODE)]);
    return $m;
}

/** Lỗi từ nền tảng: ghi last_error rồi ném. */
function metrics_fail(array $conn, string $msg): void {
    global $pdo;
    $pdo->prepare('UPDATE connections SET last_error=? WHERE id=?')->execute([$msg, $conn['id']]);
    throw new SyncError($msg, 502);
}

function sync_connection(array $CFG, array $conn): array {
    if ($conn['status'] !== 'connected') throw new SyncError('Kênh chưa kết nối', 400);
    if (!$conn['external_account_id']) throw new SyncError('Chưa chọn tài khoản/kênh cụ thể — bấm "Chọn tài khoản" trước khi đồng bộ', 400);
    $p = $conn['provider'];
    if (!isset(PROVIDERS[$p])) throw new SyncError('Loại kênh không hỗ trợ', 400);

    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');
    $manual = ($conn['auth_method'] ?? 'oauth') === 'manual';
    // YouTube nhập tay có OAuth riêng (Client ID + Secret + Refresh Token) → đi đường OAuth, đọc được cả YouTube Analytics.
    $ytOauth = $p === 'youtube' && $manual && !empty($conn['refresh_token']);
    $ownOauth = in_array($p, ['youtube', 'gbp'], true) && $manual && !empty($conn['refresh_token']); // OAuth riêng nhập tay
    if ($manual && !$conn['secret'] && !$ownOauth) metrics_fail($conn, 'Chưa có token / API key — bấm Sửa để dán vào');

    if ($manual && $p === 'youtube' && !$ytOauth) {
        $ytList = yt_recent_videos($conn['external_account_id'], [], $conn['secret']);
        // API key chỉ đọc được số liệu công khai của kênh.
        [$code, $ch] = http_get_json('https://www.googleapis.com/youtube/v3/channels?' . http_build_query([
            'part' => 'snippet,statistics', 'id' => $conn['external_account_id'], 'key' => $conn['secret'],
        ]));
        if ($code !== 200) metrics_fail($conn, 'YouTube: ' . ($ch['error']['message'] ?? "HTTP $code"));
        $stat = $ch['items'][0]['statistics'] ?? null;
        if ($stat === null) metrics_fail($conn, 'YouTube: không tìm thấy kênh có Channel ID này');
        return metrics_save($conn, [
            mitem('Người đăng ký', (int)($stat['subscriberCount'] ?? 0), 'num', 'subscribers'),
            mitem('Tổng lượt xem', (int)($stat['viewCount'] ?? 0), 'num', 'views_total'),
            mitem('Số video', (int)($stat['videoCount'] ?? 0), 'num', 'videos'),
        ], ['Lượt xem / phút xem 30 ngày cần kết nối bằng đăng nhập Google (API key chỉ đọc được số công khai)'], ['content' => $ytList, 'content_kind' => 'video']);
    }

    if ($p === 'tiktok') return sync_tiktok($CFG, $conn);
    if ($p === 'x') return sync_x($CFG, $conn); // 12/10/2026: X (Twitter)
    if ($p === 'zalo_oa') {
        [$tok, $err] = zalo_access_token($conn);
        if (!$tok) metrics_fail($conn, $err);
        [$c, $d] = http_get_json('https://openapi.zalo.me/v2.0/oa/getoa', ['access_token: ' . $tok]);
        if ($c !== 200 || ($d['error'] ?? 0) != 0) metrics_fail($conn, 'Zalo OA: ' . ($d['message'] ?? "HTTP $c"));
        $o = $d['data'] ?? [];
        $items = [mitem('Người quan tâm', (int)($o['num_follower'] ?? 0), 'num', 'followers')];
        // Số cuộc trò chuyện gần đây (tối đa 50) để thấy nhịp khách nhắn.
        $n7 = 0; $since = (time() - 7 * 86400) * 1000;
        foreach ([0, 10, 20, 30, 40] as $off) {
            [$c2, $r] = http_get_json('https://openapi.zalo.me/v2.0/oa/listrecentchat?data=' . rawurlencode(json_encode(['offset' => $off, 'count' => 10])), ['access_token: ' . $tok]);
            $rows = $r['data'] ?? [];
            foreach ($rows as $m) if ((int)($m['time'] ?? 0) >= $since) $n7++;
            if (count($rows) < 10) break;
        }
        $items[] = mitem('Cuộc trò chuyện 7 ngày', $n7, 'num', 'chats_7d');
        $GLOBALS['pdo']->prepare('UPDATE connections SET label=CASE WHEN label=\'\' THEN ? ELSE label END WHERE id=?')->execute([(string)($o['name'] ?? ''), $conn['id']]);
        return metrics_save($conn, $items);
    }

    $auth = [];
    if (PROVIDERS[$p]['auth'] === 'google') {
        if ($manual && !$ownOauth) {
            [$token, $err] = google_sa_token($conn['secret'], PROVIDERS[$p]['scope']);
            if (!$token) metrics_fail($conn, $err);
        } else {
            $token = google_access_token($CFG, $conn);
            if (!$token) {
                $e = get_connection($conn['id'])['last_error'] ?? '';
                metrics_fail($conn, $ownOauth ? ($p === 'gbp' ? 'Google Business Profile' : 'YouTube') . ': không làm mới được token từ Refresh Token' . ($e ? " ($e)" : '') . ' — kiểm tra lại Client ID / Secret / Refresh Token'
                    : 'Token Google hết hạn — hãy kết nối lại');
            }
        }
        $auth = ['Authorization: Bearer ' . $token];
    }
    if (PROVIDERS[$p]['auth'] === 'meta' && $manual) $conn['access_token'] = tok_dec($CFG, $conn['secret']) ?? $conn['secret']; // 11/10/2026: token Meta Ads lưu mã hoá (enc1:)

    if ($p === 'ga4') {
        [$code, $d] = http_call('POST', 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($conn['external_account_id']) . ':runReport', [
            'headers' => $auth,
            'json' => [
                'dateRanges' => [['startDate' => $since, 'endDate' => $until]],
                'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'screenPageViews'], ['name' => 'keyEvents'], ['name' => 'engagementRate']],
            ],
        ]);
        if ($code !== 200) metrics_fail($conn, 'GA4: ' . ($d['error']['message'] ?? "HTTP $code"));
        $v = array_column($d['rows'][0]['metricValues'] ?? [], 'value');
        $rep = function (array $dims, array $mets, int $limit, array $order = []) use ($conn, $auth, $since, $until) {
            [$c, $r] = http_call('POST', 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($conn['external_account_id']) . ':runReport', ['headers' => $auth,
                'json' => ['dateRanges' => [['startDate' => $since, 'endDate' => $until]], 'dimensions' => array_map(fn($x) => ['name' => $x], $dims),
                    'metrics' => array_map(fn($x) => ['name' => $x], $mets), 'limit' => $limit] + ($order ? ['orderBys' => $order] : [])]);
            return $c === 200 ? array_map(fn($row) => array_merge(array_column($row['dimensionValues'], 'value'), array_map('floatval', array_column($row['metricValues'], 'value'))), $r['rows'] ?? []) : [];
        };
        $byDesc = fn($m) => [['metric' => ['metricName' => $m], 'desc' => true]];
        $top = [];
        $pages = $rep(['pagePath'], ['screenPageViews', 'sessions', 'engagementRate'], 15, $byDesc('screenPageViews'));
        if ($pages) $top[] = ['label' => 'Trang xem nhiều', 'cols' => ['Trang', 'Lượt xem', 'Phiên', 'Tương tác'], 'rows' => array_map(fn($r) => [$r[0], (int)$r[1], (int)$r[2], round($r[3] * 100, 1) . '%'], $pages)];
        $src = $rep(['sessionSourceMedium'], ['sessions', 'engagementRate', 'keyEvents'], 12, $byDesc('sessions'));
        if ($src) $top[] = ['label' => 'Nguồn truy cập', 'cols' => ['Nguồn / kênh', 'Phiên', 'Tương tác', 'Chuyển đổi'], 'rows' => array_map(fn($r) => [$r[0], (int)$r[1], round($r[2] * 100, 1) . '%', (int)$r[3]], $src)];
        $dev = $rep(['deviceCategory'], ['sessions'], 5, $byDesc('sessions'));
        if ($dev) $top[] = ['label' => 'Thiết bị', 'cols' => ['Thiết bị', 'Phiên'], 'rows' => array_map(fn($r) => [$r[0], (int)$r[1]], $dev)];
        $city = $rep(['city'], ['sessions'], 10, $byDesc('sessions'));
        if ($city) $top[] = ['label' => 'Thành phố', 'cols' => ['Thành phố', 'Phiên'], 'rows' => array_map(fn($r) => [$r[0], (int)$r[1]], $city)];
        $series = array_map(fn($r) => ['d' => substr($r[0], 0, 4) . '-' . substr($r[0], 4, 2) . '-' . substr($r[0], 6, 2), 's' => (int)$r[1], 'u' => (int)$r[2]],
            $rep(['date'], ['sessions', 'totalUsers'], 60, [['dimension' => ['dimensionName' => 'date']]]));
        return metrics_save($conn, [
            mitem('Phiên', (int)($v[0] ?? 0), 'num', 'sessions'),
            mitem('Người dùng', (int)($v[1] ?? 0), 'num', 'users'),
            mitem('Lượt xem trang', (int)($v[2] ?? 0), 'num', 'pageviews'),
            mitem('Sự kiện chính (chuyển đổi)', (int)($v[3] ?? 0), 'num', 'conversions'),
            mitem('Tỉ lệ tương tác', round(((float)($v[4] ?? 0)) * 100, 1), 'pct', 'engagement_rate'),
        ], [], ['top' => $top, 'series' => $series, 'series_kind' => 'ga4']);
    }

    if ($p === 'gsc') {
        // Search Console trễ ~2–3 ngày: lấy 28 ngày kết thúc 3 ngày trước.
        $end = date('Y-m-d', strtotime('-3 days')); $start = date('Y-m-d', strtotime('-30 days'));
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($conn['external_account_id']) . '/searchAnalytics/query';
        $q = fn(array $dims, int $limit) => http_call('POST', $url, ['headers' => $auth, 'json' => ['startDate' => $start, 'endDate' => $end, 'dimensions' => $dims, 'rowLimit' => $limit]]);
        [$code, $d] = $q([], 1);
        if ($code !== 200) {
            $msg = $d['error']['message'] ?? "HTTP $code";
            if ($code === 403) $msg .= ' — thêm email service account vào Search Console (Cài đặt → Người dùng và quyền)';
            metrics_fail($conn, 'Search Console: ' . $msg);
        }
        $t = $d['rows'][0] ?? [];
        $top = [];
        foreach (['query' => ['Từ khoá', 50], 'page' => ['Trang', 25], 'device' => ['Thiết bị', 5], 'country' => ['Quốc gia', 10]] as $dim => [$lbl, $lim]) {
            [$c2, $d2] = $q([$dim], $lim);
            if ($c2 === 200) $top[] = ['label' => "$lbl top (theo click)", 'cols' => [$lbl, 'Click', 'Hiển thị', 'CTR', 'Vị trí'],
                'rows' => array_map(fn($r) => [$r['keys'][0], (int)$r['clicks'], (int)$r['impressions'], round($r['ctr'] * 100, 1) . '%', round($r['position'], 1)], $d2['rows'] ?? [])];
        }
        // Chuỗi theo ngày để vẽ biểu đồ click / hiển thị / vị trí.
        [$c3, $d3] = $q(['date'], 100);
        $series = $c3 === 200 ? array_map(fn($r) => ['d' => $r['keys'][0], 'c' => (int)$r['clicks'], 'i' => (int)$r['impressions'], 'p' => round($r['position'], 1)], $d3['rows'] ?? []) : [];
        return metrics_save($conn, [
            mitem('Click', (int)($t['clicks'] ?? 0), 'num', 'clicks'),
            mitem('Lượt hiển thị', (int)($t['impressions'] ?? 0), 'num', 'impressions'),
            mitem('CTR', round(((float)($t['ctr'] ?? 0)) * 100, 2), 'pct', 'ctr'),
            mitem('Vị trí trung bình', round((float)($t['position'] ?? 0), 1), 'num', 'position'),
        ], ["Số liệu $start → $end (Search Console trễ 2–3 ngày)"], ['top' => $top, 'series' => $series, 'period' => [$start, $end]]);
    }

    if ($p === 'gbp') {
        if (!preg_match('#locations/(\d+)$#', $conn['external_account_id'], $mm)) metrics_fail($conn, 'Location ID không hợp lệ');
        $loc = $mm[1];
        $metrics = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'BUSINESS_CONVERSATIONS'];
        [$y1, $m1, $d1] = explode('-', $since); [$y2, $m2, $d2] = explode('-', date('Y-m-d', strtotime('-1 day')));
        $qs = implode('&', array_map(fn($x) => 'dailyMetrics=' . $x, $metrics)) . '&' . http_build_query([
            'dailyRange.start_date.year' => $y1, 'dailyRange.start_date.month' => (int)$m1, 'dailyRange.start_date.day' => (int)$d1,
            'dailyRange.end_date.year' => $y2, 'dailyRange.end_date.month' => (int)$m2, 'dailyRange.end_date.day' => (int)$d2]);
        [$code, $d] = http_get_json("https://businessprofileperformance.googleapis.com/v1/locations/$loc:fetchMultiDailyMetricsTimeSeries?$qs", $auth);
        if ($code !== 200) {
            $msg = $d['error']['message'] ?? "HTTP $code";
            if ($code === 403) $msg .= ' — bật Business Profile Performance API trong Google Cloud (project phải được Google duyệt quyền Business Profile API), token cần quyền business.manage';
            metrics_fail($conn, 'Google Business Profile: ' . $msg);
        }
        $sum = array_fill_keys($metrics, 0);
        foreach ($d['multiDailyMetricTimeSeries'] ?? [] as $grp) foreach ($grp['dailyMetricTimeSeries'] ?? [] as $ts) {
            foreach ($ts['timeSeries']['datedValues'] ?? [] as $v) $sum[$ts['dailyMetric']] = ($sum[$ts['dailyMetric']] ?? 0) + (int)($v['value'] ?? 0);
        }
        $views = $sum['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] + $sum['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] + $sum['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] + $sum['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'];
        $items = [
            mitem('Lượt xem (Maps + Search)', $views, 'num', 'views'),
            mitem('Xem trên Google Maps', $sum['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] + $sum['BUSINESS_IMPRESSIONS_MOBILE_MAPS'], 'num', 'views_maps'),
            mitem('Cuộc gọi', $sum['CALL_CLICKS'], 'num', 'calls'),
            mitem('Click vào website', $sum['WEBSITE_CLICKS'], 'num', 'website_clicks'),
            mitem('Yêu cầu chỉ đường', $sum['BUSINESS_DIRECTION_REQUESTS'], 'num', 'directions'),
            mitem('Tin nhắn', $sum['BUSINESS_CONVERSATIONS'], 'num', 'messages'),
        ];
        $notes = [];
        // Điểm & số đánh giá (API v4) — cần biết tài khoản; chưa có thì dò trong các tài khoản token quản lý.
        $rv = gbp_reviews($conn, $auth, 1);
        if ($rv['ok']) { $items[] = mitem('Điểm đánh giá', round((float)$rv['rating'], 1), 'num', 'rating'); $items[] = mitem('Số đánh giá', (int)$rv['total'], 'num', 'reviews'); }
        else $notes[] = 'Đánh giá: ' . $rv['error'];
        return metrics_save($conn, $items, $notes);
    }

    if ($p === 'youtube') {
        [$code, $ch] = http_get_json('https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&mine=true', $auth);
        if ($code !== 200 && !$ytOauth) metrics_fail($conn, 'YouTube: ' . ($ch['error']['message'] ?? "HTTP $code"));
        $stat = null;
        foreach ($ch['items'] ?? [] as $c) if ($c['id'] === $conn['external_account_id']) $stat = $c['statistics'] ?? [];
        if ($stat === null && $ytOauth) {
            // Token chỉ có quyền đăng video (youtube.upload) hoặc thuộc tài khoản quản lý nhiều kênh: hỏi thẳng theo Channel ID,
            // cuối cùng dùng API key nếu có.
            [$c2, $d2] = http_get_json('https://www.googleapis.com/youtube/v3/channels?' . http_build_query(['part' => 'statistics', 'id' => $conn['external_account_id']]), $auth);
            $stat = $c2 === 200 ? ($d2['items'][0]['statistics'] ?? null) : null;
            $key = $conn['secret'] ?: ($CFG['youtube_api_key'] ?? '');
            if ($stat === null && $key) {
                [$c3, $d3] = http_get_json('https://www.googleapis.com/youtube/v3/channels?' . http_build_query(['part' => 'statistics', 'id' => $conn['external_account_id'], 'key' => $key]));
                $stat = $c3 === 200 ? ($d3['items'][0]['statistics'] ?? null) : null;
            }
            if ($stat === null) metrics_fail($conn, 'YouTube: ' . ($ch['error']['message'] ?? $d2['error']['message'] ?? 'token không đọc được kênh này') . ' — token cần quyền youtube.readonly hoặc youtube.force-ssl (hoặc thêm API key)');
        }
        if ($stat === null) metrics_fail($conn, 'Tài khoản Google đã đăng nhập không sở hữu kênh YouTube này — kết nối lại và chọn đúng kênh');

        [$code, $an] = http_get_json('https://youtubeanalytics.googleapis.com/v2/reports?' . http_build_query([
            // Kênh có OAuth riêng: hỏi đúng Channel ID (token có thể thuộc tài khoản quản lý nhiều kênh, MINE sẽ trỏ nhầm kênh).
            'ids' => $ytOauth ? 'channel==' . $conn['external_account_id'] : 'channel==MINE', 'startDate' => $since, 'endDate' => $until,
            'metrics' => 'views,estimatedMinutesWatched,subscribersGained,subscribersLost,likes,comments,shares',
        ]), $auth);
        $row = $code === 200 ? ($an['rows'][0] ?? []) : [];
        $notes = $code === 200 ? [] : ['YouTube Analytics: ' . ($an['error']['message'] ?? "HTTP $code") . ' — số 30 ngày cần token có quyền yt-analytics.readonly'];
        $items = [
            mitem('Người đăng ký', (int)($stat['subscriberCount'] ?? 0), 'num', 'subscribers'),
            mitem('Tổng lượt xem', (int)($stat['viewCount'] ?? 0), 'num', 'views_total'),
            mitem('Số video', (int)($stat['videoCount'] ?? 0), 'num', 'videos'),
        ];
        $ytList = yt_recent_videos($conn['external_account_id'], $auth, $conn['secret'] ?: ($CFG['youtube_api_key'] ?? ''));
        $ex = ['content' => $ytList, 'content_kind' => 'video'];
        if ($code !== 200) return metrics_save($conn, $items, $notes, $ex); // không có số 30 ngày thì không ghi 0 giả
        return metrics_save($conn, [...$items,
            mitem('Lượt xem 30 ngày', (int)($row[0] ?? 0), 'num', 'views'),
            mitem('Phút xem 30 ngày', (int)($row[1] ?? 0), 'num', 'watch_minutes'),
            mitem('Đăng ký mới (ròng)', (int)($row[2] ?? 0) - (int)($row[3] ?? 0), 'num', 'subscribers_net'),
            mitem('Lượt thích', (int)($row[4] ?? 0), 'num', 'likes'),
            mitem('Bình luận', (int)($row[5] ?? 0), 'num', 'comments'),
            mitem('Chia sẻ', (int)($row[6] ?? 0), 'num', 'shares'),
        ], $notes, $ex);
    }

    if ($p === 'google_ads') {
        if (!$CFG['google_ads_developer_token']) metrics_fail($conn, 'Cần Google Ads Developer Token đã được duyệt (điền google_ads_developer_token trong config.local.php)');
        $headers = array_merge($auth, ['developer-token: ' . $CFG['google_ads_developer_token']]);
        if ($CFG['google_ads_login_customer_id']) $headers[] = 'login-customer-id: ' . str_replace('-', '', $CFG['google_ads_login_customer_id']);
        [$code, $d] = http_call('POST', 'https://googleads.googleapis.com/' . $CFG['google_ads_api_version'] . '/customers/'
            . str_replace('-', '', $conn['external_account_id']) . '/googleAds:search', [
            'headers' => $headers,
            'json' => ['query' => 'SELECT metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM customer WHERE segments.date DURING LAST_30_DAYS'],
        ]);
        if ($code !== 200) metrics_fail($conn, 'Google Ads: ' . ($d['error']['message'] ?? "HTTP $code"));
        $cost = $imp = $clk = 0; $conv = 0.0;
        foreach ($d['results'] ?? [] as $r) {
            $cost += (int)($r['metrics']['costMicros'] ?? 0);
            $imp += (int)($r['metrics']['impressions'] ?? 0);
            $clk += (int)($r['metrics']['clicks'] ?? 0);
            $conv += (float)($r['metrics']['conversions'] ?? 0);
        }
        return metrics_save($conn, [
            mitem('Chi tiêu', (int)round($cost / 1e6), 'money', 'spend'),
            mitem('Hiển thị', $imp, 'num', 'impressions'), mitem('Click', $clk, 'num', 'clicks'),
            mitem('Chuyển đổi', round($conv, 1), 'num', 'conversions'),
            mitem('CPC', $clk ? (int)round($cost / 1e6 / $clk) : 0, 'money', 'cpc'),
        ]);
    }

    if ($p === 'facebook_page') {
        // Nhập tay: token dán vào chính là Page Access Token. OAuth: token người dùng → lấy Page token.
        [$code, $pg] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']), [
            'fields' => $manual ? 'name,followers_count,fan_count' : 'name,access_token,followers_count,fan_count',
            'access_token' => $conn['access_token'],
        ]));
        if ($manual && $code === 200) $pg['access_token'] = $conn['access_token'];
        if ($code !== 200 || empty($pg['access_token'])) {
            metrics_fail($conn, 'Fanpage: ' . ($pg['error']['message'] ?? 'tài khoản đã đăng nhập không quản trị Page này'));
        }
        $items = [mitem('Người theo dõi', (int)($pg['followers_count'] ?? 0), 'num', 'followers'),
            mitem('Lượt thích Page', (int)($pg['fan_count'] ?? 0), 'num', 'fans')];
        $notes = [];
        // Meta thay đổi/ngừng một số chỉ số Page Insights theo từng phiên bản — hỏi từng chỉ số để một chỉ số lỗi không làm hỏng cả lần đồng bộ.
        $metrics = ['page_impressions_unique' => ['Người tiếp cận', 'reach'], 'page_post_engagements' => ['Tương tác bài viết', 'engagements'],
            'page_daily_follows_unique' => ['Theo dõi mới', 'new_follows'], 'page_media_view' => ['Lượt xem nội dung', 'media_views']];
        foreach ($metrics as $metric => [$label, $key]) {
            [$c, $ins] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']) . '/insights', [
                'metric' => $metric, 'period' => 'day', 'since' => $since, 'until' => $until, 'access_token' => $pg['access_token'],
            ]));
            if ($c !== 200) { $notes[] = "$label: " . ($ins['error']['message'] ?? "HTTP $c"); continue; }
            $sum = 0;
            foreach ($ins['data'][0]['values'] ?? [] as $v) $sum += is_numeric($v['value'] ?? null) ? (int)$v['value'] : 0;
            $items[] = mitem($label . ' (30 ngày)', $sum, 'num', $key);
        }
        [$c, $po] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']) . '/posts', [
            'fields' => 'id,message,created_time,permalink_url,full_picture,shares,reactions.summary(true).limit(0),comments.summary(true).limit(0)',
            'limit' => 25, 'access_token' => $pg['access_token']]));
        $list = [];
        if ($c === 200) foreach ($po['data'] ?? [] as $x) $list[] = ['id' => $x['id'], 'title' => mb_substr(trim((string)($x['message'] ?? '')), 0, 200),
            'thumb' => $x['full_picture'] ?? null, 'url' => $x['permalink_url'] ?? null, 'at' => gmdate('Y-m-d H:i:s', strtotime($x['created_time'] ?? 'now')),
            'views' => null, 'likes' => (int)($x['reactions']['summary']['total_count'] ?? 0), 'comments' => (int)($x['comments']['summary']['total_count'] ?? 0),
            'shares' => (int)($x['shares']['count'] ?? 0)];
        else $notes[] = 'Danh sách bài: ' . ($po['error']['message'] ?? "HTTP $c");
        return metrics_save($conn, $items, $notes, ['content' => $list, 'content_kind' => 'post']);
    }

    if ($p === 'meta_ads') {
        [$code, $d] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']) . '/insights', [
            // 11/10/2026: thêm nhóm bán hàng (Pixel): thêm giỏ, bắt đầu thanh toán, mua hàng, doanh thu, ROAS, CPA — cho chiến dịch Sales.
            'fields' => 'spend,impressions,reach,clicks,actions,action_values,purchase_roas', 'date_preset' => 'last_30d', 'access_token' => $conn['access_token'],
        ]));
        if ($code !== 200) metrics_fail($conn, 'Meta Ads: ' . ($d['error']['message'] ?? "HTTP $code"));
        $r = $d['data'][0] ?? [];
        $leads = 0; $atc = 0; $ic = 0; $pur = 0; $rev = 0.0;
        foreach ($r['actions'] ?? [] as $a) {
            $t = (string)$a['action_type']; $v = (int)$a['value'];
            if (str_contains($t, 'lead')) $leads += $v;
            elseif ($t === 'add_to_cart' || $t === 'omni_add_to_cart') $atc = max($atc, $v);
            elseif ($t === 'initiate_checkout' || $t === 'omni_initiated_checkout') $ic = max($ic, $v);
            elseif ($t === 'purchase' || $t === 'omni_purchase') $pur = max($pur, $v);
        }
        foreach ($r['action_values'] ?? [] as $a) if (in_array((string)$a['action_type'], ['purchase', 'omni_purchase'], true)) $rev = max($rev, (float)$a['value']);
        $roas = 0.0; foreach ($r['purchase_roas'] ?? [] as $a) $roas = max($roas, (float)($a['value'] ?? 0));
        if (!$roas && $rev > 0 && (float)($r['spend'] ?? 0) > 0) $roas = $rev / (float)$r['spend'];
        $spend = (float)($r['spend'] ?? 0);
        $notes = [];
        if (!$pur && !$atc) $notes[] = 'Chưa có sự kiện Pixel (AddToCart/Purchase) trong 30 ngày — kiểm tra Pixel + Conversions API trên website';
        return metrics_save($conn, [
            mitem('Chi tiêu', (int)round($spend), 'money', 'spend'),
            mitem('Hiển thị', (int)($r['impressions'] ?? 0), 'num', 'impressions'),
            mitem('Tiếp cận', (int)($r['reach'] ?? 0), 'num', 'reach'),
            mitem('Click', (int)($r['clicks'] ?? 0), 'num', 'clicks'),
            mitem('Thêm giỏ hàng', $atc, 'num', 'add_to_cart'),
            mitem('Bắt đầu thanh toán', $ic, 'num', 'initiate_checkout'),
            mitem('Mua hàng (Purchase)', $pur, 'num', 'purchases'),
            mitem('Doanh thu theo Pixel', (int)round($rev), 'money', 'revenue'),
            mitem('ROAS', round($roas, 2), 'num', 'roas'),
            mitem('CPA mua hàng', $pur ? (int)round($spend / $pur) : 0, 'money', 'cpa'),
            mitem('Lead', $leads, 'num', 'leads'),
            mitem('CPL', $leads ? (int)round($spend / $leads) : 0, 'money', 'cpl'),
        ], $notes);
    }

    throw new SyncError('Loại kênh không hỗ trợ', 400);
}

/** Gợi ý khi TikTok báo thiếu quyền (scope) — kênh được kết nối trước khi app được cấp quyền đó, hoặc người dùng không tick. */
function tiktok_scope_hint(?array $d): string {
    return ($d['error']['code'] ?? '') === 'scope_not_authorized' ? ' — kết nối lại kênh và cho phép đủ quyền' : '';
}

/** TikTok (Display API). Tài khoản: người theo dõi, tổng lượt thích, số video. Video: TikTok chỉ trả số cộng dồn của từng video,
 *  nên "30 ngày" ở đây là tổng số HIỆN TẠI của các video đăng trong 30 ngày qua (không phải mức tăng trong 30 ngày).
 *  Thiếu quyền video.list chỉ làm mất nhóm số liệu video (ghi chú), không làm hỏng cả lần đồng bộ. */
function sync_tiktok(array $CFG, array $conn): array {
    [$token, $tokErr] = tiktok_access_token($CFG, $conn);
    if (!$token) metrics_fail($conn, $tokErr ?? 'Token TikTok hết hạn — hãy kết nối lại');
    $auth = ['Authorization: Bearer ' . $token];

    // Chỉ hỏi những trường mà quyền đã cấp cho phép (user.info.basic: tên/ảnh; user.info.stats: theo dõi/thích/số video).
    $sc = array_filter(explode(',', (string)($conn['scopes'] ?? '')));
    $hasStats = !$sc || in_array('user.info.stats', $sc, true); // kết nối cũ chưa ghi scopes → thử như trước
    $fields = 'open_id,display_name,avatar_url' . ($hasStats ? ',follower_count,likes_count,video_count' : '');
    [$code, $d] = http_get_json(tiktok_url($CFG, '/v2/user/info/?fields=' . $fields), $auth);
    $err = tiktok_error($code, $d);
    $u = $d['data']['user'] ?? null;
    if ($err !== null || !is_array($u)) metrics_fail($conn, 'TikTok: ' . ($err ?? 'không đọc được thông tin tài khoản') . tiktok_scope_hint($d));
    if (!empty($u['display_name'])) { global $pdo; $pdo->prepare('UPDATE connections SET account_name=?, avatar=COALESCE(?, avatar) WHERE id=?')->execute([(string)$u['display_name'], $u['avatar_url'] ?? null, $conn['id']]); }
    $items = [];
    $notes = [];
    if ($hasStats && isset($u['follower_count'])) {
        $items[] = mitem('Người theo dõi', (int)($u['follower_count'] ?? 0), 'num', 'followers');
        $items[] = mitem('Tổng lượt thích', (int)($u['likes_count'] ?? 0), 'num', 'likes_total');
        $items[] = mitem('Số video', (int)($u['video_count'] ?? 0), 'num', 'videos');
    } else $notes[] = 'Chưa có quyền user.info.stats (người theo dõi, tổng thích) — thêm scope trong app TikTok rồi kết nối lại nếu cần';
    if ($sc && !in_array('video.list', $sc, true)) return metrics_save($conn, $items, array_merge($notes, ['Chưa có quyền video.list — không đọc được danh sách video']));

    $since = time() - 30 * 86400;
    $n = $views = $likes = $comments = $shares = 0;
    $cursor = null; $more = false; $failed = false; $list = [];
    for ($page = 0; $page < TIKTOK_VIDEO_PAGES; $page++) {
        $body = ['max_count' => 20];
        if ($cursor !== null) $body['cursor'] = $cursor;
        [$c, $v] = http_call('POST', tiktok_url($CFG, '/v2/video/list/?fields=id,create_time,view_count,like_count,comment_count,share_count,title,video_description,cover_image_url,share_url,duration'),
            ['headers' => $auth, 'json' => $body]);
        $verr = tiktok_error($c, $v);
        if ($verr !== null) { $notes[] = 'Video TikTok: ' . $verr . tiktok_scope_hint($v); $failed = true; break; }
        $vids = $v['data']['videos'] ?? [];
        $old = false;
        foreach ($vids as $vid) {
            if (count($list) < 50) $list[] = ['id' => (string)$vid['id'], 'title' => trim((string)(($vid['title'] ?? '') ?: ($vid['video_description'] ?? ''))),
                'thumb' => $vid['cover_image_url'] ?? null, 'url' => $vid['share_url'] ?? null, 'at' => gmdate('Y-m-d H:i:s', (int)($vid['create_time'] ?? 0)),
                'dur' => (int)($vid['duration'] ?? 0), 'views' => (int)($vid['view_count'] ?? 0), 'likes' => (int)($vid['like_count'] ?? 0),
                'comments' => (int)($vid['comment_count'] ?? 0), 'shares' => (int)($vid['share_count'] ?? 0)];
            if ((int)($vid['create_time'] ?? 0) < $since) { $old = true; continue; } // API trả mới → cũ: gặp video cũ là hết video trong 30 ngày
            $n++;
            $views += (int)($vid['view_count'] ?? 0); $likes += (int)($vid['like_count'] ?? 0);
            $comments += (int)($vid['comment_count'] ?? 0); $shares += (int)($vid['share_count'] ?? 0);
        }
        $more = !empty($v['data']['has_more']) && isset($v['data']['cursor']);
        if (($old && count($list) >= 50) || !$vids || !$more) { $more = $more && !$old; break; }
        $cursor = $v['data']['cursor'];
    }
    if ($more) $notes[] = 'Chỉ đếm ' . (TIKTOK_VIDEO_PAGES * 20) . ' video mới nhất — số liệu video 30 ngày có thể thấp hơn thực tế';
    if (!$failed) {
        array_push($items,
            mitem('Video đăng 30 ngày', $n, 'num', 'videos_30d'),
            mitem('Lượt xem (video 30 ngày)', $views, 'num', 'views'),
            mitem('Lượt thích (video 30 ngày)', $likes, 'num', 'likes'),
            mitem('Bình luận (video 30 ngày)', $comments, 'num', 'comments'),
            mitem('Chia sẻ (video 30 ngày)', $shares, 'num', 'shares'));
    }
    return metrics_save($conn, $items, $notes, ['content' => $list, 'content_kind' => 'video']);
}

/** Giá trị chỉ số chính trong một bản metrics (ưu tiên `key`, bản cũ không có key thì dò theo nhãn). */
/* ---- X (Twitter) 12/10/2026: GET /2/users/me với public_metrics — gói Free không đọc được timeline/engagement nên chỉ có số tài khoản. ---- */
function sync_x(array $CFG, array $conn): array {
    [$token, $tokErr] = x_access_token($CFG, $conn);
    if (!$token) metrics_fail($conn, $tokErr ?? 'Token X hết hạn — hãy kết nối lại');
    [$code, $d] = http_get_json(X_API_BASE . '/2/users/me?user.fields=profile_image_url,public_metrics,username,name', ['Authorization: Bearer ' . $token]);
    $err = x_error($code, $d);
    $u = $d['data'] ?? null;
    if ($err !== null || !is_array($u) || empty($u['id'])) metrics_fail($conn, 'X: ' . ($err ?? 'không đọc được thông tin tài khoản') . ($code === 403 ? ' (app X thiếu quyền users.read hoặc bị hạn chế)' : ''));
    if (!empty($u['username'])) {
        global $pdo;
        $av = str_replace('_normal.', '_400x400.', (string)($u['profile_image_url'] ?? ''));
        $pdo->prepare("UPDATE connections SET account_name=?, avatar=COALESCE(NULLIF(?, ''), avatar) WHERE id=?")->execute([(string)$u['username'], $av, $conn['id']]);
    }
    $pm = $u['public_metrics'] ?? [];
    $items = [
        mitem('Người theo dõi', (int)($pm['followers_count'] ?? 0), 'num', 'followers'),
        mitem('Đang theo dõi', (int)($pm['following_count'] ?? 0), 'num', 'following'),
        mitem('Số bài (tweet)', (int)($pm['tweet_count'] ?? $pm['post_count'] ?? 0), 'num', 'tweets'), // tài liệu mới gọi là post_count, JSON thực tế vẫn là tweet_count
        mitem('Tổng lượt thích đã bấm', (int)($pm['like_count'] ?? 0), 'num', 'likes_total'),
    ];
    $notes = ['Số liệu tài khoản tại thời điểm đồng bộ (X API gói Free không cho đọc lượt xem/tương tác từng bài)'];
    return metrics_save($conn, $items, $notes);
}

function metric_key_value(string $provider, array $m): ?float {
    $def = METRIC_KEYS[$provider] ?? null;
    if (!$def) return null;
    $fallbackLabel = ['followers' => 'Người theo dõi', 'subscribers' => 'Người đăng ký', 'sessions' => 'Phiên', 'spend' => 'Chi tiêu'][$def['key']] ?? "\0";
    foreach ($m['items'] ?? [] as $it) {
        if (($it['key'] ?? null) === $def['key'] || (!isset($it['key']) && ($it['label'] ?? '') === $fallbackLabel)) {
            return is_numeric($it['value'] ?? null) ? (float)$it['value'] : null;
        }
    }
    return null;
}

/** Đánh giá Google Business Profile (API v4). Trả về ['ok','rating','total','reviews','resource'] hoặc ['ok'=>false,'error'].
 *  external_account_id chỉ có 'locations/…' thì dò tài khoản sở hữu rồi lưu lại dạng 'accounts/…/locations/…'. */
function gbp_reviews(array $conn, array $auth, int $pageSize = 50): array {
    global $pdo;
    $ext = $conn['external_account_id'];
    $cands = [];
    if (preg_match('#^accounts/\d+/locations/\d+$#', $ext)) $cands[] = $ext;
    else {
        [$c, $d] = http_get_json('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', $auth);
        foreach ($d['accounts'] ?? [] as $a) $cands[] = $a['name'] . '/' . $ext;
        if (!$cands) return ['ok' => false, 'error' => 'không đọc được danh sách tài khoản Business Profile (' . ($d['error']['message'] ?? "HTTP $c") . ')'];
    }
    $last = '';
    foreach ($cands as $res) {
        [$c, $d] = http_get_json("https://mybusiness.googleapis.com/v4/$res/reviews?pageSize=$pageSize", $auth);
        if ($c === 200) {
            if ($res !== $ext) $pdo->prepare('UPDATE connections SET external_account_id=? WHERE id=?')->execute([$res, $conn['id']]);
            return ['ok' => true, 'rating' => $d['averageRating'] ?? 0, 'total' => $d['totalReviewCount'] ?? 0, 'reviews' => $d['reviews'] ?? [], 'resource' => $res];
        }
        $last = $d['error']['message'] ?? "HTTP $c";
    }
    return ['ok' => false, 'error' => $last . ' — cần bật Google My Business API (v4) cho project'];
}

/** 30 video mới nhất của kênh YouTube (playlist "uploads") kèm số liệu từng video. $auth OAuth hoặc rỗng + $key. */
function yt_recent_videos(string $channelId, array $auth, ?string $key): array {
    $q = fn(string $path, array $params) => http_get_json('https://www.googleapis.com/youtube/v3/' . $path . '?' . http_build_query($params + ($auth ? [] : ['key' => (string)$key])), $auth);
    if (!$auth && !$key) return [];
    [$c, $d] = $q('channels', ['part' => 'contentDetails', 'id' => $channelId]);
    $pl = $d['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    if ($c !== 200 || !$pl) return [];
    [$c, $d] = $q('playlistItems', ['part' => 'contentDetails', 'playlistId' => $pl, 'maxResults' => 30]);
    $ids = array_column(array_column($d['items'] ?? [], 'contentDetails'), 'videoId');
    if (!$ids) return [];
    [$c, $d] = $q('videos', ['part' => 'snippet,statistics,contentDetails', 'id' => implode(',', $ids), 'maxResults' => 50]);
    $out = [];
    foreach ($d['items'] ?? [] as $v) {
        $dur = 0;
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $v['contentDetails']['duration'] ?? '', $m)) $dur = (int)($m[1] ?? 0) * 3600 + (int)($m[2] ?? 0) * 60 + (int)($m[3] ?? 0);
        $out[] = ['id' => $v['id'], 'title' => $v['snippet']['title'] ?? '', 'thumb' => $v['snippet']['thumbnails']['medium']['url'] ?? ($v['snippet']['thumbnails']['default']['url'] ?? null),
            'url' => 'https://www.youtube.com/watch?v=' . $v['id'], 'at' => gmdate('Y-m-d H:i:s', strtotime($v['snippet']['publishedAt'] ?? 'now')), 'dur' => $dur,
            'views' => (int)($v['statistics']['viewCount'] ?? 0), 'likes' => (int)($v['statistics']['likeCount'] ?? 0),
            'comments' => (int)($v['statistics']['commentCount'] ?? 0), 'shares' => null];
    }
    usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $out;
}
