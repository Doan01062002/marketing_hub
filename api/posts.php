<?php
/* Bài đăng đa kênh: soạn → gửi duyệt → duyệt (đăng ngay hoặc hẹn giờ) → đăng lên Fanpage / Instagram / YouTube.

   GET  posts.php                         → {posts:[…], now}   lọc: status=a,b · channel=facebook_page|instagram|youtube · campaign · from · to (YYYY-MM-DD) · mine=1
   GET  posts.php?id=…                    → {post}
   GET  posts.php?action=channel_check&connection_id=…[&refresh=1]
                                          → {connection_id, provider, can_publish, ig:{id,username}|null, perms:{…}|null, notes:[…], error}
   POST {action:'save', id?, title, body, link, media:[{path,type,name}], campaign_id, content_id, scheduled_at, options:{yt_privacy},
         targets:[{connection_id, provider, caption}], submit?:true}   → {id, status}
   POST {action:'submit'|'approve'|'publish'|'withdraw', id}  ·  {action:'reject', id, reason}  ·  {action:'retry', id, target_id?}
   DELETE posts.php?id=…
   Quyền: ai đăng nhập cũng soạn/sửa bài của mình; duyệt/từ chối/đăng cần quyền duyệt và không tự duyệt bài của mình (trừ Quản trị). */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_publish_lib.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$isMgr = in_array($me['role'], ['admin', 'manager'], true) || in_array(project_role(), PROJECT_ADMIN_ROLES, true);
$PID = pid();

const POST_EDITABLE = ['draft', 'pending', 'rejected', 'approved', 'scheduled', 'failed'];
const POST_ST_LABEL = ['draft' => 'Nháp', 'pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'scheduled' => 'Đã hẹn giờ', 'publishing' => 'Đang đăng',
    'published' => 'Đã đăng', 'partial' => 'Đăng một phần', 'failed' => 'Đăng lỗi', 'rejected' => 'Bị từ chối'];
const PROVIDER_LABEL = ['facebook_page' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'x' => 'X (Twitter)'];

function load_post(string $id): array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM posts WHERE id=? AND project_id=?'); $st->execute([$id, pid()]);
    $p = $st->fetch();
    if (!$p) fail('Không tìm thấy bài đăng trong dự án này', 404);
    return $p;
}

function post_out(array $p, array $targets, array $names, array $conns): array {
    $p['media'] = jdecode($p['media'], []);
    $p['options'] = post_options($p['id']);
    $p['created_by_name'] = $names[$p['created_by']] ?? $p['created_by'];
    $p['approved_by_name'] = $p['approved_by'] ? ($names[$p['approved_by']] ?? $p['approved_by']) : null;
    $p['targets'] = array_map(function ($t) use ($p, $conns) {
        $t['id'] = (int)$t['id'];
        $t['label'] = $conns[$t['connection_id']] ?? '(kênh đã xoá)';
        $t['link'] = utm_link($p['link'], $t['provider'], $p['campaign_id'], $p['id']);
        $t['needs_permission'] = $t['status'] === 'failed' && str_contains((string)$t['error'], PERM_HINT);
        return $t;
    }, $targets);
    return $p;
}

