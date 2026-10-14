<?php
/* Tải ảnh/video lên để đăng bài.
   POST multipart (field "file")  → {path:'uploads/YYYYMM/<ngẫu nhiên>.<ext>', type:'image'|'video', name, size}
   DELETE ?path=uploads/…         → xoá file chưa dùng trong bài nào (người tải lên trong phiên này, hoặc Quản trị/Trưởng nhóm).
   Kiểm tra cả đuôi file lẫn nội dung thật (finfo nếu PHP bật fileinfo, nếu không thì dò chữ ký đầu file). */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

const UPLOAD_MAX = ['image' => 8 * 1024 * 1024, 'video' => 200 * 1024 * 1024];
const UPLOAD_MIME = [
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
    'mp4' => ['video/mp4', 'video/quicktime', 'video/x-m4v'], 'mov' => ['video/quicktime', 'video/mp4', 'video/x-m4v'],
];

function ini_bytes(string $v): int {
    $v = trim($v); $n = (int)$v;
    $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}
function fmt_mb(int $b): string { if ($b < 1048576) return max(1, (int)round($b / 1024)) . ' KB'; return rtrim(rtrim(number_format($b / 1048576, 1, ',', ''), '0'), ',') . ' MB'; }

/** MIME thật của file: finfo nếu có, không thì dò chữ ký (magic bytes). */
function sniff_mime(string $file): string {
    if (class_exists('finfo')) {
        $m = (new finfo(FILEINFO_MIME_TYPE))->file($file);
        if ($m) return $m;
    }
    $h = (string)file_get_contents($file, false, null, 0, 16);
    if (str_starts_with($h, "\x89PNG\r\n\x1a\n")) return 'image/png';
    if (str_starts_with($h, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($h, 'GIF87a') || str_starts_with($h, 'GIF89a')) return 'image/gif';
    if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($h, 4, 4) === 'ftyp') return substr($h, 8, 4) === 'qt  ' ? 'video/quicktime' : 'video/mp4';
    if (in_array(substr($h, 4, 4), ['moov', 'mdat', 'wide', 'free', 'skip'], true)) return 'video/quicktime';
    return 'application/octet-stream';
}

if ($method === 'POST') {
    if (empty($_FILES['file'])) {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > ini_bytes((string)ini_get('post_max_size'))) fail('File quá lớn so với giới hạn của máy chủ (post_max_size = ' . ini_get('post_max_size') . '). Nhờ quản trị tăng post_max_size / upload_max_filesize trong php.ini', 413);
        fail('Chưa chọn file (gửi multipart/form-data với trường "file")');
    }
    $f = $_FILES['file'];
    if (is_array($f['name'])) fail('Mỗi lần chỉ tải lên 1 file');
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        fail('File vượt giới hạn tải lên của máy chủ (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Nhờ quản trị tăng giới hạn trong php.ini', 413);
    }
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Tải file lên không thành công (mã lỗi ' . $f['error'] . ')');
    $name = basename((string)$f['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(UPLOAD_EXT[$ext])) fail('Chỉ nhận ảnh (JPG, PNG, WEBP, GIF) hoặc video (MP4, MOV) — file "' . $name . '" không được phép', 415);
    $type = UPLOAD_EXT[$ext];
    $mime = sniff_mime($f['tmp_name']);
    if (!in_array($mime, UPLOAD_MIME[$ext] ?? [], true)) fail('Nội dung file "' . $name . '" không khớp đuôi .' . $ext . ' (nhận diện là ' . $mime . ') — không tải lên', 415);
    $size = (int)filesize($f['tmp_name']);
    if ($size <= 0) fail('File rỗng');
    if ($size > UPLOAD_MAX[$type]) fail(($type === 'image' ? 'Ảnh' : 'Video') . ' tối đa ' . fmt_mb(UPLOAD_MAX[$type]) . ' — file này ' . fmt_mb($size), 413);

    $sub = date('Ym');
    $dir = UPLOAD_DIR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail('Không tạo được thư mục uploads/' . $sub, 500);
    $fname = bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) fail('Không lưu được file trên máy chủ', 500);
    $path = 'uploads/' . $sub . '/' . $fname;
    $_SESSION['my_uploads'][$path] = time();
    log_activity('upload', 'media', $path, 'Tải lên ' . $name . ' (' . fmt_mb($size) . ')');
    respond(['path' => $path, 'type' => $type, 'name' => mb_substr($name, 0, 120), 'size' => $size, 'mime' => $mime], 201);
}

if ($method === 'DELETE') {
    $path = (string)($_GET['path'] ?? '');
    if (!preg_match('#^uploads/(\d{6})/([a-f0-9]{24})\.([a-z0-9]{3,4})$#', $path, $m) || !isset(UPLOAD_EXT[$m[3]])) fail('Đường dẫn file không hợp lệ');
    $mine = isset($_SESSION['my_uploads'][$path]);
    if (!$mine && !in_array($me['role'], ['admin', 'manager'], true)) fail('Chỉ xoá được file do bạn vừa tải lên', 403);
    $st = $pdo->prepare('SELECT id FROM posts WHERE media LIKE ? LIMIT 1');
    $st->execute(['%' . $path . '%']);
    if ($used = $st->fetchColumn()) fail('File đang được dùng trong bài ' . $used . ' — gỡ khỏi bài trước khi xoá', 409);
    $file = UPLOAD_DIR . '/' . $m[1] . '/' . $m[2] . '.' . $m[3];
    if (is_file($file)) unlink($file);
    $ig = UPLOAD_DIR . '/' . $m[1] . '/' . $m[2] . '_ig.jpg';
    if (is_file($ig)) unlink($ig);
    unset($_SESSION['my_uploads'][$path]);
    log_activity('delete', 'media', $path, 'Xoá file tải lên chưa dùng');
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
