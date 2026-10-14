<?php
/* Chuỗi chăm sóc khách (nurture).
   GET [?lead_id=…]                        → {sequences, enrollments}
   POST {id?, name, steps, stop_on_states, enabled}       → lưu chuỗi       [quản trị, trưởng nhóm]
   POST ?action=sample                      → tạo chuỗi mẫu "Chăm sóc 7 ngày" (tắt) [quản trị, trưởng nhóm]
   POST ?action=enroll {sequence_id, lead_id}  → đưa đầu mối vào chuỗi (mọi người dùng — làm việc với đầu mối)
   POST ?action=stop {id} | ?action=resume {id}  → dừng / chạy lại một lượt tham gia
   DELETE ?id=…                             → xoá chuỗi + các lượt tham gia  [quản trị, trưởng nhóm] */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_automation_lib.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$PID = pid();

function public_sequence(array $s): array {
    return ['id' => $s['id'], 'name' => $s['name'], 'steps' => seq_steps($s), 'stop_on_states' => jdecode($s['stop_on_states'], []),
        'enabled' => (bool)$s['enabled'], 'created_at' => $s['created_at'], 'updated_at' => $s['updated_at']];
}

if ($method === 'GET') {
    $w = ' WHERE s.project_id=?'; $args = [$PID];
    if (!empty($_GET['lead_id'])) { $w .= ' AND e.lead_id=?'; $args[] = $_GET['lead_id']; }
    $enr = auto_q("SELECT e.*, l.name lead_name, l.email lead_email, l.state lead_state, s.name sequence_name, s.steps
        FROM sequence_enrollments e LEFT JOIN leads l ON l.id=e.lead_id LEFT JOIN sequences s ON s.id=e.sequence_id$w
        ORDER BY CASE e.status WHEN 'active' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END, e.next_run_at, e.id DESC LIMIT 300", $args)->fetchAll();
    $enr = array_map(function ($e) {
        $e['step_count'] = count(jdecode($e['steps'] ?? null, []));
        unset($e['steps']);
        return $e;
    }, $enr);
    respond(['sequences' => array_map('public_sequence', auto_q('SELECT * FROM sequences WHERE project_id=? ORDER BY created_at', [$PID])->fetchAll()), 'enrollments' => $enr,
        'can_edit' => in_array(project_role(), PROJECT_ADMIN_ROLES, true)]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();

    if ($action === 'enroll') {
        if (!scoped('sequences', (string)($in['sequence_id'] ?? ''))) fail('Chuỗi không thuộc dự án này', 404);
        if (!scoped('leads', (string)($in['lead_id'] ?? ''))) fail('Đầu mối không thuộc dự án này', 404);
        $r = seq_enroll((string)($in['sequence_id'] ?? ''), (string)($in['lead_id'] ?? ''), true);
        if (!$r['ok']) fail(ucfirst($r['msg']));
        $seq = auto_row('SELECT name, enabled FROM sequences WHERE id=?', [$in['sequence_id']]);
        log_activity('update', 'lead', (string)$in['lead_id'], 'Đưa vào chuỗi chăm sóc ' . $seq['name']);
        respond(['ok' => true, 'msg' => $r['msg'] . ((int)$seq['enabled'] ? '' : ' — lưu ý: chuỗi đang TẮT, các bước sẽ chạy khi bật chuỗi')]);
    }
    if ($action === 'stop' || $action === 'resume') {
        $e = auto_row('SELECT e.* FROM sequence_enrollments e JOIN sequences s ON s.id=e.sequence_id WHERE e.id=? AND s.project_id=?', [(int)($in['id'] ?? 0), $PID]);
        if (!$e) fail('Không tìm thấy lượt tham gia', 404);
        if ($action === 'stop') auto_q("UPDATE sequence_enrollments SET status='stopped', last_error=? WHERE id=?", ['Dừng thủ công bởi ' . $me['name'], $e['id']]);
        else auto_q("UPDATE sequence_enrollments SET status='active', next_run_at=?, last_error=NULL WHERE id=?", [date('Y-m-d H:i:s'), $e['id']]);
        log_activity('update', 'lead', $e['lead_id'], ($action === 'stop' ? 'Dừng' : 'Chạy lại') . ' chuỗi chăm sóc');
        respond(['ok' => true]);
    }

    $me = require_project_admin();
    if ($action === 'sample') {
        $id = seq_ensure_sample();
        log_activity('create', 'automation', $id, 'Tạo chuỗi chăm sóc mẫu ' . SEQ_SAMPLE_NAME);
        respond(['id' => $id], 201);
    }

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên chuỗi');
    $steps = [];
    foreach (array_values(array_filter($in['steps'] ?? [], 'is_array')) as $i => $s) {
        $ch = (string)($s['channel'] ?? 'email');
        if (!in_array($ch, ['email', 'webhook', 'task', 'note', 'notify'], true)) fail('Bước ' . ($i + 1) . ': kênh không hợp lệ');
        if ($ch === 'webhook' && !empty($s['url']) && !preg_match('~^https?://~i', $s['url'])) fail('Bước ' . ($i + 1) . ': webhook phải bắt đầu bằng http(s)://');
        if (trim((string)($s['subject'] ?? '')) === '' && trim((string)($s['body'] ?? '')) === '') fail('Bước ' . ($i + 1) . ': nhập tiêu đề hoặc nội dung');
        $steps[] = ['delay_hours' => max(0, round((float)($s['delay_hours'] ?? 0), 2)), 'channel' => $ch,
            'subject' => mb_substr((string)($s['subject'] ?? ''), 0, 200), 'body' => mb_substr((string)($s['body'] ?? ''), 0, 5000),
            'use_ai' => !empty($s['use_ai']), 'url' => (string)($s['url'] ?? ''), 'board' => (string)($s['board'] ?? 'lead'),
            'assignee' => (string)($s['assignee'] ?? ''), 'due_hours' => (float)($s['due_hours'] ?? 24)];
    }
    if (!$steps) fail('Chuỗi cần ít nhất một bước');
    $stop = array_values(array_intersect(array_keys(AUTO_LEAD_STATES), (array)($in['stop_on_states'] ?? [])));
    $id = (string)($in['id'] ?? '');
    $vals = [$name, json_encode($steps, JSON_UNESCAPED_UNICODE), json_encode($stop), !empty($in['enabled']) ? 1 : 0];
    if ($id !== '') {
        if (!auto_row('SELECT 1 FROM sequences WHERE id=? AND project_id=?', [$id, $PID])) fail('Không tìm thấy chuỗi', 404);
        auto_q("UPDATE sequences SET name=?, steps=?, stop_on_states=?, enabled=?, updated_at=datetime('now') WHERE id=? AND project_id=?", [...$vals, $id, $PID]);
        log_activity('update', 'automation', $id, 'Sửa chuỗi chăm sóc ' . $name);
    } else {
        $id = gen_id('SEQ');
        auto_q('INSERT INTO sequences(name,steps,stop_on_states,enabled,id,project_id) VALUES (?,?,?,?,?,?)', [...$vals, $id, $PID]);
        log_activity('create', 'automation', $id, 'Tạo chuỗi chăm sóc ' . $name);
    }
    respond(['sequence' => public_sequence(auto_row('SELECT * FROM sequences WHERE id=?', [$id]))]);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = (string)($_GET['id'] ?? '');
    $row = auto_row('SELECT name FROM sequences WHERE id=? AND project_id=?', [$id, $PID]);
    if (!$row) fail('Không tìm thấy chuỗi', 404);
    auto_q('DELETE FROM sequence_enrollments WHERE sequence_id=?', [$id]);
    auto_q('DELETE FROM sequences WHERE id=?', [$id]);
    auto_q('DELETE FROM app_settings WHERE key=?', ['automation.rr.SEQ:' . $id]);
    log_activity('delete', 'automation', $id, 'Xoá chuỗi chăm sóc ' . $row['name']);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
