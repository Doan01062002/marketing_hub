<?php
/* Kịch bản tự động.
   GET                          → {automations, templates, meta, sequences}
   GET ?runs=1[&automation_id&status&limit&before] → {runs}
   POST {…}                     → lưu (tạo / sửa)               [quản trị, trưởng nhóm]
   POST ?action=toggle {id,enabled}
   POST ?action=use_template {key, enable}
   POST ?action=test {id} | {automation:{…bản nháp…}}  → chạy thử, không tác động
   DELETE ?id=…                                                  [quản trị, trưởng nhóm] */
require __DIR__ . '/_boot.php';
require_once __DIR__ . '/_automation_lib.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$PID = pid();

function public_automation(array $a): array {
    $a = auto_decode($a);
    return [
        'id' => $a['id'], 'name' => $a['name'], 'trigger' => $a['trigger'], 'conditions' => (object)$a['conditions'],
        'actions' => $a['actions'], 'enabled' => (bool)$a['enabled'], 'template_key' => $a['template_key'],
        'created_by' => $a['created_by'], 'last_run_at' => $a['last_run_at'], 'run_count' => (int)$a['run_count'],
        'created_at' => $a['created_at'], 'updated_at' => $a['updated_at'],
    ];
}

/** Kiểm tra & chuẩn hoá dữ liệu kịch bản. */
function automation_clean(array $in): array {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Thiếu tên kịch bản');
    $trigger = (string)($in['trigger'] ?? '');
    if (!isset(AUTO_TRIGGERS[$trigger])) fail('Sự kiện kích hoạt không hợp lệ');
    $cond = is_array($in['conditions'] ?? null) ? $in['conditions'] : [];
    $cond['match'] = ($cond['match'] ?? 'all') === 'any' ? 'any' : 'all';
    $cond['rules'] = array_values(array_filter($cond['rules'] ?? [], fn($r) => is_array($r) && trim((string)($r['field'] ?? '')) !== ''));
    $cond['params'] = is_array($cond['params'] ?? null) ? $cond['params'] : [];
    $cond['max_per_hour'] = max(0, min(1000, (int)($cond['max_per_hour'] ?? 30)));
    $acts = array_values(array_filter(is_array($in['actions'] ?? null) ? $in['actions'] : [], 'is_array'));
    if (!$acts) fail('Kịch bản cần ít nhất một hành động');
    foreach ($acts as $i => $ac) {
        $n = $i + 1;
        if (!isset(AUTO_ACTIONS[$ac['type'] ?? ''])) fail("Hành động $n không hợp lệ");
        if (in_array($ac['type'], ['reply_comment', 'hide_comment', 'comment_to_lead', 'assign_comment'], true) && $trigger !== 'comment.new') {
            fail('Hành động "' . AUTO_ACTIONS[$ac['type']] . '" chỉ dùng với sự kiện "Có bình luận mới"');
        }
        if (in_array($ac['type'], ['enroll_sequence', 'set_lead_state', 'add_lead_note'], true) && !in_array($trigger, ['lead.new', 'lead.state', 'lead.no_response', 'comment.new'], true)) {
            fail('Hành động "' . AUTO_ACTIONS[$ac['type']] . '" cần sự kiện liên quan đến đầu mối');
        }
        if ($ac['type'] === 'send_webhook' && !empty($ac['url']) && !preg_match('~^https?://~i', $ac['url'])) fail("Hành động $n: địa chỉ webhook phải bắt đầu bằng http(s)://");
        if ($ac['type'] === 'send_email' && ($ac['to'] ?? 'lead') === 'fixed' && !empty($ac['address']) && !filter_var($ac['address'], FILTER_VALIDATE_EMAIL)) fail("Hành động $n: email nhận không hợp lệ");
        if ($ac['type'] === 'reply_comment' && trim((string)($ac['text'] ?? '')) === '') fail("Hành động $n: nhập nội dung trả lời mẫu (kể cả khi dùng AI)");
        if ($ac['type'] === 'enroll_sequence' && !auto_row('SELECT 1 FROM sequences WHERE id=? AND project_id=?', [(string)($ac['sequence_id'] ?? ''), pid()])) fail("Hành động $n: chọn chuỗi chăm sóc của dự án này");
        if ($ac['type'] === 'set_lead_state' && (!isset(AUTO_LEAD_STATES[$ac['state'] ?? '']) || in_array($ac['state'], ['ACCEPTED', 'REJECTED'], true))) fail("Hành động $n: chọn trạng thái (CRM nhận / từ chối phải do người duyệt)");
    }
    $enabled = !empty($in['enabled']);
    $p = $cond['params'];
    if (in_array($trigger, ['schedule.daily', 'schedule.weekly'], true) && !preg_match('/^\d{1,2}:\d{2}$/', (string)($p['at'] ?? ''))) fail('Nhập giờ chạy dạng HH:MM');
    if ($trigger === 'metric.threshold' && $enabled && (!is_numeric($p['value'] ?? null) || trim((string)($p['metric_key'] ?? '')) === '')) fail('Nhập chỉ số và ngưỡng trước khi bật kịch bản');
    return ['name' => mb_substr($name, 0, 150), 'trigger' => $trigger, 'conditions' => $cond, 'actions' => $acts, 'enabled' => $enabled];
}

