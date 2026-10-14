<?php
/* Việc giao máy HL (ngoài AI chat) — bảng hl_jobs. 12/10/2026. Xác thực bằng header X-HL-Key (như hl.php).
   GET  ?action=take&take=1          → {jobs:[{id, kind, payload}]}  (đánh dấu taken)
   POST {action:'done', id, result?, error?, files?:[{name, mime, b64}]}
        - ref_fetch: result {name, bio, avatar, followers, likes, posts_count, extra, formats_guess?} → cập nhật ref_channels
        - ref_adapt: result {title, caption, slides:[…], hashtags} + files (ảnh slide PNG/JPG) → lưu uploads/ + tạo BÀI NHÁP trong Lịch đăng
   Không gửi Telegram/thông báo (anh Tập không muốn nhiều thông báo). */
require __DIR__ . '/_boot.php';
$key = (string)($_SERVER['HTTP_X_HL_KEY'] ?? ''); $want = (string)($CFG['hl_api_key'] ?? '');
if ($want === '' || $key === '' || !hash_equals($want, $key)) fail('Sai khoá máy HL', 401);
if (strtotime((string)setting_get('hl.jobs_seen', '2000-01-01')) < time() - 45) setting_set('hl.jobs_seen', date('Y-m-d H:i:s'), ['id' => null]);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $pdo->exec("UPDATE hl_jobs SET status='failed', error='quá hạn (máy HL không trả lời trong 15 phút)' WHERE status='taken' AND taken_at < datetime('now','-15 minutes')");
    $pdo->exec("UPDATE hl_jobs SET status='failed', error='quá cũ (chờ hơn 2 giờ — máy HL không chạy?)' WHERE status='queued' AND created_at < datetime('now','-2 hours')");
    $take = max(1, min(3, (int)($_GET['take'] ?? 1)));
    $rows = $pdo->query("SELECT * FROM hl_jobs WHERE status='queued' ORDER BY created_at LIMIT $take")->fetchAll();
    foreach ($rows as $r) $pdo->prepare("UPDATE hl_jobs SET status='taken', taken_at=datetime('now') WHERE id=?")->execute([$r['id']]);
    respond(['jobs' => array_map(fn($r) => ['id' => $r['id'], 'kind' => $r['kind'], 'project_id' => $r['project_id'], 'ref_id' => $r['ref_id'], 'payload' => jdecode($r['payload'], [])], $rows)]);
}

require_method('POST');
$in = json_input();
$id = (string)($in['id'] ?? '');
$st = $pdo->prepare('SELECT * FROM hl_jobs WHERE id=?'); $st->execute([$id]);
$job = $st->fetch();
if (!$job) fail('Không thấy việc', 404);
if ($job['status'] !== 'taken') fail('Việc không ở trạng thái đang làm (' . $job['status'] . ')', 409);

$fin = function (?array $result, ?string $error) use ($pdo, $id) {
    $pdo->prepare("UPDATE hl_jobs SET status=?, result=?, error=?, done_at=datetime('now') WHERE id=?")
        ->execute([$error === null ? 'done' : 'failed', $result ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $error, $id]);
};

$err = isset($in['error']) ? mb_substr(trim((string)$in['error']), 0, 1000) : '';
$res = is_array($in['result'] ?? null) ? $in['result'] : [];
$payload = jdecode($job['payload'], []);