function posts_with_targets(array $rows): array {
    global $pdo;
    if (!$rows) return [];
    $names = array_column($pdo->query('SELECT id,name FROM users')->fetchAll(), 'name', 'id');
    $conns = array_column($pdo->query('SELECT id,label FROM connections')->fetchAll(), 'label', 'id');
    $ids = array_column($rows, 'id');
    $st = $pdo->prepare('SELECT * FROM post_targets WHERE post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $st->execute($ids);
    $by = [];
    foreach ($st->fetchAll() as $t) $by[$t['post_id']][] = $t;
    return array_map(fn($p) => post_out($p, $by[$p['id']] ?? [], $names, $conns), $rows);
}

function can_edit_post(array $p, array $me): bool {
    return $p['created_by'] === $me['id'] || in_array($me['role'], ['admin', 'manager'], true) || in_array(project_role(), PROJECT_ADMIN_ROLES, true);
}

function check_not_self(array $p, array $me): void {
    if ($p['created_by'] === $me['id'] && $me['role'] !== 'admin') fail('Không được tự duyệt bài đăng do chính bạn soạn — cần người có quyền duyệt khác', 403);
}

/** Chuẩn hoá & kiểm tra dữ liệu bài. $strict = khi gửi duyệt / duyệt (đủ kênh, đủ nội dung cho từng kênh). Trả về [fields, targets, media]. */
function validate_post(array $in, bool $strict): array {
    global $pdo;
    $err = [];
    $title = trim((string)($in['title'] ?? ''));
    $body = (string)($in['body'] ?? '');
    $link = trim((string)($in['link'] ?? ''));
    if ($link !== '' && !preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $link)) $err[] = 'Link phải bắt đầu bằng http:// hoặc https://';
    $sched = trim((string)($in['scheduled_at'] ?? ''));
    if ($sched !== '') {
        $sched = str_replace('T', ' ', substr($sched, 0, 16));
        $dt = DateTime::createFromFormat('Y-m-d H:i', $sched, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$dt || $dt->format('Y-m-d H:i') !== $sched) $err[] = 'Giờ hẹn không hợp lệ (định dạng YYYY-MM-DD HH:MM)';
    } else $sched = null;
    $camp = ($in['campaign_id'] ?? '') ?: null;
    if ($camp && !scoped('campaigns', (string)$camp)) $err[] = 'Chiến dịch không tồn tại trong dự án này';
    $content = ($in['content_id'] ?? '') ?: null;
    if ($content && !scoped('content_items', (string)$content)) $err[] = 'Nội dung liên kết không tồn tại trong dự án này';

    $media = [];
    foreach ((array)($in['media'] ?? []) as $m) {
        $path = (string)($m['path'] ?? '');
        if (!preg_match('#^uploads/\d{6}/[a-f0-9]{24}\.([a-z0-9]{3,4})$#', $path, $mm) || !isset(UPLOAD_EXT[$mm[1]])) { $err[] = 'File đính kèm không hợp lệ: ' . $path; continue; }
        if (!is_file(UPLOAD_DIR . '/' . substr($path, 8))) { $err[] = 'File đính kèm không còn trên máy chủ: ' . ($m['name'] ?? $path); continue; }
        $media[] = ['path' => $path, 'type' => UPLOAD_EXT[$mm[1]], 'name' => mb_substr((string)($m['name'] ?? basename($path)), 0, 120)];
    }
    $nVid = count(array_filter($media, fn($m) => $m['type'] === 'video'));
    if ($nVid > 1) $err[] = 'Mỗi bài chỉ đính kèm 1 video';
    if ($nVid && count($media) > 1) $err[] = 'Bài có video thì không kèm ảnh (Facebook/YouTube không đăng chung ảnh với video)';
    if (count($media) > 10) $err[] = 'Tối đa 10 ảnh mỗi bài';

    $opts = [];
    $priv = $in['options']['yt_privacy'] ?? 'public';
    $opts['yt_privacy'] = in_array($priv, ['public', 'unlisted', 'private'], true) ? $priv : 'public';
    // TikTok (Content Sharing Guidelines): quyền riêng tư do người dùng chọn, không chọn sẵn; công tắc tương tác; khai báo nội dung thương mại
    $tp = (string)($in['options']['tt_privacy'] ?? '');
    $opts['tt_privacy'] = in_array($tp, ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'], true) ? $tp : '';
    foreach (['tt_comment' => true, 'tt_duet' => true, 'tt_stitch' => true, 'tt_disclose' => false, 'tt_brand_organic' => false, 'tt_brand_content' => false] as $k => $def) $opts[$k] = array_key_exists($k, (array)($in['options'] ?? [])) ? (bool)$in['options'][$k] : $def;
    if (!$opts['tt_disclose']) $opts['tt_brand_organic'] = $opts['tt_brand_content'] = false;
    $hasTt = false; foreach ((array)($in['targets'] ?? []) as $t) if (($t['provider'] ?? '') === 'tiktok') $hasTt = true;
    if ($hasTt) {
        if ($opts['tt_privacy'] === '') $err[] = 'TikTok: chọn ai có thể xem video (TikTok không cho chọn sẵn)';
        if ($opts['tt_disclose'] && !$opts['tt_brand_organic'] && !$opts['tt_brand_content']) $err[] = 'TikTok: đã bật tiết lộ nội dung thương mại thì chọn ít nhất một loại';
        if ($opts['tt_brand_content'] && $opts['tt_privacy'] === 'SELF_ONLY') $err[] = 'TikTok: nội dung có thương hiệu không thể đặt "Chỉ mình tôi"';
    }

    $targets = []; $seen = [];
    foreach ((array)($in['targets'] ?? []) as $t) {
        $prov = (string)($t['provider'] ?? ''); $cid = (string)($t['connection_id'] ?? '');
        if (!in_array($prov, PUBLISH_PROVIDERS, true)) { $err[] = 'Loại kênh không hỗ trợ: ' . $prov; continue; }
        if (isset($seen[$prov . '|' . $cid])) continue;
        $seen[$prov . '|' . $cid] = true;
        $conn = get_connection($cid);
        $need = in_array($prov, ['youtube', 'tiktok', 'x'], true) ? $prov : 'facebook_page';
        if (!$conn || $conn['provider'] !== $need) { $err[] = 'Kênh đích không tồn tại hoặc sai loại (' . PROVIDER_LABEL[$prov] . ')'; continue; }
        $cap = isset($t['caption']) && trim((string)$t['caption']) !== '' ? (string)$t['caption'] : null;
        $targets[] = ['connection_id' => $cid, 'provider' => $prov, 'caption' => $cap, 'label' => $conn['label'], 'conn' => $conn];
    }

    // Giới hạn ký tự áp dụng cả khi lưu nháp; yêu cầu "đủ để đăng" chỉ áp dụng khi gửi duyệt / duyệt.
    foreach ($targets as $t) {
        $text = $t['caption'] ?? $body;
        $lim = PUBLISH_LIMITS[$t['provider']];
        $who = PROVIDER_LABEL[$t['provider']] . ' (' . $t['label'] . ')';
        $full = $link !== '' && $t['provider'] !== 'facebook_page' ? $text . "\n\n" . $link : $text;
        if (mb_strlen($full) > $lim['caption']) $err[] = "$who: nội dung dài " . mb_strlen($full) . ' ký tự, tối đa ' . $lim['caption'];
        if (isset($lim['hashtags']) && count_hashtags($text) > $lim['hashtags']) $err[] = "$who: tối đa {$lim['hashtags']} hashtag (đang có " . count_hashtags($text) . ')';
        if ($t['provider'] === 'youtube' && mb_strlen($title) > 100) $err[] = "$who: tiêu đề video tối đa 100 ký tự";
        if (!$strict) continue;
        if ($t['provider'] === 'instagram' && !$media) $err[] = "$who: Instagram cần ít nhất 1 ảnh hoặc video";
        if ($t['provider'] === 'youtube' && !$nVid) $err[] = "$who: YouTube chỉ đăng video — hãy đính kèm 1 video";
        if ($t['provider'] === 'youtube' && $title === '') $err[] = "$who: cần tiêu đề (dùng làm tiêu đề video)";
        if ($t['provider'] === 'youtube' && ($t['conn']['auth_method'] ?? 'oauth') === 'manual' && empty($t['conn']['refresh_token'])) $err[] = "$who: kênh nhập bằng API key không tải video lên được — kết nối kênh bằng đăng nhập Google";
        if ($t['provider'] === 'facebook_page' && trim($text) === '' && $link === '' && !$media) $err[] = "$who: bài trống";
        if ($t['provider'] === 'tiktok' && !$nVid) $err[] = "$who: TikTok chỉ đăng video — hãy đính kèm 1 video";
    }
    if ($strict) {
        if (!$targets) $err[] = 'Chưa chọn kênh đăng';
        if (trim($body) === '' && !$media && !array_filter($targets, fn($t) => $t['caption'] !== null)) $err[] = 'Bài chưa có nội dung';
    }
    if ($err) fail(implode(' · ', array_unique($err)), 422);
    return [['title' => $title !== '' ? mb_substr($title, 0, 200) : null, 'body' => $body, 'link' => $link !== '' ? $link : null,
        'campaign_id' => $camp, 'content_id' => $content, 'scheduled_at' => $sched], $targets, $media, $opts];
}

function save_targets(string $postId, array $targets): void {
    global $pdo;
    $pdo->prepare('DELETE FROM post_targets WHERE post_id=?')->execute([$postId]);
    $ins = $pdo->prepare("INSERT INTO post_targets(post_id,connection_id,provider,caption,status) VALUES (?,?,?,?,'pending')");
    foreach ($targets as $t) $ins->execute([$postId, $t['connection_id'], $t['provider'], $t['caption']]);
}

function save_options(string $postId, array $opts): void {
    $cur = post_options($postId);
    setting_set('post_opt.' . $postId, json_encode(array_merge($cur, $opts), JSON_UNESCAPED_UNICODE));
}

function ptitle(array $p): string { return $p['title'] ?: mb_substr((string)$p['body'], 0, 60) ?: $p['id']; }

/** Duyệt: hẹn giờ trong tương lai → 'scheduled'; còn lại → 'approved' rồi đăng ngay. */
function approve_post(array $CFG, array $p, array $me): array {
    global $pdo;
    $row = $pdo->prepare("SELECT * FROM post_targets WHERE post_id=?"); $row->execute([$p['id']]);
    $tg = $row->fetchAll();
    validate_post(['title' => $p['title'], 'body' => $p['body'], 'link' => $p['link'], 'media' => jdecode($p['media'], []),
        'campaign_id' => $p['campaign_id'], 'content_id' => $p['content_id'], 'scheduled_at' => $p['scheduled_at'],
        'options' => post_options($p['id']), 'targets' => $tg], true);
    $future = $p['scheduled_at'] && $p['scheduled_at'] > vn_now();
    $upd = $pdo->prepare("UPDATE posts SET status=?, approved_by=?, updated_at=datetime('now') WHERE id=? AND status='pending'");
    $upd->execute([$future ? 'scheduled' : 'approved', $me['id'], $p['id']]);
    if ($upd->rowCount() !== 1) fail('Bài không còn ở trạng thái chờ duyệt', 409);
    log_activity('approve', 'post', $p['id'], 'Duyệt bài đăng "' . ptitle($p) . '"' . ($future ? ' — hẹn ' . $p['scheduled_at'] : ' — đăng ngay'));
    if ($p['created_by'] && $p['created_by'] !== $me['id']) notify($p['created_by'], 'Bài đăng đã được duyệt: ' . ptitle($p), $future ? 'Sẽ tự đăng lúc ' . $p['scheduled_at'] . ' (giờ VN).' : 'Đang đăng lên các kênh.', 'publish');
    if ($future) return ['status' => 'scheduled'];
    session_write_close();
    set_time_limit(0);
    return publish_post($CFG, $p['id']);
}

/* ---------------- GET ---------------- */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'channel_check') {
        $cid = (string)($_GET['connection_id'] ?? '');
        $conn = get_connection($cid);
        if (!$conn || !in_array($conn['provider'], ['facebook_page', 'youtube', 'tiktok'], true)) fail('Không tìm thấy kênh', 404);
        $cache = $_SESSION['pub_check'][$cid] ?? null;
        if ($cache && empty($_GET['refresh']) && $cache['at'] > time() - 1800 && ($cache['sig'] ?? '') === md5($conn['access_token'] . $conn['secret'] . $conn['status'])) respond($cache['data']);
        session_write_close(); // gọi API nền tảng có thể lâu — không giữ khoá phiên
        $out = ['connection_id' => $cid, 'provider' => $conn['provider'], 'can_publish' => null, 'ig' => null, 'perms' => null, 'notes' => [], 'error' => null];
        $manual = ($conn['auth_method'] ?? 'oauth') === 'manual';
        try {
            if ($conn['status'] !== 'connected') throw new PublishError('Kênh chưa kết nối (' . $conn['status'] . ')');
            if ($conn['provider'] === 'facebook_page') {
                $tok = meta_page_token($CFG, $conn);
                if (!$manual && $conn['access_token']) {
                    [$c, $d] = http_get_json(graph_url($CFG, 'me/permissions', ['access_token' => $conn['access_token']]));
                    if ($c === 200) {
                        $g = [];
                        foreach ($d['data'] ?? [] as $pm) $g[$pm['permission']] = ($pm['status'] ?? '') === 'granted';
                        $out['perms'] = ['pages_manage_posts' => !empty($g['pages_manage_posts']), 'instagram_content_publish' => !empty($g['instagram_content_publish'])];
                        $out['can_publish'] = $out['perms']['pages_manage_posts'];
                        if (!$out['can_publish']) $out['notes'][] = PERM_HINT . ' (thiếu pages_manage_posts)';
                    }
                } else {
                    $out['notes'][] = 'Token nhập tay: quyền đăng bài sẽ được Facebook kiểm tra khi đăng (token cần quyền pages_manage_posts)';
                }
                $out['ig'] = meta_instagram_account($CFG, $conn, $tok);
                if ($out['ig'] && is_array($out['perms']) && !$out['perms']['instagram_content_publish']) $out['notes'][] = 'Instagram: ' . PERM_HINT . ' (thiếu instagram_content_publish)';
                if ($out['ig'] && !public_base_url($CFG)) $out['notes'][] = 'Instagram chỉ đăng được khi app chạy trên tên miền công khai (hiện là ' . $CFG['app_url'] . ')';
            } elseif ($conn['provider'] === 'tiktok') {
                [$tok, $terr] = tiktok_access_token($CFG, $conn);
                if (!$tok) throw new PublishError($terr ?? 'Token TikTok hết hạn — kết nối lại kênh');
                [$c, $d] = http_call('POST', tiktok_url($CFG, '/v2/post/publish/creator_info/query/'), ['headers' => ['Authorization: Bearer ' . $tok], 'json' => new stdClass()]);
                $e = tiktok_error($c, $d);
                $out['can_publish'] = $e === null;
                if ($e !== null) $out['notes'][] = PERM_HINT . ' (TikTok cần quyền video.publish — bật Content Posting API trong app TikTok rồi bấm Kết nối lại kênh): ' . $e;
                else {
                    $opts = $d['data']['privacy_level_options'] ?? [];
                    $out['tt'] = ['username' => $d['data']['creator_username'] ?? null, 'nickname' => $d['data']['creator_nickname'] ?? null, 'avatar' => $d['data']['creator_avatar_url'] ?? null,
                        'privacy' => $opts, 'max_sec' => $d['data']['max_video_post_duration_sec'] ?? null,
                        'comment_disabled' => !empty($d['data']['comment_disabled']), 'duet_disabled' => !empty($d['data']['duet_disabled']), 'stitch_disabled' => !empty($d['data']['stitch_disabled'])];
                    if ($opts === ['SELF_ONLY']) $out['notes'][] = 'App TikTok chưa được duyệt: video sẽ đăng ở chế độ riêng tư (chỉ mình tôi)';
                }
            } else {
                if ($manual && empty($conn['refresh_token'])) { $out['can_publish'] = false; $out['notes'][] = 'Kênh nhập bằng API key không tải video lên được — kết nối bằng đăng nhập Google'; }
                else {
                    $tok = google_access_token($CFG, $conn);
                    if (!$tok) throw new PublishError('Token Google hết hạn — kết nối lại kênh');
                    [$c, $d] = http_get_json('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($tok));
                    if ($c === 200) {
                        $has = str_contains((string)($d['scope'] ?? ''), 'youtube.upload') || preg_match('#/auth/youtube(\s|$)#', (string)($d['scope'] ?? ''));
                        $out['perms'] = ['youtube.upload' => (bool)$has];
                        $out['can_publish'] = (bool)$has;
                        if (!$has) $out['notes'][] = PERM_HINT . ' (thiếu quyền youtube.upload)';
                    }
                }
            }
        } catch (PublishError $e) { $out['error'] = $e->getMessage(); if (str_contains($e->getMessage(), PERM_HINT)) $out['can_publish'] = false; }
        @session_start();
        $_SESSION['pub_check'][$cid] = ['at' => time(), 'sig' => md5($conn['access_token'] . $conn['secret'] . $conn['status']), 'data' => $out];
        respond($out);
    }
    if (!empty($_GET['id'])) {
        $p = load_post((string)$_GET['id']);
        if ($p['status'] === 'draft' && !can_edit_post($p, $me) && !can_approve_project($me)) fail('Bạn không xem được bản nháp của người khác', 403);
        respond(['post' => posts_with_targets([$p])[0]]);
    }
    $w = ['p.project_id=?']; $v = [$PID];
    if (!$isMgr && !can_approve_project($me)) { $w[] = "(p.status<>'draft' OR p.created_by=?)"; $v[] = $me['id']; }
    if (!empty($_GET['status'])) {
        $sts = array_values(array_intersect(explode(',', (string)$_GET['status']), array_keys(POST_ST_LABEL)));
        if ($sts) { $w[] = 'p.status IN (' . implode(',', array_fill(0, count($sts), '?')) . ')'; array_push($v, ...$sts); }
    }
    if (!empty($_GET['channel'])) { $w[] = 'EXISTS (SELECT 1 FROM post_targets t WHERE t.post_id=p.id AND t.provider=?)'; $v[] = $_GET['channel']; }
    if (!empty($_GET['campaign'])) { $w[] = 'p.campaign_id=?'; $v[] = $_GET['campaign']; }
    if (!empty($_GET['mine'])) { $w[] = 'p.created_by=?'; $v[] = $me['id']; }
    // Ngày theo giờ VN: hẹn giờ (đã là giờ VN) hoặc giờ đăng / tạo (UTC → +7).
    $day = "COALESCE(substr(p.scheduled_at,1,10), date(COALESCE(p.published_at,p.created_at),'+7 hours'))";
    if (!empty($_GET['from']) && preg_match('/^\d{4}-\d\d-\d\d$/', $_GET['from'])) { $w[] = "$day >= ?"; $v[] = $_GET['from']; }
    if (!empty($_GET['to']) && preg_match('/^\d{4}-\d\d-\d\d$/', $_GET['to'])) { $w[] = "$day <= ?"; $v[] = $_GET['to']; }
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 300)));
    $st = $pdo->prepare('SELECT p.* FROM posts p' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY COALESCE(p.published_at, p.updated_at) DESC LIMIT $limit");
    $st->execute($v);
    respond(['posts' => posts_with_targets($st->fetchAll()), 'now' => vn_now(), 'public_url' => public_base_url($CFG) !== null]);
}

