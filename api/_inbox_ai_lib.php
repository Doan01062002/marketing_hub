<?php
/* AI CHĂM SÓC TỰ ĐỘNG trong Cửa sổ chat. 12/10/2026 — nạp sau _boot.php + _inbox_lib.php.
   Cấu hình theo DỰ ÁN (projects.settings.ai_care): mode = off | suggest | auto; instructions (hướng dẫn riêng); handoff_phone (bắt buộc xin SĐT?)
   - auto: mỗi tin khách mới → AI trả lời ngay (webhook/cron), trừ khi: hội thoại tắt AI (inbox_threads.ai_mode='off'), người thật vừa trả lời trong 60 phút,
     hội thoại đã được giao cho người, AI đã trả lời quá 15 lần/ngày trong hội thoại, hoặc AI tự thấy cần người thật (handoff) → gửi câu giữ khách + báo quản lý.
   - suggest: không tự gửi; nút "Gợi ý AI" trong khung chat.
   Hiểu biết: thông tin dự án (tên, website, hotline, mục tiêu) + Thương hiệu (brand_items) + nội dung website (/ai/service.json, /ai/faqs.json, /llms.txt — cache 24 giờ) + hướng dẫn riêng.
   AI KHÔNG bịa giá/chính sách: thiếu thông tin thì hẹn người thật. Mọi tin AI gửi lưu sent_by='ai'. */
require_once __DIR__ . '/_ai_lib.php';

const IB_AI_MAX_PER_DAY = 15;      // tin AI tối đa / hội thoại / ngày
const IB_AI_HUMAN_QUIET_MIN = 60;  // người thật vừa trả lời → AI im trong N phút

function ib_ai_cfg(string $projectId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM projects WHERE id=?'); $st->execute([$projectId]); $p = $st->fetch();
    $s = jdecode($p['settings'] ?? null); $c = (array)($s['ai_care'] ?? []);
    $mode = (string)($c['mode'] ?? 'off'); return ['mode' => in_array($mode, ['off', 'suggest', 'auto'], true) ? $mode : 'off', 'comments' => (($c['comments'] ?? 'auto') === 'suggest') ? 'suggest' : 'auto', 'instructions' => (string)($c['instructions'] ?? ''), 'project' => $p ?: [], 'settings' => $s];
}

/** Nội dung website làm kiến thức: ưu tiên file GEO (/ai/service.json, /ai/faqs.json, /llms.txt), không có thì chữ trang chủ. Cache 24 giờ. */
function ib_ai_site_knowledge(string $domain): string {
    if ($domain === '') return '';
    $key = 'inbox.kb.' . preg_replace('/[^a-z0-9.-]/', '', strtolower($domain));
    $cached = jdecode(setting_get($key) ?: null, []);
    if (!empty($cached['at']) && strtotime($cached['at']) > time() - 86400) return (string)($cached['text'] ?? '');
    $base = 'https://' . preg_replace('#^https?://#', '', rtrim($domain, '/'));
    $parts = [];
    foreach (['/ai/service.json' => 'Dịch vụ/sản phẩm & giá', '/ai/faqs.json' => 'Hỏi đáp thường gặp', '/llms.txt' => 'Giới thiệu'] as $path => $label) {
        [$c, $d, $raw] = http_call('GET', $base . $path, ['timeout' => 8]);
        if ($c !== 200) continue;
        $txt = is_array($d) ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$raw;
        $txt = trim(strip_tags($txt)); if ($txt === '') continue;
        $parts[] = "## $label ($path)\n" . mb_substr($txt, 0, 6000);
    }
    if (!$parts) {
        [$c, $raw] = ib_fetch($base . '/', 8);
        if ($c === 200 && $raw) {
            $h = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', (string)$raw);
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t !== '') $parts[] = "## Trang chủ\n" . mb_substr($t, 0, 5000);
        }
    }
    $text = implode("\n\n", $parts);
    setting_set($key, json_encode(['at' => date('Y-m-d H:i:s'), 'text' => $text], JSON_UNESCAPED_UNICODE), ['id' => null]);
    return $text;
}

