<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

$password = 'Admin@FRNP2026!';
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $db = get_db();
    $stmt = $db->prepare('UPDATE users SET password_hash = :hash, status = 1 WHERE username = :user');
    $stmt->execute([
        'hash' => $hash,
        'user' => 'admin'
    ]);

    echo "<h2 style='color: green;'>✓ Admin password reset successfully!</h2>";
    echo "<p>Username: <strong>admin</strong></p>";
    echo "<p>Password: <strong>{$password}</strong></p>";
    echo "<p><a href='/admin/'>Go to Login</a></p>";
    echo "<p style='color: red;'><strong>Security Note:</strong> Delete this file (<code>reset-admin.php</code>) now.</p>";
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>✗ Error:</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}