<?php
/* CRM KHÁCH HÀNG trong ứng dụng Chat (11/11/2026). Dùng chung bảng care_records với mục CSKH của Marketing Hub
   (khách website chungauto.vn + khách từ chat, nhập tay, nhập Excel), thêm: mức nhu cầu, công ty, liên kết hội thoại,
   cột tuỳ chỉnh, cơ sở chia khách xoay vòng, tự thu hồi khách chưa được gọi, nhắc hẹn gọi lại, cơ hội bán hàng (Kanban).
   crm_tick() chạy từ cron/inbox.php mỗi phút và từ màn hình chat (tối đa 1 lần/phút). */

const CRM_ST = ['new' => 'Mới', 'contacting' => 'Đang liên hệ', 'consulted' => 'Tham khảo', 'potential' => 'Tiềm năng', 'callback' => 'Hẹn gọi lại', 'interested' => 'Quan tâm',
    'won' => 'Đã chốt', 'lost' => 'Dừng chăm', 'transferred' => 'Chuyển cơ sở', 'invalid' => 'Sai số / trùng'];
const CRM_CARING = ['contacting', 'consulted', 'potential', 'callback', 'interested', 'transferred'];
const CRM_CLOSED = ['won', 'lost', 'invalid'];
const CRM_NEED = ['hot' => 'Rất tiềm năng', 'learning' => 'Đang tìm hiểu', 'none' => 'Chưa có nhu cầu'];
const CRM_SRC = ['chungauto.vn' => 'Website', 'chat' => 'Live Chat', 'excel' => 'Excel / Form', 'manual' => 'Nhập tay'];
const CRM_STAGES = ['new' => 'Mới tiếp nhận', 'contacted' => 'Đã liên hệ', 'interested' => 'Đang quan tâm', 'quoted' => 'Đã gửi báo giá', 'negotiating' => 'Đang đàm phán', 'won' => 'Chốt thành công'];

function crm_phone_norm(?string $p): string { $d = preg_replace('/\D/', '', (string)$p); if (str_starts_with($d, '84') && strlen($d) >= 11) $d = '0' . substr($d, 2); return $d; }
function crm_now(): string { return date('Y-m-d H:i'); }
function crm_cfg(string $pid): array {
    $c = jdecode(setting_get('crm.cfg.' . $pid) ?: null, []);
    return ['sla_min' => max(0, (int)($c['sla_min'] ?? 0)), 'staff_export' => !empty($c['staff_export']), 'cols' => array_values(array_filter((array)($c['cols'] ?? []), 'is_array'))];
}
function crm_staff(string $pid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE u.active=1 AND (u.role='admin' OR u.id IN (SELECT user_id FROM project_members WHERE project_id=?)) ORDER BY u.name");
    $st->execute([$pid]); return $st->fetchAll();
}
function crm_branches(string $pid): array {
    global $pdo;
    $st = $pdo->prepare('SELECT id, name, staff, keywords FROM branches WHERE project_id=? ORDER BY name'); $st->execute([$pid]);
    return array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'staff' => jdecode($b['staff'] ?? null, []), 'keywords' => (string)($b['keywords'] ?? '')], $st->fetchAll());
}
/** Ghi 1 dòng nhật ký chăm sóc vào bản ghi (mảng $r đã đọc) — trả JSON mới. */
function crm_log_add(array $r, string $text, ?array $u, ?string $status = null): string {
    $log = jdecode($r['care_log'] ?? null, []);
    $log[] = ['at' => crm_now(), 'by' => $u['id'] ?? null, 'name' => $u['name'] ?? 'Hệ thống', 'status' => $status ?? $r['care_status'], 'text' => $text];
    return json_encode(array_slice($log, -200), JSON_UNESCAPED_UNICODE);
}
/** Cơ sở theo từ khoá định tuyến (chiến dịch / tỉnh thành) trong địa chỉ, ghi chú, công ty. */
function crm_route_branch(string $pid, string $hay): ?string {
    $h = mb_strtolower($hay, 'UTF-8'); if (trim($h) === '') return null;
    foreach (crm_branches($pid) as $b) foreach (preg_split('/\s*,\s*/u', mb_strtolower($b['keywords'], 'UTF-8')) as $k) if ($k !== '' && str_contains($h, $k)) return $b['id'];
    return null;
}
/** Nhân viên tiếp theo (xoay vòng) của cơ sở; null nếu cơ sở chưa chọn nhân viên. */
function crm_round_robin(string $branchId): ?string {
    global $pdo;
    $st = $pdo->prepare('SELECT staff, rr_idx FROM branches WHERE id=?'); $st->execute([$branchId]); $b = $st->fetch();
    $staff = $b ? jdecode($b['staff'] ?? null, []) : []; if (!$staff) return null;
    $i = ((int)$b['rr_idx']) % count($staff);
    $pdo->prepare('UPDATE branches SET rr_idx=? WHERE id=?')->execute([$i + 1, $branchId]);
    return (string)$staff[$i];
}
/** Tạo bản ghi CRM. $d: name, phone, email, company, address, need_level, assignee_id, branch_id, note, thread_id, custom, src.
 *  Trùng SĐT trong dự án → trả ['dup' => bản ghi cũ]. */
