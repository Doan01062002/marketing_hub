<?php
/* TỰ ĐỘNG HOÁ CỬA SỔ CHAT (11/11/2026): Từ khoá · Kịch bản chăm sóc · Quy luật — áp dụng cho hội thoại tin nhắn (không áp dụng bình luận công khai).
   chauto_queue_inbound()  gọi trong ib_store khi có tin khách MỚI → gom vào hàng chờ của request
   chauto_run_pending()    xử lý hàng chờ: quy luật "khách mới nhắn lần đầu" + khớp từ khoá (trả lời + đưa vào kịch bản)
                       → trả về [thread_id => true] các hội thoại đã được tự động trả lời (để AI không trả lời chồng)
   chauto_on_tag_added()   quy luật "khách hàng được gắn thẻ"
   chauto_tick()           gửi các bước kịch bản đến hạn (cron/inbox.php mỗi phút + màn hình chat thăm dò, tối đa 1 lần/phút)
   Tin cũ hơn CHAUTO_MAX_AGE_MIN phút (vd. lần kéo lịch sử đầu tiên) không kích hoạt gì. */
require_once __DIR__ . '/_inbox_lib.php';

const CHAUTO_MAX_AGE_MIN = 360;          // chỉ phản ứng với tin khách trong 6 giờ gần nhất
const CHAUTO_KW_COOLDOWN_MIN = 10;       // cùng 1 từ khoá không trả lời lại cùng khách trong 10 phút
const CHAUTO_USER = ['id' => 'auto', 'name' => 'Tự động'];
const CHAUTO_TRIGGERS = ['first_message' => 'Khách hàng mới nhắn tin lần đầu', 'order_created' => 'Đơn hàng mới được tạo', 'order_done' => 'Đơn hàng hoàn thành / Đang giao hàng', 'tag_added' => 'Khách hàng được gắn thẻ'];
const CHAUTO_ACTIONS = ['enroll' => 'Đưa khách vào kịch bản chăm sóc', 'unenroll' => 'Rời khỏi kịch bản chăm sóc', 'send' => 'Gửi tin nhắn tự động'];

