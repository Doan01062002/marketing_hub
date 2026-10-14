<?php
/* Sổ tay thương hiệu: thông điệp chính / giọng văn / quy tắc / tài sản (link).
   Mọi người đăng nhập đều xem được; chỉ admin/manager được thêm, sửa, xoá. */
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

const BRAND_KINDS = ['message', 'tone', 'rule', 'asset'];

function brand_row(array $r): array {
    return ['id' => $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'content' => $r['content'], 'subject' => $r['subject']];
}

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM brand_items WHERE project_id=? ORDER BY kind, created_at'); $st->execute([pid()]); $rows = $st->fetchAll();
    respond(['brand' => array_map('brand_row', $rows)]);
}

if ($method === 'POST') {
    $u = require_project_admin();
    $in = json_input();
    $id = $in['id'] ?? null;
    if (array_key_exists('kind', $in) && !in_array($in['kind'], BRAND_KINDS, true)) fail('Loại mục không hợp lệ');
    $fields = ['kind', 'title', 'content', 'subject'];

    if ($id) {
        need('brand_items', $id, 'mục thương hiệu');
        if (array_key_exists('title', $in) && trim((string)$in['title']) === '') fail('Tiêu đề không được để trống');
        $set = []; $vals = [];
        foreach ($fields as $f) if (array_key_exists($f, $in)) { $set[] = "$f=?"; $vals[] = trim((string)$in[$f]); }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = pid();
        $pdo->prepare('UPDATE brand_items SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'brand', $id, 'Sửa mục thương hiệu: ' . ($in['title'] ?? $id), $u);
        respond(['ok' => true]);
    }

    $kind = (string)($in['kind'] ?? '');
    $title = trim((string)($in['title'] ?? ''));
    if (!in_array($kind, BRAND_KINDS, true)) fail('Thiếu loại mục');
    if ($title === '') fail('Thiếu tiêu đề');
    $newId = gen_id('BR');
    $pdo->prepare('INSERT INTO brand_items(id,project_id,kind,title,content,subject,created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$newId, pid(), $kind, $title, trim((string)($in['content'] ?? '')), trim((string)($in['subject'] ?? '')), $u['id']]);
    log_activity('create', 'brand', $newId, 'Thêm mục thương hiệu: ' . $title, $u);
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    $u = require_project_admin();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    $t = need('brand_items', $id, 'mục thương hiệu')['title'];
    $pdo->prepare('DELETE FROM brand_items WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'brand', $id, 'Xoá mục thương hiệu: ' . $t, $u);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
