<?php
// 14/10/2026: đã gỡ tích hợp CRM webseo.vn khỏi bản Chung Auto.
require __DIR__ . '/_boot.php';
require_login();
respond(['connected' => false, 'reason' => 'Chưa có nguồn CRM']);
