<?php
/* TỰ ĐỘNG HOÁ CỬA SỔ CHAT — cấu hình Từ khoá · Kịch bản chăm sóc · Quy luật (11/11/2026). Logic chạy ở _chat_auto_lib.php.
   GET                                  → {keywords, sequences (kèm số đang chăm sóc), rules, triggers, actions, can_edit}
   POST {action:'kw_save'|'seq_save'|'rule_save', ...}   tạo / sửa (có id = sửa)
   POST {action:'del', kind:'kw'|'seq'|'rule', id}       xoá
   POST {action:'toggle', kind, id, enabled}             bật / tắt
   POST {action:'unenroll', thread_id, sequence_id?}     cho khách rời kịch bản */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_chat_auto_lib.php';
$me = require_login();

$myProjects = projects_for_user($me);
$canEdit = $me['role'] === 'admin';
foreach ($myProjects as $p) {
    $r = (string)($p['member_role'] ?? '');
    if (str_starts_with($r, 'cr_') ? role_level(custom_role_get($r, (string)$p['id'])['perms'], ['inbox']) >= 2 : in_array($r, PROJECT_WRITE_ROLES, true)) $canEdit = true;
}
$T = ['kw' => 'ca_keywords', 'seq' => 'ca_sequences', 'rule' => 'ca_rules'];

