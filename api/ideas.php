<?php
/* Ý tưởng chiến dịch (Tiện ích) — thuộc dự án. 11/10/2026
   GET                        → {ideas}
   POST {id?, title, insight, source, channel, audience, message, cta, kpi, budget, landing_id, research_id, state}
   POST {action:'campaign_draft', id}  → tạo ĐÚNG MỘT chiến dịch nháp (ngân sách 0, chờ duyệt) — nhấp lại không tạo trùng
   DELETE ?id= */
require __DIR__ . '/_boot.php';
$me = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$PID = pid();

function idea_row(array $r): array {
    foreach (['budget'] as $k) $r[$k] = (int)$r[$k];
    return $r;
}

if ($method === 'GET') {
    $st = $pdo->prepare('SELECT * FROM ideas WHERE project_id=? ORDER BY created_at DESC'); $st->execute([$PID]);
    respond(['ideas' => array_map('idea_row', $st->fetchAll())]);
}

if ($method === 'POST') {
    require_project_write();
    $in = json_input();
    if (($in['action'] ?? '') === 'campaign_draft') {
        $i = need('ideas', (string)($in['id'] ?? ''), 'ý tưởng');
        if (!empty($i['campaign_id']) && scoped('campaigns', $i['campaign_id'])) respond(['created' => false, 'campaign_id' => $i['campaign_id']]);
        $cid = gen_id('CMP');
        $chan = trim((string)$i['channel']);
        $obj = trim(implode(' ', array_filter([$i['message'] ? 'Thông điệp: ' . $i['message'] . '.' : '', $i['cta'] ? 'CTA: ' . $i['cta'] . '.' : '', $i['kpi'] ? 'KPI: ' . $i['kpi'] . '.' : '', $i['insight'] ? 'Insight: ' . $i['insight'] : ''])));
        // Chiến dịch nháp: budget = ngân sách ĐỀ XUẤT (kế hoạch), spent 0, approval PENDING — không gửi gì lên nền tảng quảng cáo.
        $pdo->prepare('INSERT INTO campaigns(id,project_id,name,subject,co,br,state,approval,health,owner_id,channels,budget,spent,leads,roas,start_date,end_date,objective)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,0,0,NULL,NULL,?)')->execute([
            $cid, $PID, mb_substr($i['title'], 0, 150), mb_substr((string)$i['audience'], 0, 120), 'all', 'all', 'DRAFT', 'PENDING', 'ON_TRACK', $me['id'],
            json_encode($chan !== '' ? [$chan] : [], JSON_UNESCAPED_UNICODE), (int)$i['budget'], mb_substr($obj, 0, 1000)]);
        $pdo->prepare("UPDATE ideas SET campaign_id=?, state='converted', updated_at=datetime('now') WHERE id=? AND project_id=?")->execute([$cid, $i['id'], $PID]);
        log_activity('create', 'campaign', $cid, 'Chuyển ý tưởng "' . $i['title'] . '" thành chiến dịch nháp');
        respond(['created' => true, 'campaign_id' => $cid], 201);
    }
    $id = (string)($in['id'] ?? '');
    $f = ['title', 'insight', 'source', 'channel', 'audience', 'message', 'cta', 'kpi', 'landing_id', 'research_id', 'state'];
    $vals = []; foreach ($f as $k) $vals[$k] = isset($in[$k]) ? mb_substr(trim((string)$in[$k]), 0, $k === 'insight' ? 2000 : 300) : null;
    $budget = max(0, (int)($in['budget'] ?? 0));
    if ($id !== '') {
        need('ideas', $id, 'ý tưởng');
        $set = []; $v = [];
        foreach ($f as $k) if (array_key_exists($k, $in)) { $set[] = "$k=?"; $v[] = $vals[$k]; }
        if (array_key_exists('budget', $in)) { $set[] = 'budget=?'; $v[] = $budget; }
        if (!$set) fail('Không có gì để cập nhật');
        $set[] = "updated_at=datetime('now')"; $v[] = $id; $v[] = $PID;
        $pdo->prepare('UPDATE ideas SET ' . implode(',', $set) . ' WHERE id=? AND project_id=?')->execute($v);
        log_activity('update', 'idea', $id, 'Sửa ý tưởng ' . ($vals['title'] ?? $id));
        respond(['ok' => true]);
    }
    if (($vals['title'] ?? '') === '') fail('Thiếu tiêu đề ý tưởng');
    $id = gen_id('ID');
    $pdo->prepare('INSERT INTO ideas(id,project_id,title,insight,source,channel,audience,message,cta,kpi,budget,landing_id,research_id,state,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $PID, $vals['title'], $vals['insight'], $vals['source'], $vals['channel'], $vals['audience'], $vals['message'], $vals['cta'], $vals['kpi'], $budget, $vals['landing_id'], $vals['research_id'], $vals['state'] ?: 'new', $me['id']]);
    log_activity('create', 'idea', $id, 'Lưu ý tưởng ' . $vals['title']);
    respond(['id' => $id], 201);
}

if ($method === 'DELETE') {
    require_project_admin();
    $id = (string)($_GET['id'] ?? '');
    $i = need('ideas', $id, 'ý tưởng');
    $pdo->prepare('DELETE FROM ideas WHERE id=? AND project_id=?')->execute([$id, $PID]);
    log_activity('delete', 'idea', $id, 'Xoá ý tưởng ' . $i['title']);
    respond(['ok' => true]);
}
fail('Method không hỗ trợ', 405);
