<?php
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM competitors WHERE project_id=? ORDER BY share_of_voice DESC'); $st->execute([pid()]); $rows = $st->fetchAll();
    $competitors = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'sov' => (int)$r['share_of_voice'], 'spendEst' => $r['spend_estimate'],
        'move' => $r['latest_move'], 'threat' => $r['threat'],
        'website' => $r['website'] ?? null, 'web' => ($r['web_checked_at'] ?? null) ? ['at' => $r['web_checked_at'], 'change' => $r['web_change'], 'snap' => array_intersect_key(jdecode($r['web_snapshot']), array_flip(['title', 'desc', 'urls', 'new_urls', 'words', 'phones', 'tech']))] : null,
        'place' => $r['place_id'] ? ['id' => $r['place_id'], 'name' => $r['place_name'], 'rating' => $r['place_rating'] !== null ? (float)$r['place_rating'] : null, 'reviews' => (int)$r['place_reviews'], 'address' => $r['place_address'], 'url' => $r['place_url'], 'at' => $r['place_synced_at']] : null,
        'teardowns' => array_values(array_filter(jdecode($r['teardowns'] ?? null), 'is_array')),
    ], $rows);
    respond(['competitors' => $competitors]);
}

/* Google Maps (Places API mới): tìm địa điểm của đối thủ, gắn vào đối thủ, cập nhật điểm & số đánh giá.
   Khoá: Thiết lập > Nguồn dữ liệu thị trường (Google Cloud → bật "Places API (New)" → tạo API key; có tính phí theo lượt, kèm hạn mức miễn phí). */
function places_call(string $method, string $path, string $mask, ?array $body = null): array {
    $key = setting_get('data.places_key');
    if (!$key) fail('Chưa có khoá Google Places API — quản trị nhập ở Thiết lập → Nguồn dữ liệu thị trường', 400);
    $opts = ['headers' => ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $mask]];
    if ($body !== null) $opts['json'] = $body;
    [$c, $d] = http_call($method, 'https://places.googleapis.com/v1/' . $path, $opts);
    if ($c !== 200) fail('Google Places: ' . ($d['error']['message'] ?? "HTTP $c"), 502);
    return $d;
}
function place_save(string $cid, array $p): void {
    global $pdo;
    $pdo->prepare("UPDATE competitors SET place_id=?, place_name=?, place_rating=?, place_reviews=?, place_address=?, place_url=?, place_synced_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
        ->execute([$p['id'], $p['displayName']['text'] ?? null, $p['rating'] ?? null, $p['userRatingCount'] ?? null, $p['formattedAddress'] ?? null, $p['googleMapsUri'] ?? null, $cid]);
}
const PLACE_FIELDS = 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri';

if (!function_exists('comp_safe_url')) {
    function comp_safe_url(string $url): ?string {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $u = parse_url($url);
        if (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || empty($u['host'])) return null;
        $host = strtolower($u['host']);
        if (in_array($host, ['localhost', 'metadata.google.internal'], true)) return null;
        $ips = @gethostbynamel($host) ?: [];
        if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
        if (!$ips) return null;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
            $long = ip2long($ip);
            if ($long !== false) {
                // Chặn RFC 6598 Carrier-Grade NAT (100.64.0.0/10: 100.64.0.0 - 100.127.255.255)
                if (($long & 0xFFC00000) === 0x64400000) return null;
                // Chặn RFC 2544 Benchmarking (198.18.0.0/15: 198.18.0.0 - 198.19.255.255)
                if (($long & 0xFFFE0000) === 0xC6120000) return null;
            }
        }
        return $url;
    }
}

/* Lấy đối thủ thật từ kết quả Robot "Thứ hạng Google": tên miền đứng top 10 cho nhiều từ khoá dịch vụ nhất.
   Share of Voice = % số vị trí top 10 (trên mọi từ khoá theo dõi) mà tên miền đó chiếm. Bỏ các trang không phải đối thủ (mạng xã hội, sàn, wiki…). */
if ($method === 'POST') require_project_write();
$PID = $method === 'POST' ? pid() : null;