if ($method === 'GET') {
    if (!empty($_GET['runs'])) {
        $w = ['(a.project_id=? OR r.automation_id LIKE \'DRAFT-%\')']; $args = [$PID];
        if (!empty($_GET['automation_id'])) { $w[] = 'r.automation_id=?'; $args[] = $_GET['automation_id']; }
        if (!empty($_GET['status'])) { $w[] = 'r.status=?'; $args[] = $_GET['status']; }
        if (!empty($_GET['before'])) { $w[] = 'r.id<?'; $args[] = (int)$_GET['before']; }
        $lim = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $rows = auto_q('SELECT r.*, a.name automation_name FROM automation_runs r LEFT JOIN automations a ON a.id=r.automation_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY r.id DESC LIMIT $lim", $args)->fetchAll();
        respond(['runs' => $rows]);
    }
    $autos = array_map('public_automation', auto_q('SELECT * FROM automations WHERE project_id=? ORDER BY created_at', [$PID])->fetchAll());
    $used = array_count_values(array_filter(array_column($autos, 'template_key')));
    $tpls = [];
    foreach (automation_templates() as $k => $t) {
        $r = automation_template_resolved($k, false);
        $tpls[] = ['key' => $k, 'name' => $t['name'], 'desc' => $t['desc'], 'icon' => $t['icon'], 'trigger' => $t['trigger'],
            'conditions' => (object)$r['conditions'], 'actions' => $r['actions'], 'used' => $used[$k] ?? 0, 'needs_sequence' => !empty($t['needs_sequence'])];
    }
    $seqs = auto_q('SELECT id,name,enabled FROM sequences WHERE project_id=? ORDER BY created_at', [$PID])->fetchAll();
    respond([
        'automations' => $autos, 'templates' => $tpls,
        'sequences' => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'enabled' => (bool)$s['enabled']], $seqs),
        'meta' => ['triggers' => AUTO_TRIGGERS, 'scheduled' => AUTO_SCHEDULED, 'actions' => AUTO_ACTIONS, 'lead_states' => AUTO_LEAD_STATES,
            'intents' => AUTO_INTENTS, 'quiet_hours' => setting_get('notify.quiet_hours', ''), 'in_quiet' => in_quiet_hours(),
            'cron_last_run' => setting_get('cron.last_run'), 'smtp_ready' => mail_configured(),
            'webhook_ready' => (string)setting_get('webhook.default_url', '') !== '', 'hotline' => (string)setting_get('brand.hotline', '')],
        'can_edit' => in_array(project_role(), PROJECT_ADMIN_ROLES, true),
    ]);
}

if ($method === 'POST') {
    $me = require_project_admin();
    $in = json_input();

    if ($action === 'toggle') {
        $a = auto_row('SELECT * FROM automations WHERE id=? AND project_id=?', [(string)($in['id'] ?? ''), $PID]);
        if (!$a) fail('Không tìm thấy kịch bản', 404);
        $en = !empty($in['enabled']);
        if ($en) automation_clean(['enabled' => true] + auto_decode($a));
        auto_q("UPDATE automations SET enabled=?, updated_at=datetime('now') WHERE id=?", [$en ? 1 : 0, $a['id']]);
        log_activity('update', 'automation', $a['id'], ($en ? 'Bật' : 'Tắt') . ' kịch bản ' . $a['name']);
        respond(['ok' => true]);
    }

    if ($action === 'use_template') {
        $key = (string)($in['key'] ?? '');
        $t = automation_template_resolved($key, true);
        if (!$t) fail('Không có kịch bản mẫu này', 404);
        $enable = !empty($in['enable']);
        $note = null;
        if ($enable && $t['trigger'] === 'metric.threshold' && !is_numeric($t['conditions']['params']['value'] ?? null)) { $enable = false; $note = 'Cần nhập ngưỡng trước khi bật — kịch bản đã được tạo ở trạng thái tắt'; }
        $id = gen_id('AU');
        auto_q('INSERT INTO automations(id,project_id,name,trigger,conditions,actions,enabled,template_key,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [
            $id, $PID, $t['name'], $t['trigger'], json_encode($t['conditions'], JSON_UNESCAPED_UNICODE), json_encode($t['actions'], JSON_UNESCAPED_UNICODE),
            $enable ? 1 : 0, $key, $me['id'],
        ]);
        log_activity('create', 'automation', $id, 'Dùng kịch bản mẫu ' . $t['name'] . ($enable ? ' (đã bật)' : ''));
        respond(['id' => $id, 'enabled' => $enable, 'note' => $note], 201);
    }

    if ($action === 'test') {
        if (!empty($in['id'])) {
            $a = auto_row('SELECT * FROM automations WHERE id=? AND project_id=?', [(string)$in['id'], $PID]);
            if (!$a) fail('Không tìm thấy kịch bản', 404);
            $r = automation_dry_run($a, $in['entity_id'] ?? null);
            respond(['result' => $r]);
        }
        $d = automation_clean(($in['automation'] ?? []) + ['enabled' => false]);
        $tmp = 'DRAFT-' . bin2hex(random_bytes(4));
        $r = automation_dry_run(['id' => $tmp, 'project_id' => $PID, 'name' => $d['name'], 'trigger' => $d['trigger'], 'conditions' => $d['conditions'], 'actions' => $d['actions'],
            'enabled' => 0, 'created_by' => $me['id']], $in['entity_id'] ?? null);
        auto_q('DELETE FROM automation_runs WHERE automation_id=?', [$tmp]);
        respond(['result' => $r]);
    }

    $d = automation_clean($in);
    $id = (string)($in['id'] ?? '');
    if ($id !== '') {
        $row = auto_row('SELECT * FROM automations WHERE id=? AND project_id=?', [$id, $PID]);
        if (!$row) fail('Không tìm thấy kịch bản', 404);
        auto_q("UPDATE automations SET name=?, trigger=?, conditions=?, actions=?, enabled=?, updated_at=datetime('now') WHERE id=? AND project_id=?", [
            $d['name'], $d['trigger'], json_encode($d['conditions'], JSON_UNESCAPED_UNICODE), json_encode($d['actions'], JSON_UNESCAPED_UNICODE), $d['enabled'] ? 1 : 0, $id, $PID]);
        log_activity('update', 'automation', $id, 'Sửa kịch bản ' . $d['name']);
    } else {
        $id = gen_id('AU');
        $tk = isset(automation_templates()[$in['template_key'] ?? '']) ? $in['template_key'] : null;
        auto_q('INSERT INTO automations(id,project_id,name,trigger,conditions,actions,enabled,template_key,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [
            $id, $PID, $d['name'], $d['trigger'], json_encode($d['conditions'], JSON_UNESCAPED_UNICODE), json_encode($d['actions'], JSON_UNESCAPED_UNICODE),
            $d['enabled'] ? 1 : 0, $tk, $me['id']]);
        log_activity('create', 'automation', $id, 'Tạo kịch bản ' . $d['name']);
    }
    respond(['automation' => public_automation(auto_row('SELECT * FROM automations WHERE id=?', [$id]))]);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = (string)($_GET['id'] ?? '');
    $row = auto_row('SELECT name FROM automations WHERE id=? AND project_id=?', [$id, $PID]);
    if (!$row) fail('Không tìm thấy kịch bản', 404);
    auto_q('DELETE FROM automations WHERE id=?', [$id]);
    auto_q('DELETE FROM app_settings WHERE key=?', ['automation.rr.' . $id]);
    log_activity('delete', 'automation', $id, 'Xoá kịch bản ' . $row['name']);
    respond(['ok' => true]);
}

fail('Method không hỗ trợ', 405);
