<?php
/* =====================================================================================
   KỊCH BẢN TỰ ĐỘNG & CHUỖI CHĂM SÓC
   - automation_event($event, $payload)  ← fire_event() trong _boot.php gọi khi có sự kiện
   - run_automations_tick($CFG)          ← cron/run.php gọi mỗi 5 phút (kịch bản theo lịch + chuỗi chăm sóc)
   Cần _boot.php đã nạp ($pdo, $CFG, notify, log_activity, setting_get…).

   automations.conditions (JSON):
     { "match":"all|any", "rules":[{"field":"comment.intent","op":"in","value":["hoi_gia"]}…],
       "params":{…tham số của trigger…}, "max_per_hour":20, "allow_negative":false, "ignore_quiet":false }
   automations.actions (JSON): [{ "type":"reply_comment", …tham số… }, …]

   An toàn:
   - Mỗi (kịch bản, sự kiện, đối tượng) chỉ chạy MỘT lần (tra automation_runs).
   - Giới hạn số lượt chạy mỗi giờ cho từng kịch bản (max_per_hour, mặc định 30).
   - Tự trả lời bình luận chỉ khi bình luận < 24 giờ, còn "mới", và KHÔNG tiêu cực / khiếu nại
     (trừ khi bật allow_negative) — nếu tiêu cực thì chuyển trưởng nhóm thay vì trả lời.
   - Giờ yên lặng (notify.quiet_hours): không gửi gì tới khách (trả lời bình luận, email cho đầu mối,
     các bước chuỗi chăm sóc chờ đến hết giờ yên lặng); kịch bản theo điều kiện định kỳ cũng chờ.
   - Nội dung gửi khách còn "[ĐIỀN" hoặc dùng {hotline} khi chưa đặt hotline → không gửi.
   - Chạy thử: đánh giá điều kiện, ghi nhật ký "skipped" mô tả việc SẼ làm, không tác động gì.
   ===================================================================================== */

require_once __DIR__ . '/_mail_lib.php';

const AUTO_TRIGGERS = [
    'comment.new' => 'Có bình luận mới',
    'lead.new' => 'Có đầu mối mới',
    'lead.state' => 'Đầu mối đổi trạng thái',
    'post.published' => 'Bài đăng thành công',
    'post.failed' => 'Bài đăng lỗi',
    'campaign.approved' => 'Chiến dịch được duyệt',
    'schedule.daily' => 'Hằng ngày vào giờ cố định',
    'schedule.weekly' => 'Hằng tuần vào thứ / giờ cố định',
    'task.overdue' => 'Công việc trễ hạn',
    'lead.no_response' => 'Đầu mối chưa được xử lý sau N giờ',
    'metric.threshold' => 'Chỉ số kênh vượt ngưỡng',
    'comments.unanswered' => 'Bình luận chưa trả lời quá N phút',
];
const AUTO_SCHEDULED = ['schedule.daily', 'schedule.weekly', 'task.overdue', 'lead.no_response', 'metric.threshold', 'comments.unanswered'];
const AUTO_ACTIONS = [
    'reply_comment' => 'Trả lời bình luận', 'hide_comment' => 'Ẩn bình luận', 'comment_to_lead' => 'Tạo đầu mối từ bình luận',
    'assign_comment' => 'Giao bình luận cho nhân viên', 'create_task' => 'Giao việc', 'notify' => 'Thông báo trong app',
    'send_email' => 'Gửi email', 'send_webhook' => 'Gửi webhook (Google Chat / Slack / Zapier…)', 'send_telegram' => 'Gửi Telegram (nhóm mặc định)',
    'enroll_sequence' => 'Đưa vào chuỗi chăm sóc', 'set_lead_state' => 'Đổi trạng thái đầu mối', 'add_lead_note' => 'Ghi chú vào đầu mối',
];
const AUTO_BOARD_FIRST = ['content' => 'IDEA', 'design' => 'BRIEF', 'ads' => 'PLAN', 'research' => 'REQUEST', 'lead' => 'CAPTURE'];
const AUTO_BOARD_LAST = ['content' => 'PUBLISHED', 'design' => 'DONE', 'ads' => 'REPORT', 'research' => 'INSIGHT', 'lead' => 'ACCEPTED'];
const AUTO_LEAD_STATES = ['CREATED' => 'Mới', 'VALIDATING' => 'Đang kiểm tra', 'ROUTED' => 'Đã định tuyến', 'DELIVERED' => 'Đã bàn giao', 'ACCEPTED' => 'CRM đã nhận', 'REJECTED' => 'CRM từ chối'];
const AUTO_INTENTS = ['hoi_gia' => 'Hỏi giá', 'dat_lich' => 'Đặt lịch', 'khieu_nai' => 'Khiếu nại', 'khen' => 'Khen', 'spam' => 'Spam', 'khac' => 'Khác'];
const SEQ_SAMPLE_NAME = 'Chăm sóc 7 ngày';

/* ---------------- tiện ích ---------------- */
function auto_db(): PDO { global $pdo; return $pdo; }
function auto_cfg(): array { global $CFG; return is_array($CFG ?? null) ? $CFG : []; }
function auto_q(string $sql, array $p = []): PDOStatement { $st = auto_db()->prepare($sql); $st->execute($p); return $st; }
function auto_row(string $sql, array $p = []): ?array { return auto_q($sql, $p)->fetch() ?: null; }
function auto_sys(): array { return ['id' => null]; }
function auto_lower(?string $s): string { return mb_strtolower(trim((string)$s), 'UTF-8'); }
function auto_list($v): array {
    if (is_array($v)) return array_values(array_filter(array_map(fn($x) => trim((string)$x), $v), fn($x) => $x !== ''));
    return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/u', (string)$v)), fn($x) => $x !== ''));
}
function auto_user(?string $id): ?array { return $id ? auto_row('SELECT id,name,email,role,active FROM users WHERE id=?', [$id]) : null; }
function auto_uname(?string $id): string { $u = auto_user($id); return $u ? $u['name'] : '—'; }
function auto_require_libs(): void {
    foreach (['_comments_lib.php', '_ai_lib.php', '_publish_lib.php'] as $f) if (is_file(__DIR__ . '/' . $f)) require_once __DIR__ . '/' . $f;
}
function auto_decode(array $a): array {
    $a['conditions'] = is_array($a['conditions'] ?? null) ? $a['conditions'] : jdecode($a['conditions'] ?? null, []);
    $a['actions'] = is_array($a['actions'] ?? null) ? $a['actions'] : jdecode($a['actions'] ?? null, []);
    return $a;
}

/** Điền biến {ten} {sdt} {email} {hotline} {nguon} {chien_dich} {noi_dung} {bai_viet} {link} {ngay} {gio} {tom_tat} {so_luong} {trang_thai} {ma} {cong_viec} {chi_so} {gia_tri} {nguoi_phu_trach}. */
function auto_tpl(?string $s, array $ctx, array &$missing = []): string {
    $s = (string)$s;
    $l = $ctx['lead'] ?? []; $c = $ctx['comment'] ?? []; $p = $ctx['post'] ?? []; $cp = $ctx['campaign'] ?? []; $t = $ctx['task'] ?? [];
    $hot = trim((string)setting_get('brand.hotline', ''));
    $vars = [
        'ten' => $c['author_name'] ?? $l['name'] ?? ($t['title'] ?? ''),
        'sdt' => $l['phone'] ?? '', 'email' => $l['email'] ?? '', 'hotline' => $hot,
        'nguon' => $l['source'] ?? ($c ? ($c['provider'] ?? '') : ''),
        'chien_dich' => $cp['name'] ?? '', 'noi_dung' => $c['message'] ?? '',
        'bai_viet' => $p['title'] ?? ($c['post_excerpt'] ?? ''), 'link' => $c['post_permalink'] ?? '',
        'ngay' => date('d/m/Y'), 'gio' => date('H:i'),
        'tom_tat' => $ctx['summary_text'] ?? '', 'so_luong' => (string)($ctx['count'] ?? ''),
        'trang_thai' => isset($ctx['to']) ? (AUTO_LEAD_STATES[$ctx['to']] ?? $ctx['to']) : (isset($l['state']) ? (AUTO_LEAD_STATES[$l['state']] ?? $l['state']) : ''),
        'ma' => $ctx['entity_id'] ?? '', 'cong_viec' => $t['title'] ?? '',
        'chi_so' => $ctx['metric']['label'] ?? '', 'gia_tri' => isset($ctx['metric']['value']) ? number_format((float)$ctx['metric']['value'], 0, ',', '.') : '',
        'nguoi_phu_trach' => $ctx['assignee_name'] ?? '',
    ];
    return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, &$missing) {
        if (!array_key_exists($m[1], $vars)) return $m[0];
        $v = (string)$vars[$m[1]];
        if ($v === '' && in_array($m[1], ['hotline', 'ten'], true)) $missing[] = $m[1];
        return $v;
    }, $s);
}

/** Kiểm tra nội dung trước khi gửi cho KHÁCH. Trả về lý do chặn hoặc null. */
function auto_customer_text_block(string $raw, string $rendered, array $missing): ?string {
    if (in_array('hotline', $missing, true)) return 'Nội dung dùng {hotline} nhưng chưa đặt số hotline (Thiết lập → Gửi tin & thông báo)';
    if (mb_stripos($rendered, '[ĐIỀN') !== false || mb_stripos($rendered, '[điền') !== false) return 'Nội dung còn chỗ "[ĐIỀN …]" chưa thay bằng thông tin thật';
    if (trim($rendered) === '') return 'Nội dung trống';
    return null;
}

