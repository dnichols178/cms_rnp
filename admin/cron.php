<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/cache.php';
require_permission('administer_site');

$db = get_db();
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_cron') {
    // 0. Process Scheduled Publishing & Archiving
    $pub_stmt = $db->prepare("
        UPDATE nodes 
        SET status = 'published' 
        WHERE status IN ('draft', 'in_review') 
          AND publish_on IS NOT NULL 
          AND publish_on <= NOW()
    ");
    $pub_stmt->execute();
    $scheduled_publishes = $pub_stmt->rowCount();
    if ($scheduled_publishes > 0) {
        $results[] = "Scheduled Publishing: {$scheduled_publishes} nodes moved to Published.";
        watchdog('content', "Scheduler published {$scheduled_publishes} pending node(s).", 'notice');
    }

    $unpub_stmt = $db->prepare("
        UPDATE nodes 
        SET status = 'archived' 
        WHERE status = 'published' 
          AND unpublish_on IS NOT NULL 
          AND unpublish_on <= NOW()
    ");
    $unpub_stmt->execute();
    $scheduled_unpublishes = $unpub_stmt->rowCount();
    if ($scheduled_unpublishes > 0) {
        $results[] = "Scheduled Archiving: {$scheduled_unpublishes} nodes moved to Archived.";
        watchdog('content', "Scheduler archived {$scheduled_unpublishes} expired node(s).", 'notice');
    }
    // 1. Prune Watchdog logs older than 30 days or keep last 2,000 entries
    $prune_stmt = $db->prepare('
        DELETE FROM watchdog 
        WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY) 
           OR id NOT IN (
               SELECT id FROM (
                   SELECT id FROM watchdog ORDER BY id DESC LIMIT 2000
               ) AS keep_rows
           )
    ');
    $prune_stmt->execute();
    $pruned_rows = $prune_stmt->rowCount();
    $results[] = "Watchdog rotation: {$pruned_rows} stale log entries pruned.";

    // 2. Clear expired cache bins
    $exp_stmt = $db->prepare('DELETE FROM cache_data WHERE expire != 0 AND expire < :now');
    $exp_stmt->execute(['now' => time()]);
    $expired_cache = $exp_stmt->rowCount();
    $results[] = "Cache pruning: {$expired_cache} expired items purged.";

    // 3. Optimize primary MySQL tables
    $tables = ['nodes', 'node_revisions', 'taxonomy_terms', 'watchdog', 'cache_data', 'contact_submissions'];
    foreach ($tables as $t) {
        $db->query("OPTIMIZE TABLE `{$t}`");
    }
    $results[] = "Database optimization: Analyzed and defragmented " . count($tables) . " core tables.";

    watchdog('system', 'System maintenance cron completed successfully.', 'info');
    // 4. Process Outbound Mail Queue
    require_once __DIR__ . '/../includes/mailer.php';
    $mail_res = process_email_queue(50);
    $results[] = "Mail Queue: Dispatched {$mail_res['sent']} emails ({$mail_res['failed']} failed).";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Maintenance & Cron - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 800px;">
            <h1>Maintenance &raquo; Cron & System Cleanup</h1>
            
            <?php if (!empty($results)): ?>
                <div class="notice" style="background:#e8f5e9; border-left:4px solid #2e7d32; padding:15px; margin-bottom:20px; color:#1b5e20;">
                    <strong>Maintenance tasks completed:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($results as $res): ?>
                            <li><?= htmlspecialchars($res) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="card">
                <h3>Scheduled Maintenance Routine</h3>
                <p style="margin-bottom: 20px; color:#555; line-height: 1.5;">
                    Executing the maintenance cron performs automatic housekeeping tasks mirroring Drupal Core:
                </p>
                <ul style="margin-left: 20px; color: #444; margin-bottom: 25px; line-height: 1.6;">
                    <li><strong>Watchdog Rotation:</strong> Prunes entries older than 30 days and caps total records at 2,000.</li>
                    <li><strong>Cache Expiration:</strong> Flushes TTL-expired cache items from the database buffer.</li>
                    <li><strong>Database Defragmentation:</strong> Runs <code>OPTIMIZE TABLE</code> across content and audit stores.</li>
                </ul>

                <form method="POST">
                    <input type="hidden" name="action" value="run_cron">
                    <button type="submit" class="btn-primary" style="padding: 12px 24px;">Run Maintenance Cron</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>