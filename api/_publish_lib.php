<?php
/* Thư viện ĐĂNG BÀI ĐA KÊNH — dùng chung cho api/posts.php (người dùng bấm "Duyệt & đăng" / "Thử lại")
   và cron/run.php (đăng bài hẹn giờ mỗi 5 phút). Yêu cầu đã require _boot.php.

   publish_post($CFG, $postId)  → ['ok'=>bool, 'status'=>…, 'targets'=>[…], 'error'?]
   publish_due_posts($CFG)      → chuỗi tóm tắt 1 dòng cho cron

   Chống đăng trùng: bài được khoá bằng trạng thái 'publishing' (UPDATE … WHERE status IN (…) — chỉ một tiến trình thắng),
   từng kênh đích cũng khoá riêng; kênh đã 'published' không bao giờ bị đăng lại.
   Bộ đăng của từng nền tảng nằm trong $GLOBALS['PUBLISH_DRIVERS'] (provider → callable(array $CFG, array $ctx): array)
   để kịch bản kiểm thử dòng lệnh có thể thay bằng bộ giả lập. */

const PUBLISH_MAX_PER_RUN = 10;          // số bài tối đa mỗi lần cron
const PUBLISH_STALE_MIN = 30;            // khoá 'publishing' quá 30 phút coi như tiến trình đã chết
const PUBLISH_LIMITS = [
    'facebook_page' => ['caption' => 63206],
    'instagram' => ['caption' => 2200, 'hashtags' => 30],
    'youtube' => ['title' => 100, 'caption' => 5000],
    'tiktok' => ['caption' => 2200],
    'x' => ['caption' => 280], // 12/10/2026: X đếm link = 23 ký tự; bộ đăng tự cắt khéo nếu vẫn dài
];
const PUBLISH_PROVIDERS = ['facebook_page', 'instagram', 'youtube', 'tiktok', 'x'];
const PERM_HINT = 'Cần cấp quyền đăng bài — kết nối lại kênh';

class PublishError extends RuntimeException {}

$GLOBALS['PUBLISH_DRIVERS'] = $GLOBALS['PUBLISH_DRIVERS'] ?? [
    'facebook_page' => 'publish_to_facebook',
    'instagram' => 'publish_to_instagram',
    'youtube' => 'publish_to_youtube',
    'tiktok' => 'publish_to_tiktok',
    'x' => 'publish_to_x', // 12/10/2026
];

/** Giờ hiện tại theo giờ Việt Nam dạng 'YYYY-MM-DD HH:MM' (cùng định dạng posts.scheduled_at). */
function vn_now(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i');
}

/** curl mở rộng: multipart (CURLFile), body thô, đọc header trả về, timeout dài cho upload.
 *  Trả về [code, json|null, raw, headers(lowercase=>value)]. */