/** Soạn bằng AI nếu có, nếu không dùng mẫu. Trả về [text, ghi chú]. */
function auto_ai_text(string $purpose, string $instruction, array $ctx, string $fallback): array {
    auto_require_libs();
    if (!function_exists('ai_available') || !function_exists('ai_generate') || !ai_available()) return [$fallback, 'AI chưa sẵn sàng — dùng nội dung mẫu'];
    $hot = trim((string)setting_get('brand.hotline', ''));
    $sys = 'Bạn là nhân viên chăm sóc khách hàng của phòng Marketing. Viết tiếng Việt, lịch sự, ngắn gọn, tự nhiên. '
        . 'TUYỆT ĐỐI không bịa giá, khuyến mãi, số liệu, cam kết. Khách hỏi giá thì mời nhắn tin hoặc gọi hotline'
        . ($hot !== '' ? " $hot" : '') . '. Chỉ trả về đúng nội dung tin nhắn, không giải thích.';
    $info = [];
    if (!empty($ctx['comment'])) $info[] = 'Bình luận của ' . ($ctx['comment']['author_name'] ?? 'khách') . ': "' . mb_substr((string)$ctx['comment']['message'], 0, 500) . '"';
    if (!empty($ctx['lead'])) $info[] = 'Khách: ' . $ctx['lead']['name'] . ' · nguồn ' . ($ctx['lead']['source'] ?? '') . ' · quan tâm: ' . ($ctx['lead']['subject'] ?? '') . ($ctx['lead']['notes'] ? ' · ghi chú: ' . mb_substr($ctx['lead']['notes'], -300) : '');
    if (!empty($ctx['campaign'])) $info[] = 'Chiến dịch: ' . $ctx['campaign']['name'];
    $msg = implode("\n", $info) . "\n\nYêu cầu: " . $instruction . "\nBản mẫu tham khảo:\n" . $fallback;
    try {
        $r = ai_generate($sys, [['role' => 'user', 'content' => $msg]], ['purpose' => $purpose, 'max_tokens' => 500]);
        if (!empty($r['ok']) && trim((string)($r['text'] ?? '')) !== '') return [trim($r['text']), 'AI soạn (' . ($r['provider'] ?? 'AI') . ')'];
        return [$fallback, 'AI lỗi: ' . mb_substr((string)($r['error'] ?? ''), 0, 120) . ' — dùng nội dung mẫu'];
    } catch (Throwable $e) {
        return [$fallback, 'AI lỗi — dùng nội dung mẫu'];
    }
}

/* ---------------- ngữ cảnh đối tượng ---------------- */
function auto_load_lead(?string $id): ?array { return $id ? auto_row('SELECT * FROM leads WHERE id=?', [$id]) : null; }
function auto_load_campaign(?string $id): ?array { return $id ? auto_row('SELECT * FROM campaigns WHERE id=?', [$id]) : null; }

/** Dự án của đối tượng gây sự kiện (lead / bình luận / bài đăng / chiến dịch / công việc). */
function auto_ctx_project(array $ctx): ?string {
    foreach (['lead', 'comment', 'post', 'campaign', 'task'] as $k) if (!empty($ctx[$k]['project_id'])) return (string)$ctx[$k]['project_id'];
    return $ctx['project_id'] ?? ($ctx['auto']['project_id'] ?? null);
}

function auto_build_ctx(string $event, array $payload): ?array {
    $ctx = ['event' => $event, 'payload' => $payload];
    switch ($event) {
        case 'comment.new':
            $c = auto_row('SELECT * FROM comments WHERE id=?', [(string)($payload['comment_id'] ?? '')]);
            if (!$c) return null;
            $ctx += ['entity' => 'comment', 'entity_id' => $c['id'], 'comment' => $c, 'lead' => auto_load_lead($c['lead_id'] ?? null)];
            break;
        case 'lead.new': case 'lead.state': case 'lead.no_response':
            $l = auto_load_lead((string)($payload['lead_id'] ?? ''));
            if (!$l) return null;
            $ctx += ['entity' => 'lead', 'entity_id' => $l['id'], 'lead' => $l, 'campaign' => auto_load_campaign($l['campaign_id']),
                'from' => $payload['from'] ?? null, 'to' => $payload['to'] ?? null, 'source' => $payload['source'] ?? $l['source']];
            break;
        case 'post.published': case 'post.failed':
            $p = auto_row('SELECT * FROM posts WHERE id=?', [(string)($payload['post_id'] ?? '')]);
            if (!$p) return null;
            $errs = array_column(auto_q("SELECT provider,error FROM post_targets WHERE post_id=? AND status='failed'", [$p['id']])->fetchAll(), 'error', 'provider');
            $ctx += ['entity' => 'post', 'entity_id' => $p['id'], 'post' => $p, 'campaign' => auto_load_campaign($p['campaign_id']),
                'post_errors' => $errs];
            break;
        case 'campaign.approved':
            $cp = auto_load_campaign((string)($payload['campaign_id'] ?? ''));
            if (!$cp) return null;
            $ctx += ['entity' => 'campaign', 'entity_id' => $cp['id'], 'campaign' => $cp];
            break;
        case 'task.overdue':
            $t = auto_row('SELECT * FROM tasks WHERE id=?', [(string)($payload['task_id'] ?? '')]);
            if (!$t) return null;
            $ctx += ['entity' => 'task', 'entity_id' => $t['id'], 'task' => $t, 'campaign' => auto_load_campaign($t['campaign_id']),
                'assignee' => $t['assignee_id'], 'assignee_name' => auto_uname($t['assignee_id'])];
            break;
        default:
            $ctx += ['entity' => $payload['entity'] ?? 'schedule', 'entity_id' => $payload['entity_id'] ?? date('Y-m-d')];
            foreach (['summary_text', 'count', 'metric', 'connection', 'comments_list'] as $k) if (isset($payload[$k])) $ctx[$k] = $payload[$k];
    }
    if (!isset($ctx['assignee'])) $ctx['assignee'] = $ctx['comment']['assigned_to'] ?? null;
    return $ctx;
}

/* ---------------- điều kiện ---------------- */
function auto_field(array $ctx, string $field) {
    if ($field === 'now.hm') return date('H:i');
    if ($field === 'now.weekday') return (string)date('N');
    if (in_array($field, ['from', 'to', 'source'], true)) return $ctx[$field] ?? null;
    [$obj, $key] = array_pad(explode('.', $field, 2), 2, null);
    if ($obj === 'lead' && $key === 'camp') $key = 'campaign_id';
    return $ctx[$obj][$key] ?? null;
}

