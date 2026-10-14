<?php
/* Gửi email (SMTP tự viết — hàm mail() của PHP không chạy trên Windows) và webhook.
   Cần _boot.php đã nạp (setting_get, http_call).
   Cấu hình (bảng app_settings, chỉnh ở Thiết lập → Gửi tin & thông báo):
     smtp.host, smtp.port (465 = SSL, 587 = STARTTLS), smtp.secure (ssl|tls|none), smtp.user,
     smtp.password (bí mật), smtp.from_name, smtp.from_email
     webhook.default_url, notify.quiet_hours ("22:00-07:00"), brand.hotline */

function mail_config(): array {
    $port = (int)setting_get('smtp.port', 587);
    $secure = (string)setting_get('smtp.secure', $port === 465 ? 'ssl' : ($port === 25 ? 'none' : 'tls'));
    return [
        'host' => trim((string)setting_get('smtp.host', '')),
        'port' => $port ?: 587,
        'secure' => in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'tls',
        'user' => trim((string)setting_get('smtp.user', '')),
        'password' => (string)setting_get('smtp.password', ''),
        'from_name' => (string)setting_get('smtp.from_name', 'Phòng Marketing'),
        'from_email' => trim((string)setting_get('smtp.from_email', '')) ?: trim((string)setting_get('smtp.user', '')),
    ];
}

function mail_configured(): bool {
    $c = mail_config();
    return $c['host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL) !== false;
}

function mail_encode_header(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Gửi một email. $html = null → tự tạo bản HTML đơn giản từ $text. Trả về ['ok'=>bool,'error'=>?string]. */
function mail_send(string $to, string $subject, string $text, ?string $html = null, ?array $cfgOverride = null): array {
    $c = $cfgOverride ?? mail_config();
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Địa chỉ nhận không hợp lệ: ' . $to];
    if ($c['host'] === '') return ['ok' => false, 'error' => 'Chưa cấu hình máy chủ SMTP (Thiết lập → Gửi tin & thông báo)'];
    if (!filter_var($c['from_email'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Chưa cấu hình email người gửi (smtp.from_email)'];

    $remote = ($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'error' => "Không kết nối được SMTP {$c['host']}:{$c['port']} — $errstr"];
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [(int)substr($data, 0, 3), trim($data)];
    };
    $cmd = function (string $line, array $expect) use ($fp, $read): array {
        fwrite($fp, $line . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $expect, true)) throw new RuntimeException($msg !== '' ? $msg : 'Máy chủ SMTP không phản hồi');
        return [$code, $msg];
    };

    try {
        [$code, $msg] = $read();
        if ($code !== 220) throw new RuntimeException($msg ?: 'Máy chủ SMTP không chào');
        $helo = 'marketing-hub.local';
        $cmd("EHLO $helo", [250]);
        if ($c['secure'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Không bật được STARTTLS');
            }
            $cmd("EHLO $helo", [250]);
        }
        if ($c['user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['user']), [334]);
            try { $cmd(base64_encode($c['password']), [235]); }
            catch (RuntimeException $e) { throw new RuntimeException('Sai tài khoản / mật khẩu SMTP: ' . $e->getMessage()); }
        }
        $cmd('MAIL FROM:<' . $c['from_email'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $html = $html ?? '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#1f2937">'
            . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
        $b = 'b' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_encode_header($c['from_name']) . ' <' . $c['from_email'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . mail_encode_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $c['from_email'])[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$b\"",
        ];
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$b--\r\n";
        // base64 không bao giờ có dòng chỉ chứa "." nên không cần dot-stuffing.
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'SMTP: ' . mb_substr($e->getMessage(), 0, 300)];
    }
}

/** POST JSON tới webhook. Luôn có trường "text" (Google Chat / Slack hiểu ngay), kèm dữ liệu thô cho Zapier / Make. */
function webhook_send(?string $url, string $text, array $data = []): array {
    $url = trim((string)($url ?: setting_get('webhook.default_url', '')));
    if ($url === '') return ['ok' => false, 'error' => 'Chưa có địa chỉ webhook (đặt webhook mặc định ở Thiết lập → Gửi tin)'];
    if (!preg_match('~^https?://~i', $url)) return ['ok' => false, 'error' => 'Địa chỉ webhook phải bắt đầu bằng http(s)://'];
    $payload = ['text' => $text] + ($data ? ['data' => $data] : []) + ['source' => 'Marketing Hub', 'sent_at' => date('c')];
    [$code, , $raw] = http_call('POST', $url, ['json' => $payload]);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'error' => null, 'code' => $code];
    return ['ok' => false, 'error' => $code ? "Webhook trả HTTP $code: " . mb_substr((string)$raw, 0, 200) : 'Không gọi được webhook: ' . mb_substr((string)$raw, 0, 200), 'code' => $code];
}

/** Đang trong giờ yên lặng? (notify.quiet_hours dạng "22:00-07:00", để trống = không có). */
function in_quiet_hours(?int $ts = null): bool {
    $q = trim((string)setting_get('notify.quiet_hours', ''));
    if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $q, $m)) return false;
    return hm_in_range(date('H:i', $ts ?? time()), sprintf('%02d:%02d', $m[1], $m[2]), sprintf('%02d:%02d', $m[3], $m[4]));
}

/** "HH:MM" nằm trong khoảng [from, to) — hỗ trợ khoảng qua nửa đêm. */
function hm_in_range(string $hm, string $from, string $to): bool {
    if ($from === $to) return false;
    return $from < $to ? ($hm >= $from && $hm < $to) : ($hm >= $from || $hm < $to);
}
