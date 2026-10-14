<?php
/* Landing page (Tiện ích) — bản nháp thuộc dự án, có phiên bản; xem thử trong khung cách ly. 11/10/2026
   GET                          → {landings}
   GET ?id=&preview=1           → HTML xem thử (CSP chặn script/ngoài, sandbox) — KHÔNG phải URL hoạt động
   POST {source_type: prompt|link|image, source_ref, prompt, title, cta, channel, campaign_id}  → tạo bản nháp
   POST {action:'publish', id}  → đánh dấu đã duyệt/xuất bản nội bộ (ghi published_url khi có nơi đăng thật) [chủ/quản lý]
   DELETE ?id=                   [chủ/quản lý]
   Đọc link tham chiếu: chỉ http(s), chặn IP nội bộ/metadata (SSRF), tối đa 2 redirect, 1,5 MB, timeout 10s, không chạy script. */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$PID = pid();

function ld_safe_url(string $url): ?string {
    $u = parse_url($url);
    if (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || empty($u['host'])) return null;
    $host = strtolower($u['host']);
    if (in_array($host, ['localhost', 'metadata.google.internal'], true)) return null;
    $ips = gethostbynamel($host) ?: [];
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    if (!$ips) return null;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    return $url;
}
/** Đọc trang tham chiếu an toàn → [title, description, headings[], texts[]] */
function ld_fetch_ref(string $url): array {
    $out = ['title' => '', 'description' => '', 'headings' => [], 'texts' => [], 'error' => null];
    for ($hop = 0; $hop < 3; $hop++) {
        if (!ld_safe_url($url)) { $out['error'] = 'Link không hợp lệ hoặc trỏ tới địa chỉ nội bộ'; return $out; }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'MarketingHub-LandingRef/1.0', CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($r, $dt, $dl) => ($dl > 1500000) ? 1 : 0]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
        if (in_array($code, [301, 302, 303, 307, 308], true) && $loc) { $url = $loc; continue; }
        if ($body === false || $code !== 200) { $out['error'] = "Không đọc được trang (HTTP $code)"; return $out; }
        $body = mb_substr((string)$body, 0, 1500000);
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m)) $out['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i', $body, $m)) $out['description'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $body2 = preg_replace('~<(script|style|noscript|svg)[^>]*>.*?</\1>~is', '', $body);
        if (preg_match_all('~<h[1-3][^>]*>(.*?)</h[1-3]>~is', $body2, $mm)) foreach (array_slice($mm[1], 0, 12) as $h) { $t = trim(html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8')); if ($t !== '') $out['headings'][] = mb_substr($t, 0, 120); }
        if (preg_match_all('~<p[^>]*>(.*?)</p>~is', $body2, $pp)) foreach ($pp[1] as $p) { $t = trim(preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES, 'UTF-8'))); if (mb_strlen($t) > 40) $out['texts'][] = mb_substr($t, 0, 300); if (count($out['texts']) >= 8) break; }
        return $out;
    }
    $out['error'] = 'Quá nhiều chuyển hướng'; return $out;
}
/** Dựng HTML bản nháp từ mẫu + dữ liệu dự án (+ AI viết nội dung nếu có khoá). */
function ld_build(array $p, array $in, array $ref): array {
    $title = mb_substr(trim((string)$in['title']), 0, 120) ?: ($ref['title'] ?: 'Trang đích ' . $p['name']);
    $cta = mb_substr(trim((string)$in['cta']), 0, 60) ?: 'Đăng ký tư vấn';
    $hotline = (string)(jdecode($p['settings'] ?? null)['hotline'] ?? setting_get('brand.hotline', ''));
    $sections = ['lead' => $p['goal'] ?: ($ref['description'] ?: 'Giải pháp phù hợp cho doanh nghiệp của bạn.'),
        'benefits' => ['Rõ ràng, dễ hiểu, ưu tiên điện thoại', 'Quy trình minh bạch, bàn giao đúng hẹn', 'Hỗ trợ sau triển khai'],
        'steps' => ['Để lại thông tin', 'Nhận tư vấn & báo giá', 'Triển khai & bàn giao'],
        'faq' => [['Chi phí tính thế nào?', 'Báo giá theo phạm vi công việc, gửi trong 24 giờ.'], ['Thời gian triển khai?', 'Tuỳ gói, thông báo rõ trước khi bắt đầu.']], 'ai' => false];
    if ($ref['headings']) $sections['benefits'] = array_slice(array_values(array_unique($ref['headings'])), 0, 5);
    $aiNote = '';
    if (is_file(__DIR__ . '/_ai_lib.php')) {
        require_once __DIR__ . '/_ai_lib.php';
        try {
            $sys = 'Bạn viết nội dung landing page tiếng Việt cho dự án marketing. Trả về JSON với các khoá: lead (1 câu), benefits (3-5 chuỗi), steps (3 chuỗi), faq (3 cặp {q,a}). Không bịa giá, không bịa số liệu, không hứa điều không chắc.';
            $ctx = "Dự án: {$p['name']} ({$p['domain']}). Mục tiêu: {$p['goal']}. Tiêu đề: $title. CTA: $cta. Yêu cầu: " . mb_substr((string)$in['prompt'], 0, 1500)
                . ($ref['title'] ? "\nTrang tham chiếu: {$ref['title']} — {$ref['description']}\nĐề mục: " . implode(' | ', $ref['headings']) . "\nĐoạn: " . implode(' | ', array_slice($ref['texts'], 0, 4)) : '');
            $r = ai_generate($sys, [['role' => 'user', 'content' => $ctx]], ['max_tokens' => 900, 'purpose' => 'landing_draft']);
            if (!empty($r['ok']) && preg_match('~\{.*\}~s', (string)$r['text'], $mj) && ($j = json_decode($mj[0], true))) {
                if (!empty($j['lead'])) $sections['lead'] = mb_substr((string)$j['lead'], 0, 300);
                if (!empty($j['benefits']) && is_array($j['benefits'])) $sections['benefits'] = array_slice(array_map(fn($x) => mb_substr((string)$x, 0, 120), $j['benefits']), 0, 6);
                if (!empty($j['steps']) && is_array($j['steps'])) $sections['steps'] = array_slice(array_map(fn($x) => mb_substr((string)$x, 0, 120), $j['steps']), 0, 5);
                if (!empty($j['faq']) && is_array($j['faq'])) $sections['faq'] = array_slice(array_map(fn($x) => [mb_substr((string)($x['q'] ?? ''), 0, 150), mb_substr((string)($x['a'] ?? ''), 0, 300)], array_filter($j['faq'], 'is_array')), 0, 5);
                $sections['ai'] = true;
            } else $aiNote = 'AI không trả lời (' . ($r['error'] ?? 'không rõ') . ') — dùng mẫu.';
        } catch (Throwable $e) { $aiNote = 'AI lỗi: ' . $e->getMessage(); }
    }
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $color = $e($p['color'] ?: '#2563eb');
    $html = "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)}</title>
<style>body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1a2233;line-height:1.55}.wrap{max-width:960px;margin:0 auto;padding:0 20px}
.hero{background:linear-gradient(135deg,$color,#0f172a);color:#fff;padding:64px 0}.hero h1{font-size:clamp(28px,5vw,44px);margin:0 0 14px}.hero p{font-size:18px;opacity:.92;max-width:640px}
.btn{display:inline-block;background:#fff;color:$color;font-weight:700;padding:14px 26px;border-radius:10px;text-decoration:none;margin-top:18px}
section{padding:44px 0}h2{font-size:26px;margin:0 0 18px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.card{background:#f6f8fb;border-radius:12px;padding:18px}
.steps{display:flex;gap:14px;flex-wrap:wrap}.step{flex:1;min-width:180px;background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:16px}.step b{display:block;color:$color;font-size:22px}
details{border-bottom:1px solid #e5e9f0;padding:12px 0}summary{font-weight:600;cursor:pointer}.cta{background:#f6f8fb;text-align:center}.cta .btn{background:$color;color:#fff}
form{display:grid;gap:10px;max-width:420px;margin:16px auto 0}input{padding:12px;border:1px solid #cfd6e1;border-radius:8px;font-size:15px}button{padding:13px;border:0;border-radius:8px;background:$color;color:#fff;font-weight:700;font-size:15px}
footer{padding:24px 0;font-size:13px;color:#6b7789;text-align:center}.draft{position:fixed;top:0;left:0;right:0;background:#d97706;color:#fff;text-align:center;font-size:12px;padding:4px}</style></head><body>
<div class=\"draft\">BẢN NHÁP — chưa xuất bản · {$e($p['name'])}</div>
<header class=\"hero\"><div class=\"wrap\"><h1>{$e($title)}</h1><p>{$e($sections['lead'])}</p><a class=\"btn\" href=\"#lien-he\">{$e($cta)}</a></div></header>
<section><div class=\"wrap\"><h2>Lợi ích</h2><div class=\"cards\">" . implode('', array_map(fn($b) => "<div class=\"card\">{$e($b)}</div>", $sections['benefits'])) . "</div></div></section>
<section><div class=\"wrap\"><h2>Quy trình</h2><div class=\"steps\">" . implode('', array_map(fn($s, $i) => "<div class=\"step\"><b>" . ($i + 1) . "</b>{$e($s)}</div>", $sections['steps'], array_keys($sections['steps']))) . "</div></div></section>
<section><div class=\"wrap\"><h2>Câu hỏi thường gặp</h2>" . implode('', array_map(fn($f) => "<details><summary>{$e($f[0])}</summary><p>{$e($f[1])}</p></details>", $sections['faq'])) . "</div></section>
<section class=\"cta\" id=\"lien-he\"><div class=\"wrap\"><h2>{$e($cta)}</h2><p>Để lại thông tin, {$e($p['name'])} liên hệ lại sớm nhất." . ($hotline ? " Hotline: {$e($hotline)}" : '') . "</p><form onsubmit=\"return false\"><input placeholder=\"Họ tên\" required><input placeholder=\"Số điện thoại\" required><button type=\"submit\">{$e($cta)}</button></form><p style=\"font-size:12px;color:#6b7789\">Form trong bản nháp chưa gửi đi đâu; khi xuất bản sẽ nối với hộp lead của dự án.</p></div></section>
<footer>© " . date('Y') . " {$e($p['name'])}" . ($p['domain'] ? " · {$e($p['domain'])}" : '') . "</footer></body></html>";
    return [$html, $sections, $aiNote];
}

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $l = need('landing_pages', (string)$_GET['id'], 'landing page');
        if (!empty($_GET['preview'])) {
            // Xem thử cách ly: không script, không tải tài nguyên ngoài, không gửi form, không nhúng vào trang khác.
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'none'; frame-ancestors 'none'; base-uri 'none'; sandbox");
            header('X-Robots-Tag: noindex'); header('X-Frame-Options: DENY'); header('Cache-Control: no-store');
            echo (string)$l['content']; exit;
        }
        unset($l['content']); respond(['landing' => $l]);
    }
    $st = $pdo->prepare('SELECT id,project_id,title,cta,channel,campaign_id,source_type,source_ref,state,version,published_url,ai_used,created_by,created_at,updated_at FROM landing_pages WHERE project_id=? ORDER BY updated_at DESC'); $st->execute([$PID]);
    respond(['landings' => $st->fetchAll()]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    if (($in['action'] ?? '') === 'publish') {
        require_project_admin();
        $l = need('landing_pages', (string)($in['id'] ?? ''), 'landing page');
        $url = trim((string)($in['published_url'] ?? ''));
        if ($url !== '' && !preg_match('~^https://~i', $url)) fail('URL xuất bản phải bắt đầu bằng https://');
        $pdo->prepare("UPDATE landing_pages SET state=?, published_url=?, updated_at=datetime('now') WHERE id=? AND project_id=?")->execute([$url !== '' ? 'published' : 'approved', $url ?: null, $l['id'], $PID]);
        log_activity('approve', 'landing', $l['id'], ($url ? 'Xuất bản landing ' . $url : 'Duyệt landing ') . $l['title']);
        respond(['ok' => true]);
    }
    $type = in_array($in['source_type'] ?? '', ['prompt', 'link', 'image'], true) ? $in['source_type'] : 'prompt';
    $ref = ['title' => '', 'description' => '', 'headings' => [], 'texts' => [], 'error' => null];
    $srcRef = trim((string)($in['source_ref'] ?? ''));
    if ($type === 'link') { if ($srcRef === '') fail('Nhập link tham chiếu'); $ref = ld_fetch_ref($srcRef); if ($ref['error'] && !$ref['title']) fail('Không đọc được link tham chiếu: ' . $ref['error'], 422); }
    if ($type === 'image' && !preg_match('#^uploads/\d{6}/[a-f0-9]{24}\.(jpg|jpeg|png|webp)$#', $srcRef)) fail('Thiếu ảnh tham chiếu hợp lệ');
    $camp = trim((string)($in['campaign_id'] ?? '')) ?: null;
    if ($camp && !scoped('campaigns', $camp)) fail('Chiến dịch không thuộc dự án này');
    $p = current_project();
    [$html, $sections, $aiNote] = ld_build($p, $in, $ref);
    $id = gen_id('LP');
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120) ?: ($ref['title'] ?: 'Trang đích');
    $pdo->prepare('INSERT INTO landing_pages(id,project_id,title,cta,channel,campaign_id,source_type,source_ref,prompt,content,sections,state,version,ai_used,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)')
        ->execute([$id, $PID, $title, mb_substr(trim((string)($in['cta'] ?? '')), 0, 60), mb_substr((string)($in['channel'] ?? ''), 0, 20), $camp, $type, $srcRef ?: null,
            mb_substr((string)($in['prompt'] ?? ''), 0, 3000), $html, json_encode($sections, JSON_UNESCAPED_UNICODE), 'draft', $sections['ai'] ? 1 : 0, $me['id']]);
    log_activity('create', 'landing', $id, 'Tạo bản nháp landing "' . $title . '" (' . $type . ')' . ($aiNote ? ' — ' . $aiNote : ''));
    respond(['id' => $id, 'ai_used' => (bool)$sections['ai'], 'note' => $aiNote ?: null, 'ref_error' => $ref['error']], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $l = need('landing_pages', (string)($_GET['id'] ?? ''), 'landing page');
    $pdo->prepare('DELETE FROM landing_pages WHERE id=? AND project_id=?')->execute([$l['id'], $PID]);
    log_activity('delete', 'landing', $l['id'], 'Xoá landing ' . $l['title']);
    respond(['ok' => true]);
}
fail('Method không hỗ trợ', 405);