function auto_rule_ok(array $ctx, array $r): bool {
    $v = auto_field($ctx, (string)($r['field'] ?? ''));
    $op = (string)($r['op'] ?? 'eq');
    $want = $r['value'] ?? '';
    $sv = auto_lower(is_scalar($v) ? (string)$v : '');
    switch ($op) {
        case 'eq': return $sv === auto_lower((string)(is_array($want) ? ($want[0] ?? '') : $want));
        case 'neq': return $sv !== auto_lower((string)(is_array($want) ? ($want[0] ?? '') : $want));
        case 'in': return in_array($sv, array_map('auto_lower', auto_list($want)), true);
        case 'nin': return !in_array($sv, array_map('auto_lower', auto_list($want)), true);
        case 'contains': return $sv !== '' && mb_strpos($sv, auto_lower((string)$want)) !== false;
        case 'contains_any': foreach (auto_list($want) as $k) if (mb_strpos($sv, auto_lower($k)) !== false) return true; return false;
        case 'not_contains_any': foreach (auto_list($want) as $k) if (mb_strpos($sv, auto_lower($k)) !== false) return false; return true;
        case 'gt': return is_numeric($v) && (float)$v > (float)$want;
        case 'gte': return is_numeric($v) && (float)$v >= (float)$want;
        case 'lt': return is_numeric($v) && (float)$v < (float)$want;
        case 'lte': return is_numeric($v) && (float)$v <= (float)$want;
        case 'empty': return $sv === '';
        case 'not_empty': return $sv !== '';
        case 'between':
            if (!preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', trim((string)$want), $m)) return false;
            $n = fn($x) => strlen($x) === 4 ? '0' . $x : $x;
            return hm_in_range((string)$v, $n($m[1]), $n($m[2]));
    }
    return false;
}

/** [đạt?, mô tả] */
function auto_conditions_ok(array $ctx, array $cond): array {
    if (!empty($cond["skip_negative"]) && !empty($ctx["comment"])
        && (($ctx["comment"]["sentiment"] ?? "") === "negative" || ($ctx["comment"]["intent"] ?? "") === "khieu_nai")) {
        return [false, "bình luận tiêu cực / khiếu nại — kịch bản này bỏ qua (để kịch bản xử lý tiêu cực lo)"];
    }
    $rules = array_values(array_filter($cond['rules'] ?? [], fn($r) => is_array($r) && !empty($r['field'])));
    if (!$rules) return [true, 'không có điều kiện'];
    $any = ($cond['match'] ?? 'all') === 'any';
    $fails = [];
    foreach ($rules as $r) {
        $ok = auto_rule_ok($ctx, $r);
        if ($any && $ok) return [true, 'đạt điều kiện ' . $r['field']];
        if (!$ok) $fails[] = $r['field'] . ' ' . ($r['op'] ?? 'eq') . ' ' . (is_array($r['value'] ?? null) ? implode('/', $r['value']) : ($r['value'] ?? ''));
    }
    if ($any) return [false, 'không đạt điều kiện nào'];
    return $fails ? [false, 'không đạt: ' . implode('; ', $fails)] : [true, 'đạt mọi điều kiện'];
}

/* ---------------- chạy một kịch bản cho một đối tượng ---------------- */
function auto_already_ran(string $autoId, string $event, string $entity, string $entityId): bool {
    return (bool)auto_row('SELECT 1 FROM automation_runs WHERE automation_id=? AND event=? AND entity=? AND entity_id=? LIMIT 1', [$autoId, $event, $entity, $entityId]);
}
function auto_log_run(string $autoId, string $event, string $entity, ?string $entityId, string $status, string $detail): void {
    auto_q('INSERT INTO automation_runs(automation_id,event,entity,entity_id,status,detail) VALUES (?,?,?,?,?,?)',
        [$autoId, $event, $entity, $entityId, $status, mb_substr($detail, 0, 2000)]);
}

/**
 * Chạy kịch bản $a (đã decode) với ngữ cảnh $ctx. $runKey = khoá chống lặp (mặc định = event).
 * Trả về ['status'=>ok|skipped|failed|duplicate|nomatch, 'detail'=>…]
 */
function auto_run(array $a, array $ctx, bool $dry = false, ?string $runKey = null): array {
    $event = $runKey ?? $ctx['event'];
    $entity = (string)($ctx['entity'] ?? '');
    $eid = (string)($ctx['entity_id'] ?? '');
    $cond = $a['conditions'];
    $logEvent = $dry ? 'test:' . $event : $event;

    if (!$dry && auto_already_ran($a['id'], $event, $entity, $eid)) return ['status' => 'duplicate', 'detail' => 'Đã chạy cho đối tượng này rồi'];
    [$ok, $why] = auto_conditions_ok($ctx, $cond);
    if (!$ok) {
        if ($dry) auto_log_run($a['id'], $logEvent, $entity, $eid, 'skipped', 'Chạy thử: KHÔNG chạy — ' . $why);
        return ['status' => 'nomatch', 'detail' => $why];
    }
    $max = (int)($cond['max_per_hour'] ?? 30);
    if (!$dry && $max > 0) {
        $n = (int)auto_row("SELECT COUNT(*) c FROM automation_runs WHERE automation_id=? AND status IN ('ok','failed') AND created_at >= datetime('now','-1 hour')", [$a['id']])['c'];
        if ($n >= $max) {
            auto_log_run($a['id'], 'limit:' . $event, $entity, $eid, 'skipped', "Vượt giới hạn $max lượt/giờ — tạm dừng, không làm gì");
            return ['status' => 'skipped', 'detail' => 'rate limit'];
        }
    }
    $ctx['quiet'] = in_quiet_hours() && empty($cond['ignore_quiet']);
    $ctx['auto'] = $a;
    $lines = []; $nOk = 0; $nFail = 0;
    foreach ($a['actions'] as $i => $act) {
        if (!is_array($act) || empty($act['type'])) continue;
        try { $r = auto_action($act, $ctx, $dry); }
        catch (Throwable $e) { $r = ['s' => 'failed', 'm' => 'Lỗi: ' . $e->getMessage()]; }
        $label = AUTO_ACTIONS[$act['type']] ?? $act['type'];
        $mark = ['ok' => '✔', 'skipped' => '–', 'failed' => '✘', 'dry' => '→'][$r['s']] ?? '?';
        $lines[] = "$mark $label: " . $r['m'];
        if ($r['s'] === 'ok') $nOk++;
        if ($r['s'] === 'failed') $nFail++;
    }
    if (!$lines) $lines[] = 'Kịch bản chưa có hành động nào';
    $head = ($dry ? 'Chạy thử (không tác động gì) — ' : '') . (AUTO_TRIGGERS[$a['trigger']] ?? $a['trigger']) . ($eid !== '' ? " · $entity $eid" : '');
    $detail = $head . "\n" . implode("\n", $lines);
    $status = $dry ? 'skipped' : ($nFail ? 'failed' : ($nOk ? 'ok' : 'skipped'));
    auto_log_run($a['id'], $logEvent, $entity, $eid, $status, $detail);
    if (!$dry) auto_q("UPDATE automations SET last_run_at=datetime('now'), run_count=run_count+1 WHERE id=?", [$a['id']]);
    return ['status' => $status, 'detail' => $detail];
}

/* ---------------- hành động ---------------- */
function auto_pick_users(array $act, array $ctx): array {
    $ids = auto_list($act['users'] ?? []);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column(auto_q("SELECT id FROM users WHERE active=1 AND id IN ($in) ORDER BY id", $ids)->fetchAll(), 'id');
    }
    $pidX = auto_ctx_project($ctx);
    $ids = $pidX ? array_column(auto_q("SELECT u.id FROM users u JOIN project_members m ON m.user_id=u.id AND m.project_id=? WHERE u.active=1 AND m.role IN ('content','ads') ORDER BY u.id", [$pidX])->fetchAll(), 'id') : [];
    if (!$ids && $pidX) $ids = array_column(auto_q("SELECT u.id FROM users u JOIN project_members m ON m.user_id=u.id AND m.project_id=? WHERE u.active=1 AND m.role IN ('owner','manager') ORDER BY u.id", [$pidX])->fetchAll(), 'id');
    return $ids ?: array_column(auto_q("SELECT id FROM users WHERE active=1 AND role='admin' ORDER BY id")->fetchAll(), 'id');
}

/** Chọn người nhận: user | round_robin | campaign_owner | assignee | creator. $dry không xoay vòng. */
function auto_assignee(array $act, array $ctx, bool $dry): ?string {
    $mode = $act['assign'] ?? 'round_robin';
    if ($mode === 'user') return auto_user($act['user'] ?? null) ? $act['user'] : null;
    if ($mode === 'campaign_owner') return $ctx['campaign']['owner_id'] ?? null;
    if ($mode === 'assignee') return $ctx['assignee'] ?? null;
    if ($mode === 'creator') return $ctx['post']['created_by'] ?? ($ctx['auto']['created_by'] ?? null);
    $pool = auto_pick_users($act, $ctx);
    if (!$pool) return null;
    $key = 'automation.rr.' . ($ctx['auto']['id'] ?? 'x');
    // setting_get có cache tĩnh → giữ con trỏ mới nhất trong biến toàn cục cho các lần gọi sau cùng tiến trình.
    $i = (int)($GLOBALS['__auto_rr'][$key] ?? setting_get($key, 0));
    $pick = $pool[$i % count($pool)];
    if (!$dry) { $GLOBALS['__auto_rr'][$key] = ($i + 1) % 100000; setting_set($key, (string)$GLOBALS['__auto_rr'][$key], auto_sys()); }
    return $pick;
}

/** Người nhận thông báo theo cấu hình. */
function auto_notify_targets(array $act, array $ctx): array {
    $to = $act['to'] ?? 'role';
    if ($to === 'user') return array_filter([$act['user'] ?? null]);
    if ($to === 'assignee') return array_filter([$ctx['assignee'] ?? null]);
    if ($to === 'campaign_owner') return array_filter([$ctx['campaign']['owner_id'] ?? null]);
    if ($to === 'creator') return array_filter([$ctx['post']['created_by'] ?? ($ctx['auto']['created_by'] ?? null)]);
    $role = $act['role'] ?? 'manager';
    if ($role === 'admin') return array_column(auto_q("SELECT id FROM users WHERE active=1 AND role='admin'")->fetchAll(), 'id');
    $pidX = auto_ctx_project($ctx);
    if ($role === 'all') return array_column($pidX ? auto_q("SELECT u.id FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=?))", [$pidX])->fetchAll() : auto_q('SELECT id FROM users WHERE active=1')->fetchAll(), 'id');
    // chủ / quản lý DỰ ÁN (+ quản trị hệ thống)
    return array_column($pidX ? auto_q("SELECT u.id FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=? AND role IN ('owner','manager')))", [$pidX])->fetchAll() : auto_q("SELECT id FROM users WHERE active=1 AND role IN ('admin','manager')")->fetchAll(), 'id');
}

function auto_link_for(array $ctx): string {
    return ['comment' => 'care', 'lead' => 'demand', 'post' => 'publish', 'campaign' => 'campaigns', 'task' => 'tasks', 'metric' => 'channels', 'comments' => 'care'][$ctx['entity'] ?? ''] ?? 'automation';
}