/** Link bài viết Facebook trong các tin gần đây của khách (khách bấm "Nhắn tin" từ bài / trả lời bài). */
function ib_ai_find_post_refs(string $threadId): array {
    global $pdo; $out = [];
    $st = $pdo->prepare("SELECT text, attachments FROM inbox_messages WHERE thread_id=? AND direction='in' ORDER BY sent_at DESC LIMIT 8"); $st->execute([$threadId]);
    foreach ($st->fetchAll() as $m) {
        $blob = (string)$m['text'] . ' ' . (string)$m['attachments'];
        $blob = str_replace('\\/', '/', $blob);
        if (preg_match_all('#https?://(?:www\.|m\.)?facebook\.com/[^\s)"\'<>]+#i', $blob, $mm)) foreach ($mm[0] as $u) {
            $id = null;
            if (preg_match('/story_fbid=([A-Za-z0-9]+)/', $u, $x)) $id = $x[1];
            elseif (preg_match('#/posts/([A-Za-z0-9]+)#', $u, $x)) $id = $x[1];
            elseif (preg_match('#/(?:photo|photos)/.*?fbid=(\d+)#', $u, $x)) $id = $x[1];
            if ($id && !isset($out[$id])) $out[$id] = html_entity_decode($u);
        }
    }
    return array_slice($out, 0, 2, true);
}
/** Tải trang như trình duyệt, theo redirect (link rút gọn affiliate). Trả về [code, body, url cuối]. */
function ib_fetch(string $url, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', CURLOPT_HTTPHEADER => ['Accept-Language: vi-VN,vi;q=0.9']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
    return [$code, (string)$body, $final ?: $url];
}
/** Trang sản phẩm trên website: tên, giá, còn hàng (đọc data-btm-add / og: / văn bản). Cache 6 giờ. */
function ib_ai_product_info(string $url): string {
    $key = 'inbox.prod.' . md5($url); $c = jdecode(setting_get($key) ?: null, []);
    if (!empty($c['at']) && strtotime($c['at']) > time() - 6 * 3600) return (string)($c['text'] ?? '');
    [$code, $raw, $final] = ib_fetch($url);
    $text = '';
    $host = strtolower((string)parse_url($final, PHP_URL_HOST));
    if (preg_match('/shopee\.|lazada\.|tiktok\.com|tiki\.vn|sendo\.vn/', $host)) { // sàn TMĐT: không đọc được giá/tồn kho (chặn bot) → chỉ tên từ đường dẫn + link cuối
        $slug = ''; if (preg_match('#/([^/?]+)-i\.\d+\.\d+#', $final, $m)) $slug = $m[1]; elseif (preg_match('#/products?/([^/?]+)#', $final, $m)) $slug = $m[1]; elseif (preg_match('#/([^/?]+?)(?:-i\d+)?\.html#', $final, $m)) $slug = $m[1];
        $name = trim(str_replace('-', ' ', urldecode($slug)));
        $san = str_contains($host, 'shopee') ? 'Shopee' : (str_contains($host, 'lazada') ? 'Lazada' : (str_contains($host, 'tiktok') ? 'TikTok Shop' : (str_contains($host, 'tiki') ? 'Tiki' : 'sàn TMĐT')));
        $text = ($name !== '' ? "Tên: $name\n" : '') . "Nơi bán: $san (giá và tồn kho xem trực tiếp trên link; không đọc được tự động)\nTình trạng: " . ($code === 200 || $code === 0 ? 'link còn hoạt động' : "link trả HTTP $code") . "\nLink: $url" . ($final !== $url ? "\nLink đầy đủ: $final" : '');
        setting_set($key, json_encode(['at' => date('Y-m-d H:i:s'), 'text' => $text], JSON_UNESCAPED_UNICODE), ['id' => null]);
        return $text;
    }
    if ($code === 200 && $raw) {
        $h = (string)$raw; $parts = [];
        $get = fn($re) => preg_match($re, $h, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $title = $get('#<meta property="og:title" content="([^"]*)"#i') ?: $get('#<title>([^<]*)</title>#i');
        $desc = $get('#<meta (?:property="og:description"|name="description") content="([^"]*)"#i');
        $price = '';
        if (preg_match('/data-btm-(?:add|buy)="([^"]+)"/', $h, $m)) { $j = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true); if (is_array($j)) { $title = $j['name'] ?? $title; if (!empty($j['price'])) $price = number_format((float)$j['price'], 0, ',', '.') . 'đ'; } }
        if ($price === '') { $price = $get('#<meta property="product:price:amount" content="([^"]*)"#i'); if ($price !== '') $price .= 'đ'; }
        if ($price === '' && preg_match('/(\d{1,3}(?:[.,]\d{3}){1,3})\s*(?:đ|₫|VNĐ|vnd)/u', strip_tags($h), $m)) $price = $m[1] . 'đ';
        $txt = mb_strtolower(strip_tags($h));
        $stock = (str_contains($txt, 'hết hàng') || str_contains($txt, 'ngừng kinh doanh') || str_contains($txt, 'out of stock')) ? 'HẾT HÀNG (trang ghi hết hàng)' : ((str_contains($txt, 'thêm vào giỏ') || str_contains($txt, 'mua ngay') || str_contains($txt, 'đặt hàng')) ? 'đang bán (có nút mua)' : 'không rõ');
        if ($title !== '') $parts[] = 'Tên: ' . $title;
        if ($price !== '') $parts[] = 'Giá: ' . $price;
        $parts[] = 'Tình trạng: ' . $stock;
        if ($desc !== '') $parts[] = 'Mô tả: ' . mb_substr($desc, 0, 400);
        $parts[] = 'Link: ' . $url;
        $text = implode("\n", $parts);
    } else $text = "Link: $url (không mở được, HTTP $code)";
    setting_set($key, json_encode(['at' => date('Y-m-d H:i:s'), 'text' => $text], JSON_UNESCAPED_UNICODE), ['id' => null]);
    return $text;
}
/** Ngữ cảnh bài viết khách đang hỏi: nội dung bài (Graph API, token Page) + các link sản phẩm trong bài. Cache 6 giờ/bài. */
function ib_ai_post_context(array $CFG, array $conn, array $th): string {
    if ($conn['provider'] !== 'facebook_page') return '';
    $refs = ib_ai_find_post_refs($th['id']);
    if (!empty($th['post_id'])) { $pid0 = (string)$th['post_id']; if (str_contains($pid0, '_')) $pid0 = substr($pid0, strpos($pid0, '_') + 1); $refs = [$pid0 => (string)($th['permalink'] ?? ('https://www.facebook.com/' . $th['post_id']))] + $refs; }
    if (!$refs) return '';
    $out = [];
    foreach ($refs as $pid => $url) {
        $key = 'inbox.post.' . $pid; $c = jdecode(setting_get($key) ?: null, []);
        if (!empty($c['at']) && strtotime($c['at']) > time() - 6 * 3600) { $out[] = (string)($c['text'] ?? ''); continue; }
        $text = '';
        try { $tok = meta_page_token($CFG, $conn);
            // Graph cần dạng {page_id}_{post_id}; id trong link (id=…) có thể là id Trang dạng mới → thử lần lượt
            $owners = array_values(array_unique(array_filter([(string)$conn['external_account_id'], preg_match('/[?&]id=(\d+)/', $url, $om) ? $om[1] : null])));
            $code = 0; $d = [];
            foreach ($owners as $own) { [$code, $d] = http_get_json(graph_url($CFG, rawurlencode($own . '_' . $pid), ['fields' => 'message,permalink_url,created_time,attachments{title,description,url,unshimmed_url,subattachments{title,url,unshimmed_url}}', 'access_token' => $tok])); if ($code === 200) break; }
            if ($code === 200 && is_array($d)) {
                $msg = trim((string)($d['message'] ?? '')); $links = [];
                $grab = function (array $a) use (&$links, &$grab) { foreach (['unshimmed_url', 'url'] as $k) if (!empty($a[$k]) && !str_contains($a[$k], 'facebook.com')) $links[$a[$k]] = 1; foreach ($a['subattachments']['data'] ?? [] as $sa) $grab($sa); };
                foreach ($d['attachments']['data'] ?? [] as $a) $grab($a);
                if (preg_match_all('#https?://[^\s<>"\']+#i', $msg, $mm)) foreach ($mm[0] as $u) if (!str_contains($u, 'facebook.com')) $links[rtrim($u, '.,)')] = 1;
                // Kênh affiliate hay để link ở bình luận của chính Trang → đọc 10 bình luận đầu
                if (!$links) { [$c2, $cm] = http_get_json(graph_url($CFG, rawurlencode($own . '_' . $pid) . '/comments', ['filter' => 'stream', 'limit' => 10, 'fields' => 'from,message', 'access_token' => $tok]));
                    foreach ($cm['data'] ?? [] as $x) if ((string)($x['from']['id'] ?? '') === (string)$conn['external_account_id'] && preg_match_all('#https?://[^\s<>"\']+#i', (string)($x['message'] ?? ''), $mm)) foreach ($mm[0] as $u) if (!str_contains($u, 'facebook.com')) $links[rtrim($u, '.,)')] = 1; }
                $text = "Bài viết (" . ($d['permalink_url'] ?? $url) . "):\n" . mb_substr($msg !== '' ? $msg : '(không có chữ)', 0, 1500);
                $hl = jdecode(setting_get('inbox.links.' . $pid) ?: null, []); $direct = '';
                foreach (($hl['links'] ?? []) as $lk) if (!empty($lk['aff'])) { $links[$lk['aff']] = 1; if ($direct === '' && !empty($lk['direct'])) $direct = (string)$lk['direct']; } // link gắn trong reel do máy HL quét
                if ($direct !== '') $text .= "\n\nLink mở TRỰC TIẾP trang sản phẩm (gửi khi khách báo không mở được link trên; dặn mở bằng trình duyệt Chrome/Safari hoặc dán vào ô tìm kiếm app Shopee): $direct";
                $n = 0; foreach (array_keys($links) as $l) { if ($n++ >= 3) break; $text .= "\n\nSản phẩm trong bài:\n" . ib_ai_product_info($l); }
                if (!$links) { // không có link → tìm trên website của dự án theo tên trong bài
                    $pst = $GLOBALS['pdo']->prepare('SELECT domain FROM projects WHERE id=?'); $pst->execute([(string)$conn['project_id']]); $dom = (string)($pst->fetchColumn() ?: '');
                    $q = trim(preg_replace('/#\S+|[^\p{L}\p{N} ]+/u', ' ', mb_substr($msg, 0, 80))); $found = '';
                    if ($dom !== '' && $q !== '') { $base = 'https://' . preg_replace('#^https?://#', '', rtrim($dom, '/')); [$c3, $raw3] = ib_fetch($base . '/?s=' . rawurlencode($q));
                        if ($c3 === 200 && preg_match('/data-btm-add="([^"]+)"/', (string)$raw3, $m3)) { $j = json_decode(html_entity_decode($m3[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true); if (!empty($j['name'])) $found = 'Sản phẩm gần nhất trên website (' . $dom . '): ' . $j['name'] . (!empty($j['price']) ? ' — ' . number_format((float)$j['price'], 0, ',', '.') . 'đ' : '') . (!empty($j['id']) ? ' — Link: ' . $base . '/sp/' . $j['id'] . '/' : ''); } }
                    $text .= "\n" . ($found !== '' ? $found . "\n(Chỉ gợi ý nếu đúng mẫu khách hỏi; không chắc thì mời khách nhắn tin, handoff=false.)" : "(Bài không có link sản phẩm và không tìm thấy trên website — KHÔNG nói giá, mời khách nhắn tin cho page để được gửi link; handoff=false.)");
                }
            } else $text = "Bài viết $url: không đọc được qua Graph (" . ($d['error']['message'] ?? "HTTP $code") . ")";
        } catch (Throwable $e) { $text = "Bài viết $url: " . $e->getMessage(); }
        setting_set($key, json_encode(['at' => date('Y-m-d H:i:s'), 'text' => $text], JSON_UNESCAPED_UNICODE), ['id' => null]);
        $out[] = $text;
    }
    return implode("\n\n", array_filter($out));
}
function ib_ai_system(array $cfg, array $conn, array $th): string {
    global $pdo;
    if ($conn['provider'] === 'zalo_user') { require_once __DIR__ . '/_zalo_ai_lib.php'; return zu_ai_system($th); } // Zalo cá nhân: cấu hình AI Chat riêng
    $p = $cfg['project']; $s = $cfg['settings'];
    $st = $pdo->prepare('SELECT kind,title,content FROM brand_items WHERE project_id=? ORDER BY kind'); $st->execute([$p['id'] ?? '']);
    $brand = implode("\n", array_map(fn($b) => '- [' . $b['kind'] . '] ' . $b['title'] . ': ' . $b['content'], $st->fetchAll()));
    $kb = ib_ai_site_knowledge((string)($p['domain'] ?? ''));
    $ch = $conn['provider'] === 'zalo_oa' ? 'Zalo OA' : 'Messenger (Fanpage ' . $conn['label'] . ')';
    $hot = (string)($s['hotline'] ?? '');
    return "Bạn là nhân viên chăm sóc khách hàng của \"{$p['name']}\"" . (!empty($p['domain']) ? " (website {$p['domain']})" : '') . ", đang trả lời khách qua $ch.\n"
        . "Mục tiêu: trả lời đúng, ngắn (1–4 câu, tiếng Việt, xưng \"em\"/gọi \"anh/chị\" trừ khi khách dùng ngôn ngữ khác), thân thiện, dẫn khách tới mua hàng/đặt lịch, xin số điện thoại khi khách có ý định mua hoặc cần tư vấn sâu.\n"
        . "TUYỆT ĐỐI không bịa giá, tồn kho, chính sách, khuyến mãi, thời gian giao. Chỉ dùng thông tin bên dưới. Thiếu link/giá thì KHÔNG nói số tiền, không bịa link: khéo mời khách nhắn tin cho page để được gửi link/tư vấn (trong Messenger thì nói sẽ gửi link ngay khi có), giữ tông thân thiện, tự xử lý hết — KHÔNG đẩy cho người khác.\n"
        . "handoff=true CHỈ khi khách khiếu nại/giận dữ, đòi hoàn tiền/bồi thường, doạ báo cáo, hoặc nói rõ muốn gặp người thật. Mọi trường hợp khác handoff=false (kể cả khách để lại SĐT: cảm ơn, nói sẽ liên hệ, không cần bàn giao).\n"
        . "skip=true (không trả lời) khi bình luận/tin là spam, quảng cáo chéo, tục tĩu nặng, hoặc chỉ tag bạn bè vô nghĩa.\n"
        . "NGÔN NGỮ: khách viết tiếng nào trả lời tiếng đó; không đoán được (chỉ emoji/tên) thì tiếng Việt. Không văng tục dù nhẹ; không nhắc hoa hồng/affiliate; không viết HOA toàn bộ; tối đa 1–2 emoji.\n"
        . "Không lặp lại câu chào nếu đã chào trong hội thoại. Không nói mình là AI trừ khi khách hỏi thẳng.\n"
        . ((($th['kind'] ?? 'message') === 'comment') ? "ĐÂY LÀ BÌNH LUẬN CÔNG KHAI dưới bài viết: trả lời ngắn 1–2 câu, lịch sự, ai cũng đọc được; đưa link sản phẩm nếu có (Hub sẽ tự gửi thêm link vào Messenger cho khách); không nhắc tới SĐT của khách; mời khách nhắn tin nếu cần tư vấn riêng.\n" : '')
        . ($hot ? "Hotline: $hot\n" : '') . (!empty($p['goal']) ? "Mục tiêu marketing của dự án: {$p['goal']}\n" : '')
        . ($cfg['instructions'] !== '' ? "\nHướng dẫn riêng của chủ dự án:\n{$cfg['instructions']}\n" : '')
        . ($brand ? "\nThương hiệu & quy tắc:\n$brand\n" : '')
        . ($kb ? "\nThông tin từ website (nguồn sự thật duy nhất về sản phẩm/dịch vụ/giá):\n$kb\n" : "\n(Chưa có dữ liệu sản phẩm/giá từ website — câu hỏi giá/sản phẩm: không nói giá, mời khách nhắn tin cho page; handoff=false.)\n")
        . (($pc = ib_ai_post_context($CFG_GLOBAL ?? $GLOBALS['CFG'], $conn, $th)) !== '' ? "\n=== BÀI VIẾT / SẢN PHẨM KHÁCH ĐANG HỎI (ưu tiên dùng thông tin này) ===\n$pc\nKhi khách hỏi \"còn mẫu này không\": trả lời theo Tình trạng ở trên; có Link thì dán đúng Link và hướng dẫn đặt theo link (trang là kênh giới thiệu, shop trên link sẽ giao); không có Link thì mời khách nhắn tin cho page, KHÔNG bàn giao. Khách để lại SĐT/size/địa chỉ → cảm ơn, nói đã ghi nhận THÔNG TIN (không nói đã lên đơn), sẽ liên hệ xác nhận; handoff=false.\n" : '')
        . "\nTrả về JSON: {\"reply\": string, \"skip\": boolean, \"handoff\": boolean, \"reason\": string ngắn, \"phone\": string|null (SĐT khách để lại nếu có)}.";
}

/** Chuẩn bị system + messages cho một hội thoại (dùng chung cho gọi API và cho máy HL). */
function ib_ai_prepare(array $CFG, array $th, ?array $cfg = null): array {
    global $pdo;
    $cfg = $cfg ?: ib_ai_cfg($th['project_id']);
    $conn = get_connection($th['connection_id'], true); if (!$conn) return ['ok' => false, 'error' => 'Kênh không còn'];
    $st = $pdo->prepare('SELECT direction,text,attachments,sender_name,sent_by,sent_at FROM inbox_messages WHERE thread_id=? ORDER BY sent_at DESC, id DESC LIMIT 14'); $st->execute([$th['id']]);
    $rows = array_reverse($st->fetchAll()); $msgs = [];
    foreach ($rows as $m) {
        $t = trim((string)$m['text']); if ($t === '' && $m['attachments']) $t = '[khách gửi tệp/ảnh]'; if ($t === '') continue;
        $role = $m['direction'] === 'in' ? 'user' : 'assistant';
        if ($msgs && end($msgs)['role'] === $role) { $msgs[count($msgs) - 1]['content'] .= "\n" . $t; } else $msgs[] = ['role' => $role, 'content' => $t];
    }
    if (!$msgs || end($msgs)['role'] !== 'user') return ['ok' => false, 'error' => 'Tin cuối không phải của khách'];
    if ($msgs[0]['role'] !== 'user') array_shift($msgs);
    $sys = ib_ai_system($cfg, $conn, $th);
    // Bài/reel không có link sản phẩm (Graph không trả link gắn trong reel) → máy HL quét trang công khai trước khi trả lời
    $need = []; $pk = ib_ai_post_key($th);
    if ($pk !== '' && !empty($th['permalink']) && (str_contains($sys, 'Bài không có link sản phẩm') || str_contains($sys, 'không mở được')) && empty(jdecode(setting_get('inbox.links.' . $pk) ?: null, [])['links'])) $need = ['need_links' => true, 'reel_url' => (string)$th['permalink'], 'post_key' => $pk];
    return ['ok' => true, 'system' => $sys, 'messages' => $msgs] + $need;
}
/** Khoá cache bài viết của hội thoại (id bài không có tiền tố trang). */
function ib_ai_post_key(array $th): string { $pid = (string)($th['post_id'] ?? ''); if ($pid === '') return ''; return str_contains($pid, '_') ? substr($pid, strpos($pid, '_') + 1) : $pid; }
/** Máy HL gửi về link sản phẩm gắn trong reel → cache; lần sau ib_ai_post_context dùng ngay. */
function ib_ai_links_store(string $postKey, array $links): void {
    if ($postKey === '' || !$links) return;
    setting_set('inbox.links.' . $postKey, json_encode(['at' => date('Y-m-d H:i:s'), 'links' => array_slice(array_values($links), 0, 5)], JSON_UNESCAPED_UNICODE), ['id' => null]);
    setting_set('inbox.post.' . $postKey, null, ['id' => null]); // bỏ cache bài cũ để dựng lại kèm link
}
/** Máy HL có đang nhận việc AI không (poll trong 3 phút gần nhất)? */
function ib_hl_alive(): bool { $t = setting_get('inbox.hl_ai_seen'); return $t && strtotime($t) > time() - 180; }
/** Xếp hàng việc AI cho máy HL. Trả về id việc. */
function ib_ai_enqueue(array $th, string $kind, array $prep): string {
    global $pdo;
    $pdo->prepare("UPDATE inbox_ai_jobs SET status='failed', error='bị thay bằng việc mới' WHERE thread_id=? AND status IN ('queued','taken')")->execute([$th['id']]);
    $id = gen_id('AJ');
    $pdo->prepare("INSERT INTO inbox_ai_jobs(id,thread_id,project_id,kind,system,messages) VALUES (?,?,?,?,?,?)")
        ->execute([$id, $th['id'], $th['project_id'], $kind, $prep['system'], json_encode($prep['messages'], JSON_UNESCAPED_UNICODE)]);
    return $id;
}
/** Áp kết quả AI (từ API hoặc từ máy HL) vào hội thoại: gửi khách (kind=auto), bàn giao, tạo đầu mối. */
function ib_ai_apply(array $CFG, array $th, array $d, string $kind = 'auto'): string {
    global $pdo;
    if ($kind !== 'auto') return 'gợi ý sẵn sàng';
    // Khoá an toàn: chỉ bàn giao người thật khi lý do là khiếu nại/hoàn tiền/giận dữ/đòi gặp người — còn lại AI tự lo (quy ước 12/10/2026)
    if (!empty($d['handoff']) && !preg_match('/khiếu nại|khieu nai|hoàn tiền|hoan tien|bồi thường|giận|tức|báo cáo|lừa|gặp người|nhân viên thật|người thật|complain|refund|angry|scam/iu', (string)($d['reason'] ?? ''))) $d['handoff'] = false;
    if (!empty($d['skip'])) { log_activity('reply', 'inbox', $th['id'], 'AI bỏ qua (' . ($d['reason'] ?? 'spam') . '): ' . $th['customer_name'], null, $th['project_id']); return 'bỏ qua: ' . ($d['reason'] ?? 'spam'); }
    // Điều kiện an toàn lần cuối (người thật có thể đã trả lời trong lúc chờ)
    $q = $pdo->prepare("SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out' AND COALESCE(sent_by,'')<>'ai'"); $q->execute([$th['id']]);
    $lastHuman = $q->fetchColumn();
    if ($th['provider'] === 'zalo_user') { // Zalo cá nhân: chỉ nhường khi chủ TK đã trả lời SAU tin mới nhất của người kia (AI đọc theo ngữ cảnh)
        $qi = $pdo->prepare("SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='in'"); $qi->execute([$th['id']]); $lastIn = (string)$qi->fetchColumn();
        if ($lastHuman && $lastIn !== '' && $lastHuman > $lastIn) return 'người thật đã trả lời';
    } elseif ($lastHuman && strtotime($lastHuman . ' UTC') > time() - IB_AI_HUMAN_QUIET_MIN * 60) return 'người thật đã trả lời';
    $cur = $pdo->prepare('SELECT last_direction, ai_mode FROM inbox_threads WHERE id=?'); $cur->execute([$th['id']]); $c = $cur->fetch();
    if (!$c || $c['last_direction'] !== 'in') return 'không còn cần';
    if ($th['provider'] === 'zalo_user') { require_once __DIR__ . '/_zalo_ai_lib.php'; if (!zu_ai_person_allowed((string)$th['customer_id'])) return 'không còn trong phạm vi AI Zalo'; }
    elseif (($c['ai_mode'] ?? 'on') === 'off') return 'không còn cần';
    $r = inbox_send($CFG, $th, $d['reply'], ['id' => 'ai', 'name' => 'AI']);
    if (!$r['ok']) return 'gửi lỗi: ' . $r['error'];
    if (!empty($d['handoff'])) {
        $pdo->prepare("UPDATE inbox_threads SET needs_human=1, updated_at=datetime('now') WHERE id=?")->execute([$th['id']]);
        $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$th['project_id']]);
        foreach ($ms->fetchAll() as $m) notify($m['user_id'], 'Cần người thật: ' . $th['customer_name'], mb_substr((string)($d['reason'] ?: $th['last_message']), 0, 120), '#inbox', $th['project_id']);
    }
    if (!empty($d['phone']) && empty($th['lead_id'])) {
        $phone = preg_replace('/\D+/', '', (string)$d['phone']);
        if (strlen($phone) >= 9) { $id = gen_id('LD');
            $pdo->prepare("INSERT INTO leads(id,project_id,name,phone,source,subject,state,notes,source_ref) VALUES (?,?,?,?,?,?,'CREATED',?,?)")
                ->execute([$id, $th['project_id'], $th['customer_name'], $phone, $th['provider'] === 'facebook_page' ? 'messenger' : $th['provider'], 'Khách để lại SĐT qua chat (AI ghi nhận)', mb_substr((string)$th['last_message'], 0, 500), $th['id']]);
            $pdo->prepare('UPDATE inbox_threads SET lead_id=? WHERE id=?')->execute([$id, $th['id']]);
            // Khách nóng: báo ngay chủ / quản lý dự án để gọi lại
            $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$th['project_id']]);
            foreach ($ms->fetchAll() as $m) notify($m['user_id'], '📞 Khách để lại SĐT: ' . $th['customer_name'] . ' — ' . $phone, mb_substr((string)$th['last_message'], 0, 150), '#inbox', $th['project_id']); }
    }
    log_activity('reply', 'inbox', $th['id'], 'AI trả lời ' . $th['customer_name'] . (!empty($d['handoff']) ? ' · cần người thật: ' . ($d['reason'] ?? '') : ''), null, $th['project_id']);
    return 'đã trả lời' . (!empty($d['handoff']) ? ' + bàn giao' : '');
}

