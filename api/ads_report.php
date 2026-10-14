<?php
/* Báo cáo Facebook Ads — GET ?conn_id=…&range=…[&since&until]&level=campaign|adset|ad[&refresh=1]. Logic: _ads_report_lib.php. */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_ads_report_lib.php';
require_login();

$conn = get_connection((string)($_GET['conn_id'] ?? ''));
if (!$conn || $conn['provider'] !== 'meta_ads') fail('Không tìm thấy tài khoản Meta Ads', 404);
if ($conn['status'] !== 'connected') fail('Tài khoản Meta Ads chưa kết nối — bấm Kết nối lại', 409);
session_write_close();
try { respond(ads_report($CFG, $conn, $_GET)); }
catch (AdsReportError $e) { fail($e->getMessage(), $e->getCode() ?: 400); }