if ($method === 'POST' && (($inA = json_input())['action'] ?? '') === 'import_from_bot') {
    $skip = ['youtube.com', 'facebook.com', 'wikipedia.org', 'vi.wikipedia.org', 'fptshop.com.vn', 'wordpress.org', 'vnnic.vn', 'google.com', 'tiktok.com',
        'shopee.vn', 'lazada.vn', 'tiki.vn', 'reddit.com', 'quora.com', 'thegioididong.com', 'dienmayxanh.com', 'vnexpress.net', 'tuoitre.vn', 'cafef.vn', 'cafebiz.vn'];
    $own = [];
    $jobs = [];
    try { $stJ = $pdo->prepare("SELECT config,last_result FROM bot_jobs WHERE kind='rank' AND last_result IS NOT NULL AND project_id=?"); $stJ->execute([$PID]); $jobs = $stJ->fetchAll(); } catch (Throwable $e) {}
    if (!$jobs) fail('Chưa có kết quả Robot "Thứ hạng Google" — vào Robot duyệt web, tạo việc theo dõi thứ hạng và chạy trước', 400);
    $cnt = []; $kws = []; $slots = 0;
    foreach ($jobs as $j) {
        $own[] = preg_replace('/^www\./', '', strtolower((string)(jdecode($j['config'])['domain'] ?? '')));
        foreach (jdecode($j['last_result'])['rows'] ?? [] as $r) {
            $seen = [];
            foreach (array_slice($r['top'] ?? [], 0, 10) as $t) {
                $slots++;
                $d = preg_replace('/^(www|m)\./', '', strtolower((string)$t['domain']));
                if ($d === '' || isset($seen[$d])) continue;
                $seen[$d] = 1;
                $cnt[$d] = ($cnt[$d] ?? 0) + 1;
                $kws[$d][] = $r['kw'] . ' (#' . $t['pos'] . ')';
            }
        }
    }
    foreach (array_keys($cnt) as $d) foreach (array_merge($skip, $own) as $s) if ($s !== '' && ($d === $s || str_ends_with($d, '.' . $s))) unset($cnt[$d]);
    arsort($cnt);
    $min = max(1, (int)($inA['min'] ?? 2));
    $have = [];
    $stH = $pdo->prepare('SELECT website FROM competitors WHERE project_id=?'); $stH->execute([$PID]);
    foreach ($stH->fetchAll() as $r) $have[preg_replace('#^(https?://)?(www\.)?#', '', rtrim(strtolower((string)$r['website']), '/'))] = 1;
    $added = [];
    foreach (array_slice($cnt, 0, (int)($inA['limit'] ?? 12), true) as $d => $n) {
        if ($n < $min || isset($have[$d])) continue;
        $id = gen_id('CP');
        $pdo->prepare('INSERT INTO competitors(id,project_id,name,share_of_voice,spend_estimate,latest_move,threat,website) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $PID, $d, $slots ? (int)round($n / $slots * 100) : 0, '—', 'Top 10 Google cho ' . $n . ' từ khoá: ' . mb_substr(implode(', ', $kws[$d]), 0, 220), $n >= 3 ? 'Cao' : 'TB', 'https://' . $d]);
        $added[] = $id;
    }
    log_activity('create', 'competitor', null, 'Lấy ' . count($added) . ' đối thủ từ kết quả thứ hạng Google');
    // Đọc website các đối thủ mới sau khi đã trả lời (chạy nền).
    http_response_code(200);
    echo json_encode(['added' => count($added)], JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if ($added) { require_once __DIR__ . '/_market_lib.php'; @set_time_limit(600); ignore_user_abort(true); foreach ($added as $id) { try { comp_web_check($id); } catch (Throwable $e) {} } }
    exit;
}

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['teardown_url', 'teardown_save', 'teardown_delete'], true)) {
    require_once __DIR__ . '/_market_lib.php';
    $act = $inA['action'];
    $cid = trim((string)($inA['competitor_id'] ?? $inA['id'] ?? ''));

    if ($act === 'teardown_delete') {
        $cRow = need('competitors', $cid, 'đối thủ');
        $tdId = trim((string)($inA['td_id'] ?? ''));
        $list = array_values(array_filter(jdecode($cRow['teardowns'] ?? null), fn($x) => is_array($x) && ($x['id'] ?? '') !== $tdId));
        $pdo->prepare("UPDATE competitors SET teardowns=?, updated_at=datetime('now') WHERE id=? AND project_id=?")
            ->execute([json_encode($list, JSON_UNESCAPED_UNICODE), $cid, $PID]);
        log_activity('delete', 'competitor', $cid, 'Xoá phiếu học hỏi đối thủ ' . $cRow['name']);
        respond(['ok' => true]);
    }

    // action === 'teardown_url': Thẩm định URL an toàn (chống SSRF) TRƯỚC KHI tìm hoặc tạo đối thủ mới
    $safeUrl = null;
    if ($act === 'teardown_url') {
        $rawUrl = trim((string)($inA['url'] ?? $inA['source_url'] ?? ''));
        if ($rawUrl === '' && $cid !== '' && $cid !== 'new') {
            $cExisting = need('competitors', $cid, 'đối thủ');
            $rawUrl = trim((string)($cExisting['website'] ?? ''));
        }
        $safeUrl = comp_safe_url($rawUrl);
        if (!$safeUrl) fail('URL cần phân tích không hợp lệ hoặc trỏ tới địa chỉ nội bộ', 400);
    }

    // Nếu chưa chọn đối thủ có sẵn, tự tìm hoặc tạo mới đối thủ theo tên / domain
    $newCompetitorCreatedId = null;
    if ($cid === '' || $cid === 'new') {
        $cName = trim((string)($inA['competitor_name'] ?? ''));
        $rawUrl = trim((string)($inA['url'] ?? $inA['source_url'] ?? ''));
        $dom = $rawUrl !== '' ? preg_replace('/^www\./i', '', strtolower((string)(parse_url(preg_match('#^https?://#i', $rawUrl) ? $rawUrl : 'https://' . $rawUrl, PHP_URL_HOST) ?: ''))) : '';
        if ($cName === '' && $dom !== '') $cName = $dom;
        if ($cName === '') fail('Chọn đối thủ hoặc nhập tên / website đối thủ');
        $stFind = $pdo->prepare('SELECT * FROM competitors WHERE project_id=? AND (LOWER(name)=LOWER(?) OR LOWER(website) LIKE ?)');
        $stFind->execute([$PID, $cName, $dom !== '' ? '%' . $dom . '%' : '__none__']);
        $cRow = $stFind->fetch();
        if (!$cRow) {
            $cid = gen_id('CP');
            $webInit = $dom !== '' ? 'https://' . $dom : null;
            $pdo->prepare('INSERT INTO competitors(id,project_id,name,share_of_voice,spend_estimate,latest_move,threat,website,teardowns) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$cid, $PID, mb_substr($cName, 0, 120), 0, '—', 'Mới thêm qua Phiếu học hỏi đối thủ', 'TB', $webInit, '[]']);
            $cRow = need('competitors', $cid, 'đối thủ');
            $newCompetitorCreatedId = $cid;
        } else {
            $cid = $cRow['id'];
        }
    } else {
        $cRow = need('competitors', $cid, 'đối thủ');
    }

    $list = array_values(array_filter(jdecode($cRow['teardowns'] ?? null), 'is_array'));

    if ($act === 'teardown_save') {
        $tdId = trim((string)($inA['td_id'] ?? ''));
        $vLevel = in_array($inA['verify_level'] ?? '', ['verified', 'user_provided', 'inferred', 'unavailable'], true) ? $inA['verify_level'] : 'user_provided';
        $sType = in_array($inA['source_type'] ?? '', ['website', 'landing', 'ads', 'maps', 'social', 'manual'], true) ? $inA['source_type'] : 'manual';
        $td = [
            'id' => $tdId !== '' ? $tdId : gen_id('TD'),
            'title' => mb_substr(trim((string)($inA['title'] ?? '')) ?: ('Phiếu quan sát ' . $cRow['name']), 0, 160),
            'source_type' => $sType,
            'source_url' => mb_substr(trim((string)($inA['source_url'] ?? '')), 0, 500),
            'observed_at' => trim((string)($inA['observed_at'] ?? '')) ?: gmdate('Y-m-d H:i:s'),
            'verify_level' => $vLevel,
            'hook' => mb_substr(trim((string)($inA['hook'] ?? '')), 0, 400),
            'offer' => mb_substr(trim((string)($inA['offer'] ?? '')), 0, 500),
            'cta' => mb_substr(trim((string)($inA['cta'] ?? '')), 0, 250),
            'strengths' => mb_substr(trim((string)($inA['strengths'] ?? '')), 0, 800),
            'weaknesses' => mb_substr(trim((string)($inA['weaknesses'] ?? '')), 0, 800),
            'takeaway' => mb_substr(trim((string)($inA['takeaway'] ?? '')), 0, 800),
            'channel' => mb_substr(trim((string)($inA['channel'] ?? 'Website / SEO')), 0, 60),
            'tech' => is_array($inA['tech'] ?? null) ? array_slice(array_map('strval', $inA['tech']), 0, 12) : array_filter(array_map('trim', explode(',', (string)($inA['tech'] ?? '')))),
            'ai_used' => !empty($inA['ai_used']),
        ];
        $found = false;
        foreach ($list as $idx => $item) {
            if (($item['id'] ?? '') === $td['id']) {
                $td['ai_used'] = $item['ai_used'] ?? false;
                $list[$idx] = $td;
                $found = true;
                break;
            }
        }
        if (!$found) array_unshift($list, $td);
        $list = array_slice($list, 0, 30);
        $pdo->prepare("UPDATE competitors SET teardowns=?, updated_at=datetime('now') WHERE id=? AND project_id=?")
            ->execute([json_encode($list, JSON_UNESCAPED_UNICODE), $cid, $PID]);
        log_activity($found ? 'update' : 'create', 'competitor', $cid, ($found ? 'Cập nhật' : 'Tạo') . ' phiếu học hỏi đối thủ ' . $cRow['name']);
        respond(['ok' => true, 'competitor_id' => $cid, 'teardown' => $td]);
    }

    // action === 'teardown_url': Quét trang đích / website thật + phân tích AI (nếu có)
    if (!$safeUrl) {
        $rawUrl = trim((string)($inA['url'] ?? $cRow['website'] ?? ''));
        $safeUrl = comp_safe_url($rawUrl);
        if (!$safeUrl) fail('URL cần phân tích không hợp lệ hoặc trỏ tới địa chỉ nội bộ', 400);
    }
    session_write_close();
    [$code, $html, $final] = comp_fetch($safeUrl, 20);
    if ($code < 200 || $code >= 400 || $html === '') {
        if ($newCompetitorCreatedId !== null) {
            try {
                $pdo->prepare('DELETE FROM competitors WHERE id=? AND project_id=?')->execute([$newCompetitorCreatedId, $PID]);
            } catch (Throwable $delEx) {}
        }
        fail("Không đọc được trang đích đối thủ (HTTP $code). Bạn có thể tạo phiếu quan sát thủ công.", 422);
    }
    $cleanHtml = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html);
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES, 'UTF-8')));
    $g = fn(string $re) => preg_match($re, $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
    $pageTitle = mb_substr($g('#<title[^>]*>(.*?)</title>#is'), 0, 200);
    $metaDesc = mb_substr($g('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i') ?: $g('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description#i'), 0, 350);
    $h1 = mb_substr($g('#<h1[^>]*>(.*?)</h1>#is'), 0, 220);

    $headings = [];
    if (preg_match_all('#<h[1-3][^>]*>(.*?)</h[1-3]>#is', $cleanHtml, $mh)) {
        foreach ($mh[1] as $hRaw) {
            $ht = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($hRaw), ENT_QUOTES, 'UTF-8')));
            if (mb_strlen($ht) >= 4 && mb_strlen($ht) <= 140) $headings[] = $ht;
            if (count($headings) >= 12) break;
        }
    }
    $headings = array_values(array_unique($headings));

    $ctas = [];
    if (preg_match_all('#<(?:button|a[^>]+class=["\'][^"\']*(?:btn|button|cta)[^"\']*["\'])[^>]*>(.*?)</(?:button|a)>#is', $cleanHtml, $mc)) {
        foreach ($mc[1] as $cRaw) {
            $ct = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cRaw), ENT_QUOTES, 'UTF-8')));
            if (mb_strlen($ct) >= 3 && mb_strlen($ct) <= 50) $ctas[] = $ct;
            if (count($ctas) >= 8) break;
        }
    }
    $ctas = array_values(array_unique($ctas));

    preg_match_all('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', $text, $ph);
    $phones = array_values(array_unique(array_map(fn($p) => preg_replace('/\D/', '', $p), array_slice($ph[0] ?? [], 0, 6))));
    $formCount = preg_match_all('#<form\b#i', $html);

    $tech = [];
    foreach (['wp-content' => 'WordPress', 'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify', 'haravan' => 'Haravan', 'sapo' => 'Sapo', 'gtag(' => 'Google Analytics', 'googletagmanager' => 'Google Tag Manager', 'fbq(' => 'Facebook Pixel', 'zalo' => 'Zalo Chat', 'tawk.to' => 'Tawk Chat', 'messenger' => 'Messenger Chat', 'analytics.tiktok' => 'TikTok Pixel'] as $k => $v) {
        if (stripos($html, $k) !== false) $tech[] = $v;
    }

    // Tổng hợp mặc định dựa trên bằng chứng HTML đã xác minh (verified)
    $hook = $h1 ?: ($pageTitle ?: 'Trang đích ' . $cRow['name']);
    $offerParts = [];
    foreach ($headings as $hd) {
        if (preg_match('/(ưu đãi|giảm|tặng|bảo hành|miễn phí|cam kết|báo giá|khuyến mãi|trả góp|chính hãng|%)/iu', $hd)) $offerParts[] = $hd;
    }
    $offer = $offerParts ? implode(' · ', array_slice($offerParts, 0, 3)) : ($metaDesc ?: implode(' · ', array_slice($headings, 0, 3)));
    $ctaStr = $ctas ? implode(', ', array_slice($ctas, 0, 4)) : ($phones ? 'Gọi hotline ' . implode('/', array_slice($phones, 0, 2)) : 'Liên hệ tư vấn');

    $strList = [];
    if ($tech) $strList[] = 'Công nghệ & đo lường: ' . implode(', ', $tech);
    if ($formCount > 0) $strList[] = "Có $formCount biểu mẫu thu thập thông tin trực tiếp trên trang";
    if ($phones) $strList[] = 'Hiển thị hotline trực tiếp (' . implode(', ', array_slice($phones, 0, 2)) . ')';
    if (count($headings) >= 4) $strList[] = 'Cấu trúc nội dung có ' . count($headings) . ' đề mục: ' . implode(' | ', array_slice($headings, 0, 4));

    $weakList = [];
    if (!in_array('Facebook Pixel', $tech, true) && !in_array('TikTok Pixel', $tech, true)) $weakList[] = 'Chưa phát hiện Pixel remarketing mạng xã hội (Facebook/TikTok Pixel) trong mã HTML tĩnh';
    if ($formCount === 0) $weakList[] = 'Không có form đăng ký nhanh trên trang (khách phải tự gọi hoặc nhắn tin)';
    if (!$offerParts) $weakList[] = 'Tiêu đề các mục chưa nêu bật ưu đãi định lượng hoặc cam kết bảo hành rõ ràng';

    $strengths = implode('. ', $strList) ?: 'Trang truy cập ổn định, có tiêu đề và mô tả cơ bản.';
    $weaknesses = implode('. ', $weakList) ?: 'Có thể vượt qua bằng thông điệp cam kết rõ hơn và trải nghiệm đăng ký nhanh trên di động.';
    $takeaway = 'Làm trang đích tối ưu di động với tiêu đề đánh trực diện nhu cầu, nêu rõ cam kết/bảo hành và đặt form đăng ký 1 chạm kèm hotline.';
    $aiUsed = false;

    if (is_file(__DIR__ . '/_ai_lib.php')) {
        require_once __DIR__ . '/_ai_lib.php';
        $proj = current_project();
        $sys = 'Bạn là chuyên gia phân tích đối thủ cạnh tranh (Competitive Intelligence). Dựa trên dữ liệu thực tế bóc tách từ trang web đối thủ, hãy trả về JSON gồm các trường tiếng Việt ngắn gọn, sắc bén: title (tên phiếu quan sát ≤ 100 ký tự), hook (thông điệp chính/câu dẫn của đối thủ), offer (ưu đãi/USP/cam kết chính đối thủ đang dùng), cta (cách đối thủ kêu gọi hành động/thu lead), strengths (điểm mạnh thực tế trên trang), weaknesses (điểm yếu/kẽ hở để dự án của ta khai thác), takeaway (đề xuất hành động cụ thể cho dự án của ta), channel (kênh đề xuất: Google Ads | Facebook Ads | TikTok Ads | Website / SEO | Zalo OA). Tuyệt đối không bịa đặt số liệu ngoài bằng chứng.';
        $uPrompt = "Dự án của ta: " . ($proj['name'] ?? '') . " (" . ($proj['domain'] ?? '') . ") — Mục tiêu: " . ($proj['goal'] ?? '')
            . "\nĐối thủ: {$cRow['name']} · URL quan sát: $final"
            . "\nTiêu đề trang: $pageTitle\nMô tả SEO: $metaDesc\nH1: $h1"
            . "\nCác đề mục H2/H3: " . implode(' | ', $headings)
            . "\nNút CTA tìm thấy: " . implode(', ', $ctas)
            . "\nSố form trên trang: $formCount · Hotline: " . implode(', ', $phones)
            . "\nCông nghệ/Pixel phát hiện: " . implode(', ', $tech)
            . "\nTrích đoạn nội dung: " . mb_substr($text, 0, 1200)
            . (!empty($inA['note']) ? "\nGhi chú của người quan sát: " . mb_substr((string)$inA['note'], 0, 400) : '');
        $rAi = ai_generate($sys, [['role' => 'user', 'content' => $uPrompt]], ['max_tokens' => 1100, 'effort' => 'low', 'purpose' => 'competitor_teardown']);
        if (!empty($rAi['ok']) && preg_match('~\{.*\}~s', (string)$rAi['text'], $mj) && ($j = json_decode($mj[0], true))) {
            if (!empty($j['hook'])) $hook = mb_substr((string)$j['hook'], 0, 400);
            if (!empty($j['offer'])) $offer = mb_substr((string)$j['offer'], 0, 500);
            if (!empty($j['cta'])) $ctaStr = mb_substr((string)$j['cta'], 0, 250);
            if (!empty($j['strengths'])) $strengths = mb_substr((string)$j['strengths'], 0, 800);
            if (!empty($j['weaknesses'])) $weaknesses = mb_substr((string)$j['weaknesses'], 0, 800);
            if (!empty($j['takeaway'])) $takeaway = mb_substr((string)$j['takeaway'], 0, 800);
            $aiUsed = true;
        }
    }

    $td = [
        'id' => gen_id('TD'),
        'title' => mb_substr(trim((string)($inA['title'] ?? '')) ?: ($pageTitle ?: 'Mổ xẻ trang ' . $cRow['name']), 0, 160),
        'source_type' => in_array($inA['source_type'] ?? '', ['website', 'landing', 'ads', 'maps', 'social'], true) ? $inA['source_type'] : 'landing',
        'source_url' => $final ?: $safeUrl,
        'observed_at' => gmdate('Y-m-d H:i:s'),
        'verify_level' => 'verified',
        'hook' => $hook,
        'offer' => $offer,
        'cta' => $ctaStr,
        'strengths' => $strengths,
        'weaknesses' => $weaknesses,
        'takeaway' => $takeaway,
        'channel' => mb_substr((string)($j['channel'] ?? 'Website / SEO'), 0, 60),
        'tech' => $tech,
        'phones' => $phones,
        'forms' => (int)$formCount,
        'ai_used' => $aiUsed,
    ];
    array_unshift($list, $td);
    $list = array_slice($list, 0, 30);
    $updWeb = empty($cRow['website']) ? $safeUrl : $cRow['website'];
    $pdo->prepare("UPDATE competitors SET website=?, teardowns=?, updated_at=datetime('now') WHERE id=? AND project_id=?")
        ->execute([$updWeb, json_encode($list, JSON_UNESCAPED_UNICODE), $cid, $PID]);
    log_activity('create', 'competitor', $cid, 'Quét & mổ xẻ trang đối thủ ' . $cRow['name'] . ' (' . ($final ?: $safeUrl) . ')');
    respond(['ok' => true, 'competitor_id' => $cid, 'teardown' => $td]);
}

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['web_set', 'web_check'], true)) {
    require_once __DIR__ . '/_market_lib.php';
    if ($inA['action'] === 'web_set') {
        $url = trim((string)($inA['website'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) fail('Địa chỉ website không hợp lệ');
        need('competitors', (string)($inA['id'] ?? ''), 'đối thủ');
        $pdo->prepare('UPDATE competitors SET website=?, web_snapshot=NULL, web_checked_at=NULL, web_change=NULL WHERE id=? AND project_id=?')->execute([$url ?: null, (string)($inA['id'] ?? ''), $PID]);
        if ($url) { try { $r = comp_web_check((string)$inA['id']); } catch (Throwable $e) { fail('Đã lưu website nhưng chưa đọc được: ' . $e->getMessage(), 424); } }
        respond(['ok' => true]);
    }
    session_write_close();
    if (!empty($inA['id'])) need('competitors', (string)$inA['id'], 'đối thủ');
    $stW = $pdo->prepare("SELECT id FROM competitors WHERE project_id=? AND COALESCE(website,'')<>''"); $stW->execute([$PID]);
    $ids = !empty($inA['id']) ? [(string)$inA['id']] : array_column($stW->fetchAll(), 'id');
    $res = [];
    foreach ($ids as $id) { try { $res[] = ['id' => $id, 'ok' => true] + comp_web_check($id); } catch (Throwable $e) { $res[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()]; } }
    respond(['results' => $res]);
}

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['place_search', 'place_link', 'place_unlink', 'place_refresh'], true)) {
    $a = $inA['action'];
    if ($a === 'place_search') {
        $q = trim((string)($inA['q'] ?? ''));
        if ($q === '') fail('Nhập tên / địa chỉ cần tìm');
        $d = places_call('POST', 'places:searchText', implode(',', array_map(fn($f) => 'places.' . $f, explode(',', PLACE_FIELDS))),
            ['textQuery' => $q, 'languageCode' => 'vi', 'regionCode' => 'VN', 'pageSize' => 10]);
        respond(['places' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['displayName']['text'] ?? '', 'address' => $p['formattedAddress'] ?? '',
            'rating' => $p['rating'] ?? null, 'reviews' => $p['userRatingCount'] ?? 0, 'url' => $p['googleMapsUri'] ?? null], $d['places'] ?? [])]);
    }
    if ($a === 'place_refresh') {
        $stP = $pdo->prepare("SELECT id, place_id FROM competitors WHERE project_id=? AND COALESCE(place_id,'')<>''"); $stP->execute([$PID]); $rows = $stP->fetchAll();
        foreach ($rows as $r) place_save($r['id'], places_call('GET', 'places/' . rawurlencode($r['place_id']), PLACE_FIELDS));
        respond(['updated' => count($rows)]);
    }
    $cid = (string)($inA['id'] ?? '');
    need('competitors', $cid, 'đối thủ');
    if ($a === 'place_unlink') {
        $pdo->prepare('UPDATE competitors SET place_id=NULL, place_name=NULL, place_rating=NULL, place_reviews=NULL, place_address=NULL, place_url=NULL, place_synced_at=NULL WHERE id=?')->execute([$cid]);
        respond(['ok' => true]);
    }
    $pid = trim((string)($inA['place_id'] ?? ''));
    if ($pid === '') fail('Thiếu place_id');
    place_save($cid, places_call('GET', 'places/' . rawurlencode($pid), PLACE_FIELDS));
    log_activity('update', 'competitor', $cid, 'Gắn Google Maps cho đối thủ');
    respond(['ok' => true]);
}

