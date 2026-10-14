<?php
// 14/10/2026: đã gỡ Kho kênh tham khảo (chạy bằng máy HL) khỏi bản Chung Auto.
require __DIR__ . '/_boot.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'GET') respond(['refs' => [], 'jobs' => [], 'hl_alive' => false, 'platforms' => [], 'projects' => []]);
fail('Tính năng đã gỡ', 410);