function pub_http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $hdrs = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => $opts['timeout'] ?? 60, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs) {
            $p = strpos($line, ':');
            if ($p !== false) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    $h = $opts['headers'] ?? [];
    if (isset($opts['multipart'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
    elseif (isset($opts['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    elseif (isset($opts['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json'], JSON_UNESCAPED_UNICODE)); $h[] = 'Content-Type: application/json; charset=UTF-8'; }
    elseif (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, null, 'Lỗi mạng: ' . $err, $hdrs];
    return [$code, json_decode($raw, true), $raw, $hdrs];
}

/* ---------------- Tiện ích nội dung ---------------- */

/** Gắn UTM vào link cho từng kênh; giữ nguyên tham số utm_* đã có (không nhân đôi). */
function utm_link(?string $link, string $provider, ?string $campaignId, string $postId): ?string {
    $link = trim((string)$link);
    if ($link === '') return null;
    $frag = '';
    if (($p = strpos($link, '#')) !== false) { $frag = substr($link, $p); $link = substr($link, 0, $p); }
    $base = $link; $qs = '';
    if (($p = strpos($link, '?')) !== false) { $base = substr($link, 0, $p); $qs = substr($link, $p + 1); }
    $have = [];
    foreach ($qs === '' ? [] : explode('&', $qs) as $pair) {
        $k = strtolower(urldecode(explode('=', $pair, 2)[0]));
        if ($k !== '') $have[$k] = true;
    }
    $src = ['facebook_page' => 'facebook', 'instagram' => 'instagram', 'youtube' => 'youtube', 'tiktok' => 'tiktok'][$provider] ?? $provider;
    $add = ['utm_source' => $src, 'utm_medium' => 'social'];
    if ($campaignId) $add['utm_campaign'] = strtolower($campaignId);
    $add['utm_content'] = strtolower($postId);
    $extra = [];
    foreach ($add as $k => $v) if (empty($have[$k])) $extra[] = $k . '=' . rawurlencode($v);
    if (!$extra) return $link . $frag;
    return $base . '?' . ($qs !== '' ? $qs . '&' : '') . implode('&', $extra) . $frag;
}

function count_hashtags(string $s): int {
    return preg_match_all('/(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+/u', $s);
}

/** Nội dung cuối cùng gửi lên một kênh (caption riêng hoặc nội dung chung) + link đã gắn UTM. */
function target_text(array $post, array $target): array {
    $text = trim((string)($target['caption'] ?? '')) !== '' ? (string)$target['caption'] : (string)$post['body'];
    $link = utm_link($post['link'] ?? null, $target['provider'], $post['campaign_id'] ?? null, $post['id']);
    return [$text, $link];
}

/** Đường dẫn file trên đĩa của một media trong bài (chỉ nhận file nằm trong uploads/). */
function media_file(array $m): string {
    $rel = (string)($m['path'] ?? '');
    if (!preg_match('#^uploads/\d{6}/[A-Za-z0-9_-]+\.[a-z0-9]+$#', $rel)) throw new PublishError('Đường dẫn file đính kèm không hợp lệ');
    $f = UPLOAD_DIR . '/' . substr($rel, strlen('uploads/'));
    if (!is_file($f)) throw new PublishError('Không tìm thấy file đính kèm "' . ($m['name'] ?? $rel) . '" trên máy chủ (đã bị xoá?)');
    return $f;
}

function media_mime(string $file): string {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime'][$ext] ?? 'application/octet-stream';
}

/** URL công khai của app — null nếu đang chạy trên máy nội bộ (Instagram không tải được). */
function public_base_url(array $CFG): ?string {
    $u = rtrim((string)($CFG['app_url'] ?? ''), '/');
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
        || $host === '::1' || $host === '[::1]') return null;
    if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    return $u;
}

/* ---------------- Meta (Facebook / Instagram) ---------------- */

/** Thông báo lỗi tiếng Việt từ phản hồi lỗi Graph API (giữ nguyên câu gốc của Meta để tra cứu). */
function meta_error(string $who, int $code, $d, string $raw = ''): string {
    $e = is_array($d) ? ($d['error'] ?? []) : [];
    $msg = $e['error_user_msg'] ?? $e['message'] ?? ($code === 0 ? $raw : "HTTP $code");
    $c = (int)($e['code'] ?? 0);
    $hint = '';
    if ($c === 190 || $c === 102) $hint = 'Token không hợp lệ hoặc đã hết hạn — kết nối lại kênh (hoặc dán Page Access Token mới).';
    elseif (in_array($c, [3, 10, 200, 294], true) || ($c >= 200 && $c < 300)
        || preg_match('/permission|pages_manage_posts|instagram_content_publish|publish_actions/i', (string)$msg)) $hint = PERM_HINT . ' (cần pages_manage_posts' . ($who === 'Instagram' ? ', instagram_content_publish' : '') . ').';
    elseif ($c === 368) $hint = 'Meta tạm chặn đăng bài vì nghi spam — thử lại sau.';
    elseif ($c === 4 || $c === 17 || $c === 32 || $c === 613) $hint = 'Vượt giới hạn gọi API của Meta — thử lại sau ít phút.';
    elseif ($c === 9007 || $c === 36003) $hint = 'Instagram chưa xử lý xong file hoặc tỉ lệ khung hình không hợp lệ.';
    return $who . ' từ chối' . ($c ? " (mã $c)" : '') . ': ' . $msg . ($hint ? ' — ' . $hint : '');
}

/** Page Access Token của một kết nối facebook_page (nhập tay: secret; OAuth: đổi từ token người dùng). */
function meta_page_token(array $CFG, array $conn): string {
    if ($conn['provider'] !== 'facebook_page') throw new PublishError('Kênh đích không phải Fanpage Facebook');
    if (!$conn['external_account_id']) throw new PublishError('Kênh chưa chọn Page cụ thể — vào Thiết lập > Kết nối kênh');
    if (($conn['auth_method'] ?? 'oauth') === 'manual') {
        if (!$conn['secret']) throw new PublishError('Chưa có Page Access Token — vào Thiết lập > Kết nối kênh để dán token');
        return $conn['secret'];
    }
    if (!$conn['access_token']) throw new PublishError('Kênh chưa đăng nhập Facebook — ' . PERM_HINT);
    [$code, $pg, $raw] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']), [
        'fields' => 'access_token', 'access_token' => $conn['access_token'],
    ]));
    if ($code !== 200 || empty($pg['access_token'])) {
        if ($code === 200) throw new PublishError('Tài khoản Facebook đã kết nối không còn quản trị Page này — ' . PERM_HINT);
        throw new PublishError(meta_error('Facebook', $code, $pg, (string)$raw));
    }
    return $pg['access_token'];
}

function meta_permalink(array $CFG, string $id, string $token, string $field = 'permalink_url'): ?string {
    [$c, $d] = http_get_json(graph_url($CFG, rawurlencode($id), ['fields' => $field, 'access_token' => $token]));
    $p = $c === 200 ? ($d[$field] ?? null) : null;
    if ($p && str_starts_with($p, '/')) $p = 'https://www.facebook.com' . $p;
    return $p;
}

function publish_to_facebook(array $CFG, array $ctx): array {
    $conn = $ctx['conn']; $media = $ctx['media'];
    $token = meta_page_token($CFG, $conn);
    $page = rawurlencode($conn['external_account_id']);
    $text = $ctx['text']; $link = $ctx['link'];
    $videos = array_values(array_filter($media, fn($m) => ($m['type'] ?? '') === 'video'));
    $images = array_values(array_filter($media, fn($m) => ($m['type'] ?? '') === 'image'));
    $withLink = $link ? trim($text . "\n\n" . $link) : $text;

    if ($videos) {
        $f = media_file($videos[0]);
        $url = 'https://graph-video.facebook.com/' . $CFG['meta_graph_version'] . '/' . $page . '/videos';
        [$c, $d, $raw] = pub_http('POST', $url, ['timeout' => 900, 'multipart' => [
            'access_token' => $token, 'description' => $withLink, 'title' => mb_substr((string)$ctx['post']['title'], 0, 255),
            'source' => new CURLFile($f, media_mime($f), basename($f)),
        ]]);
        if ($c !== 200 || empty($d['id'])) throw new PublishError(meta_error('Facebook', $c, $d, $raw));
        return ['external_id' => $d['id'], 'permalink' => meta_permalink($CFG, $d['id'], $token) ?? 'https://www.facebook.com/' . $d['id']];
    }
    if (count($images) === 1) {
        $f = media_file($images[0]);
        [$c, $d, $raw] = pub_http('POST', graph_url($CFG, $page . '/photos'), ['timeout' => 180, 'multipart' => [
            'access_token' => $token, 'message' => $withLink, 'source' => new CURLFile($f, media_mime($f), basename($f)),
        ]]);
        if ($c !== 200 || empty($d['id'])) throw new PublishError(meta_error('Facebook', $c, $d, $raw));
        $pid = $d['post_id'] ?? $d['id'];
        return ['external_id' => $pid, 'permalink' => meta_permalink($CFG, $pid, $token)];
    }
    $form = ['access_token' => $token, 'message' => $images ? $withLink : $text];
    if ($images) {
        foreach ($images as $i => $m) {
            $f = media_file($m);
            [$c, $d, $raw] = pub_http('POST', graph_url($CFG, $page . '/photos'), ['timeout' => 180, 'multipart' => [
                'access_token' => $token, 'published' => 'false', 'source' => new CURLFile($f, media_mime($f), basename($f)),
            ]]);
            if ($c !== 200 || empty($d['id'])) throw new PublishError('Ảnh ' . ($i + 1) . ': ' . meta_error('Facebook', $c, $d, $raw));
            $form["attached_media[$i]"] = json_encode(['media_fbid' => $d['id']]);
        }
    } elseif ($link) {
        $form['link'] = $link;
    }
    if (trim((string)$form['message']) === '' && empty($form['link']) && !$images) throw new PublishError('Bài không có nội dung để đăng');
    [$c, $d, $raw] = pub_http('POST', graph_url($CFG, $page . '/feed'), ['form' => $form]);
    if ($c !== 200 || empty($d['id'])) throw new PublishError(meta_error('Facebook', $c, $d, $raw));
    return ['external_id' => $d['id'], 'permalink' => meta_permalink($CFG, $d['id'], $token)];
}

/** Tài khoản Instagram Business/Creator gắn với Page: ['id'=>…, 'username'=>…] hoặc null. Ném PublishError nếu gọi API lỗi. */
function meta_instagram_account(array $CFG, array $conn, ?string $pageToken = null): ?array {
    $token = $pageToken ?? meta_page_token($CFG, $conn);
    [$c, $d, $raw] = http_get_json(graph_url($CFG, rawurlencode($conn['external_account_id']), [
        'fields' => 'instagram_business_account{id,username}', 'access_token' => $token,
    ]));
    if ($c !== 200) throw new PublishError(meta_error('Facebook', $c, $d, (string)$raw));
    $ig = $d['instagram_business_account'] ?? null;
    return $ig && !empty($ig['id']) ? ['id' => $ig['id'], 'username' => $ig['username'] ?? null] : null;
}

/** Instagram chỉ nhận ảnh JPEG: chuyển PNG/WEBP sang JPEG nếu máy chủ có GD; trả về đường dẫn tương đối uploads/… */
function ig_jpeg_path(array $m): string {
    $f = media_file($m);
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if ($ext === 'jpg' || $ext === 'jpeg') return $m['path'];
    $fn = ['png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp', 'gif' => 'imagecreatefromgif'][$ext] ?? null;
    if (!$fn || !function_exists($fn) || !function_exists('imagejpeg')) {
        throw new PublishError('Instagram chỉ nhận ảnh JPEG — ảnh "' . ($m['name'] ?? basename($f)) . '" là ' . strtoupper($ext) . ' (máy chủ chưa bật PHP GD để tự chuyển). Hãy tải lên ảnh .jpg');
    }
    $out = preg_replace('/\.[a-z0-9]+$/', '', $f) . '_ig.jpg';
    if (!is_file($out)) {
        $src = @$fn($f);
        if (!$src) throw new PublishError('Không đọc được ảnh "' . ($m['name'] ?? basename($f)) . '" để chuyển sang JPEG');
        $bg = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
        imagejpeg($bg, $out, 90);
    }
    return preg_replace('/\.[a-z0-9]+$/', '', $m['path']) . '_ig.jpg';
}

function ig_wait(array $CFG, string $container, string $token, int $maxSec): void {
    $t0 = time();
    while (true) {
        [$c, $d, $raw] = http_get_json(graph_url($CFG, rawurlencode($container), ['fields' => 'status_code,status', 'access_token' => $token]));
        if ($c !== 200) throw new PublishError(meta_error('Instagram', $c, $d, (string)$raw));
        $s = $d['status_code'] ?? '';
        if ($s === 'FINISHED' || $s === 'PUBLISHED') return;
        if ($s === 'ERROR' || $s === 'EXPIRED') throw new PublishError('Instagram không xử lý được file (' . $s . (!empty($d['status']) ? ': ' . $d['status'] : '') . ') — kiểm tra định dạng/thời lượng/tỉ lệ khung hình (Reels: MP4 H.264, 3 giây – 15 phút, 9:16)');
        if (time() - $t0 > $maxSec) throw new PublishError('Instagram xử lý video quá lâu (hơn ' . round($maxSec / 60) . ' phút) — bấm Thử lại sau');
        sleep(5);
    }
}

function publish_to_instagram(array $CFG, array $ctx): array {
    $base = public_base_url($CFG);
    if (!$base) {
        throw new PublishError('Instagram cần tải ảnh/video từ một địa chỉ CÔNG KHAI trên Internet, nhưng app đang chạy ở ' . ($CFG['app_url'] ?? '?')
            . ' (máy nội bộ) nên Instagram không truy cập được file. Kênh này sẽ đăng được sau khi triển khai app lên tên miền thật (đổi app_url trong config.local.php).');
    }
    $media = $ctx['media'];
    if (!$media) throw new PublishError('Instagram không đăng được bài chỉ có chữ — cần ít nhất 1 ảnh hoặc video');
    $token = meta_page_token($CFG, $ctx['conn']);
    $ig = meta_instagram_account($CFG, $ctx['conn'], $token);
    if (!$ig) throw new PublishError('Fanpage này chưa liên kết tài khoản Instagram Business/Creator (liên kết trong Meta Business Suite rồi thử lại)');
    $igId = rawurlencode($ig['id']);
    $caption = $ctx['link'] ? trim($ctx['text'] . "\n\n" . $ctx['link']) : $ctx['text'];
    $url = fn(string $rel) => $base . '/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
    $create = function (array $form) use ($CFG, $igId, $token) {
        [$c, $d, $raw] = pub_http('POST', graph_url($CFG, $igId . '/media'), ['timeout' => 120, 'form' => $form + ['access_token' => $token]]);
        if ($c !== 200 || empty($d['id'])) throw new PublishError(meta_error('Instagram', $c, $d, $raw));
        return $d['id'];
    };
    $videos = array_values(array_filter($media, fn($m) => ($m['type'] ?? '') === 'video'));
    if ($videos) {
        media_file($videos[0]);
        $cid = $create(['media_type' => 'REELS', 'video_url' => $url($videos[0]['path']), 'caption' => $caption]);
        ig_wait($CFG, $cid, $token, 600);
    } elseif (count($media) === 1) {
        $cid = $create(['image_url' => $url(ig_jpeg_path($media[0])), 'caption' => $caption]);
        ig_wait($CFG, $cid, $token, 120);
    } else {
        $children = [];
        foreach (array_slice($media, 0, 10) as $m) $children[] = $create(['image_url' => $url(ig_jpeg_path($m)), 'is_carousel_item' => 'true']);
        foreach ($children as $ch) ig_wait($CFG, $ch, $token, 120);
        $cid = $create(['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $caption]);
        ig_wait($CFG, $cid, $token, 120);
    }
    [$c, $d, $raw] = pub_http('POST', graph_url($CFG, $igId . '/media_publish'), ['form' => ['creation_id' => $cid, 'access_token' => $token]]);
    if ($c !== 200 || empty($d['id'])) throw new PublishError(meta_error('Instagram', $c, $d, $raw));
    return ['external_id' => $d['id'], 'permalink' => meta_permalink($CFG, $d['id'], $token, 'permalink')];
}

/* ---------------- YouTube ---------------- */

function yt_error(int $code, $d, string $raw = ''): string {
    $e = is_array($d) ? ($d['error'] ?? []) : [];
    $msg = is_array($e) ? ($e['message'] ?? '') : (string)$e;
    if ($msg === '') $msg = $code === 0 ? $raw : "HTTP $code";
    $reason = is_array($e) ? ($e['errors'][0]['reason'] ?? '') : '';
    $hint = '';
    if ($code === 401) $hint = 'Phiên đăng nhập Google hết hạn — kết nối lại kênh.';
    elseif (in_array($reason, ['insufficientPermissions', 'forbidden', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true) || preg_match('/insufficient|scope/i', $msg)) $hint = PERM_HINT . ' (cần quyền youtube.upload).';
    elseif ($reason === 'quotaExceeded') $hint = 'Hết hạn mức YouTube API trong ngày (mỗi lần tải video tốn ~1.600 đơn vị) — thử lại ngày mai.';
    elseif ($reason === 'uploadLimitExceeded') $hint = 'Kênh đã chạm giới hạn số video tải lên — thử lại sau.';
    elseif ($reason === 'youtubeSignupRequired') $hint = 'Tài khoản Google chưa tạo kênh YouTube.';
    return 'YouTube từ chối' . ($reason ? " ($reason)" : '') . ': ' . $msg . ($hint ? ' — ' . $hint : '');
}

function publish_to_youtube(array $CFG, array $ctx): array {
    $conn = $ctx['conn'];
    $ytOwn = ($conn['auth_method'] ?? 'oauth') === 'manual' && !empty($conn['refresh_token']); // OAuth riêng của kênh
    if (($conn['auth_method'] ?? 'oauth') === 'manual' && !$ytOwn) {
        throw new PublishError('Kênh YouTube nhập bằng API key chỉ đọc được số liệu công khai, không tải video lên được — kết nối kênh bằng đăng nhập Google (Thiết lập > Kết nối kênh)');
    }
    $videos = array_values(array_filter($ctx['media'], fn($m) => ($m['type'] ?? '') === 'video'));
    if (!$videos) throw new PublishError('YouTube chỉ đăng được video — bài này không có video');
    $f = media_file($videos[0]);
    $token = google_access_token($CFG, $conn);
    if (!$token) throw new PublishError('Token Google hết hạn và không làm mới được — kết nối lại kênh');
    $auth = 'Authorization: Bearer ' . $token;

    if ($conn['external_account_id']) {
        [$c, $d, $raw] = pub_http('GET', 'https://www.googleapis.com/youtube/v3/channels?part=id&mine=true', ['headers' => [$auth]]);
        // Token riêng chỉ có quyền youtube.upload thì không đọc được danh sách kênh — bỏ qua bước kiểm tra, token đã gắn đúng kênh.
        if ($c === 403 && $ytOwn) $d = ['items' => [['id' => $conn['external_account_id']]]];
        elseif ($c !== 200) throw new PublishError(yt_error($c, $d, $raw));
        $ids = array_column($d['items'] ?? [], 'id');
        if (!in_array($conn['external_account_id'], $ids, true)) throw new PublishError('Tài khoản Google đã kết nối không sở hữu kênh YouTube ' . $conn['external_account_id'] . ' — kết nối lại và chọn đúng kênh');
    }
    $clean = fn(string $s) => str_replace(['<', '>'], ['‹', '›'], $s);
    $title = trim((string)$ctx['post']['title']) !== '' ? $ctx['post']['title'] : mb_substr($ctx['text'], 0, 90);
    $desc = $ctx['link'] ? trim($ctx['text'] . "\n\n" . $ctx['link']) : $ctx['text'];
    $size = filesize($f); $mime = media_mime($f);
    [$c, $d, $raw, $h] = pub_http('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
        'headers' => [$auth, 'X-Upload-Content-Length: ' . $size, 'X-Upload-Content-Type: ' . $mime],
        'json' => [
            'snippet' => ['title' => mb_substr($clean($title), 0, 100), 'description' => mb_substr($clean($desc), 0, 5000), 'categoryId' => '22'],
            'status' => ['privacyStatus' => $ctx['options']['yt_privacy'] ?? 'public', 'selfDeclaredMadeForKids' => false],
        ],
    ]);
    if ($c !== 200 || empty($h['location'])) throw new PublishError(yt_error($c, $d, $raw));
    $session = $h['location'];

    $chunk = 8 * 1024 * 1024; // bội số của 256 KB theo yêu cầu của Google
    $fh = fopen($f, 'rb');
    $offset = 0; $result = null; $tries = 0;
    while ($offset < $size) {
        fseek($fh, $offset);
        $buf = fread($fh, $chunk);
        $end = $offset + strlen($buf) - 1;
        [$c, $d, $raw, $h] = pub_http('PUT', $session, ['timeout' => 600, 'body' => $buf,
            'headers' => [$auth, 'Content-Type: ' . $mime, 'Content-Length: ' . strlen($buf), "Content-Range: bytes $offset-$end/$size"]]);
        if ($c === 308) {
            $offset = isset($h['range']) && preg_match('/-(\d+)$/', $h['range'], $mm) ? (int)$mm[1] + 1 : $end + 1;
            $tries = 0; continue;
        }
        if ($c === 200 || $c === 201) { $result = $d; break; }
        if (($c === 0 || $c >= 500) && ++$tries <= 3) { sleep(2 * $tries); continue; }
        fclose($fh);
        throw new PublishError(yt_error($c, $d, $raw));
    }
    fclose($fh);
    if (empty($result['id'])) throw new PublishError('YouTube không trả về mã video sau khi tải lên');
    return ['external_id' => $result['id'], 'permalink' => 'https://www.youtube.com/watch?v=' . $result['id']];
}

/* ---------------- Điều phối ---------------- */

function post_options(string $postId): array {
    $o = json_decode((string)setting_get('post_opt.' . $postId, ''), true);
    return is_array($o) ? $o : [];
}

/** Trạng thái tổng của bài theo trạng thái từng kênh. */
function post_rollup(string $postId): string {
    global $pdo;
    $st = $pdo->prepare('SELECT status, COUNT(*) n FROM post_targets WHERE post_id=? GROUP BY status');
    $st->execute([$postId]);
    $by = array_column($st->fetchAll(), 'n', 'status');
    $total = array_sum($by); $ok = (int)($by['published'] ?? 0);
    if ($total && $ok === $total) return 'published';
    return $ok > 0 ? 'partial' : 'failed';
}

/** Đăng một bài lên các kênh đích chưa đăng. An toàn khi gọi đồng thời (cron + người dùng). */
function publish_post(array $CFG, string $postId): array {
    global $pdo;
    $lock = $pdo->prepare("UPDATE posts SET status='publishing', updated_at=datetime('now') WHERE id=? AND (status IN ('approved','scheduled','partial','failed')
        OR (status='publishing' AND updated_at < datetime('now', ?)))");
    $lock->execute([$postId, '-' . PUBLISH_STALE_MIN . ' minutes']);
    if ($lock->rowCount() !== 1) {
        $st = $pdo->prepare('SELECT status FROM posts WHERE id=?'); $st->execute([$postId]);
        $s = $st->fetchColumn();
        if ($s === false) return ['ok' => false, 'error' => 'Không tìm thấy bài đăng'];
        return ['ok' => false, 'status' => $s, 'error' => $s === 'publishing' ? 'Bài đang được đăng ở một tiến trình khác' : 'Bài chưa được duyệt hoặc đã đăng xong (trạng thái: ' . $s . ')'];
    }
    // Kênh kẹt ở 'publishing' do lần trước bị ngắt: không tự đăng lại (có thể đã lên kênh) — đánh dấu lỗi để người dùng kiểm tra.
    $pdo->prepare("UPDATE post_targets SET status='failed', error='Lần đăng trước bị gián đoạn — kiểm tra trên kênh xem bài đã lên chưa trước khi bấm Thử lại' WHERE post_id=? AND status='publishing'")
        ->execute([$postId]);

    $st = $pdo->prepare('SELECT * FROM posts WHERE id=?'); $st->execute([$postId]);
    $post = $st->fetch();
    $media = jdecode($post['media'], []);
    $options = post_options($postId);
    $tq = $pdo->prepare("SELECT * FROM post_targets WHERE post_id=? AND status='pending' ORDER BY id");
    $tq->execute([$postId]);
    $pending = $tq->fetchAll();
    if (!$pending) { // không còn kênh chờ đăng: trả lại trạng thái, không ghi nhật ký / thông báo thừa
        $s = post_rollup($postId);
        $pdo->prepare("UPDATE posts SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$s, $postId]);
        return ['ok' => false, 'status' => $s, 'error' => 'Không còn kênh nào chờ đăng'];
    }
    $claim = $pdo->prepare("UPDATE post_targets SET status='publishing', error=NULL WHERE id=? AND status='pending'");
    $okSt = $pdo->prepare("UPDATE post_targets SET status='published', external_id=?, permalink=?, error=NULL, published_at=datetime('now') WHERE id=?");
    $badSt = $pdo->prepare("UPDATE post_targets SET status='failed', error=? WHERE id=?");
    $done = [];
    foreach ($pending as $t) {
        $claim->execute([$t['id']]);
        if ($claim->rowCount() !== 1) continue;
        $label = $t['provider'];
        try {
            $conn = get_connection($t['connection_id']);
            if (!$conn) throw new PublishError('Kênh đã bị xoá khỏi Thiết lập > Kết nối kênh');
            $label = ($t['provider'] === 'instagram' ? 'Instagram của ' : '') . $conn['label'];
            if ($conn['status'] !== 'connected') throw new PublishError('Kênh chưa ở trạng thái đã kết nối (' . $conn['status'] . ') — kiểm tra Thiết lập > Kết nối kênh');
            $driver = $GLOBALS['PUBLISH_DRIVERS'][$t['provider']] ?? null;
            if (!$driver) throw new PublishError('Chưa hỗ trợ đăng lên loại kênh ' . $t['provider']);
            [$text, $link] = target_text($post, $t);
            $r = $driver($CFG, ['post' => $post, 'target' => $t, 'conn' => $conn, 'media' => $media, 'text' => $text, 'link' => $link, 'options' => $options]);
            $okSt->execute([$r['external_id'] ?? null, $r['permalink'] ?? null, $t['id']]);
            $done[] = ['id' => (int)$t['id'], 'provider' => $t['provider'], 'status' => 'published', 'permalink' => $r['permalink'] ?? null];
        } catch (Throwable $e) {
            $msg = $e instanceof PublishError ? $e->getMessage() : 'Lỗi hệ thống khi đăng: ' . $e->getMessage();
            $badSt->execute([mb_substr($msg, 0, 1000), $t['id']]);
            $done[] = ['id' => (int)$t['id'], 'provider' => $t['provider'], 'status' => 'failed', 'error' => $msg];
            log_activity('error', 'post', $postId, 'Đăng lên ' . $label . ' lỗi: ' . $msg);
        }
    }
    $final = post_rollup($postId);
    $pdo->prepare("UPDATE posts SET status=?, published_at=CASE WHEN ? IN ('published','partial') THEN COALESCE(published_at, datetime('now')) ELSE published_at END, updated_at=datetime('now') WHERE id=?")
        ->execute([$final, $final, $postId]);
    $title = $post['title'] ?: mb_substr((string)$post['body'], 0, 60);
    $nOk = count(array_filter($done, fn($d) => $d['status'] === 'published'));
    $nBad = count($done) - $nOk;
    log_activity('publish', 'post', $postId, 'Đăng bài "' . $title . '": ' . $nOk . ' kênh thành công, ' . $nBad . ' kênh lỗi');
    if ($final === 'published') {
        fire_event('post.published', ['post_id' => $postId]);
        if ($post['created_by']) notify($post['created_by'], 'Đã đăng bài: ' . $title, 'Bài đã lên tất cả kênh đã chọn.', 'publish');
    } else {
        if ($final === 'partial') fire_event('post.published', ['post_id' => $postId, 'partial' => true]);
        fire_event('post.failed', ['post_id' => $postId, 'status' => $final]);
        $who = $post['created_by'] ? [$post['created_by']] : [];
        foreach ($who as $u) notify($u, ($final === 'partial' ? 'Đăng bài một phần: ' : 'Đăng bài lỗi: ') . $title, $nBad . ' kênh lỗi — mở Đăng bài đa kênh > Đã đăng để xem lý do và thử lại.', 'publish');
        notify(null, ($final === 'partial' ? 'Đăng bài một phần: ' : 'Đăng bài lỗi: ') . $title, $nBad . ' kênh lỗi.', 'publish');
    }
    return ['ok' => $final !== 'failed', 'status' => $final, 'targets' => $done];
}

/** Cron: đăng các bài đã duyệt/đã hẹn giờ đến giờ (giờ VN). */
function publish_due_posts(array $CFG): string {
    global $pdo;
    $st = $pdo->prepare("SELECT id FROM posts WHERE status IN ('approved','scheduled') AND (scheduled_at IS NULL OR scheduled_at <= ?)
        ORDER BY COALESCE(scheduled_at, created_at) LIMIT " . PUBLISH_MAX_PER_RUN);
    $st->execute([vn_now()]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return 'không có bài đến giờ đăng';
    $cnt = ['published' => 0, 'partial' => 0, 'failed' => 0, 'skip' => 0];
    $t0 = time();
    foreach ($ids as $id) {
        if (time() - $t0 > 240) break; // nhường lần chạy sau, tránh chồng cron 5 phút
        $r = publish_post($CFG, $id);
        $cnt[isset($r['error']) ? 'skip' : $r['status']]++;
    }
    return count($ids) . ' bài đến giờ: ' . $cnt['published'] . ' đăng xong, ' . $cnt['partial'] . ' một phần, ' . $cnt['failed'] . ' lỗi' . ($cnt['skip'] ? ', ' . $cnt['skip'] . ' bỏ qua (đang đăng ở nơi khác)' : '');
}

/* ---------------- TikTok (Content Posting API — Direct Post, tải tệp lên) ----------------
   Cần sản phẩm "Content Posting API" + quyền video.publish trong app TikTok, và kênh kết nối lại để cấp quyền mới.
   App chưa được TikTok duyệt (sandbox / chưa audit): video chỉ đăng được ở chế độ riêng tư (SELF_ONLY). */
function tiktok_pub_error(int $c, ?array $d, string $raw): string {
    $code = (string)($d['error']['code'] ?? '');
    $msg = tiktok_error($c, $d) ?? mb_substr($raw, 0, 200);
    if (in_array($code, ['scope_not_authorized', 'access_token_invalid', 'scope_permission_missed'], true) || str_contains($msg, 'scope'))
        return PERM_HINT . ' (TikTok cần quyền video.publish: bật Content Posting API trong app TikTok rồi kết nối lại kênh) — ' . $msg;
    if ($code === 'unaudited_client_can_only_post_to_private_accounts') return 'App TikTok chưa được duyệt chỉ đăng được khi tài khoản TikTok để chế độ riêng tư — ' . $msg;
    if ($code === 'spam_risk_too_many_posts') return 'TikTok giới hạn số bài đăng mỗi ngày — thử lại sau';
    return 'TikTok: ' . $msg . ($code ? " ($code)" : '');
}

function publish_to_tiktok(array $CFG, array $ctx): array {
    $conn = $ctx['conn'];
    $videos = array_values(array_filter($ctx['media'], fn($m) => ($m['type'] ?? '') === 'video'));
    if (!$videos) throw new PublishError('TikTok chỉ đăng video — bài này không có video');
    $f = media_file($videos[0]);
    [$token, $err] = tiktok_access_token($CFG, $conn);
    if (!$token) throw new PublishError($err ?? 'Kênh TikTok chưa có token — kết nối lại');
    $auth = 'Authorization: Bearer ' . $token;

    // 1. Quyền riêng tư được phép cho tài khoản này (app chưa duyệt chỉ có SELF_ONLY).
    [$c, $d, $raw] = pub_http('POST', tiktok_url($CFG, '/v2/post/publish/creator_info/query/'), ['headers' => [$auth], 'json' => new stdClass()]);
    if ($c !== 200 || tiktok_error($c, $d)) throw new PublishError(tiktok_pub_error($c, $d, (string)$raw));
    $info = $d['data'] ?? [];
    $allowed = $info['privacy_level_options'] ?? ['SELF_ONLY'];
    // Content Sharing Guidelines: quyền riêng tư do người dùng chọn (không chọn sẵn), phải nằm trong danh sách TikTok cho phép
    $o = $ctx['options'] ?? [];
    $want = (string)($o['tt_privacy'] ?? '');
    if ($want === '') throw new PublishError('TikTok: chưa chọn ai có thể xem video — mở bài, chọn quyền riêng tư rồi đăng lại');
    if (!in_array($want, $allowed, true)) throw new PublishError('TikTok: tài khoản này không cho phép mức "' . $want . '" (được phép: ' . implode(', ', $allowed) . ')');
    $privacy = $want;
    $brandContent = !empty($o['tt_disclose']) && !empty($o['tt_brand_content']); $brandOrganic = !empty($o['tt_disclose']) && !empty($o['tt_brand_organic']);
    if ($brandContent && $privacy === 'SELF_ONLY') throw new PublishError('TikTok: nội dung có thương hiệu không thể đặt "Chỉ mình tôi"');
    $maxSec = (int)($info['max_video_post_duration_sec'] ?? 0);

    // 2. Khởi tạo bài đăng, chia tệp: < 5MB gửi 1 lần; lớn hơn chia khúc 10MB, khúc cuối gánh phần dư.
    $size = filesize($f);
    $chunk = $size < 5 * 1048576 ? $size : 10 * 1048576;
    $count = max(1, intdiv($size, $chunk));
    $text = $ctx['link'] ? trim($ctx['text'] . "\n\n" . $ctx['link']) : $ctx['text'];
    [$c, $d, $raw] = pub_http('POST', tiktok_url($CFG, '/v2/post/publish/video/init/'), ['headers' => [$auth], 'json' => [
        'post_info' => ['title' => mb_substr($text, 0, 2200), 'privacy_level' => $privacy,
            // Người dùng tắt được từng mục; mục tài khoản đã tắt trong TikTok thì luôn tắt
            'disable_comment' => !empty($info['comment_disabled']) || (array_key_exists('tt_comment', $o) && !$o['tt_comment']),
            'disable_duet' => !empty($info['duet_disabled']) || (array_key_exists('tt_duet', $o) && !$o['tt_duet']),
            'disable_stitch' => !empty($info['stitch_disabled']) || (array_key_exists('tt_stitch', $o) && !$o['tt_stitch']),
            'brand_content_toggle' => $brandContent, 'brand_organic_toggle' => $brandOrganic, 'video_cover_timestamp_ms' => 1000],
        'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunk, 'total_chunk_count' => $count],
    ]]);
    if ($c !== 200 || tiktok_error($c, $d) || empty($d['data']['upload_url'])) throw new PublishError(tiktok_pub_error($c, $d, (string)$raw));
    $publishId = $d['data']['publish_id'];
    $url = $d['data']['upload_url'];

    // 3. Tải từng khúc.
    $fh = fopen($f, 'rb');
    for ($i = 0; $i < $count; $i++) {
        $start = $i * $chunk;
        $len = $i === $count - 1 ? $size - $start : $chunk;
        fseek($fh, $start);
        $bytes = fread($fh, $len);
        [$c, $d, $raw] = pub_http('PUT', $url, ['timeout' => 600, 'body' => $bytes, 'headers' => [
            'Content-Type: ' . media_mime($f), 'Content-Length: ' . $len, 'Content-Range: bytes ' . $start . '-' . ($start + $len - 1) . '/' . $size]]);
        if ($c < 200 || $c >= 300) { fclose($fh); throw new PublishError('TikTok: tải video lên lỗi ở khúc ' . ($i + 1) . "/$count (HTTP $c) " . mb_substr((string)$raw, 0, 150)); }
    }
    fclose($fh);

    // 4. Chờ TikTok xử lý (tối đa ~90 giây); quá thời gian thì coi như đã gửi — TikTok tiếp tục xử lý phía họ.
    $status = 'PROCESSING_UPLOAD'; $fail = '';
    for ($k = 0; $k < 18; $k++) {
        sleep(5);
        [$c, $d] = pub_http('POST', tiktok_url($CFG, '/v2/post/publish/status/fetch/'), ['headers' => [$auth], 'json' => ['publish_id' => $publishId]]);
        if ($c !== 200) continue;
        $status = (string)($d['data']['status'] ?? $status);
        $fail = (string)($d['data']['fail_reason'] ?? '');
        if (in_array($status, ['PUBLISH_COMPLETE', 'FAILED'], true)) break;
    }
    if ($status === 'FAILED') throw new PublishError('TikTok từ chối video: ' . ($fail ?: 'không rõ lý do') . ($maxSec ? " (tối đa {$maxSec} giây)" : ''));
    $vid = $d['data']['publicaly_available_post_id'][0] ?? null;
    $user = (string)($info['creator_username'] ?? '');
    $permalink = $vid && $user ? "https://www.tiktok.com/@$user/video/$vid" : null;
    return ['external_id' => $vid ? (string)$vid : $publishId, 'permalink' => $permalink,
        'note' => $privacy === 'SELF_ONLY' ? 'Đăng ở chế độ riêng tư (app TikTok chưa được duyệt)' : null];
}

/* ---------------- X (Twitter) — API v2, OAuth 2.0 user context (12/10/2026) ----------------
   Cần app X có App permissions "Read and write" + scope tweet.write, media.write; gói Free: ~500 bài/tháng/app.
   Ảnh: POST /2/media/upload một lần (≤ 5MB, tối đa 4 ảnh). Video (1): tải theo khúc initialize → append → finalize → chờ STATUS. */
const X_LINK_LEN = 23;   // mọi link trên X đều được rút gọn t.co và tính 23 ký tự
const X_MAX_CHARS = 280;

function x_pub_error(int $c, ?array $d, string $raw): string {
    $msg = x_error($c, $d) ?? mb_substr($raw, 0, 200);
    if ($c === 0) return 'Không kết nối được tới X: ' . $msg;
    if ($c === 401) return PERM_HINT . ' (X: token không còn hiệu lực hoặc bị thu hồi) — ' . $msg;
    if ($c === 403) return 'App X chưa có quyền ghi (Developer Portal → User authentication settings → App permissions "Read and write", rồi kết nối lại kênh) hoặc gói Free đã hết hạn mức 500 bài/tháng — X: ' . $msg;
    if ($c === 429) return 'X giới hạn tần suất (rate limit / hạn mức gói) — thử lại sau ít phút: ' . $msg;
    if ($c === 413) return 'X: tệp quá lớn — ' . $msg;
    return 'X: ' . $msg . " (HTTP $c)";
}

/** Nội dung ≤ 280 ký tự: link tính 23 ký tự (+1 xuống dòng); quá dài thì cắt ở ranh giới từ và thêm "…".
 *  Đếm bằng mb_strlen (X đếm chữ Latin/tiếng Việt = 1, CJK/emoji = 2 — bài tiếng Việt khớp). */
function x_compose_text(string $text, ?string $link): string {
    $text = trim($text);
    $budget = X_MAX_CHARS - ($link ? X_LINK_LEN + 1 : 0);
    if (mb_strlen($text) > $budget) {
        $cut = mb_substr($text, 0, $budget - 1);
        $sp = max((int)mb_strrpos($cut, ' '), (int)mb_strrpos($cut, "\n"));
        if ($sp > $budget * 0.6) $cut = mb_substr($cut, 0, $sp);
        $text = rtrim($cut, " \n\t.,;:-") . '…';
    }
    if (!$link) return $text;
    return $text === '' ? $link : $text . "\n" . $link;
}

/** Chờ X xử lý media (GET /2/media/upload?command=STATUS) tới succeeded; ném PublishError nếu failed / quá giờ. */
function x_media_wait(string $auth, string $mediaId, ?array $pi, int $maxSec = 180): void {
    $t0 = time();
    while ($pi && in_array((string)($pi['state'] ?? ''), ['pending', 'in_progress'], true)) {
        if (time() - $t0 > $maxSec) throw new PublishError('X: xử lý media quá lâu (hơn ' . $maxSec . ' giây) — thử lại sau');
        sleep(max(1, min(15, (int)($pi['check_after_secs'] ?? 3))));
        [$c, $d, $raw] = pub_http('GET', X_API_BASE . '/2/media/upload?command=STATUS&media_id=' . rawurlencode($mediaId), ['headers' => [$auth]]);
        if ($c !== 200) throw new PublishError('X: không hỏi được trạng thái media — ' . x_pub_error($c, $d, (string)$raw));
        $pi = $d['data']['processing_info'] ?? null;
    }
    if ($pi && ($pi['state'] ?? '') === 'failed') throw new PublishError('X từ chối media: ' . (string)($pi['error']['message'] ?? $pi['error']['name'] ?? 'không rõ lý do'));
}

/** Tải một ảnh (jpg/png/webp/gif ≤ 5MB) lên X, trả media id. */
function x_upload_image(string $auth, array $m): string {
    $f = media_file($m);
    if (filesize($f) > 5 * 1048576) throw new PublishError('X: ảnh "' . ($m['name'] ?? basename($f)) . '" quá 5MB');
    $mime = media_mime($f);
    [$c, $d, $raw] = pub_http('POST', X_API_BASE . '/2/media/upload', ['timeout' => 180, 'headers' => [$auth], 'multipart' => [
        'media' => new CURLFile($f, $mime, basename($f)),
        'media_category' => $mime === 'image/gif' ? 'tweet_gif' : 'tweet_image',
        'media_type' => $mime,
    ]]);
    $id = (string)($d['data']['id'] ?? '');
    if (($c !== 200 && $c !== 201) || $id === '') throw new PublishError('Ảnh "' . ($m['name'] ?? basename($f)) . '": ' . x_pub_error($c, $d, (string)$raw));
    x_media_wait($auth, $id, $d['data']['processing_info'] ?? null, 60);
    return $id;
}

/** Tải một video theo khúc (≤ 4MB/khúc) lên X, chờ xử lý xong, trả media id. */
function x_upload_video(string $auth, array $m): string {
    $f = media_file($m); $size = filesize($f); $mime = media_mime($f);
    [$c, $d, $raw] = pub_http('POST', X_API_BASE . '/2/media/upload/initialize', ['headers' => [$auth],
        'json' => ['media_type' => $mime, 'total_bytes' => $size, 'media_category' => 'tweet_video']]);
    $id = (string)($d['data']['id'] ?? '');
    if (($c !== 200 && $c !== 201) || $id === '') throw new PublishError('X: không khởi tạo được tải video — ' . x_pub_error($c, $d, (string)$raw));
    $chunk = 4 * 1048576;
    $fh = fopen($f, 'rb'); $i = 0;
    while (!feof($fh)) {
        $bytes = fread($fh, $chunk);
        if ($bytes === false || $bytes === '') break;
        [$c, $d, $raw] = pub_http('POST', X_API_BASE . '/2/media/upload/' . rawurlencode($id) . '/append', ['timeout' => 600, 'headers' => [$auth], 'multipart' => [
            'segment_index' => (string)$i, 'media' => new CURLStringFile($bytes, 'chunk' . $i, 'application/octet-stream')]]);
        if ($c < 200 || $c >= 300) { fclose($fh); throw new PublishError('X: tải video lên lỗi ở khúc ' . ($i + 1) . ' — ' . x_pub_error($c, $d, (string)$raw)); }
        $i++;
    }
    fclose($fh);
    [$c, $d, $raw] = pub_http('POST', X_API_BASE . '/2/media/upload/' . rawurlencode($id) . '/finalize', ['timeout' => 120, 'headers' => [$auth, 'Content-Length: 0']]);
    if ($c < 200 || $c >= 300) throw new PublishError('X: không hoàn tất được tải video — ' . x_pub_error($c, $d, (string)$raw));
    x_media_wait($auth, $id, $d['data']['processing_info'] ?? ['state' => 'pending', 'check_after_secs' => 5], 300);
    return $id;
}

function publish_to_x(array $CFG, array $ctx): array {
    $conn = $ctx['conn'];
    [$token, $err] = x_access_token($CFG, $conn);
    if (!$token) throw new PublishError($err ?? 'Kênh X chưa có token — kết nối lại');
    $auth = 'Authorization: Bearer ' . $token;
    $videos = array_values(array_filter($ctx['media'], fn($m) => ($m['type'] ?? '') === 'video'));
    $images = array_values(array_filter($ctx['media'], fn($m) => ($m['type'] ?? '') === 'image'));
    $text = x_compose_text((string)$ctx['text'], $ctx['link'] ?: null);
    if ($text === '' && !$videos && !$images) throw new PublishError('Bài không có nội dung để đăng lên X');

    // Media: 1 video HOẶC tối đa 4 ảnh (X không cho trộn).
    $ids = []; $note = null;
    if ($videos) {
        $ids[] = x_upload_video($auth, $videos[0]);
        if (count($videos) > 1 || $images) $note = 'X chỉ nhận 1 video mỗi bài — đã đăng video đầu tiên, bỏ qua ảnh/video còn lại';
    } else {
        foreach (array_slice($images, 0, 4) as $m) $ids[] = x_upload_image($auth, $m);
        if (count($images) > 4) $note = 'X tối đa 4 ảnh mỗi bài — đã đăng 4 ảnh đầu';
    }

    $body = [];
    if ($text !== '') $body['text'] = $text;
    if ($ids) $body['media'] = ['media_ids' => $ids];
    [$c, $d, $raw] = pub_http('POST', X_API_BASE . '/2/tweets', ['headers' => [$auth], 'json' => $body]);
    $id = (string)($d['data']['id'] ?? '');
    if (($c !== 200 && $c !== 201) || $id === '') throw new PublishError(x_pub_error($c, $d, (string)$raw));
    $user = (string)($conn['account_name'] ?? '');
    if ($user === '') { [$c2, $u] = pub_http('GET', X_API_BASE . '/2/users/me', ['headers' => [$auth]]); $user = (string)($u['data']['username'] ?? ''); }
    return ['external_id' => $id, 'permalink' => $user !== '' ? "https://x.com/$user/status/$id" : "https://x.com/i/status/$id", 'note' => $note];
}
