<?php
/* Kho kênh tham khảo — dùng chung mọi dự án. 12/10/2026
   Kênh mẫu (TikTok, X, YouTube, Facebook, Instagram, Threads) đáng học format nội dung; số liệu thật do máy HL lấy
   (VPS bị TikTok/X chặn); ảnh mẫu do người dùng tải lên; "Làm theo cho dự án" = máy HL (Claude Max) soạn bài + vẽ slide
   theo format đã chọn rồi tạo BÀI NHÁP trong Lịch đăng của dự án hiện hành — không tự đăng.
   GET                                   → {refs:[…], jobs:[việc đang chạy], hl_alive, platforms}
   POST {action:'add', url, notes?, formats?, projects?}   → {id}  (+ xếp việc ref_fetch cho máy HL)
   POST {action:'refresh', id}           → xếp lại ref_fetch
   POST {action:'update', id, name?, bio?, notes?, formats?, projects?}
   POST {action:'sample_add', id, media:[{path, caption}]} / {action:'sample_del', id, idx}
   POST {action:'delete', id}
   POST {action:'adapt', id, format_idx, topic, slides?, note?}  → {job_id} (ref_adapt → bài nháp)
   POST {action:'job', job_id}           → {status, result, error} */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];

const REF_PLATFORMS = ['tiktok' => 'TikTok', 'x' => 'X', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads'];

/** Nhận diện nền tảng + handle từ link hoặc @handle (mặc định TikTok). */
function ref_parse(string $s): ?array {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^@([A-Za-z0-9._-]{2,60})$/', $s, $m)) return ['tiktok', $m[1], 'https://www.tiktok.com/@' . $m[1]];
    if (!preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $h = strtolower((string)parse_url($s, PHP_URL_HOST)); $p = trim((string)parse_url($s, PHP_URL_PATH), '/');
    $h = preg_replace('/^(www|m|vm|vt|mobile)\./', '', $h);
    $seg = $p === '' ? [] : explode('/', $p);
    if ($h === 'tiktok.com' && isset($seg[0]) && $seg[0][0] === '@') return ['tiktok', substr($seg[0], 1), 'https://www.tiktok.com/' . $seg[0]];
    if (in_array($h, ['x.com', 'twitter.com'], true) && isset($seg[0]) && !in_array($seg[0], ['i', 'home', 'search', 'explore'], true)) return ['x', $seg[0], 'https://x.com/' . $seg[0]];
    if ($h === 'youtube.com' && isset($seg[0])) { $hd = $seg[0][0] === '@' ? substr($seg[0], 1) : ($seg[1] ?? $seg[0]); return ['youtube', $hd, 'https://www.youtube.com/' . ($seg[0][0] === '@' ? $seg[0] : $seg[0] . '/' . ($seg[1] ?? ''))]; }
    if ($h === 'instagram.com' && isset($seg[0])) return ['instagram', $seg[0], 'https://www.instagram.com/' . $seg[0] . '/'];
    if ($h === 'threads.net' || $h === 'threads.com') { $hd = ltrim($seg[0] ?? '', '@'); if ($hd !== '') return ['threads', $hd, 'https://www.threads.net/@' . $hd]; }
    if (in_array($h, ['facebook.com', 'fb.com'], true) && isset($seg[0])) { $hd = $seg[0] === 'profile.php' ? ($_GET['id'] ?? $seg[0]) : $seg[0]; return ['facebook', $hd, 'https://www.facebook.com/' . $hd]; }
    return null;
}

function ref_public(array $r): array {
    foreach (['extra', 'formats', 'samples', 'projects'] as $k) $r[$k] = jdecode($r[$k] ?? null, []);
    return $r;
}

function ref_can_edit(array $me): bool { return $me['role'] === 'admin' || in_array(project_role(), PROJECT_ADMIN_ROLES, true); }

/** Xếp việc cho máy HL; trả id việc. */
function hl_job_add(string $kind, array $payload, ?string $refId, ?string $projectId, array $me): string {
    global $pdo;
    $id = gen_id('HJ');
    $pdo->prepare('INSERT INTO hl_jobs(id,kind,project_id,ref_id,payload,created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$id, $kind, $projectId, $refId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $me['id']]);
    return $id;
}
function hl_jobs_alive(): bool { return strtotime((string)setting_get('hl.jobs_seen', '2000-01-01')) > time() - 90; }

/** Chuẩn hoá danh sách format từ giao diện: mảng [{name,desc,structure,why}] hoặc chuỗi mỗi dòng "Tên: mô tả". */
function ref_formats_norm($v): array {
    $out = [];
    if (is_string($v)) { foreach (preg_split('/\r?\n/', $v) as $ln) { $ln = trim($ln); if ($ln === '') continue; [$n, $d] = array_pad(explode(':', $ln, 2), 2, ''); $out[] = ['name' => trim($n), 'desc' => trim($d)]; } return $out; }
    foreach ((array)$v as $f) { if (!is_array($f)) continue; $n = trim((string)($f['name'] ?? '')); if ($n === '') continue;
        $out[] = ['name' => mb_substr($n, 0, 120), 'desc' => mb_substr(trim((string)($f['desc'] ?? '')), 0, 1000), 'structure' => mb_substr(trim((string)($f['structure'] ?? '')), 0, 2000), 'why' => mb_substr(trim((string)($f['why'] ?? '')), 0, 1000)]; }
    return $out;
}
function ref_projects_norm($v): array {
    global $pdo;
    $ids = array_values(array_unique(array_filter(array_map('strval', (array)$v))));
    if (!$ids) return [];
    $q = $pdo->query("SELECT id FROM projects")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_intersect($ids, $q));
}

if ($method === 'GET') {
    $refs = array_map('ref_public', $pdo->query('SELECT * FROM ref_channels ORDER BY updated_at DESC')->fetchAll());
    $jobs = $pdo->query("SELECT id, kind, ref_id, project_id, status, error, created_at FROM hl_jobs WHERE status IN ('queued','taken') OR (status IN ('done','failed') AND done_at > datetime('now','-10 minutes')) ORDER BY created_at DESC")->fetchAll();
    $projects = $pdo->query("SELECT id, name, color FROM projects WHERE status='active' ORDER BY name")->fetchAll();
    respond(['refs' => $refs, 'jobs' => $jobs, 'hl_alive' => hl_jobs_alive(), 'platforms' => REF_PLATFORMS, 'projects' => $projects, 'can_edit' => ref_can_edit($me), 'project_id' => pid()]);
}

require_method('POST');
$in = json_input();
$action = (string)($in['action'] ?? '');

if ($action === 'job') {
    $st = $pdo->prepare('SELECT id, kind, status, result, error, done_at FROM hl_jobs WHERE id=?'); $st->execute([(string)($in['job_id'] ?? '')]);
    $j = $st->fetch(); if (!$j) fail('Không thấy việc', 404);
    $j['result'] = jdecode($j['result'] ?? null, []);
    respond($j);
}

if (!ref_can_edit($me)) fail('Chỉ chủ / quản lý dự án được sửa kho kênh tham khảo', 403);

if ($action === 'add') {
    $p = ref_parse((string)($in['url'] ?? ''));
    if (!$p) fail('Không nhận ra link kênh — dán link hồ sơ TikTok / X / YouTube / Facebook / Instagram / Threads');
    [$platform, $handle, $url] = $p;
    $st = $pdo->prepare('SELECT id FROM ref_channels WHERE platform=? AND lower(handle)=lower(?)'); $st->execute([$platform, $handle]);
    if ($st->fetchColumn()) fail('Kênh này đã có trong kho');
    $id = gen_id('RF');
    $projects = ref_projects_norm($in['projects'] ?? [pid()]);
    $pdo->prepare('INSERT INTO ref_channels(id,platform,handle,url,name,notes,formats,samples,projects,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $platform, $handle, $url, trim((string)($in['name'] ?? '')) ?: null, mb_substr(trim((string)($in['notes'] ?? '')), 0, 2000) ?: null,
            json_encode(ref_formats_norm($in['formats'] ?? []), JSON_UNESCAPED_UNICODE), '[]', json_encode($projects), $me['id']]);
    $job = hl_job_add('ref_fetch', ['ref_id' => $id, 'platform' => $platform, 'handle' => $handle, 'url' => $url], $id, null, $me);
    log_activity('create', 'ref_channel', $id, 'Thêm kênh tham khảo ' . REF_PLATFORMS[$platform] . ' @' . $handle, $me);
    respond(['id' => $id, 'job_id' => $job, 'hl_alive' => hl_jobs_alive()], 201);
}

$id = (string)($in['id'] ?? '');
$st = $pdo->prepare('SELECT * FROM ref_channels WHERE id=?'); $st->execute([$id]);
$ref = $st->fetch();
if (!$ref) fail('Không thấy kênh tham khảo', 404);

if ($action === 'refresh') {
    $st = $pdo->prepare("SELECT id FROM hl_jobs WHERE kind='ref_fetch' AND ref_id=? AND status IN ('queued','taken')"); $st->execute([$id]);
    if ($j = $st->fetchColumn()) respond(['job_id' => $j, 'hl_alive' => hl_jobs_alive()]);
    $job = hl_job_add('ref_fetch', ['ref_id' => $id, 'platform' => $ref['platform'], 'handle' => $ref['handle'], 'url' => $ref['url']], $id, null, $me);
    respond(['job_id' => $job, 'hl_alive' => hl_jobs_alive()]);
}

if ($action === 'update') {
    $set = []; $val = [];
    foreach (['name' => 200, 'bio' => 1000, 'notes' => 2000] as $k => $max) if (array_key_exists($k, $in)) { $set[] = "$k=?"; $val[] = mb_substr(trim((string)$in[$k]), 0, $max) ?: null; }
    if (array_key_exists('formats', $in)) { $set[] = 'formats=?'; $val[] = json_encode(ref_formats_norm($in['formats']), JSON_UNESCAPED_UNICODE); }
    if (array_key_exists('projects', $in)) { $set[] = 'projects=?'; $val[] = json_encode(ref_projects_norm($in['projects'])); }
    if (!$set) fail('Không có gì để sửa');
    $set[] = "updated_at=datetime('now')"; $val[] = $id;
    $pdo->prepare('UPDATE ref_channels SET ' . implode(',', $set) . ' WHERE id=?')->execute($val);
    log_activity('update', 'ref_channel', $id, 'Sửa kênh tham khảo @' . $ref['handle'], $me);
    respond(['ok' => true]);
}

if ($action === 'sample_add') {
    $cur = jdecode($ref['samples'], []);
    foreach ((array)($in['media'] ?? []) as $m) {
        $path = (string)($m['path'] ?? '');
        if (!preg_match('#^uploads/\d{6}/[a-f0-9]{24}\.(jpg|jpeg|png|webp|gif)$#', $path)) continue;
        if (!is_file(UPLOAD_DIR . '/' . substr($path, 8))) continue;
        $cur[] = ['path' => $path, 'caption' => mb_substr(trim((string)($m['caption'] ?? '')), 0, 300)];
    }
    if (count($cur) > 40) fail('Tối đa 40 ảnh mẫu mỗi kênh');
    $pdo->prepare("UPDATE ref_channels SET samples=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    respond(['ok' => true, 'count' => count($cur)]);
}
if ($action === 'sample_del') {
    $cur = jdecode($ref['samples'], []); $i = (int)($in['idx'] ?? -1);
    if (!isset($cur[$i])) fail('Không thấy ảnh mẫu');
    array_splice($cur, $i, 1);
    $pdo->prepare("UPDATE ref_channels SET samples=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode(array_values($cur), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    respond(['ok' => true]);
}

if ($action === 'delete') {
    $pdo->prepare('DELETE FROM ref_channels WHERE id=?')->execute([$id]);
    log_activity('delete', 'ref_channel', $id, 'Xoá kênh tham khảo @' . $ref['handle'], $me);
    respond(['ok' => true]);
}

if ($action === 'adapt') {
    $pr = current_project(); if (!$pr) fail('Chọn dự án trước');
    $formats = jdecode($ref['formats'], []);
    $fi = (int)($in['format_idx'] ?? 0);
    $fmt = $formats[$fi] ?? null;
    if (!$fmt) fail('Kênh này chưa ghi format nào — bấm Sửa để thêm format (tên: mô tả) trước');
    $topic = mb_substr(trim((string)($in['topic'] ?? '')), 0, 500);
    if ($topic === '') fail('Nhập chủ đề cho bài (vd: 7 câu hỏi giúp bé 4-6 tuổi mê khám phá khoa học)');
    $slides = max(3, min(12, (int)($in['slides'] ?? 9)));
    $ps = jdecode($pr['settings'] ?? null, []);
    // Kênh đích đang kết nối của dự án → bài nháp sẽ gắn sẵn
    $cst = $pdo->prepare("SELECT id, provider, label, account_name FROM connections WHERE project_id=? AND status='connected' AND provider IN ('facebook_page','tiktok','x','instagram')"); $cst->execute([$pr['id']]);
    $targets = $cst->fetchAll();
    $payload = [
        'ref' => ['platform' => $ref['platform'], 'handle' => $ref['handle'], 'name' => $ref['name'], 'bio' => $ref['bio'], 'followers' => $ref['followers']],
        'format' => $fmt,
        'topic' => $topic, 'slides' => $slides, 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 1000),
        'project' => ['id' => $pr['id'], 'name' => $pr['name'], 'domain' => $pr['domain'], 'goal' => $pr['goal'], 'color' => $pr['color'], 'hotline' => $ps['hotline'] ?? null, 'brand_voice' => $ps['brand_voice'] ?? ($ps['ai']['instructions'] ?? null), 'handle' => $ps['social_handle'] ?? null],
        'targets' => array_map(fn($t) => ['provider' => $t['provider'], 'label' => $t['account_name'] ?: $t['label']], $targets),
        'lang' => 'vi',
    ];
    $job = hl_job_add('ref_adapt', $payload, $id, $pr['id'], $me);
    log_activity('create', 'ref_channel', $id, 'Làm theo format "' . $fmt['name'] . '" của @' . $ref['handle'] . ' cho dự án ' . $pr['name'] . ': ' . $topic, $me, $pr['id']);
    respond(['job_id' => $job, 'hl_alive' => hl_jobs_alive()], 201);
}

fail('Thao tác không hỗ trợ');
