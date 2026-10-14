<?php
require __DIR__ . '/_boot.php';
require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM competitors WHERE project_id=? ORDER BY share_of_voice DESC'); $st->execute([pid()]); $rows = $st->fetchAll();
    $competitors = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'sov' => (int)$r['share_of_voice'], 'spendEst' => $r['spend_estimate'],
        'move' => $r['latest_move'], 'threat' => $r['threat'],
        'website' => $r['website'] ?? null, 'web' => ($r['web_checked_at'] ?? null) ? ['at' => $r['web_checked_at'], 'change' => $r['web_change'], 'snap' => array_intersect_key(jdecode($r['web_snapshot']), array_flip(['title', 'desc', 'urls', 'new_urls', 'words', 'phones', 'tech']))] : null,
        'place' => $r['place_id'] ? ['id' => $r['place_id'], 'name' => $r['place_name'], 'rating' => $r['place_rating'] !== null ? (float)$r['place_rating'] : null, 'reviews' => (int)$r['place_reviews'], 'address' => $r['place_address'], 'url' => $r['place_url'], 'at' => $r['place_synced_at']] : null,
    ], $rows);
    respond(['competitors' => $competitors]);
}

/* Google Maps (Places API mới): tìm địa điểm của đối thủ, gắn vào đối thủ, cập nhật điểm & số đánh giá.
   Khoá: Thiết lập > Nguồn dữ liệu thị trường (Google Cloud → bật "Places API (New)" → tạo API key; có tính phí theo lượt, kèm hạn mức miễn phí). */
