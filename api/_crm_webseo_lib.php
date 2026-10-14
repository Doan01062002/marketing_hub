<?php
/* Nguồn dữ liệu CRM webseo.vn (Laravel, MySQL wsvn_app, cùng VPS) — CHỈ ĐỌC qua user MySQL quyền SELECT. 11/10/2026
   Cấu hình: config.local.php → crm_webseo_dsn / crm_webseo_user / crm_webseo_pass. Dự án dùng nguồn này: projects.settings.crm = 'webseo'.
   Trả về số liệu đã tổng hợp + danh sách ngắn (SĐT che giữa). Không ghi gì sang CRM. Kết quả cache 10 phút trong app_settings. */
declare(strict_types=1);

function crm_available(array $CFG): bool { return !empty($CFG['crm_webseo_dsn']) && !empty($CFG['crm_webseo_user']); }
function crm_pdo(array $CFG): PDO {
    static $p = null;
    if ($p) return $p;
    $p = new PDO($CFG['crm_webseo_dsn'], $CFG['crm_webseo_user'], (string)($CFG['crm_webseo_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
    $p->exec("SET SESSION TRANSACTION READ ONLY");
    return $p;
}
function crm_mask_phone(?string $s): string { $s = preg_replace('/\D+/', '', (string)$s); if (strlen($s) < 7) return $s ? '***' : ''; return substr($s, 0, 3) . '****' . substr($s, -3); }
function crm_q(array $CFG, string $sql, array $a = []): array { $st = crm_pdo($CFG)->prepare($sql); $st->execute($a); return $st->fetchAll(); }

/** Tổng hợp cho dự án: lead CRM, khách mới, dịch vụ sắp hết hạn, doanh thu/chi, đơn hàng, ticket. $days = kỳ (30/90). */
function crm_summary(array $CFG, int $days = 30): array {
    $since = date('Y-m-d 00:00:00', strtotime("-$days days"));
    $o = ['source' => 'CRM webseo.vn (MySQL, chỉ đọc)', 'as_of' => date('Y-m-d H:i'), 'days' => $days];
    // Lead = hội thoại/khách để lại thông tin (ht_hoi_thoai): web form, Facebook, tìm khách…
    $o['leads_total'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM ht_hoi_thoai')[0]['n'];
    $o['leads_period'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM ht_hoi_thoai WHERE created_at >= ?', [$since])[0]['n'];
    $o['leads_today'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM ht_hoi_thoai WHERE created_at >= CURDATE()')[0]['n'];
    $o['leads_unread'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM ht_hoi_thoai WHERE chua_doc=1')[0]['n'];
    $o['leads_by_channel'] = crm_q($CFG, 'SELECT COALESCE(kenh,"(khác)") k, COUNT(*) n, SUM(created_at >= ?) p FROM ht_hoi_thoai GROUP BY kenh ORDER BY n DESC', [$since]);
    $o['leads_by_day'] = crm_q($CFG, 'SELECT DATE(created_at) d, COUNT(*) n FROM ht_hoi_thoai WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d', [$since]);
    // Khách hàng
    $o['customers_total'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM customers')[0]['n'];
    $o['customers_new'] = (int)crm_q($CFG, 'SELECT COUNT(*) n FROM customers WHERE joined_at >= ?', [$since])[0]['n'];
    // Dịch vụ đang hoạt động & sắp hết hạn (cơ hội gia hạn / chăm sóc)
    $o['services_active'] = crm_q($CFG, "SELECT type, COUNT(*) n FROM services WHERE status='active' GROUP BY type ORDER BY n DESC");
    $o['expiring_30'] = crm_q($CFG, "SELECT type, COUNT(*) n FROM services WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) GROUP BY type");
    $o['expiring_7'] = (int)crm_q($CFG, "SELECT COUNT(*) n FROM services WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")[0]['n'];
    $o['expired_unrenewed_30'] = (int)crm_q($CFG, "SELECT COUNT(*) n FROM services WHERE status IN ('active','suspended') AND expires_at BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()")[0]['n'];
    // Tiền vào / ra theo tháng (sổ payments — direction in/out), 6 tháng
    $o['cash_by_month'] = crm_q($CFG, 'SELECT DATE_FORMAT(paid_at,"%Y-%m") m, SUM(direction="in")*0 + SUM(CASE WHEN direction="in" THEN amount_vnd ELSE 0 END) inflow, SUM(CASE WHEN direction="out" THEN amount_vnd ELSE 0 END) outflow FROM payments WHERE paid_at >= DATE_SUB(DATE_FORMAT(NOW(),"%Y-%m-01"), INTERVAL 5 MONTH) GROUP BY m ORDER BY m');
    $o['inflow_period'] = (int)crm_q($CFG, 'SELECT COALESCE(SUM(amount_vnd),0) s FROM payments WHERE direction="in" AND paid_at >= ?', [$since])[0]['s'];
    $o['inflow_by_category'] = crm_q($CFG, 'SELECT COALESCE(category,"(khác)") k, SUM(amount_vnd) s, COUNT(*) n FROM payments WHERE direction="in" AND paid_at >= ? GROUP BY category ORDER BY s DESC', [$since]);
    // Đơn hàng
    $o['orders_period'] = crm_q($CFG, 'SELECT status, COUNT(*) n, COALESCE(SUM(amount_vnd),0) s FROM orders WHERE created_at >= ? GROUP BY status', [$since]);
    $o['orders_by_source'] = crm_q($CFG, 'SELECT COALESCE(NULLIF(nguon_kenh,""),"(không gắn nguồn)") k, COUNT(*) n FROM orders WHERE created_at >= ? GROUP BY k ORDER BY n DESC', [$since]);
    // Hỗ trợ
    $o['tickets_open'] = (int)crm_q($CFG, "SELECT COUNT(*) n FROM tickets WHERE status IN ('open','replied')")[0]['n'];
    $o['trials_pending'] = (int)crm_q($CFG, "SELECT COUNT(*) n FROM trial_requests WHERE COALESCE(status,'pending') IN ('pending','new')")[0]['n'];
    return $o;
}
/** Lead gần đây từ CRM (SĐT che), để nhân sự thấy ai vừa để lại thông tin và đã được xử lý chưa. */
function crm_leads_recent(array $CFG, int $limit = 50): array {
    $rows = crm_q($CFG, 'SELECT h.id, h.kenh, h.ten, h.sdt, h.tin_cuoi, h.huong_cuoi, h.tin_cuoi_luc, h.chua_doc, h.created_at, u.name AS nguoi_phu_trach
        FROM ht_hoi_thoai h LEFT JOIN users u ON u.id=h.user_id ORDER BY h.created_at DESC LIMIT ' . max(1, min(200, $limit)));
    return array_map(fn($r) => ['id' => (int)$r['id'], 'channel' => $r['kenh'], 'name' => $r['ten'], 'phone' => crm_mask_phone($r['sdt']), 'has_phone' => (bool)preg_replace('/\D/', '', (string)$r['sdt']),
        'last' => mb_substr((string)$r['tin_cuoi'], 0, 160), 'last_dir' => $r['huong_cuoi'], 'last_at' => $r['tin_cuoi_luc'], 'unread' => (bool)$r['chua_doc'], 'created_at' => $r['created_at'], 'owner' => $r['nguoi_phu_trach']], $rows);
}
/** Dịch vụ sắp hết hạn (cơ hội chăm sóc/gia hạn): tên dịch vụ, loại, hạn, khách. */
function crm_expiring(array $CFG, int $days = 30, int $limit = 100): array {
    $rows = crm_q($CFG, "SELECT s.id, s.type, s.name, s.plan_code, s.price_vnd, s.expires_at, s.auto_renew, COALESCE(NULLIF(c.company_name,''), c.full_name) AS khach, c.phone
        FROM services s LEFT JOIN customers c ON c.user_id=s.user_id WHERE s.status='active' AND s.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY) ORDER BY s.expires_at LIMIT " . max(1, min(300, $limit)), [$days]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'name' => $r['name'], 'plan' => $r['plan_code'], 'price' => (int)$r['price_vnd'], 'expires_at' => $r['expires_at'],
        'auto_renew' => (bool)$r['auto_renew'], 'customer' => $r['khach'], 'phone' => crm_mask_phone($r['phone']), 'days_left' => (int)floor((strtotime($r['expires_at']) - time()) / 86400)], $rows);
}
/** Cache 10 phút theo dự án + hàm. */
function crm_cached(string $pid, string $key, int $ttl, callable $fn) {
    $k = "crm.cache.$pid.$key";
    $raw = setting_get($k);
    if ($raw) { $c = json_decode($raw, true); if (is_array($c) && ($c['at'] ?? 0) > time() - $ttl) return $c['data']; }
    $data = $fn();
    try { setting_set($k, json_encode(['at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE), ['id' => null]); } catch (Throwable $e) {}
    return $data;
}