if ($job['kind'] === 'ref_fetch') {
    $refId = (string)$job['ref_id'];
    if ($err !== '') {
        $pdo->prepare("UPDATE ref_channels SET fetch_error=?, updated_at=datetime('now') WHERE id=?")->execute([$err, $refId]);
        $fin(null, $err); respond(['ok' => true]);
    }
    $num = fn($k) => isset($res[$k]) && is_numeric($res[$k]) ? (int)$res[$k] : null;
    $pdo->prepare("UPDATE ref_channels SET name=COALESCE(?,name), bio=COALESCE(?,bio), avatar=COALESCE(?,avatar), followers=COALESCE(?,followers), likes=COALESCE(?,likes), posts_count=COALESCE(?,posts_count),
        extra=?, fetched_at=datetime('now'), fetch_error=NULL, updated_at=datetime('now') WHERE id=?")
        ->execute([mb_substr(trim((string)($res['name'] ?? '')), 0, 200) ?: null, mb_substr(trim((string)($res['bio'] ?? '')), 0, 1000) ?: null, mb_substr(trim((string)($res['avatar'] ?? '')), 0, 500) ?: null,
            $num('followers'), $num('likes'), $num('posts_count'), json_encode($res['extra'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $refId]);
    // Gợi ý format từ máy HL chỉ thêm khi kênh chưa có format nào (không ghi đè format người dùng viết)
    if (!empty($res['formats_guess']) && is_array($res['formats_guess'])) {
        $st = $pdo->prepare('SELECT formats FROM ref_channels WHERE id=?'); $st->execute([$refId]);
        if (!jdecode($st->fetchColumn() ?: null, [])) $pdo->prepare('UPDATE ref_channels SET formats=? WHERE id=?')->execute([json_encode(array_slice($res['formats_guess'], 0, 6), JSON_UNESCAPED_UNICODE), $refId]);
    }
    $fin(['followers' => $num('followers')], null);
    respond(['ok' => true]);
}

if ($job['kind'] === 'ref_adapt') {
    if ($err !== '') { $fin(null, $err); respond(['ok' => true]); }
    $pid = (string)$job['project_id'];
    $title = mb_substr(trim((string)($res['title'] ?? '')), 0, 200);
    $caption = (string)($res['caption'] ?? '');
    if ($title === '' && $caption === '') { $fin(null, 'Máy HL trả kết quả trống'); respond(['ok' => true]); }
    // Lưu ảnh slide vào uploads/YYYYMM/<24hex>.png theo đúng khuôn upload.php để posts.php chấp nhận
    $media = []; $dir = date('Ym'); @mkdir(UPLOAD_DIR . '/' . $dir, 0775, true);
    foreach ((array)($in['files'] ?? []) as $i => $f) {
        $bin = base64_decode((string)($f['b64'] ?? ''), true);
        if ($bin === false || strlen($bin) < 100 || strlen($bin) > 8 * 1024 * 1024) continue;
        $ext = str_starts_with($bin, "\x89PNG") ? 'png' : (str_starts_with($bin, "\xFF\xD8\xFF") ? 'jpg' : null);
        if (!$ext) continue;
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents(UPLOAD_DIR . "/$dir/$name", $bin) === false) continue;
        $media[] = ['path' => "uploads/$dir/$name", 'type' => 'image', 'name' => mb_substr((string)($f['name'] ?? ('slide-' . ($i + 1) . '.' . $ext)), 0, 120)];
        if (count($media) >= 10) break;
    }
    $postId = gen_id('PS');
    $body = $caption !== '' ? $caption : $title;
    $pdo->prepare("INSERT INTO posts(id,project_id,title,body,link,media,status,created_by) VALUES (?,?,?,?,NULL,?,'draft',?)")
        ->execute([$postId, $pid, $title ?: mb_substr($caption, 0, 80), $body, json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $job['created_by']]);
    // Gắn sẵn các kênh đã kết nối của dự án (FB / TikTok / X); người duyệt bỏ bớt nếu muốn
    $cst = $pdo->prepare("SELECT id, provider FROM connections WHERE project_id=? AND status='connected' AND provider IN ('facebook_page','tiktok','x')"); $cst->execute([$pid]);
    $ins = $pdo->prepare('INSERT INTO post_targets(post_id,connection_id,provider,caption) VALUES (?,?,?,NULL)');
    foreach ($cst->fetchAll() as $c) $ins->execute([$postId, $c['id'], $c['provider']]);
    $refH = ''; if ($job['ref_id']) { $r = $pdo->prepare('SELECT handle FROM ref_channels WHERE id=?'); $r->execute([$job['ref_id']]); $refH = (string)$r->fetchColumn(); }
    log_activity('create', 'post', $postId, 'Máy HL soạn bài nháp theo format của @' . $refH . ': "' . ($title ?: mb_substr($caption, 0, 60)) . '" (' . count($media) . ' ảnh slide)', $job['created_by'] ? ['id' => $job['created_by']] : null, $pid);
    $fin(['post_id' => $postId, 'media' => count($media), 'title' => $title, 'slides' => $res['slides'] ?? []], null);
    respond(['ok' => true, 'post_id' => $postId, 'media' => count($media)]);
}

$fin($res ?: null, $err !== '' ? $err : null);
respond(['ok' => true]);
