<?php
/* KẾT NỐI NHANH WEBSITE: người dùng dán link → đăng nhập Google một lần (quyền Search Console + Analytics)
   → tự tìm thuộc tính Search Console và property GA4 của đúng tên miền đó, tạo kênh và đồng bộ ngay.
   Gọi từ oauth_google_callback.php khi $_SESSION['quick_web'] khớp kênh đang kết nối. */
require_once __DIR__ . '/_metrics_lib.php';

/** Tên miền chuẩn hoá (bỏ giao thức, www, đường dẫn). */
function qw_domain(string $url): string {
    $url = trim(strtolower($url));
    if (str_starts_with($url, 'sc-domain:')) $url = substr($url, 10);
    if (!preg_match('#^[a-z]+://#', $url)) $url = 'http://' . $url;
    $h = (string)parse_url($url, PHP_URL_HOST);
    return preg_replace('/^www\./', '', $h);
}

/** Tên miền (hoặc tên miền con) của $host có thuộc $dom không. */
function qw_match(string $host, string $dom): bool {
    $host = qw_domain($host);
    return $host === $dom || str_ends_with($host, '.' . $dom) || str_ends_with($dom, '.' . $host);
}

function quick_web_finish(array $CFG, array $conn, array $tok): array {
    global $pdo;
    $dom = (string)($_SESSION['quick_web']['domain'] ?? '');
    $auth = ['Authorization: Bearer ' . $tok['access_token']];
    $msgs = []; $ok = 0;

    // 1. Search Console: ưu tiên thuộc tính Miền (sc-domain:) rồi tới tiền tố URL.
    [$c, $d] = http_get_json('https://www.googleapis.com/webmasters/v3/sites', $auth);
    $site = null;
    foreach ($d['siteEntry'] ?? [] as $x) {
        if (($x['permissionLevel'] ?? '') === 'siteUnverifiedUser') continue;
        if (!qw_match($x['siteUrl'], $dom)) continue;
        if (!$site || str_starts_with($x['siteUrl'], 'sc-domain:')) $site = $x['siteUrl'];
    }
    if ($site) {
        $dup = $pdo->prepare("SELECT id FROM connections WHERE project_id=? AND provider='gsc' AND external_account_id=? AND id<>?");
        $dup->execute([$conn['project_id'] ?? null, $site, $conn['id']]);
        if ($old = $dup->fetchColumn()) { // đã có kênh này → cập nhật token cho kênh cũ, bỏ kênh tạm
            $pdo->prepare("UPDATE connections SET status='connected', auth_method='oauth', access_token=?, refresh_token=COALESCE(?, refresh_token), expires_at=?, last_error=NULL WHERE id=?")
                ->execute([$tok['access_token'], $tok['refresh_token'] ?? null, date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600)), $old]);
            $pdo->prepare('DELETE FROM connections WHERE id=?')->execute([$conn['id']]);
            $gscId = $old;
        } else {
            $pdo->prepare('UPDATE connections SET external_account_id=?, label=? WHERE id=?')->execute([$site, $dom, $conn['id']]);
            $gscId = $conn['id'];
        }
        try { sync_connection($CFG, get_connection($gscId, true)); $msgs[] = "Search Console: $site ✓"; $ok++; }
        catch (Throwable $e) { $msgs[] = "Search Console: $site — " . $e->getMessage(); }
    } else {
        $pdo->prepare('DELETE FROM connections WHERE id=?')->execute([$conn['id']]);
        $msgs[] = $c === 200 ? "Search Console: tài khoản Google này chưa có website $dom (thêm & xác minh ở search.google.com/search-console)"
            : 'Search Console: ' . ($d['error']['message'] ?? "HTTP $c") . ($c === 403 ? ' — bật Google Search Console API trong project Google Cloud' : '');
    }

    // 2. GA4: duyệt property, xem luồng dữ liệu web nào trỏ về tên miền này.
    [$c, $d] = http_get_json('https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', $auth);
    $found = null; $checked = 0;
    if ($c === 200) {
        foreach ($d['accountSummaries'] ?? [] as $acc) {
            foreach ($acc['propertySummaries'] ?? [] as $prop) {
                if ($found || $checked >= 60) break 2;
                $checked++;
                [$c2, $s] = http_get_json('https://analyticsadmin.googleapis.com/v1beta/' . $prop['property'] . '/dataStreams?pageSize=50', $auth);
                foreach ($s['dataStreams'] ?? [] as $st) {
                    $uri = $st['webStreamData']['defaultUri'] ?? '';
                    if ($uri !== '' && qw_match($uri, $dom)) { $found = ['id' => str_replace('properties/', '', $prop['property']), 'name' => $prop['displayName']]; break; }
                }
            }
        }
        if ($found) {
            $ex = $pdo->prepare("SELECT id FROM connections WHERE project_id=? AND provider='ga4' AND external_account_id=?");
            $ex->execute([$conn['project_id'] ?? null, $found['id']]);
            $gid = $ex->fetchColumn();
            $exp = date('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 3600));
            if ($gid) $pdo->prepare("UPDATE connections SET status='connected', auth_method='oauth', access_token=?, refresh_token=COALESCE(?, refresh_token), expires_at=?, last_error=NULL WHERE id=?")
                ->execute([$tok['access_token'], $tok['refresh_token'] ?? null, $exp, $gid]);
            else {
                $gid = gen_id('CH');
                $pdo->prepare("INSERT INTO connections(id,project_id,provider,label,status,auth_method,external_account_id,access_token,refresh_token,expires_at,connected_by,connected_at)
                    VALUES (?,?,'ga4',?,'connected','oauth',?,?,?,?,?,datetime('now'))")
                    ->execute([$gid, $conn['project_id'] ?? null, $dom . ' (GA4)', $found['id'], $tok['access_token'], $tok['refresh_token'] ?? null, $exp, current_user()['id'] ?? null]);
            }
            try { sync_connection($CFG, get_connection($gid, true)); $msgs[] = "Google Analytics: {$found['name']} ({$found['id']}) ✓"; $ok++; }
            catch (Throwable $e) { $msgs[] = "Google Analytics: {$found['name']} — " . $e->getMessage(); }
        } else $msgs[] = "Google Analytics: không thấy property GA4 nào có luồng web trỏ về $dom" . ($checked >= 60 ? ' (đã xét 60 property đầu)' : '');
    } else {
        $msgs[] = 'Google Analytics: ' . ($d['error']['message'] ?? "HTTP $c") . ($c === 403 ? ' — bật Google Analytics Admin API + Data API trong project Google Cloud' : '');
    }

    log_activity('create', 'connection', null, "Kết nối nhanh website $dom: " . implode(' · ', $msgs));
    return $ok ? ['conn_msg' => implode(' · ', $msgs)] : ['conn_error' => implode(' · ', $msgs)];
}