function places_call(string $method, string $path, string $mask, ?array $body = null): array {
    $key = setting_get('data.places_key');
    if (!$key) fail('Chưa có khoá Google Places API — quản trị nhập ở Thiết lập → Nguồn dữ liệu thị trường', 400);
    $opts = ['headers' => ['X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $mask]];
    if ($body !== null) $opts['json'] = $body;
    [$c, $d] = http_call($method, 'https://places.googleapis.com/v1/' . $path, $opts);
    if ($c !== 200) fail('Google Places: ' . ($d['error']['message'] ?? "HTTP $c"), 502);
    return $d;
}
function place_save(string $cid, array $p): void {
    global $pdo;
    $pdo->prepare("UPDATE competitors SET place_id=?, place_name=?, place_rating=?, place_reviews=?, place_address=?, place_url=?, place_synced_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
        ->execute([$p['id'], $p['displayName']['text'] ?? null, $p['rating'] ?? null, $p['userRatingCount'] ?? null, $p['formattedAddress'] ?? null, $p['googleMapsUri'] ?? null, $cid]);
}
const PLACE_FIELDS = 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri';

/* Lấy đối thủ thật từ kết quả Robot "Thứ hạng Google": tên miền đứng top 10 cho nhiều từ khoá dịch vụ nhất.
   Share of Voice = % số vị trí top 10 (trên mọi từ khoá theo dõi) mà tên miền đó chiếm. Bỏ các trang không phải đối thủ (mạng xã hội, sàn, wiki…). */
if ($method === 'POST') require_project_write();
$PID = $method === 'POST' ? pid() : null;

if ($method === 'POST' && (($inA = json_input())['action'] ?? '') === 'import_from_bot') {
    $skip = ['youtube.com', 'facebook.com', 'wikipedia.org', 'vi.wikipedia.org', 'fptshop.com.vn', 'wordpress.org', 'vnnic.vn', 'google.com', 'tiktok.com',
        'shopee.vn', 'lazada.vn', 'tiki.vn', 'reddit.com', 'quora.com', 'thegioididong.com', 'dienmayxanh.com', 'vnexpress.net', 'tuoitre.vn', 'cafef.vn', 'cafebiz.vn'];
    $own = [];
    $jobs = [];
    try { $stJ = $pdo->prepare("SELECT config,last_result FROM bot_jobs WHERE kind='rank' AND last_result IS NOT NULL AND project_id=?"); $stJ->execute([$PID]); $jobs = $stJ->fetchAll(); } catch (Throwable $e) {}
    if (!$jobs) fail('Chưa có kết quả Robot "Thứ hạng Google" — vào Robot duyệt web, tạo việc theo dõi thứ hạng và chạy trước', 400);
    $cnt = []; $kws = []; $slots = 0;
    foreach ($jobs as $j) {
        $own[] = preg_replace('/^www\./', '', strtolower((string)(jdecode($j['config'])['domain'] ?? '')));
        foreach (jdecode($j['last_result'])['rows'] ?? [] as $r) {
            $seen = [];
            foreach (array_slice($r['top'] ?? [], 0, 10) as $t) {
                $slots++;
                $d = preg_replace('/^(www|m)\./', '', strtolower((string)$t['domain']));
                if ($d === '' || isset($seen[$d])) continue;
                $seen[$d] = 1;
                $cnt[$d] = ($cnt[$d] ?? 0) + 1;
                $kws[$d][] = $r['kw'] . ' (#' . $t['pos'] . ')';
            }
        }
    }
    foreach (array_keys($cnt) as $d) foreach (array_merge($skip, $own) as $s) if ($s !== '' && ($d === $s || str_ends_with($d, '.' . $s))) unset($cnt[$d]);
    arsort($cnt);
    $min = max(1, (int)($inA['min'] ?? 2));
    $have = [];
    $stH = $pdo->prepare('SELECT website FROM competitors WHERE project_id=?'); $stH->execute([$PID]);
    foreach ($stH->fetchAll() as $r) $have[preg_replace('#^(https?://)?(www\.)?#', '', rtrim(strtolower((string)$r['website']), '/'))] = 1;
    $added = [];
    foreach (array_slice($cnt, 0, (int)($inA['limit'] ?? 12), true) as $d => $n) {
        if ($n < $min || isset($have[$d])) continue;
        $id = gen_id('CP');
        $pdo->prepare('INSERT INTO competitors(id,project_id,name,share_of_voice,spend_estimate,latest_move,threat,website) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $PID, $d, $slots ? (int)round($n / $slots * 100) : 0, '—', 'Top 10 Google cho ' . $n . ' từ khoá: ' . mb_substr(implode(', ', $kws[$d]), 0, 220), $n >= 3 ? 'Cao' : 'TB', 'https://' . $d]);
        $added[] = $id;
    }
    log_activity('create', 'competitor', null, 'Lấy ' . count($added) . ' đối thủ từ kết quả thứ hạng Google');
    // Đọc website các đối thủ mới sau khi đã trả lời (chạy nền).
    http_response_code(200);
    echo json_encode(['added' => count($added)], JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if ($added) { require_once __DIR__ . '/_market_lib.php'; @set_time_limit(600); ignore_user_abort(true); foreach ($added as $id) { try { comp_web_check($id); } catch (Throwable $e) {} } }
    exit;
}

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['web_set', 'web_check'], true)) {
    require_once __DIR__ . '/_market_lib.php';
    if ($inA['action'] === 'web_set') {
        $url = trim((string)($inA['website'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) fail('Địa chỉ website không hợp lệ');
        need('competitors', (string)($inA['id'] ?? ''), 'đối thủ');
        $pdo->prepare('UPDATE competitors SET website=?, web_snapshot=NULL, web_checked_at=NULL, web_change=NULL WHERE id=? AND project_id=?')->execute([$url ?: null, (string)($inA['id'] ?? ''), $PID]);
        if ($url) { try { $r = comp_web_check((string)$inA['id']); } catch (Throwable $e) { fail('Đã lưu website nhưng chưa đọc được: ' . $e->getMessage(), 424); } }
        respond(['ok' => true]);
    }
    session_write_close();
    if (!empty($inA['id'])) need('competitors', (string)$inA['id'], 'đối thủ');
    $stW = $pdo->prepare("SELECT id FROM competitors WHERE project_id=? AND COALESCE(website,'')<>''"); $stW->execute([$PID]);
    $ids = !empty($inA['id']) ? [(string)$inA['id']] : array_column($stW->fetchAll(), 'id');
    $res = [];
    foreach ($ids as $id) { try { $res[] = ['id' => $id, 'ok' => true] + comp_web_check($id); } catch (Throwable $e) { $res[] = ['id' => $id, 'ok' => false, 'error' => $e->getMessage()]; } }
    respond(['results' => $res]);
}

if ($method === 'POST' && in_array(($inA = json_input())['action'] ?? '', ['place_search', 'place_link', 'place_unlink', 'place_refresh'], true)) {
    $a = $inA['action'];
    if ($a === 'place_search') {
        $q = trim((string)($inA['q'] ?? ''));
        if ($q === '') fail('Nhập tên / địa chỉ cần tìm');
        $d = places_call('POST', 'places:searchText', implode(',', array_map(fn($f) => 'places.' . $f, explode(',', PLACE_FIELDS))),
            ['textQuery' => $q, 'languageCode' => 'vi', 'regionCode' => 'VN', 'pageSize' => 10]);
        respond(['places' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['displayName']['text'] ?? '', 'address' => $p['formattedAddress'] ?? '',
            'rating' => $p['rating'] ?? null, 'reviews' => $p['userRatingCount'] ?? 0, 'url' => $p['googleMapsUri'] ?? null], $d['places'] ?? [])]);
    }
    if ($a === 'place_refresh') {
        $stP = $pdo->prepare("SELECT id, place_id FROM competitors WHERE project_id=? AND COALESCE(place_id,'')<>''"); $stP->execute([$PID]); $rows = $stP->fetchAll();
        foreach ($rows as $r) place_save($r['id'], places_call('GET', 'places/' . rawurlencode($r['place_id']), PLACE_FIELDS));
        respond(['updated' => count($rows)]);
    }
    $cid = (string)($inA['id'] ?? '');
    need('competitors', $cid, 'đối thủ');
    if ($a === 'place_unlink') {
        $pdo->prepare('UPDATE competitors SET place_id=NULL, place_name=NULL, place_rating=NULL, place_reviews=NULL, place_address=NULL, place_url=NULL, place_synced_at=NULL WHERE id=?')->execute([$cid]);
        respond(['ok' => true]);
    }
    $pid = trim((string)($inA['place_id'] ?? ''));
    if ($pid === '') fail('Thiếu place_id');
    place_save($cid, places_call('GET', 'places/' . rawurlencode($pid), PLACE_FIELDS));
    log_activity('update', 'competitor', $cid, 'Gắn Google Maps cho đối thủ');
    respond(['ok' => true]);
}

if ($method === 'POST') {
    $in = json_input();
    $id = $in['id'] ?? null;
    $map = ['name' => 'name', 'sov' => 'share_of_voice', 'spendEst' => 'spend_estimate', 'move' => 'latest_move', 'threat' => 'threat'];

    if ($id) {
        need('competitors', $id, 'đối thủ');
        $set = []; $vals = [];
        foreach ($map as $jsKey => $col) if (array_key_exists($jsKey, $in)) { $set[] = "$col=?"; $vals[] = $jsKey === 'sov' ? (int)$in[$jsKey] : $in[$jsKey]; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')";
        $vals[] = $id; $vals[] = $PID;
        $pdo->prepare('UPDATE competitors SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($vals);
        log_activity('update', 'competitor', $id, 'Cập nhật đối thủ ' . mb_substr((string)(($in['name'] ?? '') ?: $id), 0, 80));
        respond(['ok' => true]);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên đối thủ');
    $newId = gen_id('CP');
    $pdo->prepare('INSERT INTO competitors(id,project_id,name,share_of_voice,spend_estimate,latest_move,threat)
        VALUES (?,?,?,?,?,?,?)')->execute([
        $newId, $PID, $name, (int)($in['sov'] ?? 0), $in['spendEst'] ?? '',
        $in['move'] ?? '', $in['threat'] ?? '—',
    ]);
    log_activity('create', 'competitor', $newId, 'Thêm đối thủ ' . mb_substr((string)$name, 0, 80));
    respond(['id' => $newId], 201);
}

if ($method === 'DELETE') {
    require_project_write();
    $id = $_GET['id'] ?? null;
    if (!$id) fail('Thiếu id');
    need('competitors', $id, 'đối thủ');
    $pdo->prepare('DELETE FROM competitors WHERE id=? AND project_id=?')->execute([$id, pid()]);
    log_activity('delete', 'competitor', $id, 'Xoá đối thủ ' . $id);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