function chauto_fold(string $s): string {
    static $map = ['a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ', 'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ'];
    $s = mb_strtolower($s, 'UTF-8');
    foreach ($map as $to => $chars) $s = preg_replace('/[' . $chars . ']/u', $to, $s);
    $s = preg_replace('/\p{Mn}+/u', '', $s); // dấu tổ hợp rời (gõ kiểu tổ hợp)
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function chauto_kw_match(string $text, array $kw): ?string {
    // Không phân biệt hoa thường. Từ khoá gõ KHÔNG dấu khớp cả có dấu lẫn không dấu ("gia" ↔ "giá", "gia");
    // từ khoá gõ CÓ dấu chỉ khớp đúng dấu ("giá" không khớp "gia đình").
    $lower = trim(preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8'))); $fold = chauto_fold($text);
    if ($fold === '') return null;
    foreach (jdecode($kw['keywords'], []) as $k) {
        $kl = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string)$k, 'UTF-8'))); $kf = chauto_fold((string)$k); if ($kf === '') continue;
        [$t, $f] = $kf === $kl ? [$fold, $kf] : [$lower, $kl];
        if (($kw['match_mode'] ?? 'contains') === 'exact') { if ($t === $f) return (string)$k; }
        elseif (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($f, '/') . '($|[^\p{L}\p{N}])/u', $t)) return (string)$k;
    }
    return null;
}
function chauto_channel_ok(?string $channels, array $th): bool { $c = jdecode($channels, []); return !$c || in_array($th['connection_id'], $c, true); }
function chauto_eligible(array $th): bool { return ($th['kind'] ?? 'message') !== 'comment'; }
function chauto_log(string $kind, string $ref, string $tid, string $status, string $detail = ''): void {
    global $pdo;
    $pdo->prepare('INSERT INTO chauto_log(kind,ref_id,thread_id,status,detail) VALUES(?,?,?,?,?)')->execute([$kind, $ref, $tid, $status, mb_substr($detail, 0, 500)]);
}
function chauto_thread(string $tid): ?array { global $pdo; $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $st->execute([$tid]); return $st->fetch() ?: null; }
function chauto_fill(string $text, array $th): string {
    global $pdo;
    $name = trim((string)($th['customer_name'] ?? '')); $first = $name !== '' ? preg_split('/\s+/u', $name) : ['bạn'];
    $hot = '';
    if (str_contains($text, '{sdt_hotline}')) { $st = $pdo->prepare('SELECT settings FROM projects WHERE id=?'); $st->execute([$th['project_id']]); $hot = (string)(jdecode($st->fetchColumn() ?: null, [])['hotline'] ?? ''); }
    return strtr($text, ['{ten}' => end($first), '{sdt_hotline}' => $hot]);
}
function chauto_send(array $CFG, array $th, string $text): array {
    $text = trim(chauto_fill($text, $th));
    if ($text === '') return ['ok' => false, 'error' => 'Nội dung trống'];
    try { return inbox_send($CFG, $th, $text, CHAUTO_USER); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
}

/** Đưa hội thoại vào kịch bản. Đang chạy thì giữ nguyên; đã xong/dừng thì chạy lại từ bước 1. */
function chauto_enroll(string $seqId, array $th, string $source): string {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM ca_sequences WHERE id=?'); $st->execute([$seqId]); $seq = $st->fetch();
    if (!$seq || !(int)$seq['enabled']) return 'kịch bản không tồn tại hoặc đang tắt';
    if (!chauto_channel_ok($seq['channels'], $th)) return 'kịch bản không áp dụng cho kênh này';
    $steps = jdecode($seq['steps'], []); if (!$steps) return 'kịch bản chưa có bước';
    $now = time(); $next = gmdate('Y-m-d H:i:s', $now + 60 * max(0, (int)($steps[0]['delay_min'] ?? 0)));
    $pdo->prepare("INSERT INTO ca_enrollments(id,sequence_id,thread_id,step_idx,started_at,next_at,status,source) VALUES(?,?,?,0,?,?,'active',?)
        ON CONFLICT(sequence_id,thread_id) DO UPDATE SET step_idx=0, started_at=excluded.started_at, next_at=excluded.next_at, status='active', last_error=NULL, source=excluded.source, updated_at=datetime('now') WHERE ca_enrollments.status<>'active'")
        ->execute([gen_id('CE'), $seqId, $th['id'], gmdate('Y-m-d H:i:s', $now), $next, $source]);
    chauto_log('enroll', $seqId, $th['id'], 'ok', $source);
    return 'ok';
}
function chauto_unenroll(?string $seqId, string $tid): void {
    global $pdo;
    if ($seqId) $pdo->prepare("UPDATE ca_enrollments SET status='stopped', updated_at=datetime('now') WHERE sequence_id=? AND thread_id=? AND status='active'")->execute([$seqId, $tid]);
    else $pdo->prepare("UPDATE ca_enrollments SET status='stopped', updated_at=datetime('now') WHERE thread_id=? AND status='active'")->execute([$tid]);
}
/** Chạy các hành động của 1 quy luật. Trả về true nếu đã gửi tin cho khách. */
function chauto_rule_run(array $CFG, array $rule, array $th): bool {
    $sent = false;
    foreach (jdecode($rule['actions'], []) as $a) {
        $type = (string)($a['type'] ?? '');
        if ($type === 'enroll' && !empty($a['sequence_id'])) chauto_enroll((string)$a['sequence_id'], $th, 'rule:' . $rule['id']);
        elseif ($type === 'unenroll') chauto_unenroll(($a['sequence_id'] ?? '') ?: null, $th['id']);
        elseif ($type === 'send' && trim((string)($a['text'] ?? '')) !== '') {
            $r = chauto_send($CFG, $th, (string)$a['text']); $sent = $sent || $r['ok'];
            chauto_log('rule', $rule['id'], $th['id'], $r['ok'] ? 'ok' : 'error', $r['ok'] ? 'gửi tin' : (string)($r['error'] ?? ''));
        }
    }
    return $sent;
}
function chauto_fire(array $CFG, string $trigger, array $th, string $tagId = ''): bool {
    global $pdo;
    if (!chauto_eligible($th)) return false;
    $st = $pdo->prepare('SELECT * FROM ca_rules WHERE enabled=1 AND trigger_type=? ORDER BY created_at'); $st->execute([$trigger]);
    $sent = false;
    foreach ($st->fetchAll() as $r) {
        if (!chauto_channel_ok($r['channels'], $th)) continue;
        if ($trigger === 'tag_added' && (string)$r['trigger_tag'] !== '' && $r['trigger_tag'] !== $tagId) continue;
        try { $sent = chauto_rule_run($CFG, $r, $th) || $sent; } catch (Throwable $e) { chauto_log('rule', $r['id'], $th['id'], 'error', $e->getMessage()); }
    }
    return $sent;
}

function chauto_queue_inbound(string $tid, bool $newThread, array $texts): void {
    $q = &$GLOBALS['ca_pending'][$tid];
    $q = ['new' => ($q['new'] ?? false) || $newThread, 'texts' => array_merge($q['texts'] ?? [], $texts)];
}
function chauto_run_pending(array $CFG): array {
    global $pdo;
    $pending = $GLOBALS['ca_pending'] ?? []; $GLOBALS['ca_pending'] = [];
    $handled = [];
    foreach ($pending as $tid => $p) {
        try {
            $th = chauto_thread($tid); if (!$th || !chauto_eligible($th)) continue;
            $fresh = array_values(array_filter($p['texts'], fn($m) => strtotime($m['at'] . ' UTC') >= time() - CHAUTO_MAX_AGE_MIN * 60));
            if (!$fresh) continue;
            if ($p['new'] && chauto_fire($CFG, 'first_message', $th)) $handled[$tid] = true;
            $kws = $pdo->query('SELECT * FROM ca_keywords WHERE enabled=1 ORDER BY sort, created_at')->fetchAll();
            $text = (string)end($fresh)['text'];
            foreach ($kws as $kw) {
                if (!chauto_channel_ok($kw['channels'], $th) || ($hit = chauto_kw_match($text, $kw)) === null) continue;
                $cd = $pdo->prepare("SELECT 1 FROM ca_log WHERE kind='keyword' AND ref_id=? AND thread_id=? AND status='ok' AND created_at > datetime('now', ?)");
                $cd->execute([$kw['id'], $tid, '-' . CHAUTO_KW_COOLDOWN_MIN . ' minutes']);
                if ($cd->fetchColumn()) break;
                if (trim((string)$kw['reply']) !== '') {
                    $th = chauto_thread($tid) ?: $th;
                    $r = chauto_send($CFG, $th, (string)$kw['reply']);
                    chauto_log('keyword', $kw['id'], $tid, $r['ok'] ? 'ok' : 'error', $r['ok'] ? 'khớp "' . $hit . '"' : (string)($r['error'] ?? ''));
                    if ($r['ok']) $handled[$tid] = true;
                } else chauto_log('keyword', $kw['id'], $tid, 'ok', 'khớp "' . $hit . '" (không có tin trả lời)');
                if (!empty($kw['sequence_id'])) chauto_enroll((string)$kw['sequence_id'], $th, 'keyword:' . $kw['id']);
                break; // mỗi tin chỉ áp dụng từ khoá khớp đầu tiên
            }
        } catch (Throwable $e) { chauto_log('pending', '', (string)$tid, 'error', $e->getMessage()); }
    }
    return $handled;
}
function chauto_on_tag_added(array $CFG, array $th, array $tagIds): void {
    foreach ($tagIds as $g) { try { chauto_fire($CFG, 'tag_added', $th, (string)$g); } catch (Throwable $e) { chauto_log('rule', '', $th['id'], 'error', $e->getMessage()); } }
}

/** Gửi các bước kịch bản đến hạn. */
function chauto_tick(array $CFG, int $limit = 30): int {
    global $pdo;
    $st = $pdo->prepare("SELECT e.*, s.steps, s.enabled seq_enabled, s.channels FROM ca_enrollments e JOIN ca_sequences s ON s.id=e.sequence_id WHERE e.status='active' AND e.next_at<=? ORDER BY e.next_at LIMIT " . max(1, $limit));
    $st->execute([gmdate('Y-m-d H:i:s')]);
    $n = 0;
    foreach ($st->fetchAll() as $e) {
        if (!(int)$e['seq_enabled']) continue; // kịch bản đang tắt: giữ nguyên, bật lại thì chạy tiếp
        $th = chauto_thread($e['thread_id']); $steps = jdecode($e['steps'], []); $i = (int)$e['step_idx'];
        $upd = $pdo->prepare("UPDATE ca_enrollments SET step_idx=?, next_at=?, status=?, last_error=?, updated_at=datetime('now') WHERE id=?");
        if (!$th || !chauto_eligible($th) || !chauto_channel_ok($e['channels'], $th) || !isset($steps[$i])) { $upd->execute([$i, $e['next_at'], $th ? 'done' : 'stopped', null, $e['id']]); continue; }
        $r = chauto_send($CFG, $th, (string)($steps[$i]['text'] ?? ''));
        chauto_log('step', $e['sequence_id'], $th['id'], $r['ok'] ? 'ok' : 'error', 'bước ' . ($i + 1) . ($r['ok'] ? '' : ': ' . ($r['error'] ?? '')));
        $j = $i + 1;
        if (isset($steps[$j])) {
            $due = max(time(), strtotime($e['started_at'] . ' UTC') + 60 * max(0, (int)($steps[$j]['delay_min'] ?? 0)));
            $upd->execute([$j, gmdate('Y-m-d H:i:s', $due), 'active', $r['ok'] ? null : mb_substr((string)($r['error'] ?? ''), 0, 300), $e['id']]);
        } else $upd->execute([$j, $e['next_at'], $r['ok'] ? 'done' : 'failed', $r['ok'] ? null : mb_substr((string)($r['error'] ?? ''), 0, 300), $e['id']]);
        $n++;
    }
    return $n;
}
/** Chạy ca_tick tối đa 1 lần/phút khi được gọi từ request web (dự phòng khi không có cron, vd. chạy local). */
function chauto_tick_maybe(array $CFG): void {
    if (time() - (int)setting_get('ca.tick_at', 0) < 60) return;
    $lock = @fopen(__DIR__ . '/../data/ca-tick.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    setting_set('ca.tick_at', (string)time(), ['id' => null]);
    try { chauto_tick($CFG, 20); } catch (Throwable $e) { chauto_log('tick', '', '', 'error', $e->getMessage()); }
    try { require_once __DIR__ . '/_crm_lib.php'; crm_tick(); } catch (Throwable $e) { chauto_log('tick', 'crm', '', 'error', $e->getMessage()); }
    flock($lock, LOCK_UN);
}
