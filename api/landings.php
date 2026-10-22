<?php
/* Landing page (Tiện ích) — công cụ độc lập: tạo từ prompt / link / ảnh (AI Vision), chỉnh sửa từng khối,
   xem thử trực tiếp Mobile/Desktop, xuất bản thành link hoạt động thật và tự động đổ Lead về dự án.
   GET                          → {landings} (kèm sections & lead_count)
   GET ?id=&preview=1           → HTML xem thử (cho phép nhúng iframe cùng tên miền)
   GET ?id=&public=1            → Trang đích công khai đã xuất bản (không cần đăng nhập)
   GET ?id=&download=1          → Tải file HTML độc lập về máy
   POST ?public_lead=1          → Khách gửi form tư vấn trên trang đích → lưu vào bảng leads của dự án
   POST {source_type: prompt|link|image, ...} → Tạo bản nháp mới
   POST {action:'update', id, title, cta, channel, campaign_id, sections} → Lưu chỉnh sửa khối (tăng version)
   POST {action:'publish', id, state?, published_url?} → Xuất bản / gỡ xuất bản */
require __DIR__ . '/_boot.php';
$method = $_SERVER['REQUEST_METHOD'];

function ld_safe_url(string $url): ?string {
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

/** Đọc trang tham chiếu an toàn → [title, description, headings[], bullets[], ctas[], texts[], phones[]] */
function ld_fetch_ref(string $url): array {
    $out = ['title' => '', 'description' => '', 'headings' => [], 'bullets' => [], 'ctas' => [], 'texts' => [], 'phones' => [], 'error' => null];
    for ($hop = 0; $hop < 3; $hop++) {
        $safe = ld_safe_url($url);
        if (!$safe) { $out['error'] = 'Link không hợp lệ hoặc trỏ tới địa chỉ nội bộ'; return $out; }
        $url = $safe;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) MarketingHub-LandingRef/2.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($r, $dt, $dl) => ($dl > 1500000) ? 1 : 0]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
        if (in_array($code, [301, 302, 303, 307, 308], true) && $loc) { $url = $loc; continue; }
        if ($body === false || $code !== 200) { $out['error'] = "Không đọc được trang (HTTP $code)"; return $out; }
        $body = mb_substr((string)$body, 0, 1500000);
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m)) $out['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i', $body, $m) || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description~i', $body, $m)) {
            $out['description'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        $body2 = preg_replace('~<(script|style|noscript|svg)[^>]*>.*?</\1>~is', ' ', $body);
        if (preg_match_all('~<h[1-3][^>]*>(.*?)</h[1-3]>~is', $body2, $mm)) {
            foreach ($mm[1] as $h) {
                $t = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8')));
                if (mb_strlen($t) >= 4 && mb_strlen($t) <= 130) $out['headings'][] = $t;
                if (count($out['headings']) >= 14) break;
            }
            $out['headings'] = array_values(array_unique($out['headings']));
        }
        if (preg_match_all('~<li[^>]*>(.*?)</li>~is', $body2, $ml)) {
            foreach ($ml[1] as $li) {
                $t = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($li), ENT_QUOTES, 'UTF-8')));
                if (mb_strlen($t) >= 12 && mb_strlen($t) <= 140) $out['bullets'][] = $t;
                if (count($out['bullets']) >= 10) break;
            }
            $out['bullets'] = array_values(array_unique($out['bullets']));
        }
        if (preg_match_all('~<(?:button|a[^>]+class=["\'][^"\']*(?:btn|button|cta)[^"\']*["\'])[^>]*>(.*?)</(?:button|a)>~is', $body2, $mc)) {
            foreach ($mc[1] as $cRaw) {
                $t = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($cRaw), ENT_QUOTES, 'UTF-8')));
                if (mb_strlen($t) >= 3 && mb_strlen($t) <= 45) $out['ctas'][] = $t;
                if (count($out['ctas']) >= 6) break;
            }
            $out['ctas'] = array_values(array_unique($out['ctas']));
        }
        if (preg_match_all('~<p[^>]*>(.*?)</p>~is', $body2, $pp)) {
            foreach ($pp[1] as $p) {
                $t = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($p), ENT_QUOTES, 'UTF-8')));
                if (mb_strlen($t) > 35) $out['texts'][] = mb_substr($t, 0, 320);
                if (count($out['texts']) >= 10) break;
            }
        }
        $plain = strip_tags($body2);
        if (preg_match_all('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', $plain, $mph)) {
            $out['phones'] = array_values(array_unique(array_map(fn($x) => preg_replace('/\D/', '', $x), array_slice($mph[0], 0, 5))));
        }
        return $out;
    }
    $out['error'] = 'Quá nhiều chuyển hướng'; return $out;
}

const LD_TEMPLATES = ['modern', 'luxury_dark', 'split_lead', 'promo_flash', 'editorial'];

