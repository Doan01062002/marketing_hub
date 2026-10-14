<?php
/**
 * Lớp AI dùng chung — hỗ trợ Claude (Anthropic) và ChatGPT (OpenAI).
 * Nạp SAU _boot.php (cần $pdo, $CFG). Các hàm không in ra gì và không ném lỗi.
 *
 *   ai_available(): ['ok','provider','model','error']
 *   ai_generate(string $system, array $messages, array $opts = []):
 *       ['ok','text','json','provider','model','error','code', (+ 'status','usage')]
 *       $messages = [['role'=>'user'|'assistant','content'=>string], …]
 *       $opts: json_schema (array|null), max_tokens, temperature, purpose, provider ('anthropic'|'openai'),
 *              effort ('low'|'medium'|'high'), cache_system (bool, Anthropic prompt cache), timeout (giây)
 *       code ∈ no_api_key | auth | rate_limit | overloaded | timeout | refusal | bad_response | http
 *
 * Cấu hình (bảng app_settings, nhập ở Thiết lập > AI & khoá API):
 *   ai.provider            auto | anthropic | openai
 *   ai.provider_preference anthropic | openai  (thứ tự ưu tiên khi auto; mặc định anthropic)
 *   ai.anthropic_key / ai.openai_key            (dự phòng: $CFG['anthropic_api_key'] / $CFG['openai_api_key'])
 *   ai.anthropic_model / ai.openai_model
 */

const AI_PROVIDERS = ['anthropic', 'openai'];
const AI_PROVIDER_NAMES = ['anthropic' => 'Claude (Anthropic)', 'openai' => 'ChatGPT (OpenAI)'];
const AI_DEFAULT_OPENAI_MODEL = 'gpt-6.1-sol';
const AI_SETUP_HINT = 'Quản trị viên vào Thiết lập > AI & khoá API để nhập khoá.';

/** Đọc trực tiếp app_settings (không cache — để đọc lại đúng ngay sau khi lưu trong cùng request). */
function ai_setting(string $key, ?string $default = null): ?string {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT value FROM app_settings WHERE key=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || trim((string)$v) === '') ? $default : trim((string)$v);
    } catch (Throwable $e) { return $default; }
}

/** Khoá API của một nhà cung cấp: [key|null, nguồn 'app'|'config'|null]. */
function ai_key(string $provider): array {
    global $CFG;
    $k = ai_setting("ai.{$provider}_key");
    if ($k) return [$k, 'app'];
    $c = trim((string)($CFG[$provider === 'openai' ? 'openai_api_key' : 'anthropic_api_key'] ?? ''));
    return $c !== '' ? [$c, 'config'] : [null, null];
}

function ai_default_model(string $provider): string {
    global $CFG;
    if ($provider === 'openai') return trim((string)($CFG['openai_model'] ?? '')) ?: AI_DEFAULT_OPENAI_MODEL;
    return trim((string)($CFG['anthropic_model'] ?? '')) ?: 'claude-opus-5-5';
}
function ai_model(string $provider): string {
    return ai_setting("ai.{$provider}_model") ?: ai_default_model($provider);
}

/** Chọn nhà cung cấp. $force = ép một nhà cung cấp (bỏ qua ai.provider). */
function ai_resolve(?string $force = null): array {
    try {
        $mode = $force ?: (ai_setting('ai.provider', 'auto') ?: 'auto');
        if (in_array($mode, AI_PROVIDERS, true)) {
            [$k] = ai_key($mode);
            if (!$k) return ['ok' => false, 'provider' => $mode, 'model' => ai_model($mode), 'key' => null,
                'error' => 'Chưa có khoá API cho ' . AI_PROVIDER_NAMES[$mode] . '. ' . AI_SETUP_HINT];
            return ['ok' => true, 'provider' => $mode, 'model' => ai_model($mode), 'key' => $k, 'error' => null];
        }
        $pref = ai_setting('ai.provider_preference', 'anthropic');
        $order = $pref === 'openai' ? ['openai', 'anthropic'] : ['anthropic', 'openai'];
        foreach ($order as $p) {
            [$k] = ai_key($p);
            if ($k) return ['ok' => true, 'provider' => $p, 'model' => ai_model($p), 'key' => $k, 'error' => null];
        }
        return ['ok' => false, 'provider' => null, 'model' => null, 'key' => null,
            'error' => 'Chưa cấu hình AI (chưa có khoá Claude hoặc ChatGPT). ' . AI_SETUP_HINT];
    } catch (Throwable $e) {
        return ['ok' => false, 'provider' => null, 'model' => null, 'key' => null, 'error' => 'Không đọc được cấu hình AI.'];
    }
}

