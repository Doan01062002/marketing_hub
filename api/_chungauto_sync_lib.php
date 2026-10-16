<?php
/* Đồng bộ khách từ trang quản trị chungauto.vn (Laravel) vào bảng care_records để CSKH chăm sóc. 16/10/2026
   - CHỈ ĐỌC: đăng nhập rồi đọc trang danh sách Liên hệ (/cp_admin/contacts), danh sách + trang "Xem" đơn hàng (/cp_admin/orders/{id}).
     Không mở link hành động (responded / noresponseyet / edit / delete / status) — các link đó đổi dữ liệu trên web.
   - Chạy lại không tạo trùng (UNIQUE project_id+src+kind+ext_id). Cập nhật thông tin từ web, KHÔNG đè phần chăm sóc
     (care_status, assignee_id, next_follow_at, care_log) — trừ lần đầu: trạng thái chăm sóc ban đầu suy từ trạng thái web.
   - Tài khoản: config.local.php 'chungauto_admin_email' / 'chungauto_admin_password'.
   ca_sync($CFG, $projectId, ['full' => bool, 'max_seconds' => int, 'log' => callable]) → ['contacts' => [new, upd], 'orders' => [new, upd], 'pages' => n] */

const CA_BASE = 'https://chungauto.vn/cp_admin';
const CA_SRC = 'chungauto.vn';
const CA_ORDER_ST = [1 => 'Đã xóa', 2 => 'Mới', 3 => 'Đang xử lý', 4 => 'Hủy', 5 => 'Thành công', 6 => 'Chưa thanh toán', 7 => 'Đã thanh toán'];

class CaSyncError extends RuntimeException {}

/** Phiên HTTP có cookie (đăng nhập Laravel). */
final class CaHttp {
    private string $jar;
    public int $requests = 0;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'ca_'); }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $url, ?array $post = null): array {
        if ($this->requests++ > 0) usleep(250000); // lịch sự với máy chủ web: ~4 yêu cầu/giây
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'MarketingHub-CSKH-Sync/1.0 (+mkt.chunggroup.vn)', CURLOPT_ENCODING => '']);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch);
        if ($body === false) throw new CaSyncError('Không kết nối được chungauto.vn');
        return [$code, (string)$body, $loc];
    }
    public function page(string $url): string {
        [$c, $b, $loc] = $this->req($url);
        if ($c === 302 && str_contains($loc, '/login')) throw new CaSyncError('Phiên đăng nhập chungauto.vn hết hạn');
        if ($c !== 200) throw new CaSyncError("chungauto.vn trả HTTP $c cho $url");
        return $b;
    }
}

function ca_login(array $CFG): CaHttp {
    // Ưu tiên biến môi trường lúc chạy (chạy tay một lần, không lưu mật khẩu); sau đó mới tới config.local.php.
    $email = (string)(getenv('CHUNGAUTO_ADMIN_EMAIL') ?: ($CFG['chungauto_admin_email'] ?? '')); $pass = (string)(getenv('CHUNGAUTO_ADMIN_PASSWORD') ?: ($CFG['chungauto_admin_password'] ?? ''));
    if ($email === '' || $pass === '') throw new CaSyncError('Chưa cấu hình tài khoản quản trị chungauto.vn (chungauto_admin_email / chungauto_admin_password)');
    $h = new CaHttp();
    [, $b] = $h->req(CA_BASE . '/login');
    if (!preg_match('/name="_token" value="([^"]+)"/', $b, $m)) throw new CaSyncError('Không đọc được trang đăng nhập chungauto.vn');
    [$c, , $loc] = $h->req(CA_BASE . '/login', ['_token' => $m[1], 'email' => $email, 'password' => $pass]);
    if ($c !== 302 || str_contains($loc, '/login')) throw new CaSyncError('Đăng nhập chungauto.vn thất bại — kiểm tra email / mật khẩu');
    return $h;
}