function chauto_payload(): array {
    global $pdo;
    $kw = array_map(fn($r) => ['id' => $r['id'], 'enabled' => (int)$r['enabled'], 'keywords' => jdecode($r['keywords'], []), 'match' => $r['match_mode'], 'reply' => $r['reply'], 'channels' => jdecode($r['channels'], []), 'sequence_id' => $r['sequence_id'], 'updated_at' => $r['updated_at']],
        $pdo->query('SELECT * FROM ca_keywords ORDER BY sort, created_at')->fetchAll());
    $act = []; foreach ($pdo->query("SELECT sequence_id, COUNT(*) n FROM ca_enrollments WHERE status='active' GROUP BY sequence_id")->fetchAll() as $r) $act[$r['sequence_id']] = (int)$r['n'];
    $seq = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'enabled' => (int)$r['enabled'], 'channels' => jdecode($r['channels'], []), 'steps' => jdecode($r['steps'], []), 'active' => $act[$r['id']] ?? 0, 'updated_at' => $r['updated_at']],
        $pdo->query('SELECT * FROM ca_sequences ORDER BY created_at')->fetchAll());
    $rules = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'enabled' => (int)$r['enabled'], 'channels' => jdecode($r['channels'], []), 'trigger' => $r['trigger_type'], 'trigger_tag' => $r['trigger_tag'], 'actions' => jdecode($r['actions'], []), 'updated_at' => $r['updated_at']],
        $pdo->query('SELECT * FROM ca_rules ORDER BY created_at')->fetchAll());
    return ['keywords' => $kw, 'sequences' => $seq, 'rules' => $rules, 'triggers' => CHAUTO_TRIGGERS, 'actions' => CHAUTO_ACTIONS, 'ready_triggers' => ['first_message', 'tag_added']];
}
function chauto_channels_in($v): string {
    global $pdo;
    $ids = array_values(array_unique(array_filter(array_map('strval', (array)$v))));
    if (!$ids) return '[]';
    $ph = str_repeat('?,', count($ids) - 1) . '?'; $st = $pdo->prepare("SELECT id FROM connections WHERE id IN ($ph)"); $st->execute($ids);
    return json_encode(array_values(array_intersect($ids, $st->fetchAll(PDO::FETCH_COLUMN))));
}
function chauto_seq_ok(?string $id): ?string { global $pdo; if (!$id) return null; $st = $pdo->prepare('SELECT id FROM ca_sequences WHERE id=?'); $st->execute([$id]); return $st->fetchColumn() ?: null; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') respond(chauto_payload() + ['can_edit' => $canEdit]);
require_method('POST');
$b = json_input(); $a = (string)($b['action'] ?? '');
if (!$canEdit) fail('Bạn chỉ có quyền xem', 403);
$now = gmdate('Y-m-d H:i:s');

if ($a === 'kw_save') {
    $words = array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), (array)($b['keywords'] ?? [])), fn($w) => $w !== '')));
    if (!$words) fail('Nhập ít nhất một từ khoá');
    $match = ($b['match'] ?? '') === 'exact' ? 'exact' : 'contains';
    $reply = mb_substr(trim((string)($b['reply'] ?? '')), 0, 2000); $seq = chauto_seq_ok(($b['sequence_id'] ?? '') ?: null);
    if ($reply === '' && !$seq) fail('Nhập tin trả lời hoặc gán một kịch bản chăm sóc');
    $vals = [(int)!empty($b['enabled']), json_encode($words, JSON_UNESCAPED_UNICODE), $match, $reply, chauto_channels_in($b['channels'] ?? []), $seq, $now];
    if (!empty($b['id'])) $pdo->prepare('UPDATE ca_keywords SET enabled=?, keywords=?, match_mode=?, reply=?, channels=?, sequence_id=?, updated_at=? WHERE id=?')->execute(array_merge($vals, [(string)$b['id']]));
    else $pdo->prepare('INSERT INTO ca_keywords(enabled,keywords,match_mode,reply,channels,sequence_id,updated_at,id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [gen_id('CK'), $me['id']]));
    log_activity('update', 'chat_auto', null, 'Từ khoá tự động: ' . implode(', ', $words), $me);
    respond(chauto_payload() + ['can_edit' => true]);
}
if ($a === 'seq_save') {
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 120); if ($name === '') fail('Nhập tên kịch bản');
    $steps = [];
    foreach ((array)($b['steps'] ?? []) as $s) { $t = mb_substr(trim((string)($s['text'] ?? '')), 0, 2000); if ($t === '') continue; $steps[] = ['delay_min' => max(0, min(60 * 24 * 365, (int)($s['delay_min'] ?? 0))), 'text' => $t]; }
    if (!$steps) fail('Thêm ít nhất một bước có nội dung tin nhắn');
    usort($steps, fn($x, $y) => $x['delay_min'] <=> $y['delay_min']);
    $vals = [$name, (int)!empty($b['enabled']), chauto_channels_in($b['channels'] ?? []), json_encode($steps, JSON_UNESCAPED_UNICODE), $now];
    if (!empty($b['id'])) $pdo->prepare('UPDATE ca_sequences SET name=?, enabled=?, channels=?, steps=?, updated_at=? WHERE id=?')->execute(array_merge($vals, [(string)$b['id']]));
    else $pdo->prepare('INSERT INTO ca_sequences(name,enabled,channels,steps,updated_at,id,created_by) VALUES(?,?,?,?,?,?,?)')->execute(array_merge($vals, [gen_id('CS'), $me['id']]));
    log_activity('update', 'chat_auto', null, 'Kịch bản chăm sóc: ' . $name, $me);
    respond(chauto_payload() + ['can_edit' => true]);
}
if ($a === 'rule_save') {
    $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 120); if ($name === '') fail('Nhập tên quy luật');
    $trig = (string)($b['trigger'] ?? ''); if (!isset(CHAUTO_TRIGGERS[$trig])) fail('Sự kiện không hợp lệ');
    $tag = $trig === 'tag_added' ? (string)($b['trigger_tag'] ?? '') : '';
    $acts = [];
    foreach ((array)($b['actions'] ?? []) as $x) {
        $type = (string)($x['type'] ?? ''); if (!isset(CHAUTO_ACTIONS[$type])) continue;
        if ($type === 'enroll') { $s = chauto_seq_ok(($x['sequence_id'] ?? '') ?: null); if (!$s) fail('Chọn kịch bản cho hành động "Đưa khách vào kịch bản"'); $acts[] = ['type' => $type, 'sequence_id' => $s]; }
        elseif ($type === 'unenroll') $acts[] = ['type' => $type, 'sequence_id' => chauto_seq_ok(($x['sequence_id'] ?? '') ?: null)];
        else { $t = mb_substr(trim((string)($x['text'] ?? '')), 0, 2000); if ($t === '') fail('Nhập nội dung cho hành động "Gửi tin nhắn tự động"'); $acts[] = ['type' => $type, 'text' => $t]; }
    }
    if (!$acts) fail('Thêm ít nhất một hành động');
    $vals = [$name, (int)!empty($b['enabled']), chauto_channels_in($b['channels'] ?? []), $trig, $tag, json_encode($acts, JSON_UNESCAPED_UNICODE), $now];
    if (!empty($b['id'])) $pdo->prepare('UPDATE ca_rules SET name=?, enabled=?, channels=?, trigger_type=?, trigger_tag=?, actions=?, updated_at=? WHERE id=?')->execute(array_merge($vals, [(string)$b['id']]));
    else $pdo->prepare('INSERT INTO ca_rules(name,enabled,channels,trigger_type,trigger_tag,actions,updated_at,id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [gen_id('CR'), $me['id']]));
    log_activity('update', 'chat_auto', null, 'Quy luật tự động: ' . $name, $me);
    respond(chauto_payload() + ['can_edit' => true]);
}
if ($a === 'toggle' || $a === 'del') {
    $tbl = $T[(string)($b['kind'] ?? '')] ?? fail('Loại không hợp lệ'); $id = (string)($b['id'] ?? '');
    if ($a === 'toggle') $pdo->prepare("UPDATE $tbl SET enabled=?, updated_at=? WHERE id=?")->execute([(int)!empty($b['enabled']), $now, $id]);
    else {
        if ($tbl === 'ca_sequences') {
            $pdo->prepare("UPDATE ca_enrollments SET status='stopped' WHERE sequence_id=? AND status='active'")->execute([$id]);
            $pdo->prepare('UPDATE ca_keywords SET sequence_id=NULL WHERE sequence_id=?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM $tbl WHERE id=?")->execute([$id]);
    }
    respond(chauto_payload() + ['can_edit' => true]);
}
if ($a === 'unenroll') { chauto_unenroll(($b['sequence_id'] ?? '') ?: null, (string)($b['thread_id'] ?? '')); respond(['ok' => true]); }
fail('Hành động không hợp lệ');
