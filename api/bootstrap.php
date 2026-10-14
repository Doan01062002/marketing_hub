<?php
/* Dữ liệu khởi động cho giao diện — TẤT CẢ theo dự án hiện hành (header X-Project / ?project_id).
   Trả thêm danh sách dự án người này thuộc để vẽ bộ chọn dự án. */
require __DIR__ . '/_boot.php';
require_method('GET');
$user = require_login();
$projects = array_map('public_project', projects_for_user($user));
$cur = current_project();

function row_campaign(array $r): array {
    return [
        'id' => $r['id'], 'name' => $r['name'], 'subject' => $r['subject'],
        'co' => $r['co'], 'br' => $r['br'], 'state' => $r['state'], 'approval' => $r['approval'],
        'health' => $r['health'], 'owner' => $r['owner_id'], 'channels' => jdecode($r['channels']),
        'budget' => (int)$r['budget'], 'spent' => (int)$r['spent'], 'leads' => (int)$r['leads'],
        'roas' => (float)$r['roas'], 'start' => $r['start_date'], 'end' => $r['end_date'], 'obj' => $r['objective'],
        'project_id' => $r['project_id'] ?? null,
    ];
}
function row_content(array $r): array {
    return [
        'id' => $r['id'], 'title' => $r['title'], 'type' => $r['type'], 'pillar' => $r['pillar'],
        'subject' => $r['subject'], 'co' => $r['co'], 'br' => $r['br'], 'state' => $r['state'],
        'owner' => $r['owner_id'], 'channel' => $r['channel'], 'due' => $r['due_date'], 'campaign' => $r['campaign_id'],
    ];
}
function row_task(array $r): array {
    return [
        'id' => $r['id'], 'title' => $r['title'], 'co' => $r['co'], 'br' => $r['br'],
        'board' => $r['board'], 'col' => $r['col'], 'type' => $r['type'],
        'assignee' => $r['assignee_id'], 'assigner' => $r['assigner_id'], 'due' => $r['due_date'],
        'pri' => $r['pri'], 'life' => $r['life'], 'apr' => $r['apr'], 'health' => $r['health'],
        'camp' => $r['campaign_id'],
    ];
}
function row_lead(array $r): array {
    return [
        'id' => $r['id'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'] ?? null, 'notes' => $r['notes'] ?? null, 'co' => $r['co'], 'br' => $r['br'],
        'camp' => $r['campaign_id'], 'source' => $r['source'], 'score' => (int)$r['score'],
        'state' => $r['state'], 'subject' => $r['subject'], 'created' => $r['created_at'] ?? null,
    ];
}
function human_age(string $ts): string {
    $d = max(0, (int)floor((time() - strtotime($ts . ' UTC')) / 86400));
    if ($d === 0) return 'Hôm nay';
    if ($d === 1) return '1 ngày';
    if ($d < 14) return $d . ' ngày';
    return round($d / 7) . ' tuần';
}

// Chưa thuộc dự án nào: chỉ trả người dùng + danh sách rỗng để giao diện hướng dẫn, không lỗi 403.
if (!$cur) {
    respond(['user' => $user, 'projects' => [], 'project' => null, 'staff' => [], 'campaigns' => [], 'content' => [], 'tasks' => [], 'leads' => [],
        'connections' => [], 'insights' => [], 'segments' => [], 'trends' => [], 'competitors' => [], 'kpis' => null, 'okrs' => [], 'brand' => [],
        'roles' => ROLES, 'projectRoles' => PROJECT_ROLES, 'canApprove' => can_approve($user)]);
}
$pid = $cur['id'];
$q = function (string $sql, array $v = []) use ($pdo, $pid): array { $st = $pdo->prepare($sql); $st->execute(array_merge([$pid], $v)); return $st->fetchAll(); };

// Thành viên dự án (để giao việc / chọn người phụ trách). Admin hệ thống luôn có mặt.
$staff = $q("SELECT u.id,u.name,u.title,u.role,u.approve,u.active,u.scope,u.co,u.br, COALESCE(m.role, CASE WHEN u.role='admin' THEN 'owner' END) AS prole
    FROM users u LEFT JOIN project_members m ON m.user_id=u.id AND m.project_id=?
    WHERE (m.user_id IS NOT NULL OR u.role='admin') ORDER BY u.active DESC, u.id");
$staff = array_map(fn($s) => [
    'id' => $s['id'], 'name' => $s['name'], 'role' => $s['title'] ?: '', 'sysrole' => $s['role'], 'prole' => $s['prole'],
    'scope' => $s['scope'] ?: '—', 'approve' => (bool)$s['approve'], 'active' => (bool)$s['active'],
    'co' => $s['co'], 'br' => $s['br'],
], $staff);

$okrs = array_map(fn($r) => [
    'id' => $r['id'], 'quarter' => $r['quarter'], 'objective' => $r['objective'], 'kr' => $r['key_result'],
    'target' => (float)$r['target'], 'current' => (float)$r['current'], 'unit' => $r['unit'],
    'dir' => (int)$r['direction'], 'owner' => $r['owner_id'], 'metric' => $r['metric'] ?? 'manual',
], $q('SELECT * FROM okrs WHERE project_id=? ORDER BY quarter DESC, created_at'));

$brand = array_map(fn($r) => [
    'id' => $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'content' => $r['content'], 'subject' => $r['subject'],
], $q('SELECT * FROM brand_items WHERE project_id=? ORDER BY kind, created_at'));

$campaigns = array_map('row_campaign', $q('SELECT * FROM campaigns WHERE project_id=? ORDER BY created_at'));
$content = array_map('row_content', $q('SELECT * FROM content_items WHERE project_id=? ORDER BY created_at'));
$tasks = array_map('row_task', $q('SELECT * FROM tasks WHERE project_id=? ORDER BY created_at'));
$leads = array_map('row_lead', $q('SELECT * FROM leads WHERE project_id=? ORDER BY created_at'));
$conns = array_map('public_connection', $q('SELECT * FROM connections WHERE project_id=? ORDER BY provider, created_at'));

$insights = array_map(fn($r) => [
    'id' => $r['id'], 'q' => $r['question'], 'ins' => $r['insight'], 'src' => $r['source'],
    'conf' => $r['confidence'], 'subject' => $r['subject'], 'state' => $r['state'],
    'fresh' => human_age($r['updated_at']),
], $q('SELECT * FROM insights WHERE project_id=? ORDER BY created_at DESC'));

$segments = array_map(fn($r) => [
    'id' => $r['id'], 'name' => $r['name'], 'size' => (int)$r['size'], 'value' => $r['value'], 'camp' => $r['campaigns_fit'],
], $q('SELECT * FROM segments WHERE project_id=? ORDER BY size DESC'));

$trends = array_map(fn($r) => [
    'id' => $r['id'], 'name' => $r['name'], 'vol' => $r['volume_change'], 'dir' => (int)$r['direction'],
    'data' => jdecode($r['data_points'], []), 'note' => $r['note'],
], $q('SELECT * FROM trends WHERE project_id=? ORDER BY created_at'));

$competitors = array_map(fn($r) => [
    'id' => $r['id'], 'name' => $r['name'], 'sov' => (int)$r['share_of_voice'], 'spendEst' => $r['spend_estimate'],
    'move' => $r['latest_move'], 'threat' => $r['threat'],
    'website' => $r['website'] ?? null, 'web' => ($r['web_checked_at'] ?? null) ? ['at' => $r['web_checked_at'], 'change' => $r['web_change'], 'snap' => array_intersect_key(jdecode($r['web_snapshot']), array_flip(['title', 'desc', 'urls', 'new_urls', 'words', 'phones', 'tech']))] : null,
    'place' => $r['place_id'] ? ['id' => $r['place_id'], 'name' => $r['place_name'], 'rating' => $r['place_rating'] !== null ? (float)$r['place_rating'] : null, 'reviews' => (int)$r['place_reviews'], 'address' => $r['place_address'], 'url' => $r['place_url'], 'at' => $r['place_synced_at']] : null,
], $q('SELECT * FROM competitors WHERE project_id=? ORDER BY share_of_voice DESC'));

// KPI tổng hợp theo dự án — tính từ campaigns/leads của dự án (không số tĩnh). CPL/CAC không có mẫu số → null (giao diện hiện "—").
$campAgg = $q('SELECT COALESCE(SUM(spent),0) spend, COALESCE(SUM(budget),0) budget, COALESCE(SUM(leads),0) leads,
    COALESCE(SUM(spent*roas),0) roasw FROM campaigns WHERE project_id=?')[0];
$mql = (int)$q("SELECT COUNT(*) c FROM leads WHERE project_id=? AND state IN ('ROUTED','DELIVERED','ACCEPTED')")[0]['c'];
$sql = (int)$q("SELECT COUNT(*) c FROM leads WHERE project_id=? AND state='ACCEPTED'")[0]['c'];
$spend = (int)$campAgg['spend'];
$kpis = [
    'spend' => $spend,
    'budget' => (int)$campAgg['budget'],
    'budget_month' => (int)$cur['budget_month'],
    'leads' => (int)$campAgg['leads'],
    'mql' => $mql,
    'sql' => $sql,
    'roas' => $spend > 0 ? round($campAgg['roasw'] / $spend, 2) : null,
    'cac' => $sql > 0 ? (int)round($spend / $sql) : null,
    'cpl' => ((int)$campAgg['leads']) > 0 ? (int)round($spend / (int)$campAgg['leads']) : null,
    // Doanh thu quy gán cần dữ liệu Kế toán/CRM thật — để null thay vì bịa số.
    'rev' => null,
];

respond([
    'user' => $user,
    'projects' => $projects,
    'project' => public_project($cur),
    'staff' => $staff,
    'campaigns' => $campaigns,
    'content' => $content,
    'tasks' => $tasks,
    'leads' => $leads,
    'connections' => $conns,
    'insights' => $insights,
    'segments' => $segments,
    'trends' => $trends,
    'competitors' => $competitors,
    'kpis' => $kpis,
    'okrs' => $okrs,
    'brand' => $brand,
    'roles' => ROLES,
    'projectRoles' => PROJECT_ROLES,
    'canApprove' => can_approve_project($user),
]);
