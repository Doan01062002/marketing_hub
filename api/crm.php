<?php
/* Dữ liệu CRM thật cho dự án (hiện chỉ nguồn webseo.vn). 11/10/2026
   GET ?action=summary[&days=30|90]   → tổng hợp lead/khách/dịch vụ/doanh thu/đơn
   GET ?action=leads[&limit=50]       → lead gần đây (SĐT che)
   GET ?action=expiring[&days=30]     → dịch vụ sắp hết hạn
   GET ?action=refresh                → xoá cache (chủ/quản lý dự án)
   Dự án phải bật settings.crm = 'webseo' (Cài đặt dự án); không bật → {connected:false}. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_crm_webseo_lib.php';
$me = require_login();
require_method('GET');
$p = current_project(); if (!$p) fail('Chưa chọn dự án', 403);
$PID = $p['id'];
$crm = (string)(jdecode($p['settings'] ?? null)['crm'] ?? '');
$action = (string)($_GET['action'] ?? 'summary');
if ($crm !== 'webseo') respond(['connected' => false, 'reason' => 'Dự án chưa gắn nguồn CRM (Cài đặt dự án → Nguồn CRM)']);
if (!crm_available($CFG)) respond(['connected' => false, 'reason' => 'Máy chủ chưa cấu hình crm_webseo_dsn/user/pass trong config.local.php']);
// Khách hàng (client) / chỉ xem: không xem danh sách lead và dịch vụ chi tiết, chỉ tổng hợp
$role = project_role();
try {
    if ($action === 'refresh') { require_project_admin(); $pdo->prepare('DELETE FROM app_settings WHERE key LIKE ?')->execute(["crm.cache.$PID.%"]); respond(['ok' => true]); }
    if ($action === 'leads') { if (in_array($role, ['client', 'viewer'], true)) fail('Vai trò của bạn không xem danh sách lead CRM', 403); $lim = max(1, min(200, (int)($_GET['limit'] ?? 50))); respond(['connected' => true, 'leads' => crm_cached($PID, 'leads' . $lim, 600, fn() => crm_leads_recent($CFG, $lim))]); }
    if ($action === 'expiring') { if (in_array($role, ['client', 'viewer'], true)) fail('Vai trò của bạn không xem danh sách dịch vụ', 403); respond(['connected' => true, 'expiring' => crm_cached($PID, 'expiring', 600, fn() => crm_expiring($CFG, (int)($_GET['days'] ?? 30)))]); }
    $days = in_array((int)($_GET['days'] ?? 30), [30, 90], true) ? (int)$_GET['days'] : 30;
    respond(['connected' => true, 'summary' => crm_cached($PID, "summary$days", 600, fn() => crm_summary($CFG, $days))]);
} catch (PDOException $e) {
    log_activity('error', 'crm', null, 'CRM webseo.vn: ' . $e->getMessage());
    respond(['connected' => false, 'reason' => 'Không kết nối được CSDL CRM (' . mb_substr($e->getMessage(), 0, 120) . ')'], 424);
}
