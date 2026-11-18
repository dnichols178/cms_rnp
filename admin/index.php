<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

/**
 * Check if current user holds a specific permission (compatible with auth.php)
 */
function user_can(string $perm): bool {
    if (function_exists('has_permission')) {
        return (bool)has_permission($perm);
    }
    if (function_exists('user_has_permission')) {
        return (bool)user_has_permission($perm);
    }
    $u = current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'administrator' || ($u['machine_name'] ?? '') === 'administrator') {
        return true;
    }

    $db = get_db();
    $uid = (int)($u['id'] ?? 0);
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = ?
              AND (r.machine_name = 'administrator' OR p.machine_name = ? OR p.machine_name = 'administer_site')
        ");
        $stmt->execute([$uid, $perm]);
        if ((int)$stmt->fetchColumn() > 0) return true;
    } catch (\Throwable $e) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM users u
                JOIN roles r ON r.id = u.role_id
                LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions p ON p.id = rp.permission_id
                WHERE u.id = ?
                  AND (r.machine_name = 'administrator' OR p.machine_name = ? OR p.machine_name = 'administer_site')
            ");
            $stmt->execute([$uid, $perm]);
            if ((int)$stmt->fetchColumn() > 0) return true;
        } catch (\Throwable $e2) {}
    }
    return false;
}

/**
 * Determine the best landing URL for the logged-in user
 */
function resolve_admin_landing_url(): string {
    // 1. Site Admins & Content Editors -> Content Repository
    if (
        user_can('administer_site') ||
        user_can('administer_nodes') ||
        user_can('access_content_overview') ||
        user_can('create_content') ||
        user_can('edit_any_content') ||
        user_can('edit_own_content')
    ) {
        return base_path('admin/nodes.php');
    }

    $canTraining = user_can('view_any_training') || user_can('view_own_training') || user_can('add_training');
    $canRegistrations = user_can('administer_registrations') || user_can('view_registrations') || user_can('manage_registrations') || user_can('create_registrations');

    // 2. If user only has Training permissions -> Go straight to Training
    if ($canTraining && !$canRegistrations) {
        return base_path('admin/training.php');
    }

    // 3. If user only has Registration permissions -> Go straight to Registrations
    if ($canRegistrations && !$canTraining) {
        return base_path('admin/registrations.php');
    }

    // 4. Otherwise (has both or neither) -> Show the User Workspace hub on index.php
    return base_path('admin/index.php?workspace=1');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (authenticate_user($username, $password)) {
        header('Location: ' . resolve_admin_landing_url());
        exit;
    } else {
        $error = 'Invalid credentials or account disabled.';
    }
}

$user = current_user();
if ($user && empty($_GET['workspace'])) {
    header('Location: ' . resolve_admin_landing_url());
    exit;
}

// -----------------------------------------------------------------------------
// Logged-In User Workspace Landing View (for non-site-admins / multi-tool users)
// -----------------------------------------------------------------------------
if ($user && !empty($_GET['workspace'])):
    $canTraining = user_can('view_any_training') || user_can('view_own_training') || user_can('add_training');
    $canRegistrations = user_can('administer_registrations') || user_can('view_registrations') || user_can('manage_registrations') || user_can('create_registrations');
    $canNodes = user_can('administer_site') || user_can('administer_nodes') || user_can('edit_any_content') || user_can('edit_own_content');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Workspace - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body class="admin-body" style="display:flex; min-height:100vh; margin:0; background:#f8fafc;">
    <?php if (file_exists(__DIR__ . '/nav.php')) include __DIR__ . '/nav.php'; ?>
    <main class="admin-main" style="flex:1; min-width:0; padding:32px;">
        <div style="max-width:1000px; margin:0 auto;">
            <h1 style="margin:0 0 6px 0; font-size:24px; color:#0f172a;">
                Welcome, <?= htmlspecialchars($user['username'] ?? $user['name'] ?? 'User') ?>
            </h1>
            <p style="margin:0 0 24px 0; color:#64748b; font-size:14px;">Select a workspace module below to get started.</p>

            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                <?php if ($canNodes): ?>
                    <a href="<?= base_path('admin/nodes.php') ?>" style="display:block; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; text-decoration:none; color:inherit;">
                        <h3 style="margin:0 0 8px 0; color:#0b4f6c;">Content Repository →</h3>
                        <p style="margin:0; font-size:13.5px; color:#64748b;">Create, edit, and manage site pages and articles.</p>
                    </a>
                <?php endif; ?>

                <?php if ($canTraining): ?>
                    <a href="<?= base_path('admin/training.php') ?>" style="display:block; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; text-decoration:none; color:inherit;">
                        <h3 style="margin:0 0 8px 0; color:#0b4f6c;">Training Management →</h3>
                        <p style="margin:0; font-size:13.5px; color:#64748b;">Upload training PDFs, assign trainees, and download completion audit logs.</p>
                    </a>
                <?php endif; ?>

                <?php if ($canRegistrations): ?>
                    <a href="<?= base_path('admin/registrations.php') ?>" style="display:block; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; text-decoration:none; color:inherit;">
                        <h3 style="margin:0 0 8px 0; color:#0b4f6c;">Event Registrations →</h3>
                        <p style="margin:0; font-size:13.5px; color:#64748b;">View and manage event capacities, registrants, and waitlists.</p>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
<?php
    exit;
endif;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - FRNP CMS Admin</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .login-box { max-width: 420px; margin: 100px auto; background: #fff; padding: 30px; border-radius: 4px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-top: 4px solid #003366; }
        .login-box h2 { margin-bottom: 20px; color: #003366; }
    </style>
</head>
<body style="background: #f0f2f5;">
    <div class="login-box">
        <h2>FRNP CMS Login</h2>
        <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-row">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-row">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">Sign In</button>
        </form>
    </div>
</body>
</html>