function ai_available(): array {
    $r = ai_resolve();
    unset($r['key']);
    return $r;
}

/* ---------------- Chuẩn hoá đầu vào ---------------- */

/** Bắt đầu bằng user, xen kẽ vai, gộp lượt liền nhau cùng vai, bỏ lượt rỗng. */
function ai_norm_messages(array $messages): array {
    $out = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($m['content'] ?? ''));
        if ($text === '') continue;
        if (!$out && $role === 'assistant') continue;
        if ($out && $out[count($out) - 1]['role'] === $role) { $out[count($out) - 1]['content'] .= "\n\n" . $text; continue; }
        $out[] = ['role' => $role, 'content' => $text];
    }
    // Không được kết thúc bằng assistant (prefill bị từ chối trên model mới)
    while ($out && $out[count($out) - 1]['role'] === 'assistant') array_pop($out);
    return $out;
}

/** Schema cho OpenAI strict: mọi object có additionalProperties:false, required = tất cả thuộc tính; bỏ từ khoá không hỗ trợ. */
function ai_openai_schema(array $s): array {
    foreach (['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern', 'format', 'default', '$schema'] as $k) unset($s[$k]);
    if (($s['type'] ?? null) === 'object' || isset($s['properties'])) {
        $props = (array)($s['properties'] ?? []);
        foreach ($props as $k => $v) $props[$k] = is_array($v) ? ai_openai_schema($v) : $v;
        $s['properties'] = $props ?: new stdClass();
        $s['required'] = array_keys($props);
        $s['additionalProperties'] = false;
    }
    if (isset($s['items']) && is_array($s['items'])) $s['items'] = ai_openai_schema($s['items']);
    foreach (['anyOf', 'oneOf', 'allOf'] as $k) if (isset($s[$k]) && is_array($s[$k])) $s[$k] = array_map(fn($x) => is_array($x) ? ai_openai_schema($x) : $x, $s[$k]);
    return $s;
}

/* ---------------- HTTP ---------------- */

/** POST JSON. Trả về [status, decoded|null, headers(lowercase), curlErrno, curlError]. */
function ai_http_post(string $url, array $headers, array $payload, int $timeout): array {
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch); $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, null, $respHeaders, $errno, $err];
    $d = json_decode((string)$raw, true);
    return [$status, is_array($d) ? $d : null, $respHeaders, 0, ''];
}

function ai_fail(string $code, string $error, ?string $provider, ?string $model, int $status = 502, string $detail = ''): array {
    return ['ok' => false, 'text' => '', 'json' => null, 'provider' => $provider, 'model' => $model,
        'error' => $error, 'code' => $code, 'status' => $status, 'detail' => mb_substr($detail, 0, 300)];
}

/* ---------------- Anthropic (Claude) ---------------- */

function ai_anthropic_supports_fallback(string $model): bool {
    return in_array($model, ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'], true);
}

function ai_anthropic_call(string $key, string $model, string $system, array $msgs, array $opts): array {
    $isHaiku = str_starts_with($model, 'claude-haiku');
    $p = ['model' => $model, 'max_tokens' => (int)($opts['max_tokens'] ?? 16000), 'messages' => $msgs];
    if ($system !== '') {
        $p['system'] = !empty($opts['cache_system'])
            ? [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]]
            : $system;
    }
    if (!empty($opts['json_schema'])) $p['output_config']['format'] = ['type' => 'json_schema', 'schema' => $opts['json_schema']];
    if (!$isHaiku) {
        // Model 4.6+: adaptive thinking; độ sâu điều khiển bằng effort. Model mới không nhận temperature khi có thinking.
        $p['thinking'] = ['type' => 'adaptive'];
        $p['output_config']['effort'] = in_array($opts['effort'] ?? '', ['low', 'medium', 'high'], true) ? $opts['effort'] : 'medium';
    } elseif (isset($opts['temperature'])) {
        $p['temperature'] = max(0.0, min(1.0, (float)$opts['temperature']));
    }
    $headers = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    if (ai_anthropic_supports_fallback($model)) {
        $p['fallbacks'] = 'default';
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $timeout = (int)($opts['timeout'] ?? 120);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        [$status, $d, $h, $errno, $cerr] = ai_http_post('https://api.anthropic.com/v1/messages', $headers, $p, $timeout);
        if ($status === 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) return ai_fail('timeout', "Claude phản hồi quá lâu (quá $timeout giây). Anh/chị thử lại hoặc rút gọn yêu cầu.", 'anthropic', $model, 504);
            return ai_fail('http', "Máy chủ không kết nối được tới Anthropic API ($cerr). Kiểm tra mạng / tường lửa của máy chủ.", 'anthropic', $model, 502);
        }
        if ($status === 200 && is_array($d)) return ai_anthropic_parse($d, $model, $opts);
        if ($attempt === 0 && in_array($status, [500, 503, 529], true)) { sleep(2); continue; }
        return ai_anthropic_error($status, $d ?? [], $h, $model);
    }
    return ai_fail('overloaded', 'Dịch vụ Claude đang quá tải. Anh/chị thử lại sau ít phút.', 'anthropic', $model, 503);
}

