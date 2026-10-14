<?php
/* Nhật ký thao tác. GET ?user_id=&entity=&limit=(≤200)&before=<id>
   admin/manager xem tất cả; nhân viên chỉ xem thao tác của chính mình. */
require __DIR__ . '/_boot.php';
require_method('GET');
$me = require_login();

$where = ['(a.project_id=? OR a.project_id IS NULL)']; $vals = [pid()];
$userId = (string)($_GET['user_id'] ?? '');
if (!in_array($me['role'], ['admin', 'manager'], true)) $userId = $me['id'];
if ($userId !== '') { $where[] = 'a.user_id=?'; $vals[] = $userId; }
if (!empty($_GET['entity'])) { $where[] = 'a.entity=?'; $vals[] = (string)$_GET['entity']; }
if (!empty($_GET['before'])) { $where[] = 'a.id<?'; $vals[] = (int)$_GET['before']; }
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));

$sql = 'SELECT a.id,a.user_id,u.name AS user_name,a.action,a.entity,a.entity_id,a.detail,a.created_at
        FROM activity_log a LEFT JOIN users u ON u.id=a.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC LIMIT ' . ($limit + 1);
$st = $pdo->prepare($sql);
$st->execute($vals);
$rows = $st->fetchAll();
$more = count($rows) > $limit;
if ($more) array_pop($rows);
foreach ($rows as &$r) $r['id'] = (int)$r['id'];
respond(['activity' => $rows, 'more' => $more]);
