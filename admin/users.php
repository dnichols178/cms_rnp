<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_permission('administer_users');

$db = get_db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id  = (int)($_POST['role_id'] ?? 0);

    if ($username === '' || $email === '' || $password === '' || $role_id === 0) {
        $error = 'All fields are required.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, status) VALUES (?, ?, ?, 1)');
            $stmt->execute([$username, $email, $hash]);
            $uid = (int)$db->lastInsertId();

            $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
            $assign->execute([$uid, $role_id]);

            $message = "User '{$username}' created successfully.";
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$users = $db->query('
    SELECT u.id, u.username, u.email, u.status, u.created_at, GROUP_CONCAT(r.label SEPARATOR ", ") AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    GROUP BY u.id
    ORDER BY u.id ASC
')->fetchAll();

$roles = $db->query('SELECT * FROM roles ORDER BY id ASC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Accounts - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <h1>People &raquo; User Accounts</h1>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <section class="card">
                <h3>Existing Accounts</h3>
                <table>
                    <thead>
                        <tr><th>ID</th><th>Username</th><th>Email</th><th>Roles</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
<tr>
    <td><?= $u['id'] ?></td>
    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
    <td><?= htmlspecialchars($u['email']) ?></td>
    <td><?= htmlspecialchars($u['role_names'] ?? 'None') ?></td>
    <td>
        <span class="badge-status <?= $u['status'] ? 'status-published' : 'status-draft' ?>">
            <?= $u['status'] ? 'Active' : 'Disabled' ?>
        </span>
    </td>
    <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
    <td>
        <a href="<?= base_path('admin/user_edit.php') ?>?id=<?= $u['id'] ?>" class="btn-secondary" style="font-size:0.8rem; padding: 3px 8px;">Edit</a>
    </td>
</tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Create New User</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create_user">
                    <div class="form-row">
                        <label>Username *</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-row">
                        <label>Email Address *</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-row">
                        <label>Initial Password *</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-row">
                        <label>Primary Role *</label>
                        <select name="role_id" required>
                            <option value="">-- Select Role --</option>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Create Account</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>