function ai_anthropic_error(int $status, array $d, array $h, string $model): array {
    $type = (string)($d['error']['type'] ?? '');
    $msg = (string)($d['error']['message'] ?? '');
    $f = fn(string $code, string $vi, int $s = 502) => ai_fail($code, $vi, 'anthropic', $model, $s, $msg);
    switch (true) {
        case $status === 401 || $type === 'authentication_error':
            return $f('auth', 'Anthropic từ chối khoá API (401): khoá không hợp lệ hoặc đã bị thu hồi. Tạo khoá mới tại console.anthropic.com → API Keys rồi cập nhật ở Thiết lập > AI & khoá API.');
        case $status === 402 || $type === 'billing_error' || stripos($msg, 'credit balance') !== false:
            return $f('http', 'Tài khoản Anthropic hết hạn mức / chưa nạp tiền. Kiểm tra mục Billing tại console.anthropic.com.');
        case $status === 403 || $type === 'permission_error':
            return $f('http', 'Khoá API Anthropic không có quyền dùng model hoặc tính năng này (403).');
        case $status === 404 || $type === 'not_found_error':
            return $f('http', "Anthropic không tìm thấy model \"$model\" (404). Sửa tên model ở Thiết lập > AI & khoá API.");
        case $status === 413 || $type === 'request_too_large':
            return $f('http', 'Yêu cầu gửi Claude quá dài. Anh/chị rút gọn nội dung hoặc bắt đầu cuộc trò chuyện mới.', 413);
        case $status === 429 || $type === 'rate_limit_error':
            $ra = $h['retry-after'] ?? '';
            return $f('rate_limit', 'Đã chạm giới hạn tốc độ của Anthropic (429)' . ($ra !== '' ? ", thử lại sau khoảng $ra giây." : ', thử lại sau ít phút.'), 429);
        case $status === 529 || $type === 'overloaded_error':
            return $f('overloaded', 'Dịch vụ Claude đang quá tải (529). Anh/chị thử lại sau ít phút.', 503);
        case $status >= 500:
            return $f('overloaded', "Dịch vụ Claude gặp lỗi tạm thời ($status). Anh/chị thử lại sau.", 503);
        default:
            return $f('http', "Anthropic từ chối yêu cầu ($status): " . mb_substr($msg ?: 'không rõ lý do', 0, 200));
    }
}

