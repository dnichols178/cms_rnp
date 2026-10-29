<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();

if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    validate_csrf();
    $db->exec('TRUNCATE TABLE watchdog');
    watchdog('system', 'Watchdog logs cleared by administrator.', 'notice');
    set_flash('success', 'Log messages table truncated.');
    header('Location: ' . base_path('admin/watchdog.php'));
    exit;
}

$type_filter = trim($_GET['type'] ?? '');
$sql = '
    SELECT w.*, u.username 
    FROM watchdog w
    LEFT JOIN users u ON u.id = w.uid
';
$params = [];

if ($type_filter !== '') {
    $sql .= ' WHERE w.type = :type';
    $params['type'] = $type_filter;
}

$sql .= ' ORDER BY w.id DESC LIMIT 100';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$types = $db->query('SELECT DISTINCT type FROM watchdog ORDER BY type ASC')->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recent Log Messages (Watchdog) - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .sev-error { color: #d32f2f; font-weight: bold; }
        .sev-warning { color: #f57c00; font-weight: bold; }
        .sev-notice { color: #1976d2; }
        .sev-info { color: #388e3c; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Reports &raquo; Recent Log Messages</h1>
                <div>
                    <a href="<?= base_path('admin/watchdog.php?action=clear&csrf_token=' . get_csrf_token()) ?>" 
                       data-confirm="Clear all recorded event logs? This action cannot be undone." 
                       data-confirm-title="Clear Event Logs"
                       data-confirm-btn="Clear Logs"
                       class="btn-secondary" style="color:#c00; text-decoration: none;">Clear Log Messages</a>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <section class="card" style="padding: 15px; margin-bottom: 20px;">
                <form method="GET" style="display:flex; gap: 15px; align-items:center;">
                    <label style="font-weight:600;">Filter by Event Type:</label>
                    <select name="type" onchange="this.form.submit()" style="max-width: 200px;">
                        <option value="">-- All Types --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $type_filter === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($t)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($type_filter): ?>
                        <a href="<?= base_path('admin/watchdog.php') ?>" class="btn-secondary" style="font-size:0.8rem;">Reset</a>
                    <?php endif; ?>
                </form>
            </section>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Severity</th>
                            <th>Type</th>
                            <th>Date / Time</th>
                            <th>Message</th>
                            <th>User</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="7" style="text-align:center; color:#777;">No logged events recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= $log['id'] ?></td>
                                <td>
                                    <span class="sev-<?= htmlspecialchars($log['severity']) ?>">
                                        <?= strtoupper(htmlspecialchars($log['severity'])) ?>
                                    </span>
                                </td>
                                <td><code><?= htmlspecialchars($log['type']) ?></code></td>
                                <td><?= date('Y-m-d H:i:s', strtotime($log['timestamp'])) ?></td>
                                <td>
                                    <?= htmlspecialchars($log['message']) ?>
                                    <?php if (!empty($log['link'])): ?>
                                        <br><a href="<?= htmlspecialchars($log['link']) ?>" style="font-size:0.8rem; color:var(--frnp-navy);">View Target Link</a>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($log['username'] ?? 'Anonymous') ?></td>
                                <td><code><?= htmlspecialchars($log['ip_address']) ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>