/** Sinh câu trả lời cho hội thoại (không gửi) — gọi API trực tiếp. Không có khoá API → ['ok'=>false,'code'=>'no_api_key']. */
function ib_ai_draft(array $CFG, array $th, ?array $cfg = null): array {
    global $pdo;
    $cfg = $cfg ?: ib_ai_cfg($th['project_id']);
    $conn = get_connection($th['connection_id'], true); if (!$conn) return ['ok' => false, 'error' => 'Kênh không còn'];
    $st = $pdo->prepare('SELECT direction,text,attachments,sender_name,sent_by,sent_at FROM inbox_messages WHERE thread_id=? ORDER BY sent_at DESC, id DESC LIMIT 14'); $st->execute([$th['id']]);
    $rows = array_reverse($st->fetchAll()); $msgs = [];
    foreach ($rows as $m) {
        $t = trim((string)$m['text']); if ($t === '' && $m['attachments']) $t = '[khách gửi tệp/ảnh]'; if ($t === '') continue;
        $role = $m['direction'] === 'in' ? 'user' : 'assistant';
        if ($msgs && end($msgs)['role'] === $role) { $msgs[count($msgs) - 1]['content'] .= "\n" . $t; } else $msgs[] = ['role' => $role, 'content' => $t];
    }
    if (!$msgs || end($msgs)['role'] !== 'user') return ['ok' => false, 'error' => 'Tin cuối không phải của khách'];
    if ($msgs[0]['role'] !== 'user') array_shift($msgs);
    $schema = ['type' => 'object', 'properties' => ['reply' => ['type' => 'string'], 'skip' => ['type' => 'boolean'], 'handoff' => ['type' => 'boolean'], 'reason' => ['type' => 'string'], 'phone' => ['type' => ['string', 'null']]], 'required' => ['reply', 'skip', 'handoff', 'reason', 'phone'], 'additionalProperties' => false];
    $r = ai_generate(ib_ai_system($cfg, $conn, $th), $msgs, ['json_schema' => $schema, 'max_tokens' => 500, 'temperature' => 0.4, 'purpose' => 'inbox_ai', 'cache_system' => true, 'timeout' => 40]);
    if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'AI không trả lời được', 'code' => $r['code'] ?? null];
    $j = is_array($r['json'] ?? null) ? $r['json'] : (json_decode((string)$r['text'], true) ?: []);
    $reply = trim((string)($j['reply'] ?? ''));
    if (!empty($j['skip'])) return ['ok' => true, 'skip' => true, 'reply' => $reply, 'handoff' => false, 'reason' => (string)($j['reason'] ?? 'spam'), 'phone' => null];
    if ($reply === '') return ['ok' => false, 'error' => 'AI trả về rỗng'];
    return ['ok' => true, 'skip' => false, 'reply' => $reply, 'handoff' => !empty($j['handoff']), 'reason' => (string)($j['reason'] ?? ''), 'phone' => $j['phone'] ?? null, 'provider' => $r['provider'] ?? null, 'model' => $r['model'] ?? null];
}

