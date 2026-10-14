<?php
/* Cầu nối MÁY HL (app phòng marketing/kinh doanh chạy trên PC, :8686) ↔ Marketing Hub. 11/10/2026
   Máy HL đẩy báo cáo (không cần đăng nhập, xác thực bằng khoá):
     POST hl.php  header X-HL-Key: <hl_api_key>  body {project_id, kind, day (YYYY-MM-DD), data:{…}}
       kind: tim_khach | email_chien_dich | hop_thu | seo_hang_ngay | kinh_doanh | tong_ngay
     → upsert bảng hl_reports (một bản/dự án/kind/ngày). Không nhận SĐT/email thô: máy HL tự tổng hợp trước khi gửi.
   Giao diện đọc (đăng nhập, theo dự án):
     GET hl.php[?days=14]   → {reports:{kind:[{day,data,updated_at}…]}, latest:{kind:{day,data}}} */
require __DIR__ . '/_boot.php';
$method = $_SERVER['REQUEST_METHOD'];

// 12/10/2026: NOVA (máy HL) đăng chéo TikTok + Facebook Reels bằng token của Hub. X-HL-Key.
//   GET  ?action=social_accounts                 → kênh tiktok/facebook_page/x đã nối (id, provider, label, account_name, project_id) · 12/10: thêm X
//   GET  ?action=social_token&connection_id=…    → token hiện hành (TikTok/X tự làm mới; Facebook = Page token) + open_id/page_id/user_id
//   POST {action:'social_posted', connection_id, title, permalink, external_id, error?} → ghi nhật ký vào dự án của kênh
if (in_array((string)($_GET['action'] ?? ''), ['social_accounts', 'social_token'], true) || ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)file_get_contents('php://input'), '{"action":"social_posted"'))) {
    $key = (string)($_SERVER['HTTP_X_HL_KEY'] ?? ''); $want = (string)($CFG['hl_api_key'] ?? '');
    if ($want === '' || $key === '' || !hash_equals($want, $key)) fail('Sai khoá máy HL', 401);
    $a = (string)($_GET['action'] ?? 'social_posted');
    if ($a === 'social_accounts') {
        $rows = $pdo->query("SELECT c.id, c.provider, c.label, c.account_name, c.external_account_id, c.project_id, c.env, p.name project_name FROM connections c LEFT JOIN projects p ON p.id=c.project_id WHERE c.status='connected' AND c.provider IN ('tiktok','facebook_page','x') ORDER BY c.project_id, c.provider")->fetchAll();
        respond(['accounts' => $rows]);
    }
    if ($a === 'social_token') {
        $c = get_connection((string)($_GET['connection_id'] ?? ''), true);
        if (!$c || !in_array($c['provider'], ['tiktok', 'facebook_page', 'x'], true)) fail('Kênh không hợp lệ', 404);
        if ($c['provider'] === 'x') { [$t, $err] = x_access_token($CFG, $c); if (!$t) fail($err ?? 'Token X hết hạn — kết nối lại trong Hub', 424); // 12/10/2026: X tự làm mới (refresh token xoay vòng)
            respond(['provider' => 'x', 'token' => $t, 'user_id' => $c['external_account_id'], 'username' => $c['account_name'], 'api_base' => X_API_BASE]); }
        if ($c['provider'] === 'tiktok') { [$t, $err] = tiktok_access_token($CFG, $c); if (!$t) fail($err ?? 'Token TikTok hết hạn — kết nối lại trong Hub', 424);
            respond(['provider' => 'tiktok', 'token' => $t, 'open_id' => $c['external_account_id'], 'username' => $c['account_name'], 'api_base' => rtrim(tiktok_url($CFG, '/'), '/')]); }
        require_once __DIR__ . '/_publish_lib.php';
        try { $t = meta_page_token($CFG, $c); } catch (Throwable $e) { fail($e->getMessage(), 424); }
        respond(['provider' => 'facebook_page', 'token' => $t, 'page_id' => $c['external_account_id'], 'graph_version' => $CFG['meta_graph_version']]);
    }
    $in = json_input(); $c = get_connection((string)($in['connection_id'] ?? ''), true);
    if (!$c) fail('Kênh không hợp lệ', 404);
    $ok = empty($in['error']);
    log_activity($ok ? 'publish' : 'error', 'connection', $c['id'], ($ok ? 'NOVA đăng lên ' : 'NOVA đăng LỖI ') . $c['label'] . ' (' . $c['provider'] . '): ' . mb_substr((string)($in['title'] ?? ''), 0, 120) . ($ok ? (!empty($in['permalink']) ? ' → ' . $in['permalink'] : '') : ' — ' . mb_substr((string)$in['error'], 0, 200)), null, $c['project_id']);
    // TikTok chưa audit → video chỉ vào hộp thư nháp, API không gắn được caption: ghi caption chuẩn SEO vào nhật ký dự án (không bắn thông báo — anh Tập không muốn)
    if ($ok && !empty($in['inbox'])) log_activity('publish', 'connection', $c['id'], 'TikTok ' . $c['label'] . ' — video đã vào hộp thư nháp, caption để dán khi bấm Đăng: ' . mb_substr((string)($in['caption'] ?? $in['title'] ?? ''), 0, 1500), null, $c['project_id']);
    respond(['ok' => true]);
}
// 12/10/2026: việc AI chăm sóc chat cho máy HL (Claude Code / gói Max). GET ?action=ai_jobs&take=3 → danh sách việc queued (đánh dấu taken); POST {action:'ai_job_done', id, result|error}
if (($_GET['action'] ?? '') === 'ai_jobs' || (($_SERVER['REQUEST_METHOD'] === 'POST') && str_starts_with((string)file_get_contents('php://input'), '{"action":"ai_job_done"'))) {
    $key = (string)($_SERVER['HTTP_X_HL_KEY'] ?? ''); $want = (string)($CFG['hl_api_key'] ?? '');
    if ($want === '' || $key === '' || !hash_equals($want, $key)) fail('Sai khoá máy HL', 401);
    require_once __DIR__ . '/_inbox_lib.php';
    if (strtotime((string)setting_get('inbox.hl_ai_seen', '2000-01-01')) < time() - 45) setting_set('inbox.hl_ai_seen', date('Y-m-d H:i:s'), ['id' => null]); // ghi nhịp tim tối đa 1 lần/45 giây
    if ($method === 'GET') {
        $pdo->exec("UPDATE inbox_ai_jobs SET status='failed', error='quá hạn (máy HL không trả lời trong 5 phút)' WHERE status='taken' AND taken_at < datetime('now','-5 minutes')");
        $pdo->exec("UPDATE inbox_ai_jobs SET status='failed', error='quá cũ (chờ hơn 30 phút)' WHERE status='queued' AND created_at < datetime('now','-30 minutes')");
        $take = max(1, min(5, (int)($_GET['take'] ?? 3)));
        $rows = $pdo->query("SELECT j.*, t.customer_name, t.provider, t.permalink, t.post_id FROM inbox_ai_jobs j JOIN inbox_threads t ON t.id=j.thread_id WHERE j.status='queued' ORDER BY j.created_at LIMIT $take")->fetchAll();
        foreach ($rows as $r) $pdo->prepare("UPDATE inbox_ai_jobs SET status='taken', taken_at=datetime('now') WHERE id=?")->execute([$r['id']]);
        require_once __DIR__ . '/_inbox_ai_lib.php';
        respond(['jobs' => array_map(function ($r) {
            // Bài/reel chưa có link sản phẩm → nhờ máy HL quét trang công khai (Graph không trả link gắn trong reel)
            $pk = ib_ai_post_key($r); $need = $pk !== '' && !empty($r['permalink']) && (str_contains((string)$r['system'], 'Bài không có link sản phẩm') || str_contains((string)$r['system'], 'không mở được')) && empty(jdecode(setting_get('inbox.links.' . $pk) ?: null, [])['links']);
            return ['id' => $r['id'], 'kind' => $r['kind'], 'thread_id' => $r['thread_id'], 'project_id' => $r['project_id'], 'customer' => $r['customer_name'], 'provider' => $r['provider'], 'system' => $r['system'], 'messages' => jdecode($r['messages'], []),
                'need_links' => $need, 'reel_url' => $need ? (string)$r['permalink'] : null, 'post_key' => $pk]; }, $rows)]);
    }
    $in = json_input(); $id = (string)($in['id'] ?? '');
    $st = $pdo->prepare("SELECT * FROM inbox_ai_jobs WHERE id=? AND status='taken'"); $st->execute([$id]); $j = $st->fetch();
    if (!$j) fail('Việc không tồn tại hoặc đã xử lý', 404);
    if (!empty($in['links']) && is_array($in['links']) && !empty($in['post_key'])) { require_once __DIR__ . '/_inbox_ai_lib.php'; ib_ai_links_store(preg_replace('/[^A-Za-z0-9_]/', '', (string)$in['post_key']), $in['links']); }
    if (!empty($in['error']) || !is_array($in['result'] ?? null) || (trim((string)($in['result']['reply'] ?? '')) === '' && empty($in['result']['skip']))) {
        $pdo->prepare("UPDATE inbox_ai_jobs SET status='failed', error=?, done_at=datetime('now') WHERE id=?")->execute([mb_substr((string)($in['error'] ?? 'kết quả rỗng'), 0, 500), $id]);
        respond(['ok' => true, 'applied' => 'lỗi ghi nhận']);
    }
    $res = ['reply' => trim((string)$in['result']['reply']), 'handoff' => !empty($in['result']['handoff']), 'reason' => (string)($in['result']['reason'] ?? ''), 'phone' => $in['result']['phone'] ?? null, 'skip' => !empty($in['result']['skip'])];
    $pdo->prepare("UPDATE inbox_ai_jobs SET status='done', result=?, done_at=datetime('now') WHERE id=?")->execute([json_encode($res, JSON_UNESCAPED_UNICODE), $id]);
    $tt = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $tt->execute([$j['thread_id']]); $th = $tt->fetch();
    $applied = $th ? ib_ai_apply($CFG, $th, $res, $j['kind']) : 'hội thoại không còn';
    respond(['ok' => true, 'applied' => $applied]);
}
if ($method === 'POST') {
    $key = (string)($_SERVER['HTTP_X_HL_KEY'] ?? '');
    $want = (string)($CFG['hl_api_key'] ?? '');
    if ($want === '' || $key === '' || !hash_equals($want, $key)) fail('Sai khoá máy HL', 401);
    $in = json_input();
    $pidIn = (string)($in['project_id'] ?? '');
    $st = $pdo->prepare("SELECT id FROM projects WHERE id=? AND status<>'archived'"); $st->execute([$pidIn]);
    if (!$st->fetchColumn()) fail('Dự án không tồn tại: ' . $pidIn, 404);
    $kind = (string)($in['kind'] ?? '');
    if (!preg_match('/^[a-z_]{3,30}$/', $kind)) fail('kind không hợp lệ');
    $day = (string)($in['day'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d\d-\d\d$/', $day)) fail('day dạng YYYY-MM-DD');
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 200000) fail('data quá lớn (tối đa 200 KB)');
    // Không nhận dữ liệu cá nhân thô: chặn chuỗi giống SĐT / email trong payload.
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json) || preg_match('/(?<!\d)0\d{9}(?!\d)/', $json)) fail('Payload chứa SĐT/email thô — máy HL phải tổng hợp (đếm) trước khi gửi', 422);
    $pdo->prepare("INSERT INTO hl_reports(project_id,kind,day,data,updated_at) VALUES (?,?,?,?,datetime('now'))
        ON CONFLICT(project_id,kind,day) DO UPDATE SET data=excluded.data, updated_at=excluded.updated_at")->execute([$pidIn, $kind, $day, $json]);
    respond(['ok' => true, 'project_id' => $pidIn, 'kind' => $kind, 'day' => $day]);
}

require_method('GET');
require_login();
$PID = pid();
$days = max(1, min(90, (int)($_GET['days'] ?? 14)));
$st = $pdo->prepare('SELECT kind, day, data, updated_at FROM hl_reports WHERE project_id=? AND day >= ? ORDER BY kind, day'); $st->execute([$PID, date('Y-m-d', strtotime("-$days days"))]);
$rep = []; $latest = [];
foreach ($st->fetchAll() as $r) { $row = ['day' => $r['day'], 'data' => jdecode($r['data']), 'updated_at' => $r['updated_at']]; $rep[$r['kind']][] = $row; $latest[$r['kind']] = $row; }
respond(['reports' => $rep, 'latest' => $latest, 'configured' => !empty($CFG['hl_api_key'])]);