if ($method === 'POST') {
    $in = json_input();
    $id = $in['id'] ?? null;
    $map = ['name' => 'name', 'sov' => 'share_of_voice', 'spendEst' => 'spend_estimate', 'move' => 'latest_move', 'threat' => 'threat', 'website' => 'website'];
    if (isset($in['website'])) {
        $w = trim((string)$in['website']);
        if ($w !== '' && !preg_match('#^https?://#i', $w)) $w = 'https://' . $w;
        $in['website'] = $w ?: null;
    }

    if ($id) {
        need('competitors', $id, 'đối thủ');
        $set = []; $vals = [];
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $jsKey === 'sov' ? (int)$in[$jsKey] : $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = $PID;
        $pdo->prepare('UPDATE competitors SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'competitor', $id, 'Cập nhật đối thủ ' . mb_substr((string)(($in['name'] ?? '') ?: $id), 0, 80));
        respond(['ok' => true]);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên đối thủ');
    $newId = gen_id('CP');
    $pdo->prepare('INSERT INTO competitors(id,project_id,name,share_of_voice,spend_estimate,latest_move,threat,website,teardowns)
        VALUES (?,?,?,?,?,?,?,?,?)')->execute([
        $newId, $PID, $name, (int)($in['sov'] ?? 0), $in['spendEst'] ?? '',
        $in['move'] ?? '', $in['threat'] ?? '—', $in['website'] ?? null, '[]'
    ]);
    log_activity('create', 'competitor', $newId, 'Thêm đối thủ ' . mb_substr((string)$name, 0, 80));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    need('competitors', $id, 'đối thủ');
    $pdo->prepare('DELETE FROM competitors WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'competitor', $id, 'Xoá đối thủ ' . $id);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