function ca_txt(string $html): string { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); }
function ca_num(string $s): float { return (float)preg_replace('/[^\d.-]/', '', str_replace(',', '', $s)); }
function ca_phone_norm(?string $p): string { $d = preg_replace('/\D/', '', (string)$p); if (str_starts_with($d, '84') && strlen($d) >= 11) $d = '0' . substr($d, 2); return $d; }
function ca_date(string $s): ?string {
    $s = trim($s);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4}) (\d{2}:\d{2}:\d{2})#', $s, $m)) return "$m[3]-$m[2]-$m[1] $m[4]";
    if (preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}#', $s, $m)) return $m[0];
    return null;
}
function ca_rows(string $html): array {
    if (!preg_match('#<tbody[^>]*>(.*?)</tbody>#s', $html, $m)) return [];
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $m[1], $rows);
    return array_map(function ($r) { preg_match_all('#<td[^>]*>(.*?)</td>#s', $r, $td); return $td[1]; }, $rows[1]);
}
function ca_last_page(string $html): int { preg_match_all('/[?&]page=(\d+)/', $html, $m); return $m[1] ? max(array_map('intval', $m[1])) : 1; }

/** Trang danh sách Liên hệ → [{ext_id, name, phone, address, email, message, web_status, ext_created_at}] */
function ca_parse_contacts(string $html): array {
    $out = [];
    foreach (ca_rows($html) as $td) {
        if (count($td) < 5) continue;
        $info = $td[1]; $f = [];
        foreach (['Tên' => 'name', 'Số điện thoại' => 'phone', 'Địa chỉ' => 'address', 'Email' => 'email'] as $lbl => $k)
            $f[$k] = preg_match('#<b>\s*' . preg_quote($lbl, '#') . '\s*</b>\s*:\s*(.*?)(?:<br|$)#su', $info, $m) ? ca_txt($m[1]) : '';
        $out[] = $f + ['ext_id' => ca_txt($td[0]), 'message' => ca_txt($td[2]), 'ext_created_at' => ca_date(ca_txt($td[3])), 'web_status' => ca_txt($td[4]) ?: null];
    }
    return array_values(array_filter($out, fn($r) => ctype_digit($r['ext_id'])));
}

/** Trang danh sách Đơn hàng → [{ext_id, name, total, status_code, web_status, ext_created_at}] */
function ca_parse_order_list(string $html): array {
    $out = [];
    foreach (ca_rows($html) as $td) {
        if (count($td) < 5) continue;
        $id = ca_txt($td[0]); if (!ctype_digit($id)) continue;
        $code = preg_match('/data-value="(\d+)"/', $td[3], $m) ? (int)$m[1] : 0;
        $out[] = ['ext_id' => $id, 'name' => ca_txt($td[1]), 'total' => ca_num(ca_txt($td[2])), 'status_code' => $code,
            'web_status' => CA_ORDER_ST[$code] ?? (ca_txt($td[3]) ?: null), 'ext_created_at' => ca_date(ca_txt($td[4]))];
    }
    return $out;
}

/** Trang "Xem" đơn hàng → chi tiết khách, thanh toán, sản phẩm, ghi chú. */
function ca_parse_order_detail(string $html): array {
    $d = ['name' => '', 'phone' => '', 'address' => '', 'subtotal' => null, 'discount' => null, 'total' => null, 'coupon' => '', 'items' => [], 'message' => ''];
    if (preg_match('#Khách hàng\s*<address>(.*?)</address>#su', $html, $m)) {
        $parts = array_values(array_filter(array_map('ca_txt', preg_split('#<br\s*/?>#i', $m[1])), fn($x) => $x !== ''));
        $d['name'] = $parts[0] ?? ''; $d['phone'] = $parts[1] ?? ''; $d['address'] = implode(', ', array_slice($parts, 2));
    }
    if (preg_match('#Thanh toán\s*<address>(.*?)</address>#su', $html, $m)) {
        $nums = array_values(array_filter(array_map('ca_txt', preg_split('#<br\s*/?>#i', $m[1])), fn($x) => $x !== ''));
        $d['subtotal'] = isset($nums[0]) ? ca_num($nums[0]) : null;
        $d['total'] = $nums ? ca_num(end($nums)) : null;
        $d['discount'] = count($nums) >= 3 ? ca_num($nums[count($nums) - 2]) : 0.0;
    }
    if (preg_match('#<b>Mã giảm giá:</b>(.*?)<br#su', $html, $m)) $d['coupon'] = ca_txt($m[1]);
    if (preg_match('#<th>Sản phẩm</th>.*?<tbody>(.*?)</tbody>#su', $html, $m)) {
        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $m[1], $rows);
        foreach ($rows[1] as $r) {
            preg_match_all('#<td[^>]*>(.*?)</td>#s', $r, $td); $c = $td[1];
            if (count($c) < 5) continue;
            $d['items'][] = ['name' => ca_txt($c[1]), 'qty' => (int)ca_num(ca_txt($c[2])), 'price' => ca_num(ca_txt($c[3])), 'total' => ca_num(ca_txt($c[4])), 'note' => ca_txt($c[5] ?? '')];
        }
    }
    if (preg_match('#<div class="col-6">\s*Ghi chú\s*:(.*?)</div>#su', $html, $m)) $d['message'] = ca_txt($m[1]);
    return $d;
}

