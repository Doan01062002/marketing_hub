<?php
/* Thông báo trong app của người đang đăng nhập.
   GET [?limit=50][&count=1]       → {notifications, unread}  (count=1: chỉ đếm, dùng cho chuông)
   POST ?action=read {id}          → đánh dấu đã đọc
   POST ?action=read_all           → đánh dấu tất cả đã đọc */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

// user_id NULL = gửi chung cho mọi Quản trị + Trưởng nhóm.
$mine = in_array($me['role'], ['admin', 'manager'], true) ? '(user_id=? OR user_id IS NULL)' : 'user_id=?';

if ($method === 'GET') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $mine AND read_at IS NULL");
    $st->execute([$me['id']]);
    $unread = (int)$st->fetchColumn();
    if (!empty($_GET['count'])) respond(['unread' => $unread]);
    $lim = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $st = $pdo->prepare("SELECT id,title,body,link,read_at,created_at FROM notifications WHERE $mine ORDER BY id DESC LIMIT $lim");
    $st->execute([$me['id']]);
    respond(['notifications' => $st->fetchAll(), 'unread' => $unread]);
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';
    if ($action === 'read') {
        $in = json_input();
        $pdo->prepare("UPDATE notifications SET read_at=datetime('now') WHERE id=? AND $mine AND read_at IS NULL")->execute([(int)($in['id'] ?? 0), $me['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'read_all') {
        $pdo->prepare("UPDATE notifications SET read_at=datetime('now') WHERE $mine AND read_at IS NULL")->execute([$me['id']]);
        respond(['ok' => true]);
    }
    fail('Thao tác không hỗ trợ');
}

fail('Method không hỗ trợ', 405);
