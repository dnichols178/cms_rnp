<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (authenticate_user($username, $password)) {
        header('Location: ' . base_path('admin/nodes.php'));
        exit;
    } else {
        $error = 'Invalid credentials or account disabled.';
    }
}

$user = current_user();
if ($user) {
    header('Location: ' . base_path('admin/nodes.php'));
    exit;
}
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