function crm_create(string $pid, array $d, ?array $u): array {
    global $pdo;
    $phone = trim((string)($d['phone'] ?? '')); $norm = crm_phone_norm($phone);
    if ($norm !== '') { $st = $pdo->prepare('SELECT * FROM care_records WHERE project_id=? AND phone_norm=? ORDER BY created_at LIMIT 1'); $st->execute([$pid, $norm]); if ($old = $st->fetch()) return ['dup' => $old]; }
    $branch = (string)($d['branch_id'] ?? '') ?: crm_route_branch($pid, implode(' ', [(string)($d['address'] ?? ''), (string)($d['note'] ?? ''), (string)($d['company'] ?? ''), (string)($d['campaign'] ?? '')]));
    $assignee = (string)($d['assignee_id'] ?? '') ?: ($branch ? crm_round_robin($branch) : null);
    $id = 'CR' . strtoupper(bin2hex(random_bytes(5))); $now = crm_now();
    $src = isset(CRM_SRC[$d['src'] ?? '']) && ($d['src'] ?? '') !== 'chungauto.vn' ? $d['src'] : 'manual';
    $note = trim((string)($d['note'] ?? ''));
    $log = [['at' => $now, 'by' => $u['id'] ?? null, 'name' => $u['name'] ?? 'Hệ thống', 'status' => 'new', 'text' => 'Tạo khách (' . CRM_SRC[$src] . ')' . ($note !== '' ? ' · ' . $note : '')]];
    $pdo->prepare("INSERT INTO care_records(id,project_id,src,kind,ext_id,name,phone,phone_norm,email,address,company,message,need_level,custom,thread_id,ext_created_at,care_status,assignee_id,assigned_at,branch_id,care_log)
        VALUES (?,?,?,'contact',?,?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?,?)")
        ->execute([$id, $pid, $src, $id, mb_substr(trim((string)($d['name'] ?? '')), 0, 120), $phone ?: null, $norm ?: null, trim((string)($d['email'] ?? '')) ?: null, trim((string)($d['address'] ?? '')) ?: null,
            trim((string)($d['company'] ?? '')) ?: null, $note ?: null, isset(CRM_NEED[$d['need_level'] ?? '']) ? $d['need_level'] : null, json_encode((object)($d['custom'] ?? []), JSON_UNESCAPED_UNICODE),
            ($d['thread_id'] ?? '') ?: null, date('Y-m-d H:i:s'), $assignee ?: null, $assignee ? $now : null, $branch ?: null, json_encode($log, JSON_UNESCAPED_UNICODE)]);
    if ($assignee && $assignee !== ($u['id'] ?? null)) notify($assignee, 'Bạn được giao khách mới: ' . (trim((string)($d['name'] ?? '')) ?: $phone), $phone, '#inbox/crm', $pid);
    $st = $pdo->prepare('SELECT * FROM care_records WHERE id=?'); $st->execute([$id]);
    return ['record' => $st->fetch()];
}

/** Nhắc hẹn gọi lại đến hạn + thu hồi khách mới quá hạn chưa gọi (SLA). Chạy cho mọi dự án. */
function crm_tick(): array {
    global $pdo;
    $now = crm_now(); $out = ['reminded' => 0, 'recycled' => 0];
    $st = $pdo->prepare("SELECT * FROM care_records WHERE next_follow_at IS NOT NULL AND next_follow_at<=? AND care_status NOT IN ('won','lost','invalid') AND (follow_notified_at IS NULL OR follow_notified_at<>next_follow_at) LIMIT 100");
    $st->execute([$now]);
    foreach ($st->fetchAll() as $r) {
        notify($r['assignee_id'] ?: null, 'Đến hẹn gọi lại: ' . ($r['name'] ?: $r['phone']), trim(($r['phone'] ?? '') . ' · hẹn ' . $r['next_follow_at']), '#inbox/crm_tasks', $r['project_id']);
        $pdo->prepare('UPDATE care_records SET follow_notified_at=next_follow_at WHERE id=?')->execute([$r['id']]);
        $out['reminded']++;
    }
    $rows = $pdo->query("SELECT project_id FROM care_records WHERE care_status='new' AND assignee_id IS NOT NULL AND assigned_at IS NOT NULL AND last_contact_at IS NULL GROUP BY project_id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $pid) {
        $sla = crm_cfg((string)$pid)['sla_min']; if ($sla <= 0) continue;
        $cut = date('Y-m-d H:i', time() - $sla * 60);
        $q = $pdo->prepare("SELECT * FROM care_records WHERE project_id=? AND care_status='new' AND assignee_id IS NOT NULL AND assigned_at<=? AND last_contact_at IS NULL LIMIT 200"); $q->execute([$pid, $cut]);
        foreach ($q->fetchAll() as $r) {
            $pdo->prepare("UPDATE care_records SET assignee_id=NULL, assigned_at=NULL, recycled_at=?, care_log=?, updated_at=datetime('now') WHERE id=?")
                ->execute([crm_now(), crm_log_add($r, 'Thu hồi về kho: quá ' . $sla . ' phút chưa gọi lần 1', null), $r['id']]);
            $out['recycled']++;
        }
    }
    return $out;
}