function ai_anthropic_parse(array $d, string $model, array $opts): array {
    $used = (string)($d['model'] ?? $model);
    $stop = (string)($d['stop_reason'] ?? '');
    if ($stop === 'refusal') return ai_fail('refusal', 'AI từ chối trả lời yêu cầu này. Anh/chị diễn đạt lại theo hướng công việc marketing nhé.', 'anthropic', $used, 422);
    $text = '';
    foreach ($d['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= (string)$b['text'];
    return ai_finish($text, $stop === 'max_tokens', 'anthropic', $used, $opts, $d['usage'] ?? []);
}

/* ---------------- OpenAI (ChatGPT) — Responses API ---------------- */

function ai_openai_is_reasoning(string $model): bool {
    return (bool)preg_match('/^(o\d|gpt-[5-9])/i', $model);
}

function ai_openai_call(string $key, string $model, string $system, array $msgs, array $opts): array {
    $p = ['model' => $model, 'input' => $msgs, 'max_output_tokens' => (int)($opts['max_tokens'] ?? 16000), 'store' => false];
    if ($system !== '') $p['instructions'] = $system;
    if (!empty($opts['json_schema'])) {
        $p['text'] = ['format' => ['type' => 'json_schema', 'name' => 'ket_qua', 'schema' => ai_openai_schema($opts['json_schema']), 'strict' => true]];
    }
    if (ai_openai_is_reasoning($model)) {
        $p['reasoning'] = ['effort' => in_array($opts['effort'] ?? '', ['low', 'medium', 'high'], true) ? $opts['effort'] : 'medium'];
    } elseif (isset($opts['temperature'])) {
        $p['temperature'] = max(0.0, min(2.0, (float)$opts['temperature']));
    }
    $timeout = (int)($opts['timeout'] ?? 120);
    $headers = ['Authorization: Bearer ' . $key];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        [$status, $d, $h, $errno, $cerr] = ai_http_post('https://api.openai.com/v1/responses', $headers, $p, $timeout);
        if ($status === 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) return ai_fail('timeout', "ChatGPT phản hồi quá lâu (quá $timeout giây). Anh/chị thử lại hoặc rút gọn yêu cầu.", 'openai', $model, 504);
            return ai_fail('http', "Máy chủ không kết nối được tới OpenAI API ($cerr). Kiểm tra mạng / tường lửa của máy chủ.", 'openai', $model, 502);
        }
        if ($status === 200 && is_array($d)) return ai_openai_parse($d, $model, $opts);
        if ($attempt === 0 && in_array($status, [500, 502, 503], true)) { sleep(2); continue; }
        return ai_openai_error($status, $d ?? [], $h, $model);
    }
    return ai_fail('overloaded', 'Dịch vụ ChatGPT đang quá tải. Anh/chị thử lại sau ít phút.', 'openai', $model, 503);
}

function ai_openai_error(int $status, array $d, array $h, string $model): array {
    $e = is_array($d['error'] ?? null) ? $d['error'] : [];
    $code = (string)($e['code'] ?? ''); $type = (string)($e['type'] ?? '');
    $msg = (string)($e['message'] ?? '');
    $f = fn(string $c, string $vi, int $s = 502) => ai_fail($c, $vi, 'openai', $model, $s, $msg);
    switch (true) {
        case $status === 401 || $code === 'invalid_api_key':
            return $f('auth', 'OpenAI từ chối khoá API (401): khoá không hợp lệ hoặc đã bị thu hồi. Tạo khoá mới tại platform.openai.com → API keys rồi cập nhật ở Thiết lập > AI & khoá API.');
        case $code === 'insufficient_quota' || $type === 'insufficient_quota':
            return $f('rate_limit', 'Tài khoản OpenAI đã hết hạn mức / chưa nạp tiền (429 insufficient_quota). Kiểm tra mục Billing tại platform.openai.com.', 429);
        case $status === 429:
            $ra = $h['retry-after'] ?? '';
            return $f('rate_limit', 'Đã chạm giới hạn tốc độ của OpenAI (429)' . ($ra !== '' ? ", thử lại sau khoảng $ra giây." : ', thử lại sau ít phút.'), 429);
        case $status === 403:
            return $f('http', 'Khoá OpenAI không có quyền dùng model này hoặc khu vực không được hỗ trợ (403).');
        case $status === 404 || $code === 'model_not_found':
            return $f('http', "OpenAI không tìm thấy model \"$model\" hoặc khoá chưa được dùng model này (404). Sửa tên model ở Thiết lập > AI & khoá API.");
        case $status >= 500:
            return $f('overloaded', "Dịch vụ ChatGPT gặp lỗi tạm thời ($status). Anh/chị thử lại sau.", 503);
        default:
            return $f('http', "OpenAI từ chối yêu cầu ($status): " . mb_substr($msg ?: 'không rõ lý do', 0, 200));
    }
}

