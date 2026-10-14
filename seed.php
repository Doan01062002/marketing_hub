<?php
declare(strict_types=1);

/**
 * Khởi tạo CSDL lần đầu (api/_boot.php gọi khi chưa có data/marketing.sqlite). 13/10/2026 — bản dùng chung:
 * chỉ tạo tài khoản quản trị + dự án mặc định, KHÔNG có dữ liệu mẫu của nơi dùng trước.
 *
 * Không có mật khẩu nào nằm trong code: email + mật khẩu quản trị lấy từ config.local.php
 * (initial_admin_email / initial_admin_name / initial_admin_password). Thiếu mật khẩu thì sinh ngẫu nhiên và ghi vào
 * data/initial-passwords.txt (thư mục data/ không đưa lên git) — đọc xong nên xoá file đó và đổi mật khẩu.
 * Dự án mặc định: default_project_id / default_project_name / default_project_domain / default_project_goal.
 */
function seed_marketing_db(PDO $pdo): void {
    global $CFG;
    $generated = [];
    $pick = function (string $key, string $label) use (&$generated, $CFG): string {
        $v = (string)($CFG[$key] ?? '');
        if ($v !== '') return $v;
        $v = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Aa'), '=');
        $generated[] = "$label: $v";
        return $v;
    };
    $adminEmail = (string)($CFG['initial_admin_email'] ?? '') ?: 'admin@marketing.local';
    $adminName = (string)($CFG['initial_admin_name'] ?? '') ?: 'Quản trị';
    $adminHash = password_hash($pick('initial_admin_password', "Quản trị ($adminEmail)"), PASSWORD_DEFAULT);
    if ($generated) {
        @file_put_contents(__DIR__ . '/data/initial-passwords.txt',
            "Mật khẩu sinh lúc tạo CSDL " . date('Y-m-d H:i') . " — đổi ngay sau khi đăng nhập rồi xoá file này.\n" . implode("\n", $generated) . "\n");
    }

    $pdo->prepare('INSERT INTO users(id,name,email,password_hash,role,title,approve) VALUES (?,?,?,?,?,?,?)')
        ->execute(['U1', $adminName, $adminEmail, $adminHash, 'admin', 'Quản trị', 1]);

    // Dự án mặc định — mọi bản ghi nghiệp vụ thuộc một dự án; sửa tên/tên miền/mục tiêu ở Tất cả dự án.
    $pid = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($CFG['default_project_id'] ?? ''))) ?: 'main';
    $pdo->prepare("INSERT INTO projects(id,name,type,domain,color,status,goal,created_by) VALUES (?,?,?,?,?,'active',?,?)")
        ->execute([$pid, (string)($CFG['default_project_name'] ?? '') ?: 'Dự án chính', 'internal',
            (string)($CFG['default_project_domain'] ?? '') ?: null, '#168164', (string)($CFG['default_project_goal'] ?? '') ?: null, 'U1']);
    $pdo->prepare('INSERT OR IGNORE INTO project_members(project_id,user_id,role) VALUES (?,?,?)')->execute([$pid, 'U1', 'owner']);

    if (!empty($CFG['brand_hotline'])) {
        $pdo->prepare("INSERT OR IGNORE INTO app_settings(key,value) VALUES ('brand.hotline',?)")->execute([(string)$CFG['brand_hotline']]);
    }
}