/* ---------------- POST ---------------- */
if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    $action = $in['action'] ?? 'save';

    if ($action === 'save') {
        $id = ($in['id'] ?? '') ?: null;
        $submit = !empty($in['submit']);
        [$f, $targets, $media, $opts] = validate_post($in, $submit);
        if ($id) {
            $p = load_post($id);
            if (!can_edit_post($p, $me)) fail('Chỉ người soạn hoặc Quản trị/Trưởng nhóm mới sửa được bài này', 403);
            if (!in_array($p['status'], POST_EDITABLE, true)) fail('Bài đã/đang đăng (' . POST_ST_LABEL[$p['status']] . ') — không sửa được; dùng "Thử lại" cho kênh lỗi', 409);
            $new = in_array($p['status'], ['draft', 'rejected'], true) ? ($submit ? 'pending' : 'draft') : 'pending';
            $upd = $pdo->prepare("UPDATE posts SET title=?,body=?,link=?,media=?,campaign_id=?,content_id=?,scheduled_at=?,status=?,
                approved_by=CASE WHEN ?='pending' THEN NULL ELSE approved_by END, updated_at=datetime('now') WHERE id=? AND status=?");
            $upd->execute([$f['title'], $f['body'], $f['link'], json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $f['campaign_id'], $f['content_id'],
                $f['scheduled_at'], $new, $new, $id, $p['status']]);
            if ($upd->rowCount() !== 1) fail('Bài vừa thay đổi trạng thái ở nơi khác — tải lại rồi thử lại', 409);
            save_targets($id, $targets);
            save_options($id, $opts);
            $wasApproved = in_array($p['status'], ['approved', 'scheduled', 'failed'], true);
            log_activity('update', 'post', $id, 'Sửa bài đăng "' . ptitle($f + ['body' => $f['body'], 'id' => $id]) . '"' . ($wasApproved ? ' — chuyển lại Chờ duyệt' : ''));
            if (($submit && $p['status'] !== 'pending') || $wasApproved) notify(null, 'Bài đăng chờ duyệt: ' . ptitle($f + ['id' => $id]), $me['name'] . ($wasApproved ? ' đã sửa bài đã duyệt — cần duyệt lại.' : ' gửi duyệt bài đăng.'), 'publish');
            respond(['id' => $id, 'status' => $new]);
        }
        $id = gen_id('PS');
        $status = $submit ? 'pending' : 'draft';
        $pdo->prepare('INSERT INTO posts(id,project_id,title,body,link,media,campaign_id,content_id,status,scheduled_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $PID, $f['title'], $f['body'], $f['link'], json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $f['campaign_id'], $f['content_id'], $status, $f['scheduled_at'], $me['id']]);
        save_targets($id, $targets);
        save_options($id, $opts);
        log_activity('create', 'post', $id, ($submit ? 'Soạn & gửi duyệt' : 'Lưu nháp') . ' bài đăng "' . ptitle($f + ['id' => $id]) . '"');
        if ($submit) notify(null, 'Bài đăng chờ duyệt: ' . ptitle($f + ['id' => $id]), $me['name'] . ' gửi duyệt bài đăng lên ' . count($targets) . ' kênh.', 'publish');
        respond(['id' => $id, 'status' => $status], 201);
    }

    $id = (string)($in['id'] ?? '');
    if ($id === '') fail('Thiếu id bài đăng');
    $p = load_post($id);

    if ($action === 'submit') {
        if (!can_edit_post($p, $me)) fail('Bạn không gửi duyệt được bài này', 403);
        if (!in_array($p['status'], ['draft', 'rejected'], true)) fail('Chỉ gửi duyệt được bài nháp / bị từ chối', 409);
        $tg = $pdo->prepare('SELECT * FROM post_targets WHERE post_id=?'); $tg->execute([$id]);
        validate_post(['title' => $p['title'], 'body' => $p['body'], 'link' => $p['link'], 'media' => jdecode($p['media'], []), 'campaign_id' => $p['campaign_id'],
            'content_id' => $p['content_id'], 'scheduled_at' => $p['scheduled_at'], 'targets' => $tg->fetchAll()], true);
        $pdo->prepare("UPDATE posts SET status='pending', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        log_activity('submit', 'post', $id, 'Gửi duyệt bài đăng "' . ptitle($p) . '"');
        notify(null, 'Bài đăng chờ duyệt: ' . ptitle($p), $me['name'] . ' gửi duyệt bài đăng.', 'publish');
        respond(['ok' => true, 'status' => 'pending']);
    }

    if ($action === 'withdraw') {
        if (!can_edit_post($p, $me) && !can_approve_project($me)) fail('Bạn không thu hồi được bài này', 403);
        $upd = $pdo->prepare("UPDATE posts SET status='draft', approved_by=NULL, updated_at=datetime('now') WHERE id=? AND status IN ('pending','approved','scheduled')");
        $upd->execute([$id]);
        if ($upd->rowCount() !== 1) fail('Chỉ thu hồi được bài đang chờ duyệt / chờ đăng', 409);
        log_activity('update', 'post', $id, 'Thu hồi bài đăng "' . ptitle($p) . '" về nháp');
        respond(['ok' => true, 'status' => 'draft']);
    }

    if ($action === 'approve') {
        $u = $me; if (!can_approve_project($u)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được duyệt', 403);
        check_not_self($p, $u);
        if ($p['status'] !== 'pending') fail('Bài không ở trạng thái chờ duyệt (' . (POST_ST_LABEL[$p['status']] ?? $p['status']) . ')', 409);
        respond(approve_post($CFG, $p, $u) + ['ok' => true]);
    }

    if ($action === 'reject') {
        $u = $me; if (!can_approve_project($u)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được từ chối', 403);
        check_not_self($p, $u);
        if (!in_array($p['status'], ['pending', 'approved', 'scheduled'], true)) fail('Chỉ từ chối được bài đang chờ duyệt / chờ đăng', 409);
        $reason = trim((string)($in['reason'] ?? ''));
        $pdo->prepare("UPDATE posts SET status='rejected', approved_by=NULL, updated_at=datetime('now') WHERE id=?")->execute([$id]);
        save_options($id, ['reject_reason' => mb_substr($reason, 0, 500), 'rejected_by' => $u['name']]);
        log_activity('reject', 'post', $id, 'Từ chối bài đăng "' . ptitle($p) . '"' . ($reason !== '' ? ': ' . $reason : ''));
        if ($p['created_by']) notify($p['created_by'], 'Bài đăng bị từ chối: ' . ptitle($p), $reason !== '' ? $reason : 'Người duyệt không ghi lý do.', 'publish');
        respond(['ok' => true, 'status' => 'rejected']);
    }

    if ($action === 'publish') {
        $u = $me; if (!can_approve_project($u)) fail('Chỉ chủ / quản lý dự án hoặc người có quyền duyệt mới được đăng', 403);
        if (!in_array($p['status'], ['approved', 'scheduled'], true)) fail('Chỉ "Đăng ngay" được bài đã duyệt / đã hẹn giờ', 409);
        log_activity('publish', 'post', $id, 'Đăng ngay bài "' . ptitle($p) . '"' . ($p['scheduled_at'] ? ' (thay vì chờ đến ' . $p['scheduled_at'] . ')' : ''), $u);
        session_write_close(); set_time_limit(0);
        $r = publish_post($CFG, $id);
        if (isset($r['error'])) fail($r['error'], 409);
        respond($r + ['ok' => true]);
    }

    if ($action === 'retry') {
        if (!can_edit_post($p, $me) && !can_approve_project($me)) fail('Bạn không thử lại được bài này', 403);
        if (!in_array($p['status'], ['partial', 'failed'], true)) fail('Chỉ thử lại được bài đăng lỗi / đăng một phần', 409);
        $q = "UPDATE post_targets SET status='pending', error=NULL WHERE post_id=? AND status='failed'";
        $args = [$id];
        if (!empty($in['target_id'])) { $q .= ' AND id=?'; $args[] = (int)$in['target_id']; }
        $st = $pdo->prepare($q); $st->execute($args);
        if (!$st->rowCount()) fail('Không có kênh lỗi để thử lại', 409);
        log_activity('retry', 'post', $id, 'Thử lại ' . $st->rowCount() . ' kênh lỗi của bài "' . ptitle($p) . '"');
        session_write_close(); set_time_limit(0);
        $r = publish_post($CFG, $id);
        if (isset($r['error'])) fail($r['error'], 409);
        respond($r + ['ok' => true]);
    }

    fail('Thao tác không hỗ trợ');
}

/* ---------------- DELETE ---------------- */
if ($method === 'DELETE') {
    require_project_write();
    $p = load_post((string)($_GET['id'] ?? ''));
    $ownEarly = $p['created_by'] === $me['id'] && in_array($p['status'], ['draft', 'rejected', 'pending'], true);
    if (!$ownEarly && !$isMgr) fail('Chỉ xoá được bài nháp / chờ duyệt của chính bạn', 403);
    if ($p['status'] === 'publishing') fail('Bài đang được đăng — đợi xong rồi xoá', 409);
    $pdo->prepare('DELETE FROM post_targets WHERE post_id=?')->execute([$p['id']]);
    $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([$p['id']]);
    $pdo->prepare('DELETE FROM app_settings WHERE key=?')->execute(['post_opt.' . $p['id']]);
    log_activity('delete', 'post', $p['id'], 'Xoá bài đăng "' . ptitle($p) . '"' . (in_array($p['status'], ['published', 'partial'], true) ? ' (chỉ xoá khỏi app — bài trên kênh vẫn còn)' : ''));
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