function ai_openai_parse(array $d, string $model, array $opts): array {
    $used = (string)($d['model'] ?? $model);
    $text = ''; $refused = false;
    foreach ($d['output'] ?? [] as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach ($item['content'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'output_text') $text .= (string)($c['text'] ?? '');
            elseif (($c['type'] ?? '') === 'refusal') $refused = true;
        }
    }
    if ($refused && trim($text) === '') return ai_fail('refusal', 'AI từ chối trả lời yêu cầu này. Anh/chị diễn đạt lại theo hướng công việc marketing nhé.', 'openai', $used, 422);
    $truncated = ($d['status'] ?? '') === 'incomplete' && (($d['incomplete_details']['reason'] ?? '') === 'max_output_tokens');
    if (($d['status'] ?? '') === 'incomplete' && ($d['incomplete_details']['reason'] ?? '') === 'content_filter') {
        return ai_fail('refusal', 'Bộ lọc nội dung của OpenAI chặn câu trả lời. Anh/chị diễn đạt lại yêu cầu.', 'openai', $used, 422);
    }
    $u = $d['usage'] ?? [];
    return ai_finish($text, $truncated, 'openai', $used, $opts, ['input_tokens' => $u['input_tokens'] ?? null, 'output_tokens' => $u['output_tokens'] ?? null]);
}

/* ---------------- Chung ---------------- */

function ai_finish(string $text, bool $truncated, string $provider, string $model, array $opts, array $usage): array {
    $json = null;
    if (!empty($opts['json_schema'])) {
        $json = json_decode($text, true);
        if (!is_array($json)) {
            return $truncated
                ? ai_fail('bad_response', 'Câu trả lời quá dài nên bị cắt giữa chừng. Anh/chị thu hẹp yêu cầu rồi thử lại.', $provider, $model)
                : ai_fail('bad_response', 'Không đọc được câu trả lời của AI. Anh/chị thử lại.', $provider, $model);
        }
    } elseif (trim($text) === '') {
        return ai_fail('bad_response', $truncated ? 'AI chưa kịp viết xong (hết giới hạn độ dài). Anh/chị thử lại.' : 'AI trả về nội dung rỗng. Anh/chị thử lại.', $provider, $model);
    }
    return ['ok' => true, 'text' => $text, 'json' => $json, 'provider' => $provider, 'model' => $model,
        'error' => null, 'code' => null, 'status' => 200, 'truncated' => $truncated,
        'usage' => array_filter($usage, fn($v) => $v !== null)];
}

function ai_generate(string $system, array $messages, array $opts = []): array {
    try {
        $force = in_array($opts['provider'] ?? null, AI_PROVIDERS, true) ? $opts['provider'] : null;
        $r = ai_resolve($force);
        if (!$r['ok']) return ai_fail('no_api_key', $r['error'], $r['provider'], $r['model'], 503);
        $msgs = ai_norm_messages($messages);
        if (!$msgs) return ai_fail('bad_response', 'Chưa có nội dung để gửi cho AI.', $r['provider'], $r['model'], 400);
        $out = $r['provider'] === 'openai'
            ? ai_openai_call($r['key'], $r['model'], $system, $msgs, $opts)
            : ai_anthropic_call($r['key'], $r['model'], $system, $msgs, $opts);
        if (!$out['ok'] && function_exists('log_activity') && empty($opts['no_log'])) {
            log_activity('error', 'ai', null, ($opts['purpose'] ?? 'ai') . ' · ' . $out['provider'] . '/' . $out['model'] . ' · ' . $out['code'] . ': ' . $out['error']);
        }
        return $out;
    } catch (Throwable $e) {
        return ai_fail('http', 'Lỗi nội bộ khi gọi AI: ' . mb_substr($e->getMessage(), 0, 150), null, null, 500);
    }
}

/** Gọi thử rất ngắn tới một nhà cung cấp bằng khoá đã lưu. */
function ai_test(string $provider): array {
    $t = microtime(true);
    $r = ai_generate('Bạn là trợ lý kiểm tra kết nối.', [['role' => 'user', 'content' => 'Trả lời đúng một từ: OK']],
        ['provider' => $provider, 'max_tokens' => 1024, 'effort' => 'low', 'purpose' => 'test', 'timeout' => 45, 'no_log' => true]);
    $ms = (int)round((microtime(true) - $t) * 1000);
    // Bị cắt/rỗng nghĩa là đã gọi được API (khoá đúng) — vẫn tính là kết nối thành công.
    $ok = $r['ok'] || $r['code'] === 'bad_response';
    return ['ok' => $ok, 'provider' => $provider, 'model' => $r['model'], 'ms' => $ms,
        'reply' => $r['ok'] ? mb_substr(trim($r['text']), 0, 60) : null,
        'error' => $ok ? null : $r['error'], 'code' => $ok ? null : $r['code'], 'detail' => $ok ? null : ($r['detail'] ?? null)];
}