/** Trạng thái chăm sóc ban đầu (chỉ dùng khi thêm mới). */
function ca_initial_care(string $kind, ?string $web): string {
    $w = mb_strtolower((string)$web);
    if ($kind === 'contact') return str_contains($w, 'đã phản hồi') ? 'consulted' : 'new';
    if (in_array($web, ['Thành công', 'Đã thanh toán'], true)) return 'won';
    if (in_array($web, ['Hủy', 'Đã xóa'], true)) return 'lost';
    return 'new';
}

/** Ghi / cập nhật một bản ghi. Trả 'new' | 'upd' | 'same'. */
function ca_upsert(string $pid, string $kind, array $r): string {
    global $pdo;
    $st = $pdo->prepare('SELECT id, name, phone, address, email, message, items, total, web_status FROM care_records WHERE project_id=? AND src=? AND kind=? AND ext_id=?');
    $st->execute([$pid, CA_SRC, $kind, $r['ext_id']]); $old = $st->fetch();
    $items = isset($r['items']) ? json_encode($r['items'], JSON_UNESCAPED_UNICODE) : null;
    if (!$old) {
        $pdo->prepare("INSERT INTO care_records(id,project_id,src,kind,ext_id,name,phone,phone_norm,email,address,message,items,subtotal,discount,total,coupon,web_status,ext_created_at,care_status,synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))")
            ->execute([gen_id('CS'), $pid, CA_SRC, $kind, $r['ext_id'], $r['name'] ?? '', $r['phone'] ?? '', ca_phone_norm($r['phone'] ?? ''), $r['email'] ?? '', $r['address'] ?? '',
                $r['message'] ?? '', $items, $r['subtotal'] ?? null, $r['discount'] ?? null, $r['total'] ?? null, $r['coupon'] ?? '', $r['web_status'] ?? null, $r['ext_created_at'] ?? null,
                ca_initial_care($kind, $r['web_status'] ?? null)]);
        return 'new';
    }
    $chg = ($r['web_status'] ?? $old['web_status']) !== $old['web_status'] || ($r['name'] ?? $old['name']) !== $old['name'] || ($r['phone'] ?? $old['phone']) !== $old['phone']
        || ($r['address'] ?? $old['address']) !== $old['address'] || ($r['message'] ?? $old['message']) !== $old['message'] || ($items !== null && $items !== $old['items'])
        || (isset($r['total']) && (float)$r['total'] !== (float)$old['total']);
    if (!$chg) { $pdo->prepare("UPDATE care_records SET synced_at=datetime('now') WHERE id=?")->execute([$old['id']]); return 'same'; }
    $pdo->prepare("UPDATE care_records SET name=COALESCE(?,name), phone=COALESCE(?,phone), phone_norm=COALESCE(?,phone_norm), email=COALESCE(?,email), address=COALESCE(?,address),
        message=COALESCE(?,message), items=COALESCE(?,items), subtotal=COALESCE(?,subtotal), discount=COALESCE(?,discount), total=COALESCE(?,total), coupon=COALESCE(?,coupon),
        web_status=COALESCE(?,web_status), ext_created_at=COALESCE(?,ext_created_at), synced_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
        ->execute([$r['name'] ?? null, $r['phone'] ?? null, isset($r['phone']) ? ca_phone_norm($r['phone']) : null, $r['email'] ?? null, $r['address'] ?? null, $r['message'] ?? null, $items,
            $r['subtotal'] ?? null, $r['discount'] ?? null, $r['total'] ?? null, $r['coupon'] ?? null, $r['web_status'] ?? null, $r['ext_created_at'] ?? null, $old['id']]);
    return 'upd';
}

function ca_sync(array $CFG, string $pid, array $opt = []): array {
    global $pdo;
    $full = !empty($opt['full']); $deadline = time() + (int)($opt['max_seconds'] ?? 240); $log = $opt['log'] ?? fn($m) => null;
    $h = ca_login($CFG);
    $res = ['contacts' => ['new' => 0, 'upd' => 0], 'orders' => ['new' => 0, 'upd' => 0], 'pages' => 0, 'complete' => true];

    // ---- Liên hệ: trang 1 là mới nhất. Đồng bộ thường: dừng khi gặp 2 trang liền không có gì mới.
    $html = $h->page(CA_BASE . '/contacts'); $last = ca_last_page($html); $quiet = 0;
    for ($p = 1; $p <= $last; $p++) {
        if ($p > 1) $html = $h->page(CA_BASE . '/contacts?page=' . $p);
        $res['pages']++; $newHere = 0;
        foreach (ca_parse_contacts($html) as $r) { $k = ca_upsert($pid, 'contact', $r); if ($k !== 'same') { $res['contacts'][$k]++; $newHere++; } }
        if ($p % 20 === 0) $log("liên hệ: trang $p/$last");
        if (!$full && ($quiet = $newHere ? 0 : $quiet + 1) >= 2) break;
        if (time() > $deadline) { $res['complete'] = false; break; }
    }

    // ---- Đơn hàng: danh sách cho trạng thái + tổng; trang "Xem" cho SĐT, địa chỉ, sản phẩm (chỉ đơn mới hoặc chưa có chi tiết).
    $have = []; $st = $pdo->prepare("SELECT ext_id, web_status, items IS NOT NULL AS has_detail FROM care_records WHERE project_id=? AND src=? AND kind='order'"); $st->execute([$pid, CA_SRC]);
    foreach ($st->fetchAll() as $r) $have[$r['ext_id']] = $r;
    $html = $h->page(CA_BASE . '/orders'); $last = ca_last_page($html); $quiet = 0;
    for ($p = 1; $p <= $last && time() <= $deadline; $p++) {
        if ($p > 1) $html = $h->page(CA_BASE . '/orders?page=' . $p);
        $res['pages']++; $newHere = 0;
        foreach (ca_parse_order_list($html) as $r) {
            $o = $have[$r['ext_id']] ?? null;
            if (!$o || !$o['has_detail']) {
                if (time() > $deadline) { $res['complete'] = false; break 2; }
                $d = ca_parse_order_detail($h->page(CA_BASE . '/orders/' . $r['ext_id']));
                $r = array_merge($r, array_filter($d, fn($v) => $v !== '' && $v !== null && $v !== []), ['items' => $d['items']]);
            }
            unset($r['status_code']);
            $k = ca_upsert($pid, 'order', $r);
            if ($k !== 'same') { $res['orders'][$k]++; $newHere++; }
        }
        if ($p % 5 === 0) $log("đơn hàng: trang $p/$last");
        if (!$full && ($quiet = $newHere ? 0 : $quiet + 1) >= 2) break;
    }
    if (time() > $deadline) $res['complete'] = false;
    setting_set('care.last_sync.' . $pid, json_encode(['at' => date('Y-m-d H:i:s'), 'res' => $res], JSON_UNESCAPED_UNICODE), ['id' => null]);
    return $res;
}
