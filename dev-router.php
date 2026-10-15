<?php
/* Router cho máy chủ PHP tích hợp (`php -S … dev-router.php`) — CHỈ dùng khi chạy local / qua đường hầm ngrok.
   Máy chủ php -S phục vụ mọi file trong thư mục app, kể cả data/marketing.sqlite, data/initial-passwords.txt,
   config.local.php, schema.sql… Router này chỉ cho đi qua đúng những gì web server thật được phép phục vụ
   (danh sách cho phép, không phải danh sách chặn — Windows không phân biệt hoa/thường nên danh sách chặn dễ bị lách):
     /  và  /index.html          giao diện
     /api/<tên>.php              endpoint (file bắt đầu bằng _ là thư viện nội bộ, không gọi trực tiếp)
     /uploads/<file>             ảnh/video đã tải lên, trừ file chạy được (.php…)
   Mọi đường dẫn khác trả 404. Khi triển khai VPS dùng quy tắc tương đương trong vhost (xem README). */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

$ok = $path === '/' || $path === '/index.html' || in_array($path, ['/privacy.html', '/terms.html', '/data-deletion.html'], true)
    || preg_match('#^/api/[a-z0-9][a-z0-9_]*\.php$#', $path)
    || (preg_match('#^/uploads/(?:[A-Za-z0-9_-]+/)?(?!\.+$)[A-Za-z0-9._-]+$#', $path) && !preg_match('#\.(php\d?|phtml|phar|htaccess)$#i', $path));

if (!$ok) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    return true;
}

// Kiểm tra cấu hình kết nối API server online
$serverApi = '';
$cfgLocal = __DIR__ . '/config.local.php';
if (file_exists($cfgLocal)) {
    $c = @include $cfgLocal;
    if (is_array($c) && !empty($c['server_api_url'])) {
        $serverApi = rtrim((string)$c['server_api_url'], '/');
    }
}

// Nếu có server_api_url và request là API hoặc Uploads -> Proxy sang server online
if ($serverApi !== '' && (str_starts_with($path, '/api/') || str_starts_with($path, '/uploads/'))) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $targetUrl = $serverApi . $path . ($query !== '' ? '?' . $query : '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $fwdHeaders = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $hName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            if (in_array(strtolower($hName), ['host', 'accept-encoding', 'content-length'], true)) continue;
            $fwdHeaders[] = "$hName: $v";
        } elseif ($k === 'CONTENT_TYPE') {
            $fwdHeaders[] = "Content-Type: $v";
        }
    }

    $ch = curl_init($targetUrl);
    $ca = 'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $fwdHeaders,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
    ];
    if (file_exists($ca)) {
        $opts[CURLOPT_CAINFO] = $ca;
    }

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $opts[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);

    if ($response === false) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Không thể kết nối đến server API online: ' . curl_error($ch)], JSON_UNESCAPED_UNICODE);
        return true;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    http_response_code($httpCode);
    $headerLines = explode("\r\n", $rawHeaders);
    foreach ($headerLines as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookieLine = preg_replace('/;\s*Path=[^;]+/i', '; path=/', $line);
            $cookieLine = preg_replace('/;\s*Secure\b/i', '', $cookieLine);
            $cookieLine = preg_replace('/;\s*Domain=[^;]+/i', '', $cookieLine);
            header($cookieLine, false);
        } elseif (stripos($line, 'Content-Type:') === 0 || stripos($line, 'Location:') === 0) {
            header($line, true);
        }
    }

    echo $body;
    return true;
}

return false; // để php -S tự phục vụ / chạy file local
