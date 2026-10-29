<?php
declare(strict_types=1);

// Enable error reporting to catch connection failures immediately
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

try {
    $pdo = get_db();
    
    // Test query against the imported schema
    $stmt = $pdo->query("SELECT COUNT(*) AS total_types FROM content_types");
    $row = $stmt->fetch();
    
    echo "<h2 style='color: green;'>✓ Database connection successful!</h2>";
    echo "<p>Found <strong>" . htmlspecialchars((string)$row['total_types']) . "</strong> default content types in <code>frnp_cms</code>.</p>";
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>✗ Database connection failed:</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "<p>Double-check <code>config/database.php</code> credentials (user, password, port, host).</p>";
}