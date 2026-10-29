<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_users');

$db = get_db();
$user_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $user_id]);
$target_user = $stmt->fetch();

if (!$target_user) {
    die('User account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    $new_password = $_POST['new_password'] ?? '';
    $selected_roles = array_map('intval', $_POST['roles'] ?? []);

    if ($email === '') {
        set_flash('error', 'Email address cannot be blank.');
        header('Location: ' . base_path('admin/user_edit.php?id=' . $user_id));
        exit;
    } elseif (empty($selected_roles)) {
        set_flash('error', 'User must have at least one assigned role.');
        header('Location: ' . base_path('admin/user_edit.php?id=' . $user_id));
        exit;
    } else {
        try {
            // Update profile
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $upd = $db->prepare('UPDATE users SET email = :email, password_hash = :hash, status = :status WHERE id = :id');
                $upd->execute(['email' => $email, 'hash' => $hash, 'status' => $status, 'id' => $user_id]);
            } else {
                $upd = $db->prepare('UPDATE users SET email = :email, status = :status WHERE id = :id');
                $upd->execute(['email' => $email, 'status' => $status, 'id' => $user_id]);
            }

            // Synchronize roles
            $del = $db->prepare('DELETE FROM user_roles WHERE user_id = :uid');
            $del->execute(['uid' => $user_id]);

            $ins = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            foreach ($selected_roles as $rid) {
                $ins->execute([$user_id, $rid]);
            }

            set_flash('success', 'User account updated successfully.');
            header('Location: ' . base_path('admin/user_edit.php?id=' . $user_id));
            exit;
        } catch (PDOException $e) {
            set_flash('error', 'Database error: ' . $e->getMessage());
            header('Location: ' . base_path('admin/user_edit.php?id=' . $user_id));
            exit;
        }
    }
}

// Fetch user's current role IDs
$user_roles_stmt = $db->prepare('SELECT role_id FROM user_roles WHERE user_id = :uid');
$user_roles_stmt->execute(['uid' => $user_id]);
$current_role_ids = $user_roles_stmt->fetchAll(PDO::FETCH_COLUMN);

$all_roles = $db->query('SELECT * FROM roles ORDER BY id ASC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit User: <?= htmlspecialchars($target_user['username']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 800px;">
            <h1>Edit Account: <?= htmlspecialchars($target_user['username']) ?></h1>
            <p style="margin-bottom: 20px;"><a href="<?= base_path('admin/users.php') ?>" class="btn-secondary">&laquo; Back to User List</a></p>

            <?php render_flash_messages(); ?>

            <form method="POST" class="card">
                <div class="form-row">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($target_user['username']) ?>" disabled style="background: #f1f3f5; cursor: not-allowed;">
                    <small style="color: #666;">Usernames are fixed system identifiers.</small>
                </div>

                <div class="form-row">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($target_user['email']) ?>" required>
                </div>

                <div class="form-row">
                    <label>Reset Password (leave blank to keep current password)</label>
                    <input type="password" name="new_password" placeholder="Enter new password">
                </div>

                <div class="form-row">
                    <label>Assigned Roles *</label>
                    <?php foreach ($all_roles as $r): ?>
                        <label style="display: block; font-weight: normal; margin-bottom: 6px; font-size: 0.9rem;">
                            <input type="checkbox" name="roles[]" value="<?= $r['id'] ?>" <?= in_array($r['id'], $current_role_ids) ? 'checked' : '' ?>>
                            <strong><?= htmlspecialchars($r['label']) ?></strong> (<code><?= htmlspecialchars($r['machine_name']) ?></code>)
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="form-row">
                    <label>
                        <input type="checkbox" name="status" value="1" <?= $target_user['status'] ? 'checked' : '' ?>> 
                        Account Active
                    </label>
                </div>

                <div class="form-actions" style="margin-top: 25px;">
                    <button type="submit" class="btn-primary">Save Changes</button>
                    <a href="<?= base_path('admin/users.php') ?>" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>