/** Một hành động. Trả về ['s'=>ok|skipped|failed|dry,'m'=>mô tả]. */
function auto_action(array $act, array &$ctx, bool $dry): array {
    auto_require_libs();
    $CFG = auto_cfg();
    $by = $ctx['auto']['created_by'] ?? (auto_row("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")['id'] ?? '');
    $c = $ctx['comment'] ?? null;
    $type = $act['type'];

    switch ($type) {
    case 'reply_comment': {
        if (!$c) return ['s' => 'skipped', 'm' => 'không có bình luận'];
        if (($c['status'] ?? 'new') !== 'new') return ['s' => 'skipped', 'm' => 'bình luận đã được xử lý (' . $c['status'] . ')'];
        $ts = strtotime(($c['created_time'] ?: $c['fetched_at']) . ' UTC');
        if ($ts && time() - $ts > 86400) return ['s' => 'skipped', 'm' => 'bình luận đã quá 24 giờ — không tự trả lời'];
        $neg = ($c['sentiment'] ?? '') === 'negative' || ($c['intent'] ?? '') === 'khieu_nai';
        if ($neg && empty($act['allow_negative']) && empty($ctx['auto']['conditions']['allow_negative'])) {
            if ($dry) return ['s' => 'dry', 'm' => 'bình luận tiêu cực/khiếu nại → KHÔNG trả lời, sẽ chuyển trưởng nhóm'];
            auto_q("UPDATE comments SET status='escalated' WHERE id=? AND status='new'", [$c['id']]);
            notify(null, 'Bình luận tiêu cực cần người xử lý', ($c['author_name'] ?? 'Khách') . ': ' . mb_substr((string)$c['message'], 0, 300), 'care');
            return ['s' => 'skipped', 'm' => 'bình luận tiêu cực/khiếu nại → không tự trả lời, đã chuyển trưởng nhóm'];
        }
        if ($spam = (($c['intent'] ?? '') === 'spam')) return ['s' => 'skipped', 'm' => 'bình luận spam — không trả lời'];
        if ($ctx['quiet']) return ['s' => 'skipped', 'm' => 'đang giờ yên lặng — không tự trả lời khách'];
        $missing = [];
        $text = auto_tpl($act['text'] ?? '', $ctx, $missing);
        $note = '';
        if (!empty($act['use_ai'])) [$text, $note] = $dry ? [$text, 'sẽ nhờ AI soạn dựa trên mẫu'] : auto_ai_text('reply', 'Viết câu trả lời công khai cho bình luận trên mạng xã hội (tối đa 2 câu).', $ctx, $text);
        if ($block = auto_customer_text_block((string)($act['text'] ?? ''), $text, $missing)) return ['s' => 'skipped', 'm' => $block];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ trả lời: "' . mb_substr($text, 0, 200) . '"' . ($note ? " ($note)" : '')];
        if (!function_exists('comment_reply')) return ['s' => 'skipped', 'm' => 'chưa có chức năng trả lời bình luận (api/_comments_lib.php) — nội dung định gửi: "' . mb_substr($text, 0, 150) . '"'];
        $r = comment_reply($CFG, $c, $text, (string)$by);
        if (empty($r['ok'])) return ['s' => 'failed', 'm' => 'nền tảng từ chối: ' . ($r['error'] ?? 'không rõ lỗi')];
        $ctx['comment'] = auto_row('SELECT * FROM comments WHERE id=?', [$c['id']]) ?: $c;
        return ['s' => 'ok', 'm' => 'đã trả lời "' . mb_substr($text, 0, 120) . '"' . ($note ? " ($note)" : '')];
    }
    case 'hide_comment': {
        if (!$c) return ['s' => 'skipped', 'm' => 'không có bình luận'];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ ẩn bình luận'];
        if (!function_exists('comment_hide')) return ['s' => 'skipped', 'm' => 'chưa có chức năng ẩn bình luận (api/_comments_lib.php)'];
        $r = comment_hide($CFG, $c, true);
        return empty($r['ok']) ? ['s' => 'failed', 'm' => $r['error'] ?? 'không ẩn được'] : ['s' => 'ok', 'm' => 'đã ẩn bình luận'];
    }
    case 'comment_to_lead': {
        if (!$c) return ['s' => 'skipped', 'm' => 'không có bình luận'];
        if (!empty($c['lead_id'])) { $ctx['lead'] = auto_load_lead($c['lead_id']); return ['s' => 'skipped', 'm' => 'bình luận đã gắn đầu mối ' . $c['lead_id']]; }
        $phone = null;
        if (function_exists('comment_classify')) $phone = comment_classify((string)$c['message'])['phone'] ?? null;
        if (!$phone && preg_match('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', (string)$c['message'], $m)) $phone = preg_replace('/\D/', '', $m[0]);
        if (!$phone && ($act['require_phone'] ?? true)) return ['s' => 'skipped', 'm' => 'bình luận không có số điện thoại — chưa tạo đầu mối'];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ tạo đầu mối ' . ($c['author_name'] ?? '') . ($phone ? " ($phone)" : '')];
        if (!function_exists('comment_to_lead')) return ['s' => 'skipped', 'm' => 'chưa có chức năng tạo đầu mối từ bình luận (api/_comments_lib.php)' . ($phone ? " — SĐT $phone" : '')];
        $lid = comment_to_lead($c, $phone);
        if (!$lid) return ['s' => 'failed', 'm' => 'không tạo được đầu mối'];
        $ctx['lead'] = auto_load_lead($lid);
        $ctx['comment']['lead_id'] = $lid;
        return ['s' => 'ok', 'm' => "đã tạo đầu mối $lid" . ($phone ? " ($phone)" : '')];
    }
    case 'assign_comment': {
        if (!$c) return ['s' => 'skipped', 'm' => 'không có bình luận'];
        if (!empty($c['assigned_to']) && empty($act['override'])) { $ctx['assignee'] = $c['assigned_to']; $ctx['assignee_name'] = auto_uname($c['assigned_to']); return ['s' => 'skipped', 'm' => 'đã có người phụ trách: ' . $ctx['assignee_name']]; }
        $uid = auto_assignee($act, $ctx, $dry);
        if (!$uid) return ['s' => 'skipped', 'm' => 'không tìm được người nhận'];
        $ctx['assignee'] = $uid; $ctx['assignee_name'] = auto_uname($uid);
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ giao cho ' . $ctx['assignee_name']];
        auto_q('UPDATE comments SET assigned_to=? WHERE id=?', [$uid, $c['id']]);
        return ['s' => 'ok', 'm' => 'đã giao cho ' . $ctx['assignee_name']];
    }
    case 'create_task': {
        $title = trim(auto_tpl($act['title'] ?? 'Việc từ kịch bản', $ctx));
        $board = isset(AUTO_BOARD_FIRST[$act['board'] ?? '']) ? $act['board'] : 'lead';
        $uid = auto_assignee($act, $ctx, $dry);
        $h = max(0, (float)($act['due_hours'] ?? 24));
        $dueTs = time() + (int)round($h * 3600);
        $title = mb_substr($title . ' (hạn ' . date('H:i d/m', $dueTs) . ')', 0, 200);
        $ctx['assignee'] = $uid; $ctx['assignee_name'] = $uid ? auto_uname($uid) : '';
        if ($dry) return ['s' => 'dry', 'm' => "sẽ giao việc \"$title\"" . ($uid ? ' cho ' . $ctx['assignee_name'] : ' (chưa có người nhận)')];
        $id = gen_id('T');
        $src = $ctx['lead'] ?? $ctx['campaign'] ?? [];
        auto_q("INSERT INTO tasks(id,project_id,title,co,br,board,col,type,assignee_id,assigner_id,due_date,pri,life,apr,health,campaign_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $id, auto_ctx_project($ctx) ?? ($ctx['auto']['project_id'] ?? null), $title, $src['co'] ?? 'all', $src['br'] ?? 'all', $board, AUTO_BOARD_FIRST[$board], $board,
            $uid, $ctx['auto']['created_by'] ?? null, date('Y-m-d', $dueTs), in_array($act['pri'] ?? '', ['P0', 'P1', 'P2', 'P3'], true) ? $act['pri'] : 'P1',
            'DRAFT', 'NA', 'ON_TRACK', $ctx['lead']['campaign_id'] ?? ($ctx['campaign']['id'] ?? null),
        ]);
        $ctx['task'] = auto_row('SELECT * FROM tasks WHERE id=?', [$id]);
        log_activity('create', 'task', $id, 'Kịch bản «' . $ctx['auto']['name'] . '» giao việc "' . $title . '"' . ($uid ? ' cho ' . $ctx['assignee_name'] : ''), auto_sys());
        if ($uid && empty($act['silent'])) notify($uid, 'Bạn được giao việc: ' . $title, 'Từ kịch bản tự động «' . $ctx['auto']['name'] . '»', 'tasks');
        return ['s' => 'ok', 'm' => "đã giao việc $id \"$title\"" . ($uid ? ' cho ' . $ctx['assignee_name'] : ' (chưa có người nhận)')];
    }
    case 'notify': {
        $ids = array_values(array_unique(auto_notify_targets($act, $ctx)));
        $title = auto_tpl($act['title'] ?? $ctx['auto']['name'], $ctx);
        $body = auto_tpl($act['body'] ?? '', $ctx);
        if (!$ids) return ['s' => 'skipped', 'm' => 'không có người nhận'];
        $names = implode(', ', array_map('auto_uname', $ids));
        if ($dry) return ['s' => 'dry', 'm' => "sẽ báo \"$title\" cho $names"];
        foreach ($ids as $uid) notify($uid, $title, $body !== '' ? $body : null, $act['link'] ?? auto_link_for($ctx));
        return ['s' => 'ok', 'm' => "đã báo \"$title\" cho $names"];
    }
    case 'send_email': {
        $toLead = ($act['to'] ?? 'lead') === 'lead';
        $addr = $toLead ? trim((string)($ctx['lead']['email'] ?? '')) : trim((string)($act['address'] ?? ''));
        if ($addr === '') return ['s' => 'skipped', 'm' => $toLead ? 'đầu mối chưa có email' : 'chưa nhập địa chỉ nhận'];
        if ($toLead && $ctx['quiet']) return ['s' => 'skipped', 'm' => 'đang giờ yên lặng — không gửi email cho khách'];
        $missing = [];
        $subject = auto_tpl($act['subject'] ?? '', $ctx, $missing);
        $body = auto_tpl($act['body'] ?? '', $ctx, $missing);
        $note = '';
        if (!empty($act['use_ai'])) [$body, $note] = $dry ? [$body, 'sẽ nhờ AI soạn'] : auto_ai_text('email', 'Viết email ngắn gửi khách (chỉ phần thân thư).', $ctx, $body);
        if ($toLead && ($block = auto_customer_text_block('', $subject . "\n" . $body, $missing))) return ['s' => 'skipped', 'm' => $block];
        if ($dry) return ['s' => 'dry', 'm' => "sẽ gửi email \"$subject\" tới $addr" . ($note ? " ($note)" : '')];
        $r = mail_send($addr, $subject !== '' ? $subject : $ctx['auto']['name'], $body);
        return $r['ok'] ? ['s' => 'ok', 'm' => "đã gửi email tới $addr" . ($note ? " ($note)" : '')] : ['s' => 'failed', 'm' => "gửi tới $addr lỗi — " . $r['error']];
    }
    case 'send_webhook': {
        $url = trim((string)($act['url'] ?? '')) ?: (string)setting_get('webhook.default_url', '');
        if ($url === '') return ['s' => 'skipped', 'm' => 'chưa có địa chỉ webhook'];
        $text = auto_tpl($act['text'] ?? ($ctx['auto']['name'] . ' — {ma}'), $ctx);
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ gửi webhook tới ' . parse_url($url, PHP_URL_HOST) . ': "' . mb_substr($text, 0, 120) . '"'];
        $data = ['event' => $ctx['event'], 'automation' => $ctx['auto']['name'], 'entity' => $ctx['entity'] ?? null, 'entity_id' => $ctx['entity_id'] ?? null];
        foreach (['lead', 'comment', 'post', 'campaign', 'task', 'metric'] as $k) if (!empty($ctx[$k])) $data[$k] = array_intersect_key((array)$ctx[$k], array_flip(['id', 'name', 'title', 'phone', 'email', 'source', 'state', 'message', 'author_name', 'intent', 'sentiment', 'status', 'label', 'value', 'key', 'due_date']));
        $r = webhook_send($url, $text, $data);
        return $r['ok'] ? ['s' => 'ok', 'm' => 'đã gửi webhook (' . parse_url($url, PHP_URL_HOST) . ')'] : ['s' => 'failed', 'm' => $r['error']];
    }
    case 'send_telegram': {
        $text = auto_tpl($act['text'] ?? ($ctx['auto']['name'] . "\n" . '{tom_tat}'), $ctx);
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ gửi Telegram: "' . mb_substr($text, 0, 120) . '"'];
        $r = telegram_send($text, $act['chat_id'] ?? null);
        return $r['ok'] ? ['s' => 'ok', 'm' => 'đã gửi Telegram'] : ['s' => 'failed', 'm' => $r['error']];
    }
    case 'enroll_sequence': {
        $l = $ctx['lead'] ?? null;
        if (!$l) return ['s' => 'skipped', 'm' => 'không có đầu mối'];
        $seq = auto_row('SELECT * FROM sequences WHERE id=?', [(string)($act['sequence_id'] ?? '')]);
        if (!$seq) return ['s' => 'failed', 'm' => 'không tìm thấy chuỗi chăm sóc'];
        if (!(int)$seq['enabled']) return ['s' => 'skipped', 'm' => 'chuỗi «' . $seq['name'] . '» đang tắt'];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ đưa ' . $l['name'] . ' vào chuỗi «' . $seq['name'] . '»'];
        $r = seq_enroll($seq['id'], $l['id'], false);
        return ['s' => $r['ok'] ? 'ok' : 'skipped', 'm' => $r['msg']];
    }
    case 'set_lead_state': {
        $l = $ctx['lead'] ?? null; $st = (string)($act['state'] ?? '');
        if (!$l) return ['s' => 'skipped', 'm' => 'không có đầu mối'];
        if (!isset(AUTO_LEAD_STATES[$st]) || in_array($st, ['ACCEPTED', 'REJECTED'], true)) return ['s' => 'failed', 'm' => 'trạng thái không hợp lệ (CRM nhận / từ chối phải do người có quyền duyệt)'];
        if ($l['state'] === $st) return ['s' => 'skipped', 'm' => 'đầu mối đã ở trạng thái ' . AUTO_LEAD_STATES[$st]];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ chuyển ' . $l['name'] . ' → ' . AUTO_LEAD_STATES[$st]];
        auto_q("UPDATE leads SET state=?, updated_at=datetime('now') WHERE id=?", [$st, $l['id']]);
        log_activity('move', 'lead', $l['id'], 'Kịch bản «' . $ctx['auto']['name'] . '»: ' . $l['name'] . ' ' . $l['state'] . ' → ' . $st, auto_sys());
        $from = $l['state'];
        $ctx['lead'] = auto_load_lead($l['id']);
        automation_event('lead.state', ['lead_id' => $l['id'], 'from' => $from, 'to' => $st]);
        return ['s' => 'ok', 'm' => 'đã chuyển ' . $l['name'] . ' → ' . AUTO_LEAD_STATES[$st]];
    }
    case 'add_lead_note': {
        $l = $ctx['lead'] ?? null;
        if (!$l) return ['s' => 'skipped', 'm' => 'không có đầu mối'];
        $text = trim(auto_tpl($act['text'] ?? '', $ctx));
        if ($text === '') return ['s' => 'skipped', 'm' => 'ghi chú trống'];
        if ($dry) return ['s' => 'dry', 'm' => 'sẽ ghi chú: "' . mb_substr($text, 0, 120) . '"'];
        lead_append_note($l['id'], $text);
        return ['s' => 'ok', 'm' => 'đã ghi chú vào ' . $l['name']];
    }
    }
    return ['s' => 'failed', 'm' => 'hành động không hỗ trợ: ' . $type];
}

