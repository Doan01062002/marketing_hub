<?php
/* AI CHAT cho Zalo cá nhân (14/10/2026). Cấu hình app_settings 'zalo_user.ai' (một số Zalo / toàn hệ thống).
   AI chỉ trả lời khi: bật · người gửi trong phạm vi (danh sách chọn, hoặc mọi người trừ danh sách chặn) · khớp từ khoá (nếu có đặt)
   · trong giờ hoạt động · chủ tài khoản không vừa tự nhắn người đó · chưa vượt số tin AI/người/ngày.
   Chủ đề ngoài phạm vi: AI tự trả skip=true (im lặng). Chạy bằng khoá API nếu có, không thì xếp việc cho máy HL (Claude Max). */
require_once __DIR__ . '/_zalo_user_lib.php';

/** Kịch bản bán hàng mặc định (sửa được ở Cài đặt → Kịch bản bán hàng). */
function zu_ai_sales_default(): string {
    return "VAI TRÒ: chuyên gia tư vấn bán hàng của doanh nghiệp (xem 'Công việc của chủ tài khoản' và dữ liệu website bên dưới), trả lời trên Zalo của chủ tài khoản.\n"
        . "1. NHẬN DIỆN NHU CẦU từ tin mới nhất + mạch chuyện: khách đang cần sản phẩm/dịch vụ nào, cho mục đích gì.\n"
        . "2. NHU CẦU CHƯA RÕ → hỏi 1 câu ngắn đúng trọng tâm (mục đích, quy mô, thời gian, ngân sách). Không hỏi dồn quá 2 câu một lúc.\n"
        . "3. GỬI CHI TIẾT DỊCH VỤ khi khách hỏi \"bên em làm gì / có X không / khác gì bên khác\": 2–3 lợi ích chính + 1 link trang dịch vụ đúng loại (chỉ link có trong dữ liệu).\n"
        . "4. GỬI BÁO GIÁ khi khách hỏi giá HOẶC đã rõ nhu cầu: đưa gói/sản phẩm PHÙ HỢP NHẤT (tên + giá + 1–2 điểm nổi bật), kèm link bảng giá nếu dữ liệu có. Chỉ dùng giá có trong dữ liệu; nhu cầu đặc thù → hẹn báo giá chính xác sau khi trao đổi.\n"
        . "5. XIN SỐ ĐIỆN THOẠI khi khách có ý định rõ (muốn mua/làm, hỏi thời gian, thanh toán, hợp đồng), nhu cầu phức tạp, hoặc muốn gọi/gặp: xin SĐT + giờ tiện nghe để gọi tư vấn. Khách đã để SĐT → điền phone, cảm ơn, hẹn gọi; KHÔNG xin lại.\n"
        . "6. ĐƯA HOTLINE (nếu có trong dữ liệu) khi khách muốn gọi ngay, cần gấp hoặc ngoài giờ làm việc.\n"
        . "7. GỬI SẢN PHẨM/DỰ ÁN MẪU (link trong dữ liệu) khi khách muốn xem mẫu, hỏi uy tín, so sánh.\n"
        . "8. KHÁCH CHÊ ĐẮT → nêu giá trị nhận được hoặc gợi ý lựa chọn thấp hơn phù hợp; KHÔNG tự giảm giá, không hứa khuyến mãi.\n"
        . "9. YÊU CẦU ĐẶC THÙ / LÀM THEO YÊU CẦU → hỏi mục đích và 2–3 yêu cầu chính; không báo giá cứng; xin SĐT để chủ tài khoản trực tiếp tư vấn; handoff=true.\n"
        . "10. KHÁCH ĐANG DÙNG DỊCH VỤ báo lỗi → ghi nhận, xin thông tin cần thiết, hẹn kiểm tra; gấp → handoff=true.\n"
        . "11. MỖI TIN kết thúc bằng 1 bước tiếp theo rõ ràng (1 câu hỏi hoặc 1 lời mời), tối đa 1–2 link/tin, không kết thúc cụt, không dài dòng.";
}
function zu_ai_cfg(): array {
    $c = jdecode(setting_get('zalo_user.ai') ?: null, []);
    return $c + ['sales_mode' => true, 'sales_playbook' => '', 'enabled' => false, 'scope' => 'list', 'people' => [], 'block' => [], 'keywords' => '', 'topics' => '', 'off_topic_silent' => true,
        'xung_ho' => 'em-anh/chị', 'persona' => '', 'knowledge' => '', 'use_project' => true, 'signature' => '',
        'hour_from' => 0, 'hour_to' => 24, 'delay_sec' => 8, 'quiet_min' => 0, 'max_per_day' => 20];
}
function zu_ai_save(array $in): array {
    $c = zu_ai_cfg();
    if (isset($in['sales_playbook'])) { $pb = mb_substr(trim((string)$in['sales_playbook']), 0, 8000); $c['sales_playbook'] = $pb === trim(zu_ai_sales_default()) ? '' : $pb; }
    foreach (['enabled', 'off_topic_silent', 'use_project', 'sales_mode'] as $k) if (isset($in[$k])) $c[$k] = (bool)$in[$k];
    if (isset($in['scope'])) $c['scope'] = $in['scope'] === 'all' ? 'all' : 'list';
    foreach (['people', 'block'] as $k) if (isset($in[$k]) && is_array($in[$k])) {
        $seen = []; $c[$k] = [];
        foreach ($in[$k] as $p) { $uid = preg_replace('/\D/', '', (string)($p['uid'] ?? '')); if ($uid === '' || isset($seen[$uid])) continue; $seen[$uid] = 1;
            $c[$k][] = ['uid' => $uid, 'name' => mb_substr((string)($p['name'] ?? ''), 0, 80)]; if (count($c[$k]) >= 300) break; }
    }
    foreach (['keywords' => 500, 'topics' => 1500, 'persona' => 2000, 'knowledge' => 8000, 'signature' => 120] as $k => $max) if (isset($in[$k])) $c[$k] = mb_substr(trim((string)$in[$k]), 0, $max);
    if (isset($in['xung_ho']) && in_array($in['xung_ho'], ['em-anh/chị', 'mình-bạn', 'tôi-bạn', 'anh-em', 'chị-em', 'tự nhiên'], true)) $c['xung_ho'] = $in['xung_ho'];
    foreach (['hour_from' => [0, 23], 'hour_to' => [1, 24], 'delay_sec' => [0, 120], 'quiet_min' => [0, 1440], 'max_per_day' => [1, 100]] as $k => [$mn, $mx]) if (isset($in[$k])) $c[$k] = max($mn, min($mx, (int)$in[$k]));
    setting_set('zalo_user.ai', json_encode($c, JSON_UNESCAPED_UNICODE), current_user());
    return $c;
}
function zu_ai_fold(string $s): string { // bỏ dấu để so từ khoá
    $s = mb_strtolower($s);
    $s = strtr($s, ['đ' => 'd']);
    $n = class_exists('Normalizer') ? Normalizer::normalize($s, Normalizer::FORM_D) : $s;
    return preg_replace('/\p{Mn}+/u', '', (string)$n);
}
/** Người này có trong phạm vi AI không (bỏ qua giờ, từ khoá…). */
function zu_ai_person_allowed(string $uid, ?array $c = null): bool {
    $c = $c ?: zu_ai_cfg();
    if (!$c['enabled']) return false;
    $in = fn($list) => in_array($uid, array_column($list, 'uid'), true);
    if ($in($c['block'])) return false;
    return $c['scope'] === 'all' || $in($c['people']);
}
/** Kiểm tra đủ điều kiện trả lời một tin đến. Trả về [bool, lý do]. */
function zu_ai_check(string $uid, string $text, ?string $threadId = null, ?array $c = null): array {
    global $pdo;
    $c = $c ?: zu_ai_cfg();
    if (!$c['enabled']) return [false, 'AI Chat đang tắt'];
    if (in_array($uid, array_column($c['block'], 'uid'), true)) return [false, 'Người này trong danh sách chặn'];
    if ($c['scope'] !== 'all' && !in_array($uid, array_column($c['people'], 'uid'), true)) return [false, 'Người này không trong danh sách AI được trả lời'];
    $kw = array_values(array_filter(array_map(fn($k) => trim(zu_ai_fold($k)), preg_split('/[,;\n]+/u', (string)$c['keywords']))));
    if ($kw) { $t = zu_ai_fold($text); $hit = false; foreach ($kw as $k) if ($k !== '' && str_contains($t, $k)) { $hit = true; break; }
        if (!$hit) return [false, 'Tin không chứa từ khoá nào (' . implode(', ', array_slice($kw, 0, 6)) . ')']; }
    $h = (int)date('G');
    if (!($c['hour_from'] <= $h && $h < $c['hour_to'])) return [false, "Ngoài giờ hoạt động ({$c['hour_from']}h–{$c['hour_to']}h)"];
    if ($threadId) {
        // Bạn đã tự trả lời SAU tin mới nhất của người kia → bạn đang lo, AI không chen vào
        $q = $pdo->prepare("SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='in'"); $q->execute([$threadId]); $lastIn = (string)$q->fetchColumn();
        $q = $pdo->prepare("SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out' AND COALESCE(sent_by,'')<>'ai'"); $q->execute([$threadId]); $lastMe = (string)$q->fetchColumn();
        if ($lastMe !== '' && $lastIn !== '' && $lastMe > $lastIn) return [false, 'Bạn đã tự trả lời tin mới nhất'];
        // Tuỳ chọn "nhường bạn": N phút > 0 và bạn vừa nhắn trong N phút → AI im (0 = AI vẫn trả lời theo ngữ cảnh)
        if ($c['quiet_min'] > 0 && $lastMe !== '' && strtotime($lastMe . ' UTC') > time() - $c['quiet_min'] * 60) return [false, "Bạn vừa tự nhắn người này (đặt nhường {$c['quiet_min']} phút)"];
        $q = $pdo->prepare("SELECT COUNT(*) FROM inbox_messages WHERE thread_id=? AND sent_by='ai' AND sent_at > ?"); $q->execute([$threadId, gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ((int)$q->fetchColumn() >= $c['max_per_day']) return [false, "Đã đủ {$c['max_per_day']} tin AI cho người này trong 24 giờ"];
    }
    return [true, 'AI sẽ trả lời' . ($c['topics'] !== '' && $c['off_topic_silent'] ? ' (nếu đúng chủ đề)' : '')];
}

/** Lời dặn AI cho hội thoại Zalo cá nhân (thay ib_ai_system). */
function zu_ai_system(array $th): string {
    global $pdo;
    $c = zu_ai_cfg();
    $owner = '';
    [$code, $b] = zu_bridge('GET', '/status', null, 5); if ($code === 200) $owner = (string)($b['name'] ?? '');
    $xh = ['em-anh/chị' => 'xưng "em", gọi người kia "anh/chị" (đoán theo tên, không chắc thì "anh/chị")', 'mình-bạn' => 'xưng "mình", gọi "bạn"', 'tôi-bạn' => 'xưng "tôi", gọi "bạn"',
        'anh-em' => 'xưng "anh", gọi "em"', 'chị-em' => 'xưng "chị", gọi "em"', 'tự nhiên' => 'xưng hô tự nhiên theo cách người kia đang nói'][$c['xung_ho']] ?? '';
    $s = "Bạn trả lời tin nhắn Zalo cá nhân THAY MẶT chủ tài khoản" . ($owner ? " \"$owner\"" : '') . ". Người đang nhắn: \"" . ($th['customer_name'] ?? '') . "\".\n"
        . "Cách nói: tiếng Việt tự nhiên như người thật nhắn Zalo, ngắn gọn (thường 1–4 câu; báo giá/chi tiết dịch vụ được gạch đầu dòng tối đa 5 dòng), $xh. Không viết HOA toàn bộ, tối đa 1 emoji, không văng tục.\n"
        . "TUYỆT ĐỐI không bịa giá, số liệu, lời hứa, lịch hẹn, cam kết thay chủ tài khoản. Không biết thì nói sẽ kiểm tra và báo lại.\n"
        . "Không đồng ý chuyển tiền, cho vay, gửi mã OTP/mật khẩu/thông tin cá nhân, không bàn chuyện riêng tư nhạy cảm — gặp những việc này: trả lời lịch sự là sẽ trao đổi trực tiếp sau, handoff=true.\n"
        . "Nếu bị hỏi thẳng có phải máy/AI không: trả lời thật là trợ lý tự động đang trả lời giúp, chủ tài khoản sẽ xem tin sau.\n"
        . "handoff=true khi: người kia cần chính chủ quyết định, khiếu nại/giận dữ, chuyện tiền bạc/cá nhân, hoặc đòi gặp người thật. Còn lại handoff=false.\n";
    $s .= "\nĐỌC NGỮ CẢNH CẢ CUỘC TRÒ CHUYỆN: các lượt 'assistant' là tin của chủ tài khoản (tự nhắn hoặc trợ lý gửi). Quyết định trả lời dựa trên TIN MỚI NHẤT của người kia, hiểu theo mạch chuyện trước đó. Đầu cuộc nói chuyện riêng/xã giao KHÔNG có nghĩa là im mãi: hễ người kia chuyển sang hỏi việc (VD hỏi sản phẩm, dịch vụ, báo giá, nhờ tư vấn) thì trả lời ngay, nối tiếp tự nhiên với mạch chuyện. Tin mới nhất chỉ là chuyện riêng/xã giao thì skip=true.\n";
    if ($c['topics'] !== '') $s .= "\nCHỈ trả lời các chủ đề sau (hiểu rộng theo ý: hỏi gián tiếp, hỏi giá, nhờ tư vấn, nhờ làm giúp đều tính):\n{$c['topics']}\n" . ($c['sales_mode'] ? "(Chế độ bán hàng: MỌI câu hỏi về sản phẩm/dịch vụ của doanh nghiệp, kể cả hỏi giá, nhờ tư vấn, đều thuộc phạm vi.)\n" : '') . ($c['off_topic_silent'] ? "Tin mới nhất (hiểu theo ngữ cảnh) KHÔNG thuộc các chủ đề trên (chào hỏi xã giao, chuyện riêng, chủ đề khác) → skip=true, reply=\"\" (im lặng, để chủ tài khoản tự trả lời).\n" : "Tin ngoài chủ đề → trả lời ngắn là sẽ báo lại sau, handoff=false.\n");
    if ($c['sales_mode']) $s .= "\nKỊCH BẢN BÁN HÀNG (áp dụng khi người kia hỏi về dịch vụ/công việc):\n" . ($c['sales_playbook'] !== '' ? $c['sales_playbook'] : zu_ai_sales_default()) . "\n";
    if ($c['persona'] !== '') $s .= "\nVai trò / cách làm việc:\n{$c['persona']}\n";
    if ($c['knowledge'] !== '') $s .= "\nThông tin được phép dùng (nguồn sự thật):\n{$c['knowledge']}\n";
    if ($c['use_project']) {
        $proj = (string)setting_get('zalo_user.project', '');
        $ps = $pdo->prepare('SELECT * FROM projects WHERE id=?'); $ps->execute([$proj]); $p = $ps->fetch();
        if ($p) {
            $set = jdecode($p['settings'] ?? null);
            $s .= "\nCông việc của chủ tài khoản: {$p['name']}" . (!empty($p['domain']) ? " (website {$p['domain']})" : '') . (!empty($set['hotline']) ? ", hotline {$set['hotline']}" : '') . ".\n";
            $bs = $pdo->prepare('SELECT kind,title,content FROM brand_items WHERE project_id=? ORDER BY kind'); $bs->execute([$p['id']]);
            $brand = implode("\n", array_map(fn($x) => '- ' . $x['title'] . ': ' . mb_substr((string)$x['content'], 0, 400), $bs->fetchAll()));
            if ($brand) $s .= "Thương hiệu & quy tắc:\n$brand\n";
            if (function_exists('ib_ai_site_knowledge') && ($kb = ib_ai_site_knowledge((string)($p['domain'] ?? ''))) !== '') $s .= "Thông tin từ website (dịch vụ/giá — chỉ dùng khi người kia hỏi về công việc):\n" . mb_substr($kb, 0, 6000) . "\n";
        }
    }
    if ($c['signature'] !== '') $s .= "\nKết thúc mỗi tin bằng dòng: {$c['signature']}\n";
    return $s . "\nTrả về JSON: {\"reply\": string, \"skip\": boolean, \"handoff\": boolean, \"reason\": string ngắn (ghi giai đoạn: hỏi nhu cầu / gửi dịch vụ / báo giá / xin SĐT / chốt), \"phone\": string|null (SĐT người kia vừa để lại, nếu có)}.";
}

/** Gọi sau khi lưu tin đến (cổng hook). Chờ delay_sec (gộp tin nhắn liên tiếp), kiểm tra lại, rồi trả lời / xếp việc máy HL. */
function zu_ai_handle(array $CFG, string $uid, string $msgId): string {
    global $pdo;
    require_once __DIR__ . '/_inbox_lib.php';
    $c = zu_ai_cfg();
    if (!zu_ai_person_allowed($uid, $c)) return 'ngoài phạm vi';
    $tid = 'zalo_user:' . $uid;
    if ($c['delay_sec'] > 0) sleep((int)$c['delay_sec']);
    // Có tin mới hơn → lượt sau xử lý (gộp)
    $q = $pdo->prepare("SELECT id, direction, text FROM inbox_messages WHERE thread_id=? ORDER BY sent_at DESC, id DESC LIMIT 1"); $q->execute([$tid]); $last = $q->fetch();
    if (!$last || $last['direction'] !== 'in' || $last['id'] !== 'zalo_user:' . $msgId) return 'có tin mới hơn / đã có người trả lời';
    $q = $pdo->prepare("SELECT GROUP_CONCAT(text, ' ') FROM (SELECT text FROM inbox_messages WHERE thread_id=? AND direction='in' AND sent_at > COALESCE((SELECT MAX(sent_at) FROM inbox_messages WHERE thread_id=? AND direction='out'), '0') ORDER BY sent_at)"); $q->execute([$tid, $tid]);
    [$ok, $why] = zu_ai_check($uid, (string)$q->fetchColumn(), $tid, $c);
    if (!$ok) return $why;
    $st = $pdo->prepare('SELECT * FROM inbox_threads WHERE id=?'); $st->execute([$tid]); $th = $st->fetch(); if (!$th) return 'không có hội thoại';
    if (!ai_available()['ok']) {
        $prep = ib_ai_prepare($CFG, $th); if (empty($prep['ok'])) return $prep['error'];
        unset($prep['need_links']);
        $jid = ib_ai_enqueue($th, 'auto', $prep);
        return ib_hl_alive() ? "chờ máy HL ($jid)" : "xếp hàng ($jid) — máy HL chưa kết nối";
    }
    $d = ib_ai_draft($CFG, $th); if (empty($d['ok'])) return 'AI lỗi: ' . $d['error'];
    return ib_ai_apply($CFG, $th, $d, 'auto');
}