/** Tự trả lời nếu đủ điều kiện (gọi sau khi có tin khách mới). Trả về mô tả ngắn để log. */
function ib_ai_auto_reply(array $CFG, string $threadId): string {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $st->execute([$threadId]); $th = $st->fetch();
    if (!$th || $th['last_direction'] !== 'in') return 'bỏ qua';
    $cfg = ib_ai_cfg($th['project_id']);
    if ($cfg['mode'] !== 'auto') return 'AI tắt ở dự án';
    if (($th['ai_mode'] ?? 'on') === 'off') return 'AI tắt ở hội thoại';
    if (($th['kind'] ?? 'message') === 'comment' && $cfg['comments'] !== 'auto') return 'bình luận: chỉ gợi ý';
    if (!empty($th['assigned_to'])) return 'đã giao người';
    $q = $pdo->prepare("SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out' AND COALESCE(sent_by,'')<>'ai'"); $q->execute([$threadId]);
    $lastHuman = $q->fetchColumn();
    if ($lastHuman && strtotime($lastHuman . ' UTC') > time() - IB_AI_HUMAN_QUIET_MIN * 60) return 'người thật đang trả lời';
    $q = $pdo->prepare("SELECT COUNT(*) FROM inbox_messages WHERE thread_id=? AND sent_by='ai' AND sent_at > ?"); $q->execute([$threadId, gmdate('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$q->fetchColumn() >= IB_AI_MAX_PER_DAY) return 'quá giới hạn ngày';
    if (!ai_available()['ok']) { // Không có khoá API → máy HL (Claude Max) xử lý
        $prep = ib_ai_prepare($CFG, $th, $cfg); if (empty($prep['ok'])) return $prep['error'];
        $jid = ib_ai_enqueue($th, 'auto', $prep);
        return ib_hl_alive() ? "chờ máy HL ($jid)" : "xếp hàng ($jid) — máy HL chưa kết nối";
    }
    $d = ib_ai_draft($CFG, $th, $cfg);
    if (empty($d['ok'])) return 'AI lỗi: ' . $d['error'];
    return ib_ai_apply($CFG, $th, $d, 'auto');
}
function ib_ai_auto_reply_legacy_tail(array $CFG, array $th, array $d): string {
    global $pdo;
    $r = inbox_send($CFG, $th, $d['reply'], ['id' => 'ai', 'name' => 'AI']);
    if (!$r['ok']) return 'gửi lỗi: ' . $r['error'];
    if ($d['handoff']) {
        $pdo->prepare("UPDATE inbox_threads SET needs_human=1, updated_at=datetime('now') WHERE id=?")->execute([$threadId]);
        $ms = $pdo->prepare("SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')"); $ms->execute([$th['project_id']]);
        foreach ($ms->fetchAll() as $m) notify($m['user_id'], 'Cần người thật: ' . $th['customer_name'], mb_substr($d['reason'] ?: (string)$th['last_message'], 0, 120), '#inbox', $th['project_id']);
    }
    if (!empty($d['phone']) && empty($th['lead_id'])) { // khách để lại SĐT → tạo đầu mối
        $phone = preg_replace('/\D+/', '', (string)$d['phone']);
        if (strlen($phone) >= 9) { $id = gen_id('LD');
            $pdo->prepare("INSERT INTO leads(id,project_id,name,phone,source,subject,state,notes,source_ref) VALUES (?,?,?,?,?,?,'CREATED',?,?)")
                ->execute([$id, $th['project_id'], $th['customer_name'], $phone, $th['provider'] === 'facebook_page' ? 'messenger' : 'zalo_oa', 'Khách để lại SĐT qua chat (AI ghi nhận)', mb_substr((string)$th['last_message'], 0, 500), $th['id']]);
            $pdo->prepare('UPDATE inbox_threads SET lead_id=? WHERE id=?')->execute([$id, $threadId]); }
    }
    log_activity('reply', 'inbox', $threadId, 'AI trả lời ' . $th['customer_name'] . ($d['handoff'] ? ' · cần người thật: ' . $d['reason'] : ''), null, $th['project_id']);
    return 'đã trả lời' . ($d['handoff'] ? ' + bàn giao' : '');
}