function lead_append_note(string $leadId, string $text): void {
    $old = (string)(auto_row('SELECT notes FROM leads WHERE id=?', [$leadId])['notes'] ?? '');
    $line = '[' . date('d/m/Y H:i') . '] ' . $text;
    auto_q("UPDATE leads SET notes=?, updated_at=datetime('now') WHERE id=?", [mb_substr(trim($old . "\n" . $line), -8000), $leadId]);
}

/* ---------------- sự kiện ---------------- */
function automation_event(string $event, array $payload): void {
    static $depth = 0;
    if ($depth >= 3) return; // chặn vòng lặp kịch bản gọi kịch bản
    if (!isset(AUTO_TRIGGERS[$event]) || in_array($event, AUTO_SCHEDULED, true)) return;
    $ctx = auto_build_ctx($event, $payload);
    if (!$ctx) return;
    $ctxPid = auto_ctx_project($ctx);
    if ($ctxPid === null) return; // đối tượng không thuộc dự án nào → không chạy kịch bản (tránh lọt sang dự án khác)
    $ctx['project_id'] = $ctxPid;
    $autos = auto_q('SELECT * FROM automations WHERE enabled=1 AND trigger=? AND project_id=? ORDER BY created_at', [$event, $ctxPid])->fetchAll();
    if (!$autos) return;
    $depth++;
    try {
        foreach ($autos as $a) {
            $a = auto_decode($a);
            $key = $event;
            if ($event === 'lead.state') {
                $want = $a['conditions']['params']['to_state'] ?? '';
                if ($want !== '' && $want !== ($ctx['to'] ?? '')) continue;
                $key = 'lead.state:' . ($ctx['to'] ?? '');
            }
            try { auto_run($a, $ctx, false, $key); }
            catch (Throwable $e) { auto_log_run($a['id'], $event, $ctx['entity'] ?? '', $ctx['entity_id'] ?? '', 'failed', 'Lỗi hệ thống: ' . $e->getMessage()); }
        }
    } finally { $depth--; }
}

/* ---------------- kịch bản theo lịch ---------------- */
function auto_daily_summary(): array {
    $leads = (int)auto_row("SELECT COUNT(*) c FROM leads WHERE created_at >= datetime('now','-1 day')")['c'];
    $open = (int)auto_row("SELECT COUNT(*) c FROM leads WHERE state IN ('CREATED','VALIDATING')")['c'];
    $cm = (int)auto_row("SELECT COUNT(*) c FROM comments WHERE status='new'")['c'];
    $late = 0;
    foreach (auto_q('SELECT board,col,life,due_date FROM tasks')->fetchAll() as $t) if (auto_task_overdue($t)) $late++;
    $text = 'Báo cáo ngày ' . date('d/m/Y') . "\n"
        . "• Đầu mối mới 24 giờ qua: $leads\n• Đầu mối chưa xử lý (Mới / Đang kiểm tra): $open\n"
        . "• Bình luận chưa xử lý: $cm\n• Công việc trễ hạn: $late";
    return ['text' => $text, 'leads' => $leads, 'open' => $open, 'comments' => $cm, 'late' => $late];
}
function auto_task_overdue(array $t): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$t['due_date']) || $t['due_date'] >= date('Y-m-d')) return false;
    if (in_array($t['life'], ['APPROVED', 'PUBLISHED'], true)) return false;
    return ($t['col'] ?? '') !== (AUTO_BOARD_LAST[$t['board']] ?? '');
}
function auto_metric_value(array $metrics, string $key): ?array {
    foreach (($metrics['items'] ?? []) as $it) if (($it['key'] ?? '') === $key && is_numeric($it['value'] ?? null)) return $it;
    return null;
}