/** Chuẩn hoá dữ liệu các khối (sections) của Landing Page */
function ld_norm_sections(array $raw, array $p): array {
    $defHotline = (string)(jdecode($p['settings'] ?? null)['hotline'] ?? setting_get('brand.hotline', ''));
    $defColor = (string)($p['color'] ?: '#168164');
    $tpl = (string)($raw['template'] ?? 'modern');
    if (!in_array($tpl, LD_TEMPLATES, true)) $tpl = 'modern';

    $benefits = [];
    foreach ((array)($raw['benefits'] ?? []) as $b) {
        if (is_array($b) || is_object($b)) continue;
        $t = mb_substr(trim((string)$b), 0, 180);
        if ($t !== '') $benefits[] = $t;
    }
    if (!$benefits) $benefits = ['Sản phẩm & dịch vụ chính hãng, nguồn gốc minh bạch', 'Đội ngũ kỹ thuật chuyên sâu, tư vấn đúng nhu cầu', 'Bảo hành điện tử rõ ràng, hỗ trợ tận tâm sau bán'];

    $steps = [];
    foreach ((array)($raw['steps'] ?? []) as $s) {
        if (is_array($s) || is_object($s)) continue;
        $t = mb_substr(trim((string)$s), 0, 180);
        if ($t !== '') $steps[] = $t;
    }
    if (!$steps) $steps = ['Để lại Số điện thoại & nhu cầu cần tư vấn', 'Chuyên viên liên hệ báo giá chi tiết trong 5 phút', 'Trải nghiệm thực tế, lắp đặt / bàn giao & kích hoạt bảo hành'];

    $faq = [];
    foreach ((array)($raw['faq'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $q = mb_substr(trim((string)($f['q'] ?? $f[0] ?? '')), 0, 180);
        $a = mb_substr(trim((string)($f['a'] ?? $f[1] ?? '')), 0, 400);
        if ($q !== '' && $a !== '') $faq[] = [$q, $a];
    }
    if (!$faq) {
        $faq = [
            ['Chi phí và báo giá được tính như thế nào?', 'Bảng giá niêm yết minh bạch theo từng gói/dòng sản phẩm. Chuyên viên sẽ gửi báo giá chi tiết kèm ưu đãi hiện hành ngay khi nhận thông tin.'],
            ['Chế độ bảo hành và hậu mãi ra sao?', 'Cam kết bảo hành chính hãng theo đúng tiêu chuẩn của ' . ($p['name'] ?: 'chúng tôi') . ', hỗ trợ kỹ thuật nhanh chóng trong suốt quá trình sử dụng.'],
        ];
    }

    return [
        'template' => $tpl,
        'badge' => mb_substr(trim((string)($raw['badge'] ?? '')), 0, 90) ?: ('Chính hãng · Uy tín · ' . ($p['name'] ?: 'Chuyên nghiệp')),
        'lead' => mb_substr(trim((string)($raw['lead'] ?? '')), 0, 400) ?: ($p['goal'] ?: 'Giải pháp chất lượng cao, tối ưu chi phí và cam kết đồng hành lâu dài cùng khách hàng.'),
        'offer' => mb_substr(trim((string)($raw['offer'] ?? '')), 0, 350) ?: 'Đăng ký ngay hôm nay để nhận tư vấn miễn phí 1-1 từ chuyên gia và ưu đãi đặc quyền trong tháng.',
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($raw['color'] ?? '')) ? $raw['color'] : $defColor,
        'hotline' => mb_substr(trim((string)($raw['hotline'] ?? $defHotline)), 0, 40),
        'hero_img' => mb_substr(trim((string)($raw['hero_img'] ?? '')), 0, 300),
        'benefits' => array_slice($benefits, 0, 8),
        'steps' => array_slice($steps, 0, 6),
        'faq' => array_slice($faq, 0, 8),
        'ai' => !empty($raw['ai']),
    ];
}

/** Dựng HTML hoàn chỉnh cho Landing Page theo 5 kiến trúc giao diện hoàn toàn khác biệt. */
function ld_render_html(string $id, array $p, string $title, string $cta, array $sections, string $state = 'draft'): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $tpl = in_array($sections['template'] ?? 'modern', LD_TEMPLATES, true) ? $sections['template'] : 'modern';
    $color = $e($sections['color'] ?? ($p['color'] ?: '#168164'));
    $pName = $e($p['name'] ?: 'Thương hiệu');
    $badge = $e($sections['badge'] ?: ('Chính hãng · Uy tín · ' . ($p['name'] ?: 'Chuyên nghiệp')));
    $lead = $e($sections['lead'] ?: 'Giải pháp chất lượng cao, tối ưu chi phí và cam kết đồng hành lâu dài cùng khách hàng.');
    $offer = $e($sections['offer'] ?: 'Đăng ký ngay hôm nay để nhận tư vấn miễn phí 1-1 từ chuyên gia và ưu đãi đặc quyền trong tháng.');
    $hotline = trim((string)($sections['hotline'] ?? ''));
    $telClean = $e(preg_replace('/\D/', '', $hotline));
    $heroImg = trim((string)($sections['hero_img'] ?? ''));
    if ($heroImg !== '' && !preg_match('#^(https?://|/)#i', $heroImg)) $heroImg = '/' . ltrim($heroImg, '/');
    $isPublished = in_array($state, ['published', 'approved'], true);
    $draftBar = $isPublished ? '' : "<div class=\"draft\">BẢN NHÁP XEM THỬ — {$pName} · Mẫu giao diện: " . strtoupper($e($tpl)) . " (Khi Xuất bản thanh này sẽ ẩn và form đổ Lead thẳng về hệ thống)</div>";
    $floatCall = $hotline !== '' ? "<a class=\"float-tel\" href=\"tel:$telClean\">📞 Hotline: {$e($hotline)}</a>" : '';

    $bens = array_values($sections['benefits'] ?: ['Sản phẩm chính hãng 100%, nguồn gốc minh bạch', 'Đội ngũ kỹ thuật viên tay nghề cao, giàu kinh nghiệm', 'Bảo hành điện tử dài hạn, hỗ trợ tận tâm 24/7']);
    $steps = array_values($sections['steps'] ?: ['Để lại Họ tên & Số điện thoại nhận tư vấn', 'Chuyên viên liên hệ báo giá chi tiết trong 5 phút', 'Trải nghiệm thực tế, nghiệm thu & kích hoạt bảo hành']);
    $faqs = array_values($sections['faq'] ?: [['Chi phí được tính như thế nào?', 'Bảng giá minh bạch theo từng hạng mục, báo giá chi tiết trong 5 phút.']]);

    // Bộ chip chọn nhanh nhu cầu tư vấn (bấm vào tự điền vào ô Ghi chú của Form)
    $quickChips = "<div class=\"q-chips\"><span class=\"q-lbl\">Chọn nhanh nhu cầu:</span>"
        . "<button type=\"button\" class=\"q-chip\" onclick=\"fillNote('Nhận báo giá chi tiết & ưu đãi')\">+ Báo giá & Ưu đãi</button>"
        . "<button type=\"button\" class=\"q-chip\" onclick=\"fillNote('Tư vấn giải pháp phù hợp nhu cầu')\">+ Tư vấn 1-1</button>"
        . "<button type=\"button\" class=\"q-chip\" onclick=\"fillNote('Đặt lịch hẹn trải nghiệm / lắp đặt')\">+ Đặt lịch hẹn</button></div>";

    $formFields = "<div id=\"ld-ok\" class=\"msg-ok\"></div><div id=\"ld-err\" class=\"msg-err\"></div>
<form id=\"ld-form\" onsubmit=\"return submitLead(event)\">
<input type=\"hidden\" name=\"landing_id\" value=\"{$e($id)}\">
<div class=\"f-row\"><label>Họ và tên khách hàng *</label><input name=\"name\" placeholder=\"VD: Nguyễn Văn Minh\" required maxlength=\"100\"></div>
<div class=\"f-row\"><label>Số điện thoại nhận báo giá *</label><input name=\"phone\" type=\"tel\" placeholder=\"VD: 0988 123 456\" required maxlength=\"20\"></div>
$quickChips
<div class=\"f-row\"><label>Nhu cầu / Dòng sản phẩm quan tâm</label><textarea name=\"notes\" id=\"ld-notes\" rows=\"2\" placeholder=\"Ghi chú thêm nhu cầu của bạn (tuỳ chọn)...\" maxlength=\"500\"></textarea></div>
<button type=\"submit\" id=\"ld-btn\">{$e($cta)} →</button>
<div class=\"form-safe\">🔒 Bảo mật thông tin 100% · Phản hồi trong 5 phút · Không làm phiền</div>
</form>";

    $commonCss = "*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;-webkit-font-smoothing:antialiased}
.draft{position:sticky;top:0;z-index:999;background:linear-gradient(90deg,#d97706,#b45309);color:#fff;text-align:center;font-size:12px;font-weight:700;padding:7px 12px;letter-spacing:.2px}
.msg-ok{background:#dcfce7;color:#166534;border:1px solid #86efac;padding:14px;border-radius:10px;text-align:center;font-weight:700;display:none;margin-bottom:12px}
.msg-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:10px;border-radius:10px;text-align:center;font-size:14px;display:none;margin-bottom:12px}
.q-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:2px 0 4px}
.q-lbl{font-size:11.5px;opacity:.75;font-weight:600}
.q-chip{border:1px solid rgba(148,163,184,.4);background:rgba(148,163,184,.1);color:inherit;border-radius:99px;padding:4px 10px;font-size:11.5px;font-weight:600;cursor:pointer;transition:.15s}
.q-chip:hover{border-color:var(--pri);background:var(--pri);color:#fff}
.f-row{display:flex;flex-direction:column;gap:5px;text-align:left}
.f-row label{font-size:12.5px;font-weight:700;opacity:.88}
.float-tel{position:fixed;bottom:18px;right:18px;background:var(--pri);color:#fff;text-decoration:none;font-weight:800;padding:13px 22px;border-radius:99px;box-shadow:0 12px 30px rgba(0,0,0,.3);font-size:14px;z-index:90;border:2px solid rgba(255,255,255,.4)}";

    $commonJs = "<script>
function fillNote(txt){var el=document.getElementById('ld-notes');if(!el)return;el.value=el.value?(el.value+', '+txt):txt;el.focus();}
(function(){
 var s=document.getElementById('cd-s'),m=document.getElementById('cd-m'),h=document.getElementById('cd-h');
 if(s&&m&&h){var t=4*3600+45*60+29;setInterval(function(){if(t>0)t--;h.textContent=String(Math.floor(t/3600)).padStart(2,'0');m.textContent=String(Math.floor((t%3600)/60)).padStart(2,'0');s.textContent=String(t%60).padStart(2,'0');},1000);}
})();
async function submitLead(ev){
 ev.preventDefault();
 var f=document.getElementById('ld-form'),btn=document.getElementById('ld-btn'),ok=document.getElementById('ld-ok'),err=document.getElementById('ld-err');
 ok.style.display='none';err.style.display='none';
 var payload={action:'submit_lead',landing_id:f.landing_id.value,name:f.name.value.trim(),phone:f.phone.value.trim(),notes:f.notes.value.trim()};
 if(!payload.name||!payload.phone){err.textContent='Vui lòng nhập đầy đủ Họ tên và Số điện thoại.';err.style.display='block';return false;}
 btn.disabled=true;var oldTxt=btn.textContent;btn.textContent='Đang gửi thông tin…';
 try{
  var r=await fetch(location.pathname+'?public_lead=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  var d=await r.json().catch(function(){return {}});
  if(!r.ok||d.error){throw new Error(d.error||'Không gửi được thông tin, vui lòng thử lại.');}
  ok.textContent=d.message||'Cảm ơn bạn! Thông tin đã được ghi nhận, chúng tôi sẽ liên hệ ngay.';
  ok.style.display='block';f.reset();
 }catch(e){err.textContent=e.message;err.style.display='block';}
 finally{btn.disabled=false;btn.textContent=oldTxt;}
 return false;
}
</script>";

    // =========================================================================
    // MẪU 2: LUXURY DARK — SHOWROOM ĐÊM & CÔNG NGHỆ HẠNG SANG (Carbon Obsidian & Gold)
    // =========================================================================
    if ($tpl === 'luxury_dark') {
        $benCards = implode('', array_map(fn($b, $i) => "<div class=\"card\"><div class=\"spec-top\"><span class=\"spec-idx\">0" . ($i + 1) . "</span><span class=\"spec-status\">[ VERIFIED SPEC ]</span></div><h3>Tiêu chuẩn hạng mục #" . ($i + 1) . "</h3><p>{$e($b)}</p><div class=\"spec-bar\"><span></span></div></div>", $bens, array_keys($bens)));
        $stepCards = implode('', array_map(fn($s, $i) => "<div class=\"step\"><div class=\"step-node\">0" . ($i + 1) . "</div><div class=\"step-body\"><div class=\"step-tag\">QUY TRÌNH KỸ THUẬT BƯỚC " . ($i + 1) . "</div><h4>{$e($s)}</h4><div class=\"step-meta\">✓ Kiểm định chất lượng 100% trước khi bàn giao</div></div></div>", $steps, array_keys($steps)));
        $faqItems = implode('', array_map(fn($f, $i) => "<details" . ($i === 0 ? ' open' : '') . "><summary><span>Q0" . ($i + 1) . ".</span> {$e($f[0] ?? '')}</summary><p>{$e($f[1] ?? '')}</p></details>", $faqs, array_keys($faqs)));

        $cockpitVisual = $heroImg !== ''
            ? "<div class=\"hud-frame\"><div class=\"hud-hdr\"><span>● SHOWROOM LIVE VIEW</span><span>100% ZIN</span></div><img src=\"{$e($heroImg)}\" alt=\"{$e($title)}\"><div class=\"hud-ftr\"><span>⚡ CHỨNG NHẬN CHÍNH HÃNG</span><span>BẢO HÀNH ĐIỆN TỬ</span></div></div>"
            : "<div class=\"hud-panel\"><div class=\"hud-hdr\"><span>● HỆ THỐNG KIỂM ĐỊNH KỸ THUẬT</span><span class=\"gold\">PASS 100%</span></div>
               <div class=\"hud-title\">BẢNG THÔNG SỐ TIÊU CHUẨN SHOWROOM {$pName}</div>
               <div class=\"hud-metrics\">
                <div class=\"hm-row\"><div class=\"hm-lbl\"><span>Độ tương thích & An toàn hệ thống</span><b>100%</b></div><div class=\"hm-bar\"><i style=\"width:100%\"></i></div></div>
                <div class=\"hm-row\"><div class=\"hm-lbl\"><span>Linh kiện & Sản phẩm chính hãng</span><b>100%</b></div><div class=\"hm-bar\"><i style=\"width:100%\"></i></div></div>
                <div class=\"hm-row\"><div class=\"hm-lbl\"><span>Chỉ số hài lòng khách hàng thực tế</span><b>99.6%</b></div><div class=\"hm-bar\"><i style=\"width:99%\"></i></div></div>
               </div>
               <div class=\"hud-grid\">
                <div class=\"hud-box\"><b>02 NĂM</b><span>Bảo hành điện tử</span></div>
                <div class=\"hud-box\"><b>1 ĐỔI 1</b><span>Nếu có lỗi NSX</span></div>
                <div class=\"hud-box\"><b>5 PHÚT</b><span>Báo giá chuẩn xác</span></div>
               </div></div>";

        return "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)} — {$pName}</title>
<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link href=\"https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700&family=Outfit:wght@400;600;700;800&display=swap\" rel=\"stylesheet\">
<style>
:root{--pri:$color;--gold:#f59e0b;--gold-lt:#fde68a;--bg:#050811;--card:#0d1322;--bd:rgba(245,158,11,.24)}
$commonCss
body{font-family:'Outfit',system-ui,sans-serif;background:var(--bg);color:#e2e8f0;line-height:1.6}
.wrap{max-width:1140px;margin:0 auto;padding:0 20px}
.tele-bar{background:#020409;border-bottom:1px solid rgba(255,255,255,.08);padding:8px 0;font-family:'JetBrains Mono',monospace;font-size:11px;color:#94a3b8;letter-spacing:.6px}
.tele-bar .wrap{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
.tele-bar b{color:var(--gold)}
.nav{background:rgba(5,8,17,.88);backdrop-filter:blur(14px);border-bottom:1px solid rgba(245,158,11,.18);padding:15px 0;position:sticky;top:0;z-index:50}
.nav .wrap{display:flex;align-items:center;justify-content:space-between;gap:12px}
.brand{font-size:21px;font-weight:800;color:#fff;text-decoration:none;letter-spacing:.5px;display:flex;align-items:center;gap:10px;text-transform:uppercase}
.brand-gem{width:14px;height:14px;background:linear-gradient(135deg,var(--gold),#b45309);transform:rotate(45deg);display:inline-block;box-shadow:0 0 12px var(--gold)}
.hero{padding:68px 0 76px;background:radial-gradient(circle at 80% 20%,rgba(245,158,11,.16),transparent 42%),radial-gradient(circle at 15% 80%,var(--pri),transparent 45%),linear-gradient(180deg,#050811 0%,#0b1120 100%);border-bottom:1px solid rgba(255,255,255,.08);position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.hero-grid{display:grid;grid-template-columns:1fr;gap:40px;align-items:center;position:relative;z-index:2}
@media(min-width:860px){.hero-grid{grid-template-columns:1.15fr .85fr}}
.vip-tag{display:inline-flex;align-items:center;gap:8px;background:rgba(245,158,11,.14);border:1px solid var(--gold);color:var(--gold-lt);padding:6px 16px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;text-transform:uppercase;margin-bottom:18px}
.hero h1{font-size:clamp(30px,4.5vw,50px);line-height:1.12;margin:0 0 18px;font-weight:800;background:linear-gradient(180deg,#ffffff 0%,#cbd5e1 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero p.lead{font-size:17.5px;color:#94a3b8;margin:0 0 28px}
.spec-counters{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:28px;border-top:1px solid rgba(255,255,255,.1);border-bottom:1px solid rgba(255,255,255,.1);padding:16px 0}
.sc-item b{display:block;font-size:22px;color:var(--gold);font-family:'JetBrains Mono',monospace}
.sc-item span{font-size:12px;color:#cbd5e1;text-transform:uppercase;letter-spacing:.5px}
.btn-gold{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:#050811;font-weight:800;padding:15px 32px;border-radius:6px;text-decoration:none;text-transform:uppercase;letter-spacing:.5px;box-shadow:0 0 28px rgba(245,158,11,.35)}
.btn-dark{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.06);color:#fff;border:1px solid rgba(255,255,255,.2);padding:14px 24px;border-radius:6px;text-decoration:none;font-weight:700}
.hud-panel,.hud-frame{background:linear-gradient(145deg,#0f172a,#070b16);border:1px solid var(--bd);border-radius:12px;padding:24px;box-shadow:0 24px 60px rgba(0,0,0,.65),inset 0 0 24px rgba(245,158,11,.05)}
.hud-frame img{width:100%;max-height:360px;object-fit:cover;border-radius:8px;margin:12px 0}
.hud-hdr,.hud-ftr{display:flex;justify-content:space-between;font-family:'JetBrains Mono',monospace;font-size:11px;color:#64748b}
.hud-hdr .gold{color:#10b981;font-weight:700}
.hud-title{font-weight:800;font-size:15px;color:#f8fafc;margin:14px 0 18px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,.08)}
.hm-row{margin-bottom:14px}
.hm-lbl{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px;color:#cbd5e1}
.hm-lbl b{color:var(--gold);font-family:'JetBrains Mono',monospace}
.hm-bar{height:6px;background:#1e293b;border-radius:99px;overflow:hidden}
.hm-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--pri),var(--gold))}
.hud-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}
.hud-box{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);padding:10px;border-radius:8px;text-align:center}
.hud-box b{display:block;color:#fff;font-size:14px;font-family:'JetBrains Mono',monospace}
.hud-box span{font-size:11px;color:#94a3b8}
section{padding:68px 0}
.sec-head{margin-bottom:40px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:16px;border-bottom:1px solid rgba(255,255,255,.08);padding-bottom:18px}
.sec-head h2{font-size:clamp(24px,3.5vw,34px);margin:6px 0 0;color:#fff;text-transform:uppercase;letter-spacing:.5px}
.sec-sub{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--gold);text-transform:uppercase;letter-spacing:1.2px}
.black-card{background:radial-gradient(circle at 90% 10%,rgba(245,158,11,.25),transparent 45%),linear-gradient(135deg,#111827 0%,#030712 100%);border:1.5px solid var(--gold);border-radius:16px;padding:32px;display:grid;grid-template-columns:1fr;gap:24px;align-items:center;box-shadow:0 20px 50px rgba(0,0,0,.6)}
@media(min-width:780px){.black-card{grid-template-columns:1.4fr .6fr}}
.bc-chip{display:inline-block;background:linear-gradient(135deg,#fde68a,#d97706);color:#000;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:800;padding:4px 12px;border-radius:4px;margin-bottom:12px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}
.card{background:var(--card);border:1px solid rgba(255,255,255,.08);border-top:2px solid var(--gold);border-radius:10px;padding:24px;display:flex;flex-direction:column;justify-content:space-between}
.spec-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;font-family:'JetBrains Mono',monospace}
.spec-idx{font-size:26px;font-weight:800;color:var(--gold)}
.spec-status{font-size:11px;color:#10b981;background:rgba(16,185,129,.1);padding:3px 8px;border-radius:4px}
.card h3{margin:0 0 8px;font-size:17px;color:#fff}
.card p{margin:0 0 18px;color:#cbd5e1;font-size:15px}
.spec-bar{height:3px;background:rgba(255,255,255,.08);position:relative}.spec-bar span{position:absolute;left:0;top:0;bottom:0;width:75%;background:var(--gold)}
.steps{display:grid;gap:16px;max-width:860px;margin:0 auto}
.step{display:flex;gap:20px;align-items:flex-start;background:var(--card);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:22px}
.step-node{width:48px;height:48px;border-radius:8px;background:rgba(245,158,11,.15);border:1px solid var(--gold);color:var(--gold);font-family:'JetBrains Mono',monospace;font-weight:800;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-tag{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--gold);margin-bottom:4px}
.step-body h4{margin:0 0 6px;font-size:17px;color:#fff}
.step-meta{font-size:12.5px;color:#10b981}
.faq-wrap{max-width:860px;margin:0 auto}
details{background:var(--card);border:1px solid rgba(255,255,255,.09);border-radius:10px;padding:18px 22px;margin-bottom:12px}
summary{font-weight:700;color:#fff;cursor:pointer;font-size:16px}
summary span{color:var(--gold);font-family:'JetBrains Mono',monospace;margin-right:8px}
details p{margin:12px 0 0;color:#94a3b8}
.bay-booking{background:linear-gradient(180deg,#090d1a 0%,#020409 100%);border-top:1px solid var(--bd);padding:72px 0}
.bay-grid{display:grid;grid-template-columns:1fr;gap:36px;align-items:center}
@media(min-width:840px){.bay-grid{grid-template-columns:1fr 1fr}}
.form-card{background:#0d1322;border:1.5px solid var(--bd);border-radius:14px;padding:32px;box-shadow:0 24px 60px rgba(0,0,0,.7)}
.form-card h2{margin:0 0 6px;color:#fff;font-size:24px;text-transform:uppercase}
form{display:grid;gap:14px;margin-top:18px}
input,textarea{width:100%;padding:13px 15px;background:#050811;border:1px solid #334155;border-radius:8px;color:#fff;font-family:inherit;font-size:15px}
input:focus,textarea:focus{outline:none;border-color:var(--gold)}
button[type=submit]{padding:16px;border:0;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#050811;font-weight:800;font-size:16px;text-transform:uppercase;letter-spacing:.5px;cursor:pointer}
.form-safe{text-align:center;font-size:12px;color:#64748b}
footer{background:#020409;border-top:1px solid rgba(255,255,255,.07);padding:28px 0;text-align:center;color:#64748b;font-size:13px}
</style></head><body data-template=\"luxury_dark\">
$draftBar
<div class=\"tele-bar\"><div class=\"wrap\"><span><b>[SHOWROOM STANDARD]</b> · HỆ THỐNG DỊCH VỤ & NÂNG CẤP CHÍNH HÃNG</span><span>BẢO HÀNH ĐIỆN TỬ · HỖ TRỢ KỸ THUẬT 24/7</span></div></div>
<nav class=\"nav\"><div class=\"wrap\"><a class=\"brand\" href=\"#\"><span class=\"brand-gem\"></span>{$pName}</a><div style=\"display:flex;gap:10px;align-items:center\">" . ($hotline ? "<a class=\"btn-dark\" style=\"padding:8px 16px;font-size:13px\" href=\"tel:$telClean\">📞 {$e($hotline)}</a>" : '') . "<a class=\"btn-gold\" style=\"padding:9px 18px;font-size:13px\" href=\"#lien-he\">{$e($cta)}</a></div></div></nav>
<header class=\"hero\"><div class=\"wrap\"><div class=\"hero-grid\">
 <div>
  <div class=\"vip-tag\">⚡ {$badge}</div>
  <h1>{$e($title)}</h1>
  <p class=\"lead\">{$lead}</p>
  <div class=\"spec-counters\">
   <div class=\"sc-item\"><b>100%</b><span>Chính hãng & Minh bạch</span></div>
   <div class=\"sc-item\"><b>5 PHÚT</b><span>Tiếp nhận & Báo giá</span></div>
   <div class=\"sc-item\"><b>5.0 ★</b><span>Kỹ thuật tiêu chuẩn</span></div>
  </div>
  <div style=\"display:flex;gap:12px;flex-wrap:wrap\"><a class=\"btn-gold\" href=\"#lien-he\">{$e($cta)} →</a>" . ($hotline ? "<a class=\"btn-dark\" href=\"tel:$telClean\">📞 Hotline: {$e($hotline)}</a>" : '') . "</div>
 </div>
 $cockpitVisual
</div></div></header>
<section><div class=\"wrap\">
 <div class=\"black-card\">
  <div><span class=\"bc-chip\">✦ VIP PRIVILEGE · ĐẶC QUYỀN KHÁCH HÀNG</span><h3 style=\"margin:0 0 10px;font-size:24px;color:#fff\">Đặc quyền & Cam kết khi đặt lịch tại {$pName}</h3><p style=\"margin:0;color:#fde68a;font-size:16px\">{$offer}</p></div>
  <div style=\"text-align:center;border-left:1px dashed rgba(245,158,11,.35);padding-left:20px\"><div style=\"font-family:'JetBrains Mono',monospace;font-size:12px;color:#94a3b8;margin-bottom:8px\">MÃ ƯU ĐÃI SHOWROOM</div><div style=\"font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:800;color:var(--gold);letter-spacing:2px;margin-bottom:14px\">VIP-MEMBER</div><a class=\"btn-gold\" style=\"width:100%;justify-content:center\" href=\"#lien-he\">Nhận đặc quyền →</a></div>
 </div>
</div></section>
<section style=\"padding-top:0\"><div class=\"wrap\"><div class=\"sec-head\"><div><div class=\"sec-sub\">// TECHNICAL ADVANTAGES</div><h2>Giá trị & Công nghệ Vượt trội</h2></div></div><div class=\"cards\">$benCards</div></div></section>
<section style=\"background:#080c17\"><div class=\"wrap\"><div class=\"sec-head\"><div><div class=\"sec-sub\">// STANDARD WORKFLOW</div><h2>Quy trình Kỹ thuật Tiêu chuẩn</h2></div></div><div class=\"steps\">$stepCards</div></div></section>
<section><div class=\"wrap\"><div class=\"sec-head\"><div><div class=\"sec-sub\">// Q&A</div><h2>Giải đáp Chuyên môn</h2></div></div><div class=\"faq-wrap\">$faqItems</div></div></section>
<section class=\"bay-booking\" id=\"lien-he\"><div class=\"wrap\"><div class=\"bay-grid\">
 <div><div class=\"vip-tag\">ĐẶT LỊCH KHOANG DỊCH VỤ VIP</div><h2 style=\"font-size:34px;color:#fff;margin:0 0 14px\">Sẵn sàng nâng cấp & trải nghiệm tại {$pName}</h2><p style=\"color:#94a3b8;margin:0 0 24px\">Để lại thông tin bên cạnh để chuyên viên kỹ thuật chuẩn bị phương án & báo giá chính xác nhất cho bạn.</p>
  <div style=\"display:grid;gap:12px\">
   <div style=\"background:#0d1322;border:1px solid rgba(255,255,255,.08);padding:14px 18px;border-radius:10px\">✓ <b>Báo giá minh bạch:</b> Niêm yết rõ ràng, không phát sinh chi phí ẩn.</div>
   <div style=\"background:#0d1322;border:1px solid rgba(255,255,255,.08);padding:14px 18px;border-radius:10px\">✓ <b>Kỹ thuật chuyên sâu:</b> Đảm bảo an toàn tuyệt đối & thẩm mỹ cao nhất.</div>
  </div>
 </div>
 <div class=\"form-card\"><h2>{$e($cta)}</h2><p style=\"color:#94a3b8;font-size:14px;margin:0\">Chuyên viên kỹ thuật {$pName} sẽ liên hệ lại ngay sau 5 phút.</p>$formFields</div>
</div></div></section>
<footer><div class=\"wrap\">© " . date('Y') . " <b>{$pName}</b> · Showroom & Dịch vụ Kỹ thuật Chính hãng" . ($hotline ? " · Hotline: {$e($hotline)}" : '') . "</div></footer>
$floatCall
$commonJs</body></html>";
    }

    // =========================================================================
    // MẪU 3: SPLIT LEAD — PHỄU CHỐT LEAD TỐC ĐỘ CAO 2 CỘT STICKY (Direct Response)
    // =========================================================================
    if ($tpl === 'split_lead') {
        $heroChecks = implode('', array_map(fn($b) => "<div class=\"sp-check\"><span class=\"sp-tick\">✓</span><span>{$e($b)}</span></div>", array_slice($bens, 0, 4)));
        $benCards = implode('', array_map(fn($b, $i) => "<div class=\"card\"><div class=\"sp-num\">#" . ($i + 1) . "</div><div style=\"flex:1\"><b>Giá trị thực tế " . ($i + 1) . "</b><p>{$e($b)}</p></div><span class=\"sp-inc\">✓ Đã bao gồm</span></div>", $bens, array_keys($bens)));
        $stepCards = implode('', array_map(fn($s, $i) => "<div class=\"step\"><div class=\"sp-snum\">Bước " . ($i + 1) . "</div><p>{$e($s)}</p></div>", $steps, array_keys($steps)));
        $faqItems = implode('', array_map(fn($f, $i) => "<details" . ($i === 0 ? ' open' : '') . "><summary>{$e($f[0] ?? '')}</summary><p>{$e($f[1] ?? '')}</p></details>", $faqs, array_keys($faqs)));

        return "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)} — {$pName}</title>
<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link href=\"https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">
<style>
:root{--pri:$color;--dark:#0f172a}
$commonCss
body{font-family:'Be Vietnam Pro',system-ui,sans-serif;background:#f1f5f9;color:#1e293b;line-height:1.6}
.wrap{max-width:1160px;margin:0 auto;padding:0 18px}
.live-ticker{background:#0f172a;color:#f8fafc;padding:9px 0;font-size:12.5px;border-bottom:2px solid var(--pri)}
.live-ticker .wrap{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.live-dot{display:inline-block;width:8px;height:8px;background:#22c55e;border-radius:50%;margin-right:6px;box-shadow:0 0 0 4px rgba(34,197,94,.25)}
.funnel-wrap{display:grid;grid-template-columns:1fr;gap:28px;padding:32px 0 64px;align-items:start}
@media(min-width:900px){.funnel-wrap{grid-template-columns:1.28fr .92fr}}
.story-col{display:grid;gap:24px}
.hero-box{background:#fff;border-radius:20px;padding:32px;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(15,23,42,.05)}
.sp-badge{display:inline-block;background:#ecfdf5;color:var(--pri);border:1px solid #a7f3d0;font-weight:800;font-size:12.5px;padding:5px 14px;border-radius:99px;margin-bottom:14px}
.hero-box h1{font-size:clamp(26px,3.6vw,40px);line-height:1.18;margin:0 0 14px;color:#0f172a;font-weight:800}
.hero-box p.lead{font-size:16.5px;color:#475569;margin:0 0 22px}
.sp-checks{display:grid;gap:10px;margin-bottom:24px}
.sp-check{display:flex;align-items:flex-start;gap:10px;background:#f8fafc;padding:11px 14px;border-radius:10px;font-weight:600;color:#0f172a;border:1px solid #e2e8f0}
.sp-tick{width:22px;height:22px;border-radius:50%;background:var(--pri);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0}
.sp-offer{background:linear-gradient(135deg,#fefce8,#fef3c7);border:2px solid #f59e0b;border-radius:14px;padding:18px 20px;color:#78350f}
.sp-offer b{display:block;color:#92400e;font-size:16px;margin-bottom:4px}
.panel{background:#fff;border-radius:20px;padding:28px;border:1px solid #e2e8f0}
.panel h2{margin:0 0 18px;font-size:21px;color:#0f172a;display:flex;align-items:center;gap:10px}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.step{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px}
.sp-snum{display:inline-block;background:var(--pri);color:#fff;font-size:11px;font-weight:800;padding:3px 10px;border-radius:99px;margin-bottom:8px;text-transform:uppercase}
.step p{margin:0;font-weight:600;font-size:14.5px;color:#0f172a}
.cards{display:grid;gap:12px}
.card{display:flex;align-items:center;gap:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px}
.sp-num{width:38px;height:38px;border-radius:10px;background:#0f172a;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.card b{display:block;font-size:15px;color:#0f172a}
.card p{margin:2px 0 0;font-size:14px;color:#475569}
.sp-inc{background:#dcfce7;color:#15803d;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:99px;white-space:nowrap}
details{border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:10px;background:#f8fafc}
summary{font-weight:700;cursor:pointer;color:#0f172a}
details p{margin:8px 0 0;color:#475569;font-size:14.5px}
.sticky-col{position:sticky;top:20px}
.form-card{background:#fff;border:2.5px solid var(--pri);border-radius:22px;padding:28px;box-shadow:0 24px 60px rgba(15,23,42,.14);position:relative;overflow:hidden}
.prog-bar{background:#f1f5f9;border-radius:99px;height:8px;margin-bottom:8px;overflow:hidden}
.prog-bar span{display:block;width:88%;height:100%;background:linear-gradient(90deg,var(--pri),#10b981)}
.prog-txt{font-size:12px;font-weight:700;color:var(--pri);margin-bottom:14px;display:flex;justify-content:space-between}
.form-card h2{margin:0 0 6px;font-size:23px;color:#0f172a;font-weight:800}
.form-card p.sub{margin:0 0 16px;font-size:13.5px;color:#64748b}
form{display:grid;gap:12px}
input,textarea{width:100%;padding:13px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:15px;font-family:inherit;background:#f8fafc}
input:focus,textarea:focus{outline:none;border-color:var(--pri);background:#fff;box-shadow:0 0 0 4px rgba(16,185,129,.12)}
button[type=submit]{padding:16px;border:0;border-radius:12px;background:var(--pri);color:#fff;font-weight:800;font-size:16.5px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,.18)}
.seals{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;text-align:center;font-size:11.5px;font-weight:700;color:#475569}
.form-safe{text-align:center;font-size:11.5px;color:#64748b}
footer{padding:24px 0;text-align:center;font-size:13px;color:#64748b;border-top:1px solid #cbd5e1;background:#fff}
</style></head><body data-template=\"split_lead\">
$draftBar
<div class=\"live-ticker\"><div class=\"wrap\"><span><i class=\"live-dot\"></i><b>{$pName}</b> — Hệ thống tiếp nhận tư vấn & báo giá trực tiếp</span><span>" . ($hotline ? "📞 Hotline hỗ trợ ngay: <b>{$e($hotline)}</b>" : "⚡ Cam kết phản hồi trong 5 phút") . "</span></div></div>
<div class=\"wrap\"><div class=\"funnel-wrap\">
 <div class=\"story-col\">
  <div class=\"hero-box\">
   <div class=\"sp-badge\">⚡ {$badge}</div>
   <h1>{$e($title)}</h1>
   <p class=\"lead\">{$lead}</p>
   " . ($heroImg !== '' ? "<img src=\"{$e($heroImg)}\" alt=\"{$e($title)}\" style=\"width:100%;max-height:340px;object-fit:cover;border-radius:14px;margin-bottom:20px\">" : '') . "
   <div class=\"sp-checks\">$heroChecks</div>
   <div class=\"sp-offer\"><b>🎁 ƯU ĐÃI ĐẶC BIỆT KHI ĐĂNG KÝ HÔM NAY:</b>{$offer}</div>
  </div>
  <div class=\"panel\"><h2>⚡ 3 Bước nhận tư vấn & báo giá nhanh</h2><div class=\"steps\">$stepCards</div></div>
  <div class=\"panel\"><h2>💎 Chi tiết quyền lợi & giá trị nhận được</h2><div class=\"cards\">$benCards</div></div>
  <div class=\"panel\"><h2>❓ Giải đáp nhanh thắc mắc của khách hàng</h2><div class=\"faq-wrap\">$faqItems</div></div>
 </div>
 <div class=\"sticky-col\" id=\"lien-he\">
  <div class=\"form-card\">
   <div class=\"prog-txt\"><span>⚡ Bước cuối: Điền thông tin nhận tư vấn</span><span>90%</span></div>
   <div class=\"prog-bar\"><span></span></div>
   <h2>{$e($cta)}</h2>
   <p class=\"sub\">Điền thông tin bên dưới để giữ suất ưu đãi & nhận báo giá từ <b>{$pName}</b> trong 5 phút.</p>
   $formFields
   <div class=\"seals\"><div>🔒 Bảo mật 100%</div><div>⚡ Gọi lại sau 5p</div><div>✓ Tư vấn miễn phí</div></div>
  </div>
 </div>
</div></div>
<footer><div class=\"wrap\">© " . date('Y') . " <b>{$pName}</b> · Trang đăng ký tư vấn chính thức</div></footer>
$floatCall
$commonJs</body></html>";
    }

    // =========================================================================
    // MẪU 4: PROMO FLASH — SĂN ƯU ĐÃI & FLASH SALE (Đồng hồ + Thanh Suất + Vé Voucher Răng Cưa)
    // =========================================================================
    if ($tpl === 'promo_flash') {
        $benCards = implode('', array_map(fn($b, $i) => "<div class=\"card\"><div class=\"gift-rib\">🎁 ĐẶC QUYỀN #" . ($i + 1) . "</div><h3>Quyền lợi ưu đãi " . ($i + 1) . "</h3><p>{$e($b)}</p><div class=\"gift-val\">✓ Áp dụng cho khách đăng ký online</div></div>", $bens, array_keys($bens)));
        $stepCards = implode('', array_map(fn($s, $i) => "<div class=\"step\"><div class=\"fl-num\">" . ($i + 1) . "</div><h4>Bước " . ($i + 1) . "</h4><p>{$e($s)}</p></div>", $steps, array_keys($steps)));
        $faqItems = implode('', array_map(fn($f, $i) => "<details" . ($i === 0 ? ' open' : '') . "><summary>🔥 {$e($f[0] ?? '')}</summary><p>{$e($f[1] ?? '')}</p></details>", $faqs, array_keys($faqs)));

        return "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)} — {$pName}</title>
<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link href=\"https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800;900&display=swap\" rel=\"stylesheet\">
<style>
:root{--pri:$color;--red:#dc2626;--org:#ea580c;--yel:#facc15}
$commonCss
body{font-family:'Montserrat',system-ui,sans-serif;background:#fff7ed;color:#1c1917;line-height:1.6}
.wrap{max-width:1100px;margin:0 auto;padding:0 20px}
.flash-top{background:linear-gradient(90deg,#991b1b,#dc2626,#ea580c,#dc2626);color:#fff;padding:10px 14px;text-align:center;font-weight:800;font-size:13px;letter-spacing:.4px}
.hero{background:radial-gradient(circle at 50% 0%,#f59e0b 0%,transparent 50%),linear-gradient(135deg,#7f1d1d 0%,#b91c1c 50%,#c2410c 100%);color:#fff;padding:56px 0 72px;text-align:center;position:relative;overflow:hidden}
.fl-badge{display:inline-block;background:var(--yel);color:#7f1d1d;font-weight:900;font-size:13px;padding:7px 20px;border-radius:99px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:18px;box-shadow:0 8px 20px rgba(0,0,0,.25)}
.hero h1{font-size:clamp(28px,4.8vw,52px);line-height:1.12;margin:0 auto 16px;max-width:860px;font-weight:900;text-shadow:0 4px 16px rgba(0,0,0,.25)}
.hero p.lead{font-size:18px;max-width:700px;margin:0 auto 28px;color:#ffedd5;font-weight:500}
.cd-wrap{display:inline-flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;justify-content:center}
.cd-box{background:#111827;border:2px solid var(--yel);border-radius:14px;min-width:78px;padding:10px 14px;box-shadow:0 10px 24px rgba(0,0,0,.35)}
.cd-box b{display:block;font-size:28px;font-weight:900;color:var(--yel);line-height:1.1}
.cd-box span{font-size:11px;text-transform:uppercase;color:#f8fafc;font-weight:700}
.quota-box{max-width:520px;margin:0 auto 28px;background:rgba(0,0,0,.35);padding:14px 20px;border-radius:14px;border:1px solid rgba(255,255,255,.2)}
.quota-lbl{display:flex;justify-content:space-between;font-size:13px;font-weight:800;margin-bottom:8px;color:#fef08a}
.quota-bar{height:12px;background:rgba(255,255,255,.2);border-radius:99px;overflow:hidden}
.quota-bar span{display:block;width:84%;height:100%;background:linear-gradient(90deg,#facc15,#ef4444);border-radius:99px}
.btn-flash{display:inline-flex;align-items:center;gap:10px;background:var(--yel);color:#7f1d1d;font-weight:900;font-size:18px;padding:16px 36px;border-radius:99px;text-decoration:none;text-transform:uppercase;box-shadow:0 14px 32px rgba(0,0,0,.35)}
.voucher-sec{margin-top:-36px;position:relative;z-index:10;padding-bottom:32px}
.voucher{background:#fff;border:3px solid var(--red);border-radius:20px;display:grid;grid-template-columns:1fr;overflow:hidden;box-shadow:0 20px 50px rgba(220,38,38,.18)}
@media(min-width:780px){.voucher{grid-template-columns:1.6fr .7fr}}
.v-left{padding:28px 32px;position:relative}
.v-tag{display:inline-block;background:#fee2e2;color:var(--red);font-weight:800;font-size:12px;padding:4px 12px;border-radius:6px;margin-bottom:10px}
.v-left h3{margin:0 0 8px;font-size:24px;color:#7f1d1d;font-weight:900}
.v-left p{margin:0;font-size:16px;color:#431407;font-weight:600}
.v-right{background:linear-gradient(135deg,#fef2f2,#ffedd5);border-top:2.5px dashed var(--red);padding:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
@media(min-width:780px){.v-right{border-top:0;border-left:2.5px dashed var(--red)}}
.v-code{font-size:20px;font-weight:900;color:var(--red);background:#fff;border:2px dashed var(--red);padding:6px 18px;border-radius:8px;margin:8px 0 12px;letter-spacing:1.5px}
section{padding:56px 0}
.sec-head{text-align:center;max-width:700px;margin:0 auto 36px}
.sec-head h2{font-size:clamp(24px,3.6vw,36px);margin:0 0 8px;color:#7f1d1d;font-weight:900;text-transform:uppercase}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:20px}
.card{background:#fff;border:2px solid #fed7aa;border-radius:18px;padding:24px;position:relative;box-shadow:0 8px 24px rgba(234,88,12,.08)}
.gift-rib{display:inline-block;background:linear-gradient(90deg,var(--red),var(--org));color:#fff;font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:99px;margin-bottom:12px}
.card h3{margin:0 0 8px;font-size:18px;color:#1c1917;font-weight:800}
.card p{margin:0 0 14px;color:#44403c;font-size:15px}
.gift-val{font-size:12.5px;font-weight:700;color:#15803d;background:#dcfce7;padding:5px 10px;border-radius:8px;display:inline-block}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px}
.step{background:#fff;border:2px solid #fecaca;border-radius:16px;padding:22px;text-align:center}
.fl-num{width:44px;height:44px;border-radius:50%;background:var(--red);color:#fff;font-weight:900;font-size:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px}
.step h4{margin:0 0 6px;color:#7f1d1d;font-size:16px}
.step p{margin:0;font-weight:600;font-size:14.5px}
.faq-wrap{max-width:780px;margin:0 auto}
details{background:#fff;border:1.5px solid #fed7aa;border-radius:14px;padding:16px 20px;margin-bottom:12px}
summary{font-weight:800;color:#7f1d1d;cursor:pointer}
.cta-sec{background:linear-gradient(135deg,#7f1d1d 0%,#c2410c 100%);padding:68px 0;color:#fff}
.form-card{background:#fff;color:#1c1917;max-width:540px;margin:0 auto;border-radius:24px;padding:32px;border:4px solid var(--yel);box-shadow:0 24px 60px rgba(0,0,0,.4)}
.stamp{display:inline-block;background:#fee2e2;color:var(--red);font-weight:900;font-size:12px;padding:5px 14px;border-radius:99px;margin-bottom:10px}
.form-card h2{margin:0 0 6px;font-size:25px;color:#7f1d1d;font-weight:900}
form{display:grid;gap:12px;margin-top:16px}
input,textarea{width:100%;padding:13px 15px;border:2px solid #cbd5e1;border-radius:10px;font-size:15px;font-family:inherit}
button[type=submit]{padding:16px;border:0;border-radius:12px;background:linear-gradient(90deg,var(--red),var(--org));color:#fff;font-weight:900;font-size:17px;text-transform:uppercase;cursor:pointer;box-shadow:0 10px 24px rgba(220,38,38,.35)}
.form-safe{text-align:center;font-size:12px;color:#64748b}
footer{padding:24px 0;text-align:center;font-size:13px;color:#78350f;background:#ffedd5}
</style></head><body data-template=\"promo_flash\">
$draftBar
<div class=\"flash-top\">🔥 SỰ KIỆN ƯU ĐÃI ĐẶC BIỆT TẠI {$pName} — SỐ LƯỢNG SUẤT ƯU ĐÃI CÓ HẠN!</div>
<header class=\"hero\"><div class=\"wrap\">
 <div class=\"fl-badge\">⚡ {$badge}</div>
 <h1>{$e($title)}</h1>
 <p class=\"lead\">{$lead}</p>
 <div class=\"cd-wrap\" id=\"ld-cd\">
  <div class=\"cd-box\"><b>00</b><span>Ngày</span></div>
  <div class=\"cd-box\"><b id=\"cd-h\">04</b><span>Giờ</span></div>
  <div class=\"cd-box\"><b id=\"cd-m\">45</b><span>Phút</span></div>
  <div class=\"cd-box\"><b id=\"cd-s\">29</b><span>Giây</span></div>
 </div>
 <div class=\"quota-box\">
  <div class=\"quota-lbl\"><span>🔥 Đã có 42/50 khách đăng ký giữ suất</span><span>Còn 8 suất!</span></div>
  <div class=\"quota-bar\"><span></span></div>
 </div>
 <a class=\"btn-flash\" href=\"#lien-he\">🎁 {$e($cta)} Ngay →</a>
</div></header>
<div class=\"voucher-sec\"><div class=\"wrap\">
 <div class=\"voucher\">
  <div class=\"v-left\"><span class=\"v-tag\">PHIẾU CHỨNG NHẬN ƯU ĐÃI ĐẶC QUYỀN</span><h3>🎁 Quà tặng & Cam kết dành riêng cho bạn</h3><p>{$offer}</p></div>
  <div class=\"v-right\"><span style=\"font-size:12px;font-weight:700;color:#7f1d1d\">MÃ GIỮ SUẤT ƯU ĐÃI</span><div class=\"v-code\">VIP-FLASH</div><a href=\"#lien-he\" style=\"background:var(--red);color:#fff;text-decoration:none;font-weight:800;font-size:13px;padding:8px 18px;border-radius:99px\">Bấm kích hoạt ưu đãi ↓</a></div>
 </div>
</div></div>
<section><div class=\"wrap\"><div class=\"sec-head\"><h2>🎁 Combo Quyền Lợi & Giá Trị Nhận Được</h2><p>Đăng ký ngay trong thời gian ưu đãi để nhận trọn bộ quyền lợi từ {$pName}</p></div><div class=\"cards\">$benCards</div></div></section>
<section style=\"background:#ffedd5\"><div class=\"wrap\"><div class=\"sec-head\"><h2>⚡ 3 Bước Giữ Suất Khuyến Mãi</h2></div><div class=\"steps\">$stepCards</div></div></section>
<section><div class=\"wrap\"><div class=\"sec-head\"><h2>❓ Câu Hỏi Thường Gặp Về Chương Trình</h2></div><div class=\"faq-wrap\">$faqItems</div></div></section>
<section class=\"cta-sec\" id=\"lien-he\"><div class=\"wrap\">
 <div class=\"form-card\">
  <span class=\"stamp\">🔥 GIỮ GIÁ ƯU ĐÃI TRONG 24H</span>
  <h2>{$e($cta)}</h2>
  <p style=\"margin:0;color:#57534e;font-size:14px\">Điền thông tin để hệ thống giữ suất ưu đãi tại <b>{$pName}</b> và liên hệ báo giá ngay.</p>
  $formFields
 </div>
</div></section>
<footer><div class=\"wrap\">© " . date('Y') . " <b>{$pName}</b>" . ($hotline ? " · Hotline: {$e($hotline)}" : '') . "</div></footer>
$floatCall
$commonJs</body></html>";
    }

    // =========================================================================
    // MẪU 5: EDITORIAL — ẤN PHẨM CHUYÊN GIA & TẠP CHÍ CAO CẤP (Magazine & Certificate)
    // =========================================================================
    if ($tpl === 'editorial') {
        $romans = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'];
        $benCards = implode('', array_map(fn($b, $i) => "<div class=\"card\"><div class=\"ed-rom\">" . ($romans[$i % count($romans)]) . ".</div><div class=\"ed-btxt\"><h3>Luận điểm giá trị thứ " . ($i + 1) . "</h3><p>{$e($b)}</p></div></div>", $bens, array_keys($bens)));
        $stepCards = implode('', array_map(fn($s, $i) => "<div class=\"step\"><div class=\"ed-snum\">Giai đoạn 0" . ($i + 1) . "</div><p>{$e($s)}</p></div>", $steps, array_keys($steps)));
        $faqItems = implode('', array_map(fn($f, $i) => "<details" . ($i === 0 ? ' open' : '') . "><summary>{$e($f[0] ?? '')}</summary><p>{$e($f[1] ?? '')}</p></details>", $faqs, array_keys($faqs)));

        return "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)} — {$pName}</title>
<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&display=swap\" rel=\"stylesheet\">
<style>
:root{--pri:$color;--ink:#1c1917;--paper:#faf8f5;--rule:#d6d0c4}
$commonCss
body{font-family:'Inter',system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.75}
.wrap{max-width:1040px;margin:0 auto;padding:0 22px}
.masthead{padding:24px 0 16px;border-bottom:3px double var(--ink);text-align:center}
.mh-meta{display:flex;justify-content:space-between;font-size:11.5px;text-transform:uppercase;letter-spacing:1.2px;color:#57534e;border-bottom:1px solid var(--rule);padding-bottom:10px;margin-bottom:14px;flex-wrap:wrap;gap:8px}
.mh-brand{font-family:'Playfair Display',Georgia,serif;font-size:clamp(26px,4vw,38px);font-weight:800;color:var(--ink);text-decoration:none;letter-spacing:-.5px}
.ed-hero{padding:52px 0;border-bottom:1px solid var(--rule)}
.ed-kicker{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--pri);margin-bottom:14px}
.ed-hero h1{font-family:'Playfair Display',Georgia,serif;font-size:clamp(32px,5vw,54px);line-height:1.14;margin:0 0 20px;font-weight:700;color:#0c0a09}
.ed-byline{font-size:13.5px;color:#57534e;border-top:1px solid var(--rule);border-bottom:1px solid var(--rule);padding:10px 0;margin-bottom:28px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px}
.ed-story{display:grid;grid-template-columns:1fr;gap:36px;align-items:start}
@media(min-width:820px){.ed-story{grid-template-columns:1.35fr .65fr}}
.dropcap::first-letter{float:left;font-family:'Playfair Display',Georgia,serif;font-size:3.4em;line-height:.85;padding-right:12px;padding-top:4px;color:var(--pri);font-weight:700}
.pull-quote{background:#f3efe6;border-left:4px solid var(--pri);padding:22px 24px;font-family:'Playfair Display',Georgia,serif;font-style:italic;font-size:18px;color:#1c1917;line-height:1.5}
.pull-quote cite{display:block;font-family:'Inter',sans-serif;font-style:normal;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--pri);margin-top:12px}
section{padding:56px 0;border-bottom:1px solid var(--rule)}
.sec-title{font-family:'Playfair Display',Georgia,serif;font-size:clamp(26px,3.5vw,36px);margin:0 0 28px;color:#0c0a09}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px}
.card{background:#fff;border:1px solid var(--rule);padding:26px;display:flex;gap:18px;align-items:flex-start}
.ed-rom{font-family:'Playfair Display',Georgia,serif;font-size:32px;font-weight:700;color:var(--pri);line-height:1}
.ed-btxt h3{font-family:'Playfair Display',Georgia,serif;margin:0 0 8px;font-size:19px;color:#0c0a09}
.ed-btxt p{margin:0;color:#44403c;font-size:15px}
.cert-box{background:#fff;border:4px double var(--ink);padding:36px;text-align:center;max-width:820px;margin:0 auto}
.cert-seal{display:inline-flex;width:54px;height:54px;border-radius:50%;background:var(--pri);color:#fff;align-items:center;justify-content:center;font-size:24px;margin-bottom:14px}
.cert-box h3{font-family:'Playfair Display',Georgia,serif;font-size:26px;margin:0 0 10px}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px}
.step{background:#fff;border:1px solid var(--rule);padding:22px}
.ed-snum{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--pri);margin-bottom:8px;border-bottom:1px solid var(--rule);padding-bottom:6px}
.step p{margin:0;font-weight:600;color:#1c1917}
.faq-wrap{max-width:780px}
details{border-bottom:1px solid var(--rule);padding:16px 0}
summary{font-family:'Playfair Display',Georgia,serif;font-size:19px;font-weight:700;cursor:pointer}
.consult-grid{display:grid;grid-template-columns:1fr;gap:36px;align-items:start}
@media(min-width:820px){.consult-grid{grid-template-columns:1fr 1.1fr}}
.form-card{background:#fff;border:1px solid var(--ink);padding:32px;box-shadow:12px 12px 0 rgba(28,25,23,.08)}
.form-card h2{font-family:'Playfair Display',Georgia,serif;margin:0 0 8px;font-size:26px}
form{display:grid;gap:14px;margin-top:18px}
input,textarea{width:100%;padding:12px 14px;border:1px solid #a8a29e;background:#faf8f5;font-size:15px;font-family:inherit}
button[type=submit]{padding:15px;border:0;background:var(--ink);color:#fff;font-weight:700;font-size:15px;text-transform:uppercase;letter-spacing:1px;cursor:pointer}
.form-safe{text-align:center;font-size:12px;color:#78716c}
footer{padding:32px 0;text-align:center;font-size:13px;color:#57534e}
</style></head><body data-template=\"editorial\">
$draftBar
<header class=\"masthead\"><div class=\"wrap\">
 <div class=\"mh-meta\"><span>ẤN PHẨM CHUYÊN ĐỀ & HỒ SƠ NĂNG LỰC</span><span>{$badge}</span><span>" . ($hotline ? "HOTLINE: {$e($hotline)}" : "CHỨNG NHẬN CHẤT LƯỢNG") . "</span></div>
 <a class=\"mh-brand\" href=\"#\">{$pName}</a>
</div></header>
<article class=\"ed-hero\"><div class=\"wrap\">
 <div class=\"ed-kicker\">CHUYÊN GIA TƯ VẤN & GIẢI PHÁP</div>
 <h1>{$e($title)}</h1>
 <div class=\"ed-byline\"><span>Thực hiện: <b>Hội đồng Chuyên môn {$pName}</b></span><span>Cam kết chất lượng & Minh bạch chi phí</span></div>
 <div class=\"ed-story\">
  <div>
   <p class=\"dropcap\" style=\"font-size:18px;margin:0 0 24px;color:#292524\">{$lead}</p>
   " . ($heroImg !== '' ? "<img src=\"{$e($heroImg)}\" alt=\"{$e($title)}\" style=\"width:100%;max-height:380px;object-fit:cover;border:1px solid var(--rule);margin-bottom:20px\">" : '') . "
   <a href=\"#lien-he\" style=\"display:inline-block;background:var(--ink);color:#fff;text-decoration:none;padding:13px 28px;font-weight:700;font-size:14px;text-transform:uppercase;letter-spacing:1px\">{$e($cta)} →</a>
  </div>
  <div class=\"pull-quote\">“{$offer}”<cite>— Cam kết đặc quyền từ {$pName}</cite></div>
 </div>
</div></article>
<section><div class=\"wrap\"><h2 class=\"sec-title\">I. Hệ giá trị cốt lõi dành cho khách hàng</h2><div class=\"cards\">$benCards</div></div></section>
<section><div class=\"wrap\"><div class=\"cert-box\"><div class=\"cert-seal\">★</div><h3>Chứng Nhận Cam Kết Chất Lượng & Dịch Vụ</h3><p style=\"max-width:640px;margin:0 auto 18px;color:#44403c\">{$offer}</p><div style=\"font-family:'Playfair Display',Georgia,serif;font-style:italic;font-size:17px;color:var(--pri)\">Ban Giám Đốc & Đội Ngũ Chuyên Gia {$pName}</div></div></div></section>
<section><div class=\"wrap\"><h2 class=\"sec-title\">II. Lộ trình triển khai tiêu chuẩn</h2><div class=\"steps\">$stepCards</div></div></section>
<section><div class=\"wrap\"><h2 class=\"sec-title\">III. Giải đáp chuyên sâu</h2><div class=\"faq-wrap\">$faqItems</div></div></section>
<section id=\"lien-he\"><div class=\"wrap\"><div class=\"consult-grid\">
 <div><div class=\"ed-kicker\">ĐẶT LỊCH THAM VẤN 1-1</div><h2 class=\"sec-title\" style=\"margin-bottom:14px\">Bàn Tư Vấn Chuyên Gia {$pName}</h2><p style=\"color:#44403c\">Hãy để lại thông tin liên hệ. Chuyên viên phụ trách của chúng tôi sẽ chuẩn bị hồ sơ báo giá và liên hệ tư vấn trực tiếp trong vòng 5 phút.</p>" . ($hotline ? "<p style=\"font-size:18px;font-weight:700\">📞 Đường dây nóng: <a href=\"tel:$telClean\" style=\"color:var(--pri)\">{$e($hotline)}</a></p>" : '') . "</div>
 <div class=\"form-card\"><h2>{$e($cta)}</h2><p style=\"margin:0;color:#57534e;font-size:14px\">Thông tin của quý khách được bảo mật tuyệt đối.</p>$formFields</div>
</div></div></section>
<footer><div class=\"wrap\">© " . date('Y') . " <b>{$pName}</b> · Ấn phẩm thông tin & đăng ký tư vấn chính thức</div></footer>
$floatCall
$commonJs</body></html>";
    }

    // =========================================================================
    // MẪU 1 (MẶC ĐỊNH): MODERN — BENTO SAAS & CHUYỂN ĐỔI CAO (Bento Grid + Bảng So Sánh + Review)
    // =========================================================================
    $bentoCards = implode('', array_map(function ($b, $i) use ($e, $pName) {
        if ($i === 0) {
            return "<div class=\"card bento-main\"><span class=\"bm-tag\">★ GIÁ TRỊ TRỌNG TÂM #1</span><h3>Điểm khác biệt hàng đầu tại {$pName}</h3><p>{$e($b)}</p></div>";
        }
        return "<div class=\"card\"><div class=\"c-ic\">0" . ($i + 1) . "</div><h3>Lợi ích vượt trội #" . ($i + 1) . "</h3><p>{$e($b)}</p></div>";
    }, $bens, array_keys($bens)));

    $stepCards = implode('', array_map(fn($s, $i) => "<div class=\"step\"><div class=\"s-top\"><span class=\"s-num\">0" . ($i + 1) . "</span><span class=\"s-line\"></span></div><h4>Bước " . ($i + 1) . "</h4><p>{$e($s)}</p></div>", $steps, array_keys($steps)));
    $faqItems = implode('', array_map(fn($f, $i) => "<details" . ($i === 0 ? ' open' : '') . "><summary>{$e($f[0] ?? '')}</summary><p>{$e($f[1] ?? '')}</p></details>", $faqs, array_keys($faqs)));

    $heroBentoRight = $heroImg !== ''
        ? "<div class=\"hb-img-wrap\"><img src=\"{$e($heroImg)}\" alt=\"{$e($title)}\"><div class=\"hb-float\"><b>★ 4.9/5 Đánh giá</b><span>Chính hãng · Bảo hành uy tín</span></div></div>"
        : "<div class=\"hb-showcase\">
            <div class=\"hb-main-card\">
             <div style=\"display:flex;justify-content:space-between;align-items:center;margin-bottom:12px\"><span class=\"hb-pill\">✓ CHỨNG NHẬN CHÍNH HÃNG</span><span style=\"font-size:12px;color:#047857;font-weight:700\">● Đang hoạt động</span></div>
             <div style=\"font-size:28px;font-weight:800;color:#0f172a;line-height:1.15;margin-bottom:6px\">99.4% Hài Lòng</div>
             <div style=\"font-size:13.5px;color:#475569;margin-bottom:16px\">Khách hàng đánh giá cao chất lượng dịch vụ & sự tận tâm tại <b>{$pName}</b></div>
             <div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;font-size:13.5px;color:#0f172a;font-weight:600\">🎁 " . mb_substr($offer, 0, 110) . "</div>
            </div>
            <div class=\"hb-sub-grid\">
             <div class=\"hb-mini\"><b>⚡ 5 Phút</b><span>Phản hồi & Báo giá nhanh</span></div>
             <div class=\"hb-mini\"><b>🛡 100%</b><span>Bảo hành chuẩn cam kết</span></div>
            </div>
           </div>";

    return "<!doctype html><html lang=\"vi\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$e($title)} — {$pName}</title>
<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link href=\"https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap\" rel=\"stylesheet\">
<style>
:root{--pri:$color;--dark:#0f172a}
$commonCss
body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:#f8fafc;color:#1e293b;line-height:1.65}
.wrap{max-width:1120px;margin:0 auto;padding:0 20px}
.pill-nav{position:sticky;top:12px;z-index:50;padding:0 16px;margin-top:12px}
.pill-bar{max-width:1080px;margin:0 auto;background:rgba(255,255,255,.9);backdrop-filter:blur(14px);border:1px solid #e2e8f0;border-radius:99px;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 10px 30px rgba(15,23,42,.06)}
.brand{font-weight:800;font-size:18px;color:#0f172a;text-decoration:none;display:flex;align-items:center;gap:8px}
.brand i{width:12px;height:12px;border-radius:4px;background:var(--pri);display:inline-block}
.nav-links{display:none;gap:20px;font-size:13.5px;font-weight:600}
.nav-links a{color:#475569;text-decoration:none}
@media(min-width:780px){.nav-links{display:flex}}
.hero{padding:52px 0 68px;background:radial-gradient(circle at 85% 15%,rgba(16,185,129,.12),transparent 40%),radial-gradient(circle at 10% 80%,rgba(59,130,246,.08),transparent 40%)}
.hero-grid{display:grid;grid-template-columns:1fr;gap:36px;align-items:center}
@media(min-width:860px){.hero-grid{grid-template-columns:1.15fr .85fr}}
.h-badge{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid #cbd5e1;padding:6px 16px;border-radius:99px;font-size:13px;font-weight:700;color:var(--pri);margin-bottom:18px;box-shadow:0 4px 12px rgba(0,0,0,.04)}
.hero h1{font-size:clamp(30px,4.5vw,48px);line-height:1.14;margin:0 0 18px;color:#0f172a;font-weight:800;letter-spacing:-.02em}
.hero p.lead{font-size:17.5px;color:#475569;margin:0 0 26px}
.btn-pri{display:inline-flex;align-items:center;gap:8px;background:var(--pri);color:#fff;font-weight:800;padding:15px 30px;border-radius:14px;text-decoration:none;box-shadow:0 12px 28px rgba(15,23,42,.18)}
.btn-sec{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#0f172a;border:1.5px solid #cbd5e1;font-weight:700;padding:14px 24px;border-radius:14px;text-decoration:none}
.hb-showcase{display:grid;gap:14px}
.hb-main-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:24px;box-shadow:0 20px 50px rgba(15,23,42,.08)}
.hb-pill{background:#ecfdf5;color:#047857;font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:99px}
.hb-sub-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.hb-mini{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.hb-mini b{display:block;font-size:20px;color:var(--pri);margin-bottom:2px}
.hb-mini span{font-size:13px;color:#64748b;font-weight:600}
.hb-img-wrap{position:relative}.hb-img-wrap img{width:100%;max-height:400px;object-fit:cover;border-radius:24px;box-shadow:0 24px 50px rgba(15,23,42,.18)}
.hb-float{position:absolute;bottom:16px;left:16px;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);padding:12px 18px;border-radius:14px;box-shadow:0 10px 24px rgba(0,0,0,.15)}
.hb-float b{display:block;color:#0f172a;font-size:14px}.hb-float span{font-size:12px;color:#475569}
section{padding:64px 0}
.sec-head{text-align:center;max-width:680px;margin:0 auto 40px}
.sec-tag{display:inline-block;background:#ecfdf5;color:var(--pri);font-size:12px;font-weight:800;padding:5px 14px;border-radius:99px;text-transform:uppercase;margin-bottom:10px}
.sec-head h2{font-size:clamp(24px,3.6vw,36px);margin:0 0 10px;color:#0f172a;font-weight:800}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:26px;box-shadow:0 4px 20px rgba(15,23,42,.03)}
.bento-main{background:linear-gradient(135deg,var(--pri) 0%,#0f172a 100%);color:#fff;border:0}
@media(min-width:780px){.bento-main{grid-column:span 2}}
.bm-tag{display:inline-block;background:rgba(255,255,255,.18);padding:4px 12px;border-radius:99px;font-size:11.5px;font-weight:800;margin-bottom:12px}
.bento-main h3{color:#fff !important;font-size:22px !important;margin:0 0 10px}
.bento-main p{color:rgba(255,255,255,.9) !important;font-size:16px !important;margin:0}
.c-ic{width:42px;height:42px;border-radius:12px;background:#f1f5f9;color:var(--pri);font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.card h3{margin:0 0 8px;font-size:17px;color:#0f172a;font-weight:800}
.card p{margin:0;color:#475569;font-size:15px}
.cmp-grid{display:grid;grid-template-columns:1fr;gap:20px;max-width:920px;margin:0 auto}
@media(min-width:760px){.cmp-grid{grid-template-columns:1.1fr .9fr}}
.cmp-good{background:#fff;border:2px solid var(--pri);border-radius:20px;padding:28px;box-shadow:0 16px 40px rgba(15,23,42,.07)}
.cmp-bad{background:#f1f5f9;border:1px solid #cbd5e1;border-radius:20px;padding:28px;color:#64748b}
.cmp-row{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.2);font-size:14.5px;font-weight:600}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px}
.step{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px}
.s-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.s-num{width:40px;height:40px;border-radius:12px;background:var(--pri);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.s-line{height:2px;flex:1;background:#e2e8f0}
.step h4{margin:0 0 6px;font-size:16px;color:#0f172a}
.step p{margin:0;color:#475569;font-size:14.5px}
.faq-wrap{max-width:780px;margin:0 auto}
details{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 22px;margin-bottom:12px}
summary{font-weight:700;color:#0f172a;cursor:pointer}
.book-sec{background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#fff;padding:72px 0}
.book-grid{display:grid;grid-template-columns:1fr;gap:36px;align-items:center}
@media(min-width:840px){.book-grid{grid-template-columns:1fr 1fr}}
.form-card{background:#fff;color:#0f172a;border-radius:24px;padding:32px;box-shadow:0 24px 60px rgba(0,0,0,.3)}
.form-card h2{margin:0 0 6px;font-size:24px;font-weight:800}
form{display:grid;gap:12px;margin-top:16px}
input,textarea{width:100%;padding:13px 15px;border:1.5px solid #cbd5e1;border-radius:11px;font-size:15px;font-family:inherit;background:#f8fafc}
button[type=submit]{padding:15px;border:0;border-radius:12px;background:var(--pri);color:#fff;font-weight:800;font-size:16px;cursor:pointer}
.form-safe{text-align:center;font-size:12px;color:#64748b}
footer{padding:28px 0;text-align:center;font-size:13.5px;color:#64748b;border-top:1px solid #e2e8f0;background:#fff}
</style></head><body data-template=\"modern\">
$draftBar
<div class=\"pill-nav\"><div class=\"pill-bar\">
 <a class=\"brand\" href=\"#\"><i></i>{$pName}</a>
 <div class=\"nav-links\"><a href=\"#loi-ich\">Lợi ích Bento</a><a href=\"#so-sanh\">So sánh</a><a href=\"#quy-trinh\">Quy trình</a><a href=\"#faq\">Hỏi đáp</a></div>
 <a href=\"#lien-he\" style=\"background:var(--pri);color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:8px 18px;border-radius:99px\">{$e($cta)}</a>
</div></div>
<header class=\"hero\"><div class=\"wrap\"><div class=\"hero-grid\">
 <div>
  <div class=\"h-badge\">● {$badge}</div>
  <h1>{$e($title)}</h1>
  <p class=\"lead\">{$lead}</p>
  <div style=\"display:flex;gap:12px;flex-wrap:wrap;margin-bottom:22px\"><a class=\"btn-pri\" href=\"#lien-he\">{$e($cta)} →</a>" . ($hotline ? "<a class=\"btn-sec\" href=\"tel:$telClean\">📞 {$e($hotline)}</a>" : '') . "</div>
  <div style=\"font-size:13px;color:#475569;font-weight:600\">★★★★★ <b>4.9/5</b> · Đã phục vụ hàng ngàn khách hàng hài lòng</div>
 </div>
 $heroBentoRight
</div></div></header>
<section id=\"loi-ich\"><div class=\"wrap\"><div class=\"sec-head\"><span class=\"sec-tag\">Bento Value Grid</span><h2>Giá trị cốt lõi dành cho bạn</h2><p>Thiết kế giải pháp toàn diện, minh bạch và tối ưu hiệu quả thực tế tại {$pName}</p></div><div class=\"cards\">$bentoCards</div></div></section>
<section id=\"so-sanh\" style=\"background:#fff\"><div class=\"wrap\"><div class=\"sec-head\"><span class=\"sec-tag\">Sự khác biệt</span><h2>Tại sao khách hàng chọn {$pName}?</h2></div>
 <div class=\"cmp-grid\">
  <div class=\"cmp-good\"><h3 style=\"margin:0 0 16px;color:var(--pri);font-size:20px\">✓ Khi đồng hành cùng {$pName}</h3>
   <div class=\"cmp-row\"><span>✅</span><span>Sản phẩm & dịch vụ chính hãng, nguồn gốc xuất xứ rõ ràng 100%</span></div>
   <div class=\"cmp-row\"><span>✅</span><span>Báo giá niêm yết minh bạch ngay từ đầu, không phát sinh chi phí ẩn</span></div>
   <div class=\"cmp-row\"><span>✅</span><span>Đội ngũ kỹ thuật chuyên sâu, quy trình chuẩn hoá từng bước</span></div>
   <div class=\"cmp-row\" style=\"border:0\"><span>✅</span><span>Bảo hành điện tử uy tín, hỗ trợ kỹ thuật nhanh chóng sau bán</span></div>
  </div>
  <div class=\"cmp-bad\"><h3 style=\"margin:0 0 16px;color:#475569;font-size:20px\">✕ Dịch vụ trôi nổi / Thiếu cam kết</h3>
   <div class=\"cmp-row\"><span>❌</span><span>Linh kiện không rõ nguồn gốc, dễ hỏng vặt hoặc mất an toàn</span></div>
   <div class=\"cmp-row\"><span>❌</span><span>Báo giá mập mờ, phát sinh nhiều phụ phí sau khi làm</span></div>
   <div class=\"cmp-row\"><span>❌</span><span>Thi công thiếu quy chuẩn, ảnh hưởng thẩm mỹ & độ bền</span></div>
   <div class=\"cmp-row\" style=\"border:0\"><span>❌</span><span>Khó liên hệ bảo hành hoặc từ chối trách nhiệm khi có sự cố</span></div>
  </div>
 </div>
</div></section>
<section id=\"quy-trinh\"><div class=\"wrap\"><div class=\"sec-head\"><span class=\"sec-tag\">Quy trình chuẩn</span><h2>Triển khai nhanh gọn & Minh bạch</h2></div><div class=\"steps\">$stepCards</div></div></section>
<section id=\"faq\" style=\"background:#fff\"><div class=\"wrap\"><div class=\"sec-head\"><span class=\"sec-tag\">Giải đáp thắc mắc</span><h2>Câu hỏi thường gặp</h2></div><div class=\"faq-wrap\">$faqItems</div></div></section>
<section class=\"book-sec\" id=\"lien-he\"><div class=\"wrap\"><div class=\"book-grid\">
 <div>
  <span class=\"bm-tag\">🎁 ƯU ĐÃI ĐẶC QUYỀN TRONG THÁNG</span>
  <h2 style=\"font-size:34px;margin:0 0 14px\">{$e($cta)} cùng {$pName}</h2>
  <p style=\"color:#cbd5e1;font-size:16.5px;margin:0 0 24px\">{$offer}</p>
  <div style=\"display:grid;gap:12px;font-size:14.5px;color:#e2e8f0\">
   <div>✓ Tư vấn đúng nhu cầu thực tế, hoàn toàn miễn phí</div>
   <div>✓ Gửi bảng báo giá chi tiết và ưu đãi ngay trong 5 phút</div>
   <div>✓ Cam kết bảo mật tuyệt đối thông tin cá nhân</div>
  </div>
 </div>
 <div class=\"form-card\"><h2>{$e($cta)}</h2><p style=\"margin:0;color:#64748b;font-size:14px\">Điền thông tin bên dưới, chuyên viên <b>{$pName}</b> sẽ liên hệ ngay.</p>$formFields</div>
</div></div></section>
<footer><div class=\"wrap\">© " . date('Y') . " <b>{$pName}</b>" . ($hotline ? " · Hotline: {$e($hotline)}" : '') . "</div></footer>
$floatCall
$commonJs</body></html>";
}

/** Dựng dữ liệu sections + HTML khi tạo mới từ prompt / link / ảnh (có hỗ trợ AI Vision cho ảnh). */
function ld_build(string $id, array $p, array $in, array $ref, string $type, string $srcRef): array {
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120) ?: ($ref['title'] ?: 'Trang đích ' . $p['name']);
    $cta = mb_substr(trim((string)($in['cta'] ?? '')), 0, 60) ?: (!empty($ref['ctas'][0]) ? $ref['ctas'][0] : 'Đăng ký tư vấn');
    $hotline = (string)(jdecode($p['settings'] ?? null)['hotline'] ?? setting_get('brand.hotline', ''));
    if (!$hotline && !empty($ref['phones'][0])) $hotline = $ref['phones'][0];
    $tpl = in_array($in['template'] ?? '', LD_TEMPLATES, true) ? $in['template'] : 'modern';

    $rawSec = [
        'template' => $tpl,
        'badge' => 'Chính hãng · Uy tín · ' . ($p['name'] ?: 'Chuyên nghiệp'),
        'lead' => $ref['description'] ?: ($p['goal'] ?: 'Giải pháp chất lượng cao, tư vấn tận tâm và báo giá minh bạch.'),
        'offer' => 'Đăng ký tư vấn ngay hôm nay để nhận báo giá ưu đãi và quà tặng đi kèm.',
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : ($p['color'] ?: '#168164'),
        'hotline' => $hotline,
        'hero_img' => $type === 'image' ? $srcRef : '',
        'benefits' => ['Sản phẩm & dịch vụ chính hãng, rõ nguồn gốc', 'Tư vấn đúng nhu cầu, báo giá minh bạch trước khi làm', 'Bảo hành uy tín, hỗ trợ kỹ thuật nhanh chóng'],
        'steps' => ['Để lại Số điện thoại & nhu cầu tư vấn', 'Chuyên viên liên hệ tư vấn & gửi báo giá chi tiết', 'Triển khai lắp đặt / bàn giao & kích hoạt bảo hành'],
        'faq' => [
            ['Chi phí tính như thế nào?', 'Báo giá minh bạch theo từng hạng mục, gửi chi tiết qua Zalo/SĐT trong 5 phút.'],
            ['Thời gian triển khai và bảo hành ra sao?', 'Thực hiện nhanh gọn trong ngày, cam kết bảo hành chính hãng đầy đủ.'],
        ],
        'ai' => false,
    ];

    if ($ref['headings'] || $ref['bullets']) {
        $cand = array_values(array_unique(array_merge($ref['headings'], $ref['bullets'])));
        if (count($cand) >= 2) $rawSec['benefits'] = array_slice($cand, 0, 5);
    }

    $aiNote = '';
    if (is_file(__DIR__ . '/_ai_lib.php')) {
        require_once __DIR__ . '/_ai_lib.php';
        try {
            $sys = 'Bạn là chuyên gia thiết kế Landing Page chuyển đổi cao. Hãy trả về JSON với các khoá tiếng Việt: badge (nhãn ngắn trên tiêu đề ≤ 60 ký tự), lead (1-2 câu mở đầu thu hút dưới tiêu đề chính), offer (1-2 câu cam kết/ưu đãi thúc đẩy đăng ký), benefits (mảng 3-5 lợi ích nổi bật), steps (mảng 3-4 bước quy trình), faq (mảng 3 cặp {q,a} giải đáp thắc mắc khách hàng). Không bịa giá sai lệch nếu người dùng không nêu.';
            $ctx = "Dự án: {$p['name']} ({$p['domain']}). Mục tiêu: {$p['goal']}. Tiêu đề chính: $title. Nút CTA: $cta.\nYêu cầu/Mô tả: " . mb_substr((string)($in['prompt'] ?? ''), 0, 1500);
            if ($type === 'link' && ($ref['title'] || $ref['headings'])) {
                $ctx .= "\nNội dung bóc tách từ Link tham chiếu ({$srcRef}):"
                    . "\n- Tiêu đề gốc: {$ref['title']}"
                    . "\n- Mô tả: {$ref['description']}"
                    . "\n- Các đề mục H1-H3: " . implode(' | ', $ref['headings'])
                    . "\n- Các ý chính (bullet): " . implode(' | ', array_slice($ref['bullets'], 0, 6))
                    . "\n- Nút CTA trên trang mẫu: " . implode(', ', $ref['ctas'])
                    . "\n- Đoạn văn: " . implode(' | ', array_slice($ref['texts'], 0, 4));
            }
            $aiOpts = ['max_tokens' => 1100, 'effort' => 'low', 'purpose' => 'landing_draft'];
            if ($type === 'image' && $srcRef !== '') {
                $imgFile = dirname(__DIR__) . '/' . ltrim($srcRef, '/');
                if (is_file($imgFile) && filesize($imgFile) <= 5 * 1024 * 1024) {
                    $ext = strtolower(pathinfo($imgFile, PATHINFO_EXTENSION));
                    $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
                    if (isset($mimeMap[$ext])) {
                        $aiOpts['images'] = [['mime' => $mimeMap[$ext], 'data' => base64_encode((string)file_get_contents($imgFile))]];
                        $ctx .= "\nHãy quan sát kỹ ảnh giao diện tham chiếu đính kèm: đọc bố cục, thông điệp tiêu đề, các điểm lợi ích, quy trình và câu hỏi trên ảnh để chuyển hoá thành nội dung trang đích phù hợp cho {$p['name']}.";
                    }
                }
            }
            $r = ai_generate($sys, [['role' => 'user', 'content' => $ctx]], $aiOpts);
            if (!empty($r['ok']) && preg_match('~\{.*\}~s', (string)$r['text'], $mj) && ($j = json_decode($mj[0], true))) {
                foreach (['badge', 'lead', 'offer'] as $k) if (!empty($j[$k])) $rawSec[$k] = (string)$j[$k];
                if (!empty($j['benefits']) && is_array($j['benefits'])) $rawSec['benefits'] = $j['benefits'];
                if (!empty($j['steps']) && is_array($j['steps'])) $rawSec['steps'] = $j['steps'];
                if (!empty($j['faq']) && is_array($j['faq'])) $rawSec['faq'] = $j['faq'];
                $rawSec['ai'] = true;
            } else {
                $aiNote = 'Chưa dùng AI (' . ($r['error'] ?? 'không rõ') . ') — đã dựng từ khung chuẩn & dữ liệu bóc tách.';
            }
        } catch (Throwable $e) {
            $aiNote = 'AI lỗi: ' . $e->getMessage();
        }
    }

    $sections = ld_norm_sections($rawSec, $p);
    $html = ld_render_html($id, $p, $title, $cta, $sections, 'draft');
    return [$html, $sections, $aiNote];
}

/* ---------------- 1. Endpoint công khai: Xem trang đích đã xuất bản & Khách gửi Form Lead ---------------- */
if ($method === 'GET' && !empty($_GET['id']) && !empty($_GET['public'])) {
    $st = $pdo->prepare('SELECT * FROM landing_pages WHERE id=?');
    $st->execute([(string)$_GET['id']]);
    $l = $st->fetch();
    if (!$l) { http_response_code(404); echo 'Không tìm thấy trang đích.'; exit; }
    $u = current_user();
    if (!in_array($l['state'], ['published', 'approved'], true) && !$u) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Chưa xuất bản</title><body style="font-family:system-ui;padding:40px;text-align:center"><h3>Trang đích này đang ở trạng thái Bản nháp</h3><p>Quản trị viên cần bấm "Xuất bản" trong Marketing Hub để mở đường dẫn công khai.</p></body>';
        exit;
    }
    $stP = $pdo->prepare('SELECT * FROM projects WHERE id=?');
    $stP->execute([$l['project_id']]);
    $p = $stP->fetch() ?: ['id' => $l['project_id'], 'name' => 'Dự án', 'domain' => '', 'color' => '#168164'];
    $sec = ld_norm_sections(jdecode($l['sections'] ?? null), $p);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    echo ld_render_html($l['id'], $p, (string)$l['title'], (string)($l['cta'] ?: 'Đăng ký tư vấn'), $sec, 'published');
    exit;
}

if ($method === 'POST' && (!empty($_GET['public_lead']) || (($inPub = json_input())['action'] ?? '') === 'submit_lead')) {
    $in = $inPub ?? json_input();
    $lpId = trim((string)($in['landing_id'] ?? $_POST['landing_id'] ?? ''));
    if ($lpId === '') fail('Thiếu mã trang đích');
    $st = $pdo->prepare('SELECT * FROM landing_pages WHERE id=?');
    $st->execute([$lpId]);
    $l = $st->fetch();
    if (!$l) fail('Không tìm thấy trang đích', 404);
    $u = current_user();
    if (!in_array($l['state'], ['published', 'approved'], true) && !$u) {
        fail('Trang đích chưa được xuất bản nên chưa nhận đăng ký công khai.', 403);
    }
    $name = mb_substr(trim((string)($in['name'] ?? $_POST['name'] ?? '')), 0, 100);
    $phone = mb_substr(trim((string)($in['phone'] ?? $_POST['phone'] ?? '')), 0, 30);
    $notes = mb_substr(trim((string)($in['notes'] ?? $_POST['notes'] ?? '')), 0, 500);
    $digits = preg_replace('/\D/', '', $phone);
    if ($name === '' || strlen($digits) < 8) fail('Vui lòng nhập đầy đủ Họ tên và Số điện thoại hợp lệ (tối thiểu 8 chữ số).');

    // Chống gửi trùng liên tiếp trong 60 giây
    $stDup = $pdo->prepare("SELECT id FROM leads WHERE project_id=? AND phone=? AND created_at >= datetime('now', '-60 seconds') LIMIT 1");
    $stDup->execute([$l['project_id'], $phone]);
    if ($dupId = $stDup->fetchColumn()) {
        respond(['ok' => true, 'lead_id' => $dupId, 'message' => 'Thông tin của bạn đã được ghi nhận! Chúng tôi sẽ liên hệ lại ngay.']);
    }

    $leadId = gen_id('L');
    $srcName = 'Landing: ' . mb_substr((string)$l['title'], 0, 50);
    $pdo->prepare('INSERT INTO leads(id,project_id,name,phone,email,notes,co,br,campaign_id,source,source_ref,score,state,subject) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$leadId, $l['project_id'], $name, $phone, null, $notes ?: null, 'all', 'all', $l['campaign_id'] ?: null, $srcName, $l['id'], 85, 'CREATED', (string)$l['title']]);
    if (function_exists('notify')) {
        try { notify(null, 'Khách đăng ký từ Landing Page: ' . $name, "$name ($phone) vừa điền form trên trang \"{$l['title']}\"" . ($notes ? " — $notes" : ''), 'leadsmenu'); } catch (Throwable $e) {}
    }
    respond(['ok' => true, 'lead_id' => $leadId, 'message' => 'Cảm ơn ' . $name . '! Thông tin đã được gửi thành công, chúng tôi sẽ liên hệ sớm nhất.'], 201);
}

/* ---------------- 2. Các thao tác Quản trị trong App (yêu cầu đăng nhập) ---------------- */
$me = require_login();
$PID = pid();

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $l = need('landing_pages', (string)$_GET['id'], 'landing page');
        $p = current_project();
        $sec = ld_norm_sections(jdecode($l['sections'] ?? null), $p);
        if (!empty($_GET['download'])) {
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="landing-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $l['id']) . '.html"');
            echo ld_render_html($l['id'], $p, (string)$l['title'], (string)($l['cta'] ?: 'Đăng ký tư vấn'), $sec, 'published');
            exit;
        }
        if (!empty($_GET['preview'])) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('X-Frame-Options: SAMEORIGIN');
            header('Cache-Control: no-store');
            echo ld_render_html($l['id'], $p, (string)$l['title'], (string)($l['cta'] ?: 'Đăng ký tư vấn'), $sec, (string)$l['state']);
            exit;
        }
        $l['sections'] = $sec;
        unset($l['content']);
        respond(['landing' => $l]);
    }
    $p = current_project();
    $st = $pdo->prepare('SELECT id,project_id,title,cta,channel,campaign_id,source_type,source_ref,prompt,sections,state,version,published_url,ai_used,created_by,created_at,updated_at FROM landing_pages WHERE project_id=? ORDER BY updated_at DESC');
    $st->execute([$PID]);
    $rows = $st->fetchAll();
    // Đếm số lead thu được theo từng landing page
    $stL = $pdo->prepare("SELECT source_ref, COUNT(*) AS cnt FROM leads WHERE project_id=? AND source_ref IS NOT NULL GROUP BY source_ref");
    $stL->execute([$PID]);
    $leadCounts = [];
    foreach ($stL->fetchAll() as $lr) $leadCounts[(string)$lr['source_ref']] = (int)$lr['cnt'];

    foreach ($rows as &$r) {
        $r['sections'] = ld_norm_sections(jdecode($r['sections'] ?? null), $p);
        $r['lead_count'] = $leadCounts[$r['id']] ?? 0;
    }
    unset($r);
    respond(['landings' => $rows]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $act = (string)($in['action'] ?? '');

    if ($act === 'update') {
        $l = need('landing_pages', (string)($in['id'] ?? ''), 'landing page');
        $p = current_project();
        $title = mb_substr(trim((string)($in['title'] ?? $l['title'])), 0, 120) ?: $l['title'];
        $cta = mb_substr(trim((string)($in['cta'] ?? $l['cta'])), 0, 60) ?: 'Đăng ký tư vấn';
        $channel = mb_substr(trim((string)($in['channel'] ?? $l['channel'])), 0, 30);
        $camp = array_key_exists('campaign_id', $in) ? (trim((string)$in['campaign_id']) ?: null) : $l['campaign_id'];
        if ($camp && !scoped('campaigns', $camp)) fail('Chiến dịch không thuộc dự án này');

        $oldSec = jdecode($l['sections'] ?? null);
        $inSec = is_array($in['sections'] ?? null) ? array_merge($oldSec, $in['sections']) : $oldSec;
        $sec = ld_norm_sections($inSec, $p);
        $ver = ((int)$l['version']) + 1;
        $html = ld_render_html($l['id'], $p, $title, $cta, $sec, (string)$l['state']);

        $pdo->prepare("UPDATE landing_pages SET title=?, cta=?, channel=?, campaign_id=?, sections=?, content=?, version=?, updated_at=datetime('now') WHERE id=? AND project_id=?")
            ->execute([$title, $cta, $channel, $camp, json_encode($sec, JSON_UNESCAPED_UNICODE), $html, $ver, $l['id'], $PID]);
        log_activity('update', 'landing', $l['id'], 'Cập nhật nội dung landing "' . $title . '" (v' . $ver . ')');
        respond(['ok' => true, 'version' => $ver, 'sections' => $sec]);
    }

    if ($act === 'publish') {
        $l = need('landing_pages', (string)($in['id'] ?? ''), 'landing page');
        $p = current_project();
        $newState = ($in['state'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $customUrl = trim((string)($in['published_url'] ?? ''));
        if ($customUrl !== '' && !preg_match('~^https?://~i', $customUrl) && !str_starts_with($customUrl, '/')) {
            fail('URL xuất bản phải bắt đầu bằng https:// hoặc /');
        }
        $pubUrl = $newState === 'draft' ? null : ($customUrl ?: ('/api/landings.php?id=' . rawurlencode($l['id']) . '&public=1'));
        $sec = ld_norm_sections(jdecode($l['sections'] ?? null), $p);
        $html = ld_render_html($l['id'], $p, (string)$l['title'], (string)($l['cta'] ?: 'Đăng ký tư vấn'), $sec, $newState);

        $pdo->prepare("UPDATE landing_pages SET state=?, published_url=?, content=?, updated_at=datetime('now') WHERE id=? AND project_id=?")
            ->execute([$newState, $pubUrl, $html, $l['id'], $PID]);
        log_activity('approve', 'landing', $l['id'], ($newState === 'published' ? 'Xuất bản landing ' : 'Chuyển về nháp ') . $l['title']);
        respond(['ok' => true, 'state' => $newState, 'published_url' => $pubUrl]);
    }

    $type = in_array($in['source_type'] ?? '', ['prompt', 'link', 'image'], true) ? $in['source_type'] : 'prompt';
    $ref = ['title' => '', 'description' => '', 'headings' => [], 'bullets' => [], 'ctas' => [], 'texts' => [], 'phones' => [], 'error' => null];
    $srcRef = trim((string)($in['source_ref'] ?? ''));
    if ($type === 'link') {
        if ($srcRef === '') fail('Nhập link tham chiếu');
        $ref = ld_fetch_ref($srcRef);
        if ($ref['error'] && !$ref['title']) fail('Không đọc được link tham chiếu: ' . $ref['error'], 422);
    }
    if ($type === 'image' && !preg_match('#^uploads/\d{6}/[a-f0-9]{24}\.(jpg|jpeg|png|webp)$#', $srcRef)) {
        fail('Thiếu ảnh tham chiếu hợp lệ');
    }
    $camp = trim((string)($in['campaign_id'] ?? '')) ?: null;
    if ($camp && !scoped('campaigns', $camp)) fail('Chiến dịch không thuộc dự án này');
    $p = current_project();
    $id = gen_id('LP');
    [$html, $sections, $aiNote] = ld_build($id, $p, $in, $ref, $type, $srcRef);
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120) ?: ($ref['title'] ?: 'Trang đích ' . $p['name']);
    $cta = mb_substr(trim((string)($in['cta'] ?? '')), 0, 60) ?: (!empty($ref['ctas'][0]) ? $ref['ctas'][0] : 'Đăng ký tư vấn');
    $pdo->prepare('INSERT INTO landing_pages(id,project_id,title,cta,channel,campaign_id,source_type,source_ref,prompt,content,sections,state,version,ai_used,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)')
        ->execute([$id, $PID, $title, $cta, mb_substr((string)($in['channel'] ?? ''), 0, 20), $camp, $type, $srcRef ?: null,
            mb_substr((string)($in['prompt'] ?? ''), 0, 3000), $html, json_encode($sections, JSON_UNESCAPED_UNICODE), 'draft', $sections['ai'] ? 1 : 0, $me['id']]);

    // Nếu tạo bổ trợ từ một Ý tưởng chiến dịch (idea_id), gắn landing_id vào ý tưởng đó
    if (!empty($in['idea_id']) && scoped('ideas', (string)$in['idea_id'])) {
        $pdo->prepare("UPDATE ideas SET landing_id=?, updated_at=datetime('now') WHERE id=? AND project_id=?")->execute([$id, (string)$in['idea_id'], $PID]);
    }

    log_activity('create', 'landing', $id, 'Tạo bản nháp landing "' . $title . '" (' . $type . ')' . ($aiNote ? ' — ' . $aiNote : ''));
    respond(['id' => $id, 'ai_used' => (bool)$sections['ai'], 'note' => $aiNote ?: null, 'ref_error' => $ref['error']], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $l = need('landing_pages', (string)($_GET['id'] ?? ''), 'landing page');
    $pdo->prepare('DELETE FROM landing_pages WHERE id=? AND project_id=?')->execute([$l['id'], $PID]);
    log_activity('delete', 'landing', $l['id'], 'Xoá landing ' . $l['title']);
    respond(['ok' => true]);
}
fail('Method không hỗ trợ', 405);

