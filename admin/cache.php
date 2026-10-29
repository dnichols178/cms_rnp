<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_all') {
    cache_clear();
    watchdog('system', 'All system cache bins flushed manually by administrator.', 'info');
    $message = 'All cached objects cleared from database successfully.';
}

$db = get_db();
$cache_count = $db->query('SELECT COUNT(*) FROM cache_data')->fetchColumn();
$cache_size = $db->query('SELECT SUM(LENGTH(data)) FROM cache_data')->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Performance & Caching - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 800px;">
            <h1>Performance &raquo; Cache Management</h1>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>

            <section class="card">
                <h3>Cache Storage Status</h3>
                <p style="margin-bottom: 15px; color:#555;">
                    The caching subsystem buffers compiled navigation structures, rendered block components, and query payloads to minimize relational lookups.
                </p>
                <div style="display:flex; gap: 30px; margin-bottom: 25px;">
                    <div>
                        <div style="font-size: 1.8rem; font-weight: bold; color: var(--frnp-navy);"><?= number_format((int)$cache_count) ?></div>
                        <div style="font-size: 0.85rem; color:#666;">Active Cached Keys</div>
                    </div>
                    <div>
                        <div style="font-size: 1.8rem; font-weight: bold; color: var(--frnp-navy);"><?= number_format(((float)$cache_size) / 1024, 2) ?> KB</div>
                        <div style="font-size: 0.85rem; color:#666;">Data Stored in Buffer</div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="btn-primary" style="padding: 10px 20px;">Clear All Caches</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>