/** Danh sách [ctx, runKey] cần chạy cho một kịch bản theo lịch tại thời điểm hiện tại. */
function auto_scheduled_targets(array $a, bool $forTest = false): array {
    $p = $a['conditions']['params'] ?? [];
    $out = [];
    $mk = fn(array $payload) => auto_build_ctx($a['trigger'], $payload);
    switch ($a['trigger']) {
        case 'schedule.daily': case 'schedule.weekly': {
            $at = preg_match('/^\d{1,2}:\d{2}$/', (string)($p['at'] ?? '')) ? str_pad($p['at'], 5, '0', STR_PAD_LEFT) : '08:00';
            $now = date('H:i');
            $inWin = $now >= $at && strtotime(date('Y-m-d ') . $at) > time() - 3 * 3600; // lỡ giờ quá 3 tiếng thì bỏ qua hôm đó
            if ($a['trigger'] === 'schedule.weekly') $inWin = $inWin && (string)date('N') === (string)($p['weekday'] ?? '1');
            if (!$inWin && !$forTest) break;
            $s = auto_daily_summary();
            $key = $a['trigger'] === 'schedule.weekly' ? date('o-\WW') : date('Y-m-d');
            $out[] = [$mk(['entity' => 'schedule', 'entity_id' => $key, 'summary_text' => $s['text'], 'count' => $s['leads']]), $a['trigger']];
            break;
        }
        case 'task.overdue': {
            foreach (auto_q('SELECT * FROM tasks WHERE project_id=? ORDER BY due_date', [$a['project_id'] ?? ''])->fetchAll() as $t) {
                if (!auto_task_overdue($t)) continue;
                if (!$forTest && auto_already_ran($a['id'], 'task.overdue', 'task', $t['id'])) continue;
                $out[] = [$mk(['task_id' => $t['id']]), 'task.overdue'];
                if ($forTest || count($out) >= 50) break;
            }
            break;
        }
        case 'lead.no_response': {
            $h = max(1, (float)($p['hours'] ?? 24));
            $look = max(1, (int)($p['lookback_days'] ?? 7));
            $rows = auto_q("SELECT id FROM leads WHERE project_id=? AND state IN ('CREATED','VALIDATING') AND created_at <= datetime('now', ?) AND created_at >= datetime('now', ?) ORDER BY created_at",
                [$a['project_id'] ?? '', '-' . (int)round($h * 60) . ' minutes', '-' . ($look * 24 + (int)ceil($h)) . ' hours'])->fetchAll();
            foreach ($rows as $r) {
                if (!$forTest && auto_already_ran($a['id'], 'lead.no_response', 'lead', $r['id'])) continue;
                $out[] = [$mk(['lead_id' => $r['id']]), 'lead.no_response'];
                if ($forTest || count($out) >= 50) break;
            }
            break;
        }
        case 'metric.threshold': {
            $key = (string)($p['metric_key'] ?? '');
            if ($key === '' || !is_numeric($p['value'] ?? null)) break;
            $sql = "SELECT * FROM connections WHERE status='connected' AND project_id=?";
            $args = [$a['project_id'] ?? ''];
            if (!empty($p['connection_id'])) { $sql .= ' AND id=?'; $args[] = $p['connection_id']; }
            elseif (!empty($p['provider'])) { $sql .= ' AND provider=?'; $args[] = $p['provider']; }
            foreach (auto_q($sql, $args)->fetchAll() as $cn) {
                $it = auto_metric_value(jdecode($cn['last_metrics'], []), $key);
                if (!$it) continue;
                $mode = $p['mode'] ?? 'value';
                $val = (float)$it['value'];
                $cmp = $val; $desc = $it['label'] . ' = ' . number_format($val, 0, ',', '.');
                if ($mode === 'drop_pct' || $mode === 'rise_pct') {
                    $h = auto_row('SELECT metrics FROM metrics_history WHERE connection_id=? AND day < ? ORDER BY day DESC LIMIT 1', [$cn['id'], date('Y-m-d')]);
                    $prev = $h ? auto_metric_value(jdecode($h['metrics'], []), $key) : null;
                    if (!$prev || (float)$prev['value'] == 0.0) continue;
                    $chg = ((float)$it['value'] - (float)$prev['value']) / abs((float)$prev['value']) * 100;
                    $cmp = $mode === 'drop_pct' ? -$chg : $chg;
                    $desc = $it['label'] . ($mode === 'drop_pct' ? ' giảm ' : ' tăng ') . round(abs($chg), 1) . '% so với lần trước';
                }
                $op = $p['op'] ?? 'gt';
                $hit = ['gt' => $cmp > (float)$p['value'], 'gte' => $cmp >= (float)$p['value'], 'lt' => $cmp < (float)$p['value'], 'lte' => $cmp <= (float)$p['value']][$op] ?? false;
                if (!$hit && !$forTest) continue;
                $eid = $cn['id'] . '@' . date('Y-m-d');
                if (!$forTest && auto_already_ran($a['id'], 'metric.threshold', 'metric', $eid)) continue;
                $out[] = [$mk(['entity' => 'metric', 'entity_id' => $eid, 'metric' => $it + ['desc' => $desc, 'connection' => $cn['label']], 'connection' => $cn['label'],
                    'summary_text' => $cn['label'] . ': ' . $desc . ($hit ? '' : ' (chưa vượt ngưỡng)')]), 'metric.threshold'];
                if ($forTest) break;
            }
            break;
        }
        case 'comments.unanswered': {
            $m = max(5, (int)($p['minutes'] ?? 60));
            $rows = auto_q("SELECT id,author_name,message FROM comments WHERE status='new' AND COALESCE(created_time,fetched_at) <= datetime('now', ?) AND COALESCE(created_time,fetched_at) >= datetime('now','-7 days') ORDER BY created_time", ['-' . $m . ' minutes'])->fetchAll();
            if (!$rows && !$forTest) break;
            $list = implode("\n", array_map(fn($r) => '• ' . ($r['author_name'] ?: 'Khách') . ': ' . mb_substr((string)$r['message'], 0, 80), array_slice($rows, 0, 10)));
            $out[] = [$mk(['entity' => 'comments', 'entity_id' => date('Y-m-d H') . 'h', 'count' => count($rows),
                'summary_text' => count($rows) . " bình luận chưa trả lời quá $m phút" . ($list ? ":\n$list" : '')]), 'comments.unanswered'];
            break;
        }
    }
    return array_values(array_filter($out, fn($x) => $x[0] !== null));
}

/* ---------------- chuỗi chăm sóc ---------------- */
function seq_steps(array $seq): array { return array_values(array_filter(jdecode($seq['steps'] ?? null, []), 'is_array')); }
function seq_next_at(float $hours): string { return date('Y-m-d H:i:s', time() + (int)round(max(0, $hours) * 3600)); }

/** Đưa đầu mối vào chuỗi. $restart = true cho phép bắt đầu lại nếu đã từng tham gia. */
function seq_enroll(string $seqId, string $leadId, bool $restart = true): array {
    $seq = auto_row('SELECT * FROM sequences WHERE id=?', [$seqId]);
    $lead = auto_load_lead($leadId);
    if (!$seq || !$lead) return ['ok' => false, 'msg' => 'không tìm thấy chuỗi hoặc đầu mối'];
    $steps = seq_steps($seq);
    if (!$steps) return ['ok' => false, 'msg' => 'chuỗi chưa có bước nào'];
    $stop = jdecode($seq['stop_on_states'], []);
    if (in_array($lead['state'], $stop, true)) return ['ok' => false, 'msg' => 'đầu mối đang ở trạng thái dừng của chuỗi (' . (AUTO_LEAD_STATES[$lead['state']] ?? $lead['state']) . ')'];
    $ex = auto_row('SELECT * FROM sequence_enrollments WHERE sequence_id=? AND lead_id=?', [$seqId, $leadId]);
    $next = seq_next_at((float)($steps[0]['delay_hours'] ?? 0));
    if ($ex) {
        if ($ex['status'] === 'active') return ['ok' => false, 'msg' => $lead['name'] . ' đã đang trong chuỗi «' . $seq['name'] . '»'];
        if (!$restart) return ['ok' => false, 'msg' => $lead['name'] . ' đã từng tham gia chuỗi «' . $seq['name'] . '» — không đưa lại'];
        auto_q("UPDATE sequence_enrollments SET step_index=0,next_run_at=?,status='active',last_error=NULL WHERE id=?", [$next, $ex['id']]);
    } else {
        auto_q('INSERT INTO sequence_enrollments(sequence_id,lead_id,step_index,next_run_at,status) VALUES (?,?,0,?,?)', [$seqId, $leadId, $next, 'active']);
    }
    return ['ok' => true, 'msg' => 'đã đưa ' . $lead['name'] . ' vào chuỗi «' . $seq['name'] . '» (bước 1 lúc ' . date('H:i d/m', strtotime($next)) . ')'];
}

/**
 * Chạy bước hiện tại của một lượt tham gia.
 * Quy ước: bước email mà đầu mối KHÔNG có email → bỏ qua bước đó (ghi chú vào last_error) và đi tiếp;
 * lỗi gửi thật (SMTP / webhook lỗi) → dừng lượt tham gia ở trạng thái failed, giữ nguyên bước để "Chạy lại".
 */
function seq_run_enrollment(array $enr): array {
    $seq = auto_row('SELECT * FROM sequences WHERE id=?', [$enr['sequence_id']]);
    $lead = auto_load_lead($enr['lead_id']);
    $set = function (array $f) use ($enr) {
        $cols = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
        auto_q("UPDATE sequence_enrollments SET $cols WHERE id=?", [...array_values($f), $enr['id']]);
    };
    if (!$seq) { $set(['status' => 'stopped', 'last_error' => 'Chuỗi đã bị xoá']); return ['s' => 'stopped']; }
    if (!$lead) { $set(['status' => 'stopped', 'last_error' => 'Đầu mối đã bị xoá']); return ['s' => 'stopped']; }
    if (in_array($lead['state'], jdecode($seq['stop_on_states'], []), true)) {
        $set(['status' => 'stopped', 'last_error' => 'Dừng vì đầu mối chuyển sang ' . (AUTO_LEAD_STATES[$lead['state']] ?? $lead['state'])]);
        return ['s' => 'stopped'];
    }
    $steps = seq_steps($seq);
    $i = (int)$enr['step_index'];
    if (!isset($steps[$i])) { $set(['status' => 'done', 'next_run_at' => null]); return ['s' => 'done']; }
    $st = $steps[$i];
    $ctx = ['event' => 'sequence', 'entity' => 'lead', 'entity_id' => $lead['id'], 'lead' => $lead, 'campaign' => auto_load_campaign($lead['campaign_id']), 'quiet' => false,
        'auto' => ['id' => 'SEQ:' . $seq['id'], 'name' => $seq['name'], 'created_by' => null, 'conditions' => []]];
    $n = $i + 1;
    $ch = $st['channel'] ?? 'email';
    $res = ['s' => 'ok', 'm' => ''];
    $missing = [];
    $subject = auto_tpl($st['subject'] ?? '', $ctx, $missing);
    $body = auto_tpl($st['body'] ?? '', $ctx, $missing);
    if ($ch === 'email') {
        if (trim((string)$lead['email']) === '') $res = ['s' => 'skip', 'm' => "Bước $n bỏ qua: đầu mối chưa có email"];
        else {
            if (!empty($st['use_ai'])) [$body] = auto_ai_text('email', 'Viết email chăm sóc khách hàng (chỉ phần thân thư), theo ý của bản mẫu.', $ctx, $body);
            if ($b = auto_customer_text_block('', $subject . "\n" . $body, $missing)) $res = ['s' => 'fail', 'm' => "Bước $n: $b"];
            else { $r = mail_send($lead['email'], $subject !== '' ? $subject : $seq['name'], $body); if (!$r['ok']) $res = ['s' => 'fail', 'm' => "Bước $n: " . $r['error']]; }
        }
    } elseif ($ch === 'zalo') {
        if (!empty($st['use_ai'])) [$body] = auto_ai_text('message', 'Viết tin nhắn Zalo chăm sóc khách, thân thiện, tối đa 3 câu.', $ctx, $body);
        if ($b = auto_customer_text_block('', $body, $missing)) $res = ['s' => 'fail', 'm' => "Bước $n: $b"];
        elseif (!function_exists('zalo_send_to_lead')) $res = ['s' => 'fail', 'm' => "Bước $n: thiếu thư viện Zalo"];
        else { $r = zalo_send_to_lead($lead, $body !== '' ? $body : $subject); if (!$r['ok']) $res = !empty($r['skip']) ? ['s' => 'skip', 'm' => "Bước $n bỏ qua: " . $r['error']] : ['s' => 'fail', 'm' => "Bước $n: " . $r['error']]; }
    } elseif ($ch === 'webhook') {
        if (!empty($st['use_ai'])) [$body] = auto_ai_text('message', 'Viết tin nhắn chăm sóc khách ngắn gọn.', $ctx, $body);
        $r = webhook_send($st['url'] ?? null, $body !== '' ? $body : $subject, ['sequence' => $seq['name'], 'step' => $n,
            'lead' => array_intersect_key($lead, array_flip(['id', 'name', 'phone', 'email', 'source', 'state']))]);
        if (!$r['ok']) $res = ['s' => 'fail', 'm' => "Bước $n: " . $r['error']];
    } elseif ($ch === 'task') {
        $act = ['type' => 'create_task', 'title' => $subject ?: ('Chăm sóc ' . $lead['name']), 'board' => $st['board'] ?? 'lead',
            'assign' => !empty($st['assignee']) ? 'user' : 'round_robin', 'user' => $st['assignee'] ?? null, 'due_hours' => $st['due_hours'] ?? 24];
        $r = auto_action($act, $ctx, false);
        if ($r['s'] !== 'ok') $res = ['s' => 'fail', 'm' => "Bước $n: " . $r['m']];
    } elseif ($ch === 'note') {
        lead_append_note($lead['id'], trim(($subject !== '' ? "$subject: " : '') . $body));
    } elseif ($ch === 'notify') {
        notify(null, $subject !== '' ? $subject : ('Chăm sóc ' . $lead['name']), $body ?: null, 'demand');
    } else {
        $res = ['s' => 'fail', 'm' => "Bước $n: kênh không hỗ trợ ($ch)"];
    }
    if ($res['s'] === 'fail') { $set(['status' => 'failed', 'last_error' => $res['m']]); return ['s' => 'failed', 'm' => $res['m']]; }
    $note = $res['s'] === 'skip' ? $res['m'] : null;
    if (isset($steps[$n])) $set(['step_index' => $n, 'next_run_at' => seq_next_at((float)($steps[$n]['delay_hours'] ?? 0)), 'last_error' => $note]);
    else $set(['step_index' => $n, 'next_run_at' => null, 'status' => 'done', 'last_error' => $note]);
    return ['s' => 'ok', 'm' => $note ?? "Bước $n xong"];
}

