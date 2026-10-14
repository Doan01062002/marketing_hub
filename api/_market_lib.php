<?php
/* Dữ liệu thị trường dùng chung cho api/trends.php và cron/run.php (cập nhật hằng tuần). Yêu cầu đã require _boot.php. */
/* Google Trends qua SerpApi (Google không có API Trends chính thức). Khoá: Thiết lập > Nguồn dữ liệu thị trường. */
function serpapi_trends(array $params): array {
    $key = setting_get('data.serpapi_key');
    if (!$key) throw new RuntimeException('Chưa có khoá SerpApi — quản trị nhập ở Thiết lập → Nguồn dữ liệu thị trường (serpapi.com, gói miễn phí 100 lượt/tháng)');
    [$c, $d] = http_call('GET', 'https://serpapi.com/search.json?' . http_build_query($params + [
        'engine' => 'google_trends', 'geo' => setting_get('data.trends_geo', 'VN'), 'hl' => 'vi', 'api_key' => $key]));
    if ($c !== 200 || !empty($d['error'])) throw new RuntimeException('SerpApi: ' . ($d['error'] ?? "HTTP $c"));
    return $d;
}

/** Kéo độ quan tâm 3 tháng (theo tuần) cho một xu hướng và cập nhật % thay đổi (4 tuần gần nhất so với 4 tuần trước đó). */
function trend_refresh(array $row): array {
    global $pdo;
    $q = trim(preg_replace('/["“”]/u', '', $row['name']));
    $d = serpapi_trends(['q' => $q, 'date' => 'today 3-m', 'data_type' => 'TIMESERIES']);
    $pts = array_map(fn($t) => (int)($t['values'][0]['extracted_value'] ?? 0), $d['interest_over_time']['timeline_data'] ?? []);
    if (!$pts) return ['id' => $row['id'], 'ok' => false, 'error' => 'Google Trends không có dữ liệu cho "' . $q . '" (lượng tìm kiếm quá thấp)'];
    $last = array_slice($pts, -4); $prev = array_slice($pts, -8, 4);
    $a = array_sum($last) / max(1, count($last)); $b = array_sum($prev) / max(1, count($prev));
    $chg = $b > 0 ? round(($a - $b) / $b * 100) : ($a > 0 ? 100 : 0);
    $vol = ($chg > 0 ? '+' : '') . $chg . '%';
    $pdo->prepare("UPDATE trends SET volume_change=?, direction=?, data_points=?, note=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$vol, $chg >= 0 ? 1 : -1, json_encode(array_slice($pts, -13)), 'Google Trends ' . setting_get('data.trends_geo', 'VN') . ' · 3 tháng · cập nhật ' . date('d/m'), $row['id']]);
    return ['id' => $row['id'], 'ok' => true, 'vol' => $vol];
}


/* ---------------- Theo dõi website đối thủ ----------------
   Mỗi lần kiểm tra: đọc trang chủ (tiêu đề, mô tả, H1, số chữ, số điện thoại, nền tảng) + sitemap (số URL, URL mới so với lần trước).
   So với lần trước → ghi "động thái" (trang mới, đổi tiêu đề / mô tả) vào đối thủ. */
function comp_fetch(string $url, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 MarketingHub-monitor']);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$code, (string)$body, $final];
}
function comp_sitemap_urls(string $base): array {
    $urls = []; $queue = [rtrim($base, '/') . '/sitemap.xml', rtrim($base, '/') . '/sitemap_index.xml', rtrim($base, '/') . '/wp-sitemap.xml']; $seen = [];
    [$c, $robots] = comp_fetch(rtrim($base, '/') . '/robots.txt', 10);
    if ($c === 200 && preg_match_all('/^\s*Sitemap:\s*(\S+)/mi', $robots, $m)) $queue = array_merge($m[1], $queue);
    $n = 0;
    while ($queue && $n < 12 && count($urls) < 5000) {
        $sm = array_shift($queue); if (isset($seen[$sm])) continue; $seen[$sm] = 1; $n++;
        [$c, $x] = comp_fetch($sm, 15);
        if ($c !== 200 || !str_contains($x, '<loc>')) continue;
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $x, $m);
        if (stripos($x, '<sitemapindex') !== false) { foreach ($m[1] as $u) $queue[] = html_entity_decode($u); }
        else foreach ($m[1] as $u) $urls[html_entity_decode($u)] = 1;
        if ($urls && stripos($x, '<sitemapindex') === false && count($seen) >= 1 && $n >= 8) break;
    }
    return array_keys($urls);
}
function comp_web_check(string $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM competitors WHERE id=?'); $st->execute([$id]); $c = $st->fetch();
    if (!$c || !$c['website']) throw new RuntimeException('Đối thủ chưa có website');
    [$code, $html, $final] = comp_fetch($c['website']);
    if ($code < 200 || $code >= 400 || $html === '') throw new RuntimeException("Website trả HTTP $code");
    $g = function (string $re) use ($html) { return preg_match($re, $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : ''; };
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html)), ENT_QUOTES, 'UTF-8')));
    preg_match_all('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', $text, $ph);
    $tech = [];
    foreach (['wp-content' => 'WordPress', 'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'haravan' => 'Haravan', 'sapo' => 'Sapo', 'gtag(' => 'Google Analytics', 'googletagmanager' => 'Google Tag Manager', 'fbq(' => 'Facebook Pixel', 'zalo' => 'Zalo chat', 'tawk.to' => 'Tawk chat', 'messenger' => 'Messenger chat', 'analytics.tiktok' => 'TikTok Pixel'] as $k => $v) if (stripos($html, $k) !== false) $tech[] = $v;
    $base = preg_replace('#^(https?://[^/]+).*$#', '$1', $final ?: $c['website']);
    $urls = comp_sitemap_urls($base);
    $old = jdecode($c['web_snapshot']);
    $oldSet = array_flip($old['all_urls'] ?? []);
    $new = $old ? array_values(array_filter($urls, fn($u) => !isset($oldSet[$u]))) : [];
    $snap = ['title' => mb_substr($g('#<title[^>]*>(.*?)</title>#is'), 0, 200), 'desc' => mb_substr($g('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i') ?: $g('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description#i'), 0, 300),
        'h1' => mb_substr($g('#<h1[^>]*>(.*?)</h1>#is'), 0, 200), 'words' => count(preg_split('/\s+/u', $text)), 'phones' => array_values(array_unique(array_map(fn($p) => preg_replace('/\D/', '', $p), array_slice($ph[0], 0, 10)))),
        'tech' => $tech, 'urls' => count($urls), 'new_urls' => array_slice($new, 0, 30), 'all_urls' => array_slice($urls, 0, 5000)];
    $chg = [];
    if ($old) {
        if ($new) $chg[] = count($new) . ' trang mới (vd ' . implode(', ', array_map(fn($u) => parse_url($u, PHP_URL_PATH) ?: $u, array_slice($new, 0, 3))) . ')';
        if (($old['title'] ?? '') !== $snap['title']) $chg[] = 'đổi tiêu đề trang chủ: "' . $snap['title'] . '"';
        if (($old['desc'] ?? '') !== $snap['desc'] && $snap['desc'] !== '') $chg[] = 'đổi mô tả SEO';
        $gone = count($old['all_urls'] ?? []) - (count($urls) - count($new));
        if ($gone > 0) $chg[] = "$gone trang bị gỡ";
    }
    $change = $old ? ($chg ? implode('; ', $chg) : 'Không thay đổi so với lần trước') : 'Lần đầu ghi nhận: ' . count($urls) . ' trang trong sitemap';
    $pdo->prepare("UPDATE competitors SET web_snapshot=?, web_checked_at=datetime('now'), web_change=?" . ($chg ? ", latest_move=?" : '') . ", updated_at=datetime('now') WHERE id=?")
        ->execute(array_merge([json_encode($snap, JSON_UNESCAPED_UNICODE), $change], $chg ? ['Web: ' . mb_substr(implode('; ', $chg), 0, 250)] : [], [$id]));
    if ($chg && function_exists('notify')) notify(null, 'Đối thủ ' . $c['name'] . ' vừa thay đổi website', implode("\n", $chg), 'research');
    return ['change' => $change, 'urls' => count($urls), 'new' => count($new)];
}

/** Cron: cập nhật mọi xu hướng (tối đa 20) 1 lần/tuần nếu có khoá SerpApi. */
function market_weekly(): string {
    global $pdo;
    $out = [];
    if (setting_get('data.serpapi_key')) {
        $n = 0; $bad = 0;
        foreach ($pdo->query('SELECT * FROM trends ORDER BY updated_at LIMIT 20')->fetchAll() as $r) {
            try { trend_refresh($r)['ok'] ? $n++ : $bad++; } catch (Throwable $e) { $bad++; }
        }
        $out[] = "Google Trends: $n xu hướng cập nhật" . ($bad ? ", $bad lỗi" : '');
    }
    $ids = array_column($pdo->query("SELECT id FROM competitors WHERE COALESCE(website,'')<>''")->fetchAll(), 'id');
    if ($ids) { $ok = 0; foreach ($ids as $id) { try { comp_web_check($id); $ok++; } catch (Throwable $e) {} } $out[] = "Website đối thủ: $ok/" . count($ids) . ' đã kiểm tra'; }
    return $out ? implode('; ', $out) : 'chưa có khoá SerpApi / website đối thủ — bỏ qua';
}
