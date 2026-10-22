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
    if (($in['action'] ?? '') === 'ai_suggest') {
        $p = current_project();
        $focus = trim((string)($in['focus'] ?? ''));
        $prefCh = trim((string)($in['channel'] ?? ''));
        $stC = $pdo->prepare('SELECT name, website, latest_move, teardowns FROM competitors WHERE project_id=? LIMIT 8'); $stC->execute([$PID]);
        $comps = $stC->fetchAll();
        $stI = $pdo->prepare('SELECT question AS q, insight AS ins, source AS src, subject FROM insights WHERE project_id=? ORDER BY created_at DESC LIMIT 8'); $stI->execute([$PID]);
        $insList = $stI->fetchAll();
        $stT = $pdo->prepare('SELECT name, volume_change, note FROM trends WHERE project_id=? ORDER BY updated_at DESC LIMIT 6'); $stT->execute([$PID]);
        $trList = $stT->fetchAll();

        $compSummary = [];
        foreach ($comps as $c) {
            $tds = array_values(array_filter(jdecode($c['teardowns'] ?? null), 'is_array'));
            $tdTxt = $tds ? (' | Mổ xẻ: ' . ($tds[0]['hook'] ?? '') . ' / Điểm yếu: ' . ($tds[0]['weaknesses'] ?? '')) : '';
            $compSummary[] = $c['name'] . ($c['latest_move'] ? ' (' . $c['latest_move'] . ')' : '') . $tdTxt;
        }
        $insSummary = array_map(fn($x) => ($x['q'] ?? '') . ' → ' . ($x['ins'] ?? ''), $insList);
        $trSummary = array_map(fn($x) => ($x['name'] ?? '') . ' (' . ($x['volume_change'] ?? '') . ')', $trList);

        $suggestions = [];
        $aiUsed = false;
        $aiNote = null;

        if (is_file(__DIR__ . '/_ai_lib.php')) {
            require_once __DIR__ . '/_ai_lib.php';
            $sys = 'Bạn là Giám đốc Chiến lược Marketing. Hãy đề xuất 3 ý tưởng chiến dịch marketing thực tế, khác biệt cho dự án. Trả về JSON có khoá "ideas" là mảng 3 phần tử, mỗi phần tử gồm: title (tiêu đề chiến dịch), insight (bằng chứng/insight khách hàng hoặc kẽ hở đối thủ), channel (Google Ads | Facebook Ads | TikTok Ads | Website / SEO | Zalo OA | YouTube), audience (đối tượng mục tiêu), message (thông điệp chủ đạo), cta (nút kêu gọi hành động), kpi (chỉ số mục tiêu cụ thể), budget (ngân sách đề xuất VNĐ dạng số nguyên), source (nguồn gợi ý).';
            $ctx = "Dự án: {$p['name']} ({$p['domain']}). Mục tiêu: {$p['goal']}."
                . ($focus !== '' ? "\nChủ đề/Sản phẩm trọng tâm yêu cầu: $focus" : '')
                . ($prefCh !== '' ? "\nKênh ưu tiên: $prefCh" : '')
                . ($compSummary ? "\nDữ liệu đối thủ: " . implode(' ; ', $compSummary) : '')
                . ($insSummary ? "\nInsight/VoC khách hàng: " . implode(' ; ', $insSummary) : '')
                . ($trSummary ? "\nXu hướng thị trường: " . implode(' ; ', $trSummary) : '');
            $r = ai_generate($sys, [['role' => 'user', 'content' => $ctx]], ['max_tokens' => 1400, 'effort' => 'low', 'purpose' => 'idea_suggest']);
            if (!empty($r['ok']) && preg_match('~\{.*\}~s', (string)$r['text'], $mj) && ($j = json_decode($mj[0], true)) && !empty($j['ideas']) && is_array($j['ideas'])) {
                foreach (array_slice($j['ideas'], 0, 4) as $item) {
                    if (!is_array($item) || empty($item['title'])) continue;
                    $suggestions[] = [
                        'title' => mb_substr(trim((string)$item['title']), 0, 150),
                        'insight' => mb_substr(trim((string)($item['insight'] ?? '')), 0, 600),
                        'channel' => mb_substr(trim((string)($item['channel'] ?? ($prefCh ?: 'Facebook Ads'))), 0, 60),
                        'audience' => mb_substr(trim((string)($item['audience'] ?? '')), 0, 150),
                        'message' => mb_substr(trim((string)($item['message'] ?? '')), 0, 250),
                        'cta' => mb_substr(trim((string)($item['cta'] ?? 'Đăng ký tư vấn')), 0, 80),
                        'kpi' => mb_substr(trim((string)($item['kpi'] ?? '')), 0, 150),
                        'budget' => max(0, (int)($item['budget'] ?? 10000000)),
                        'source' => mb_substr(trim((string)($item['source'] ?? 'AI phân tích dữ liệu dự án')), 0, 120),
                    ];
                }
                if ($suggestions) $aiUsed = true;
            } else {
                $aiNote = $r['error'] ?? null;
            }
        }

        if (!$suggestions) {
            $topicLabel = $focus !== '' ? $focus : ($p['name'] ?: 'dịch vụ chủ lực');
            $cName = !empty($comps[0]['name']) ? $comps[0]['name'] : 'đối thủ cùng ngành';
            $ins0 = !empty($insList[0]['ins']) ? $insList[0]['ins'] : 'Khách hàng cần báo giá minh bạch, xem thực tế quy trình lắp đặt/triển khai và chế độ bảo hành rõ ràng trước khi để lại SĐT.';
            $suggestions = [
                [
                    'title' => "Chiến dịch Thu Lead Chuyển Đổi: $topicLabel — Cam Kết Minh Bạch & Bảo Hành",
                    'insight' => $ins0,
                    'channel' => $prefCh ?: 'Google Ads',
                    'audience' => 'Khách hàng đang tìm kiếm giải pháp/báo giá ' . $topicLabel . ' có nhu cầu thực tế cao',
                    'message' => $p['name'] . ' — Giải pháp ' . $topicLabel . ' chính hãng, báo giá minh bạch trong 5 phút, bảo hành tận tâm.',
                    'cta' => 'Nhận báo giá & ưu đãi ngay',
                    'kpi' => '35–50 lead chất lượng / tháng, tỷ lệ liên hệ thành công ≥ 80%',
                    'budget' => 15000000,
                    'source' => 'Tổng hợp mục tiêu dự án & Insight khách hàng',
                ],
                [
                    'title' => "Chiến dịch Video Thực Tế & So Sánh Trực Quan ($topicLabel)",
                    'insight' => "Qua theo dõi $cName, khách hàng thường phân vân về chất lượng thực tế sau lắp đặt/sử dụng. Nội dung video thực tế + feedback khách thật giúp tăng tỷ lệ tin tưởng.",
                    'channel' => $prefCh ?: 'Facebook Ads',
                    'audience' => 'Khách hàng quan tâm ' . $topicLabel . ', từng tương tác fanpage/website hoặc xem video ngành hàng',
                    'message' => 'Trải nghiệm thực tế tại ' . $p['name'] . ': Quy trình chuẩn chỉnh, kỹ thuật viên giàu kinh nghiệm, hỗ trợ trọn đời.',
                    'cta' => 'Đặt lịch tư vấn / trải nghiệm',
                    'kpi' => '40+ tin nhắn/lead tư vấn, CPA trong ngưỡng mục tiêu',
                    'budget' => 12000000,
                    'source' => 'Phân tích đối thủ & hành vi khách hàng',
                ],
                [
                    'title' => "Chiến dịch Chăm Sóc & Ưu Đãi Khách Cũ / Giới Thiệu ($topicLabel)",
                    'insight' => 'Chi phí thu hút khách mới trên kênh quảng cáo ngày càng tăng; khai thác tệp khách đã liên hệ hoặc khách cũ nâng cấp mang lại ROAS cao nhất.',
                    'channel' => $prefCh ?: 'Zalo OA',
                    'audience' => 'Tệp khách hàng đã từng hỏi tư vấn hoặc sử dụng dịch vụ tại ' . $p['name'],
                    'message' => 'Ưu đãi đặc quyền dành riêng cho khách hàng ' . $p['name'] . ' — Kiểm tra/tư vấn miễn phí & quà tặng nâng cấp.',
                    'cta' => 'Nhận ưu đãi qua Zalo / Hotline',
                    'kpi' => '15–25 đơn nâng cấp / quay lại mỗi tháng',
                    'budget' => 5000000,
                    'source' => 'Tối ưu phễu CRM & Chăm sóc dự án',
                ],
            ];
        }
        respond(['suggestions' => $suggestions, 'ai_used' => $aiUsed, 'note' => $aiNote]);
    }

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
    require_project_write();
    $id = (string)($_GET['id'] ?? '');
    $i = need('ideas', $id, 'ý tưởng');
    $pdo->prepare('DELETE FROM ideas WHERE id=? AND project_id=?')->execute([$id, $PID]);
    log_activity('delete', 'idea', $id, 'Xoá ý tưởng ' . $i['title']);
    respond(['ok' => true]);
}
fail('Method không hỗ trợ', 405);