/* ---------------- tick định kỳ ---------------- */
function run_automations_tick(array $CFG): string {
    $ran = 0; $fail = 0; $steps = 0; $sfail = 0;
    $quiet = in_quiet_hours();
    foreach (auto_q("SELECT * FROM automations WHERE enabled=1 AND trigger IN ('" . implode("','", AUTO_SCHEDULED) . "') ORDER BY created_at")->fetchAll() as $a) {
        $a = auto_decode($a);
        $explicitTime = in_array($a['trigger'], ['schedule.daily', 'schedule.weekly'], true);
        if ($quiet && !$explicitTime && empty($a['conditions']['ignore_quiet'])) continue;
        try {
            foreach (auto_scheduled_targets($a) as [$ctx, $key]) {
                $ctx['project_id'] = $a['project_id'] ?? null;
                $r = auto_run($a, $ctx, false, $key);
                if (in_array($r['status'], ['ok', 'failed', 'skipped'], true) && $r['detail'] !== 'rate limit') $ran++;
                if ($r['status'] === 'failed') $fail++;
                if ($r['detail'] === 'rate limit') break;
            }
        } catch (Throwable $e) { $fail++; auto_log_run($a['id'], $a['trigger'], 'schedule', date('Y-m-d H:i'), 'failed', 'Lỗi hệ thống: ' . $e->getMessage()); }
    }
    if (!$quiet) {
        $due = auto_q("SELECT e.* FROM sequence_enrollments e JOIN sequences s ON s.id=e.sequence_id
            WHERE e.status='active' AND s.enabled=1 AND e.next_run_at IS NOT NULL AND e.next_run_at <= ? ORDER BY e.next_run_at LIMIT 100", [date('Y-m-d H:i:s')])->fetchAll();
        foreach ($due as $e) {
            try { $r = seq_run_enrollment($e); $steps++; if ($r['s'] === 'failed') $sfail++; }
            catch (Throwable $ex) { $sfail++; auto_q("UPDATE sequence_enrollments SET status='failed', last_error=? WHERE id=?", ['Lỗi hệ thống: ' . $ex->getMessage(), $e['id']]); }
        }
    }
    return "$ran lượt kịch bản" . ($fail ? " ($fail lỗi)" : '') . ", $steps bước chuỗi chăm sóc" . ($sfail ? " ($sfail lỗi)" : '') . ($quiet ? ' · đang giờ yên lặng' : '');
}

/** Chạy thử một kịch bản (không tác động): chọn đối tượng mẫu gần nhất hoặc $entityId. */
function automation_dry_run(array $a, ?string $entityId = null): array {
    $a = auto_decode($a);
    $t = $a['trigger'];
    $ctx = null; $key = $t;
    if (in_array($t, AUTO_SCHEDULED, true)) {
        $tg = auto_scheduled_targets($a, true);
        if ($tg) [$ctx, $key] = $tg[0];
    } else {
        $pick = function (string $sql, string $k) use ($entityId) { return $entityId ?: (auto_row($sql)[$k] ?? null); };
        $payload = match (true) {
            $t === 'comment.new' => ['comment_id' => $pick('SELECT id FROM comments ORDER BY COALESCE(created_time,fetched_at) DESC LIMIT 1', 'id')],
            str_starts_with($t, 'lead.') => ['lead_id' => $pick('SELECT id FROM leads ORDER BY created_at DESC LIMIT 1', 'id'), 'from' => 'CREATED', 'to' => $a['conditions']['params']['to_state'] ?? 'ROUTED'],
            str_starts_with($t, 'post.') => ['post_id' => $pick('SELECT id FROM posts ORDER BY created_at DESC LIMIT 1', 'id')],
            $t === 'campaign.approved' => ['campaign_id' => $pick('SELECT id FROM campaigns ORDER BY created_at DESC LIMIT 1', 'id')],
            default => [],
        };
        $ctx = auto_build_ctx($t, $payload);
    }
    if (!$ctx) {
        $msg = 'Chạy thử: chưa có dữ liệu mẫu phù hợp để thử (' . (AUTO_TRIGGERS[$t] ?? $t) . ')';
        auto_log_run($a['id'], 'test:' . $t, '', null, 'skipped', $msg);
        return ['status' => 'skipped', 'detail' => $msg];
    }
    $r = auto_run($a, $ctx, true, $key);
    return $r;
}

/* ---------------- kịch bản mẫu ---------------- */
function seq_sample_def(): array {
    return [
        'name' => SEQ_SAMPLE_NAME,
        'stop_on_states' => ['ACCEPTED', 'REJECTED'],
        'steps' => [
            ['delay_hours' => 0, 'channel' => 'email', 'use_ai' => false, 'subject' => 'Cảm ơn {ten} đã quan tâm',
                'body' => "Chào {ten},\n\nCảm ơn anh/chị đã để lại thông tin. Bộ phận tư vấn sẽ liên hệ với anh/chị trong thời gian sớm nhất.\n\nNếu cần hỗ trợ ngay, anh/chị vui lòng gọi hotline {hotline}.\n\nTrân trọng,\nPhòng Marketing"],
            ['delay_hours' => 48, 'channel' => 'email', 'use_ai' => false, 'subject' => '{ten} cần tư vấn thêm điều gì không?',
                'body' => "Chào {ten},\n\nKhông biết anh/chị đã nhận được đầy đủ thông tin cần thiết chưa? Nếu còn băn khoăn về sản phẩm / dịch vụ, anh/chị chỉ cần trả lời email này hoặc gọi {hotline}, chuyên viên sẽ tư vấn chi tiết theo nhu cầu.\n\nTrân trọng,\nPhòng Marketing"],
            ['delay_hours' => 72, 'channel' => 'email', 'use_ai' => false, 'subject' => 'Ưu đãi dành cho {ten} & đặt lịch tư vấn',
                'body' => "Chào {ten},\n\nƯu đãi hiện hành: [ĐIỀN ƯU ĐÃI THỰC TẾ ĐANG ÁP DỤNG — xoá dòng này nếu không có ưu đãi]\n\nAnh/chị muốn đặt lịch tư vấn / trải nghiệm, vui lòng trả lời email này hoặc gọi {hotline} để chọn thời gian phù hợp.\n\nTrân trọng,\nPhòng Marketing"],
        ],
    ];
}

/** Tìm (hoặc tạo, ở trạng thái TẮT) chuỗi mẫu "Chăm sóc 7 ngày". */
function seq_ensure_sample(): string {
    $r = auto_row('SELECT id FROM sequences WHERE name=? AND project_id=? ORDER BY created_at LIMIT 1', [SEQ_SAMPLE_NAME, pid()]);
    if ($r) return $r['id'];
    $d = seq_sample_def();
    $id = gen_id('SEQ');
    auto_q('INSERT INTO sequences(id,project_id,name,steps,stop_on_states,enabled) VALUES (?,?,?,?,?,0)',
        [$id, pid(), $d['name'], json_encode($d['steps'], JSON_UNESCAPED_UNICODE), json_encode($d['stop_on_states'])]);
    return $id;
}

function automation_templates(): array {
    $hoiGia = 'Chào {ten}, cảm ơn bạn đã quan tâm! Bạn vui lòng nhắn tin (inbox) cho trang hoặc gọi hotline {hotline} để được tư vấn và báo giá chi tiết nhé.';
    return [
        'hoi_gia' => ['name' => 'Bình luận hỏi giá → trả lời mời inbox / hotline', 'icon' => 'users',
            'desc' => 'Trả lời mẫu mời nhắn tin / gọi hotline (không nêu giá), tạo đầu mối nếu bình luận có SĐT, giao bình luận cho nhân viên (chia đều) và báo người đó.',
            'trigger' => 'comment.new',
            'conditions' => ['match' => 'any', 'rules' => [
                ['field' => 'comment.intent', 'op' => 'in', 'value' => ['hoi_gia']],
                ['field' => 'comment.message', 'op' => 'contains_any', 'value' => ['giá', 'bao nhiêu', 'báo giá', 'nhiêu tiền', 'bn tiền']],
            ], 'max_per_hour' => 30, 'skip_negative' => true],
            'actions' => [
                ['type' => 'reply_comment', 'text' => $hoiGia, 'use_ai' => false],
                ['type' => 'comment_to_lead', 'require_phone' => true],
                ['type' => 'assign_comment', 'assign' => 'round_robin', 'users' => []],
                ['type' => 'notify', 'to' => 'assignee', 'title' => '{ten} hỏi giá trên {nguon}', 'body' => '{noi_dung}', 'link' => 'care'],
            ]],
        'zalo_tu_van' => ['name' => 'Tin nhắn Zalo OA mới → trả lời tư vấn tự động + tạo đầu mối', 'icon' => 'users',
            'desc' => 'Khách nhắn Zalo OA: trả lời ngay (AI soạn theo thương hiệu nếu đã có khoá AI, không thì dùng mẫu), tạo đầu mối nếu tin có SĐT, giao nhân viên và báo. Không tự trả lời tin tiêu cực / ngoài giờ.',
            'trigger' => 'comment.new',
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'comment.provider', 'op' => 'eq', 'value' => 'zalo_oa'],
            ], 'max_per_hour' => 60, 'skip_negative' => true],
            'actions' => [
                ['type' => 'reply_comment', 'text' => 'Chào {ten}, cảm ơn anh/chị đã nhắn tin! Anh/chị đang cần tư vấn sản phẩm / dịch vụ nào ạ? Để lại số điện thoại, nhân viên sẽ gọi lại ngay.', 'use_ai' => true],
                ['type' => 'comment_to_lead', 'require_phone' => true],
                ['type' => 'assign_comment', 'assign' => 'round_robin', 'users' => []],
                ['type' => 'notify', 'to' => 'assignee', 'title' => 'Khách {ten} nhắn Zalo OA', 'body' => '{noi_dung}', 'link' => 'care'],
            ]],
        'zalo_sdt' => ['name' => 'Khách để lại SĐT qua Zalo → giao gọi lại trong 1 giờ', 'icon' => 'route',
            'desc' => 'Tin Zalo có số điện thoại: tạo đầu mối, giao việc gọi lại hạn 1 giờ, báo trưởng nhóm.',
            'trigger' => 'comment.new',
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'comment.provider', 'op' => 'eq', 'value' => 'zalo_oa'],
                ['field' => 'comment.message', 'op' => 'contains_any', 'value' => ['09', '08', '07', '03', '05', '+84']],
            ], 'max_per_hour' => 60],
            'actions' => [
                ['type' => 'comment_to_lead', 'require_phone' => true],
                ['type' => 'create_task', 'title' => 'Gọi lại {ten} ({sdt}) — khách để số qua Zalo', 'board' => 'lead', 'assign' => 'round_robin', 'users' => [], 'due_hours' => 1, 'pri' => 'P0'],
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => 'Khách {ten} để SĐT qua Zalo', 'body' => '{noi_dung}', 'link' => 'demand'],
            ]],
        'tieu_cuc' => ['name' => 'Bình luận tiêu cực → báo trưởng nhóm + giao xử lý trong 2 giờ', 'icon' => 'flag',
            'desc' => 'Không tự ẩn, không tự trả lời. Báo trưởng nhóm ngay và giao việc xử lý hạn 2 giờ (chia đều).',
            'trigger' => 'comment.new',
            'conditions' => ['match' => 'any', 'rules' => [
                ['field' => 'comment.sentiment', 'op' => 'eq', 'value' => 'negative'],
                ['field' => 'comment.intent', 'op' => 'eq', 'value' => 'khieu_nai'],
            ], 'max_per_hour' => 30],
            'actions' => [
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => 'Bình luận tiêu cực từ {ten}', 'body' => '{noi_dung}', 'link' => 'care'],
                ['type' => 'create_task', 'title' => 'Xử lý bình luận tiêu cực của {ten}', 'board' => 'content', 'assign' => 'round_robin', 'users' => [], 'due_hours' => 2, 'pri' => 'P0'],
            ]],
        'lead_moi' => ['name' => 'Đầu mối mới → giao gọi lại trong 2 giờ + chuỗi Chăm sóc 7 ngày', 'icon' => 'route',
            'desc' => 'Giao việc gọi lại hạn 2 giờ (chia đều cho nhân viên), báo người nhận, đưa vào chuỗi «Chăm sóc 7 ngày» — bước ngày 0 của chuỗi là email cảm ơn (chỉ gửi khi đầu mối có email). Cần bật chuỗi sau khi xem lại nội dung.',
            'trigger' => 'lead.new', 'needs_sequence' => true,
            'conditions' => ['match' => 'all', 'rules' => [], 'max_per_hour' => 60],
            'actions' => [
                ['type' => 'create_task', 'title' => 'Gọi lại đầu mối {ten} {sdt}', 'board' => 'lead', 'assign' => 'round_robin', 'users' => [], 'due_hours' => 2, 'pri' => 'P1'],
                ['type' => 'enroll_sequence', 'sequence_id' => '__SAMPLE__'],
            ]],
        'lead_cho' => ['name' => 'Đầu mối chưa xử lý sau 24 giờ → nhắc người phụ trách + trưởng nhóm', 'icon' => 'clock',
            'desc' => 'Đầu mối còn ở "Mới" / "Đang kiểm tra" sau 24 giờ: nhắc người phụ trách chiến dịch và trưởng nhóm (mỗi đầu mối một lần).',
            'trigger' => 'lead.no_response',
            'conditions' => ['match' => 'all', 'rules' => [], 'params' => ['hours' => 24, 'lookback_days' => 7], 'max_per_hour' => 30],
            'actions' => [
                ['type' => 'notify', 'to' => 'campaign_owner', 'title' => 'Đầu mối {ten} chưa được xử lý sau 24 giờ', 'body' => 'Nguồn {nguon} · {sdt}', 'link' => 'demand'],
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => 'Đầu mối {ten} chưa được xử lý sau 24 giờ', 'body' => 'Nguồn {nguon} · {sdt}', 'link' => 'demand'],
            ]],
        'bl_cho' => ['name' => 'Bình luận chưa trả lời quá 60 phút → báo nhóm', 'icon' => 'bell',
            'desc' => 'Mỗi giờ tối đa một lần: báo trưởng nhóm danh sách bình luận chưa trả lời quá 60 phút (và gửi webhook nhóm nếu đã cấu hình).',
            'trigger' => 'comments.unanswered',
            'conditions' => ['match' => 'all', 'rules' => [], 'params' => ['minutes' => 60], 'max_per_hour' => 2],
            'actions' => [
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => '{so_luong} bình luận chưa trả lời quá 60 phút', 'body' => '{tom_tat}', 'link' => 'care'],
                ['type' => 'send_webhook', 'url' => '', 'text' => '{tom_tat}'],
            ]],
        'bai_loi' => ['name' => 'Bài đăng lỗi → báo người tạo', 'icon' => 'send',
            'desc' => 'Khi đăng bài lên một kênh thất bại, báo ngay cho người tạo bài.',
            'trigger' => 'post.failed',
            'conditions' => ['match' => 'all', 'rules' => [], 'max_per_hour' => 30],
            'actions' => [
                ['type' => 'notify', 'to' => 'creator', 'title' => 'Bài "{bai_viet}" đăng lỗi', 'body' => 'Mở mục Đăng bài đa kênh để xem lỗi và đăng lại.', 'link' => 'publish'],
            ]],
        'cpl' => ['name' => 'CPL vượt ngưỡng → báo trưởng nhóm qua webhook', 'icon' => 'chart',
            'desc' => 'So CPL (chi phí / lead) của tài khoản Meta Ads sau mỗi lần đồng bộ với ngưỡng bạn đặt; tối đa 1 lần/ngày/tài khoản. Nhập ngưỡng trước khi bật.',
            'trigger' => 'metric.threshold',
            'conditions' => ['match' => 'all', 'rules' => [], 'params' => ['provider' => 'meta_ads', 'metric_key' => 'cpl', 'op' => 'gt', 'value' => '', 'mode' => 'value'], 'max_per_hour' => 10],
            'actions' => [
                ['type' => 'send_webhook', 'url' => '', 'text' => 'Cảnh báo: {tom_tat}'],
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => 'CPL vượt ngưỡng', 'body' => '{tom_tat}', 'link' => 'channels'],
            ]],
        'bao_cao_sang' => ['name' => 'Báo cáo sáng 8:00 mỗi ngày', 'icon' => 'mega',
            'desc' => 'Tóm tắt: đầu mối mới, đầu mối chưa xử lý, bình luận chưa xử lý, việc trễ hạn — gửi qua email (nhập địa chỉ) và webhook, kèm thông báo trong app.',
            'trigger' => 'schedule.daily',
            'conditions' => ['match' => 'all', 'rules' => [], 'params' => ['at' => '08:00'], 'max_per_hour' => 5],
            'actions' => [
                ['type' => 'send_email', 'to' => 'fixed', 'address' => '', 'subject' => 'Báo cáo Marketing sáng {ngay}', 'body' => '{tom_tat}'],
                ['type' => 'send_webhook', 'url' => '', 'text' => '{tom_tat}'],
                ['type' => 'notify', 'to' => 'role', 'role' => 'manager', 'title' => 'Báo cáo sáng {ngay}', 'body' => '{tom_tat}', 'link' => 'dashboard'],
            ]],
    ];
}

/** Cấu hình mẫu đã thay __SAMPLE__ bằng id chuỗi mẫu (tạo nếu chưa có khi $create). */
function automation_template_resolved(string $key, bool $create): ?array {
    $t = automation_templates()[$key] ?? null;
    if (!$t) return null;
    if (!empty($t['needs_sequence'])) {
        $sid = $create ? seq_ensure_sample() : (auto_row('SELECT id FROM sequences WHERE name=? LIMIT 1', [SEQ_SAMPLE_NAME])['id'] ?? '');
        foreach ($t['actions'] as &$ac) if (($ac['sequence_id'] ?? '') === '__SAMPLE__') $ac['sequence_id'] = $sid;
        unset($ac);
    }
    return $t;
}
