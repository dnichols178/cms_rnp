<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

require_permission('administer_site');

$db = get_db();

// 1. Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $stmt = $db->prepare('DELETE FROM site_alerts WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['id']]);
    set_flash('success', 'Alert item deleted.');
    header('Location: ' . base_path('admin/alerts.php'));
    exit;
}

// 2. Handle Status Toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    validate_csrf();
    $stmt = $db->prepare('UPDATE site_alerts SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['id']]);
    set_flash('success', 'Alert status updated.');
    header('Location: ' . base_path('admin/alerts.php'));
    exit;
}

// 3. Handle Add Alert / Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_alert') {
    validate_csrf();
    $title        = trim($_POST['title'] ?? '');
    $body         = trim($_POST['message'] ?? '');
    $display_type = $_POST['display_type'] ?? 'modal';
    $severity     = $_POST['severity'] ?? 'warning';
    $target_pages = trim($_POST['target_pages'] ?? '<front>');
    $cta_label    = trim($_POST['cta_label'] ?? '');
    $cta_url      = trim($_POST['cta_url'] ?? '');
    $dismissible  = isset($_POST['dismissible']) ? 1 : 0;
    $start_date   = !empty($_POST['start_date']) ? str_replace('T', ' ', $_POST['start_date']) : null;
    $end_date     = !empty($_POST['end_date']) ? str_replace('T', ' ', $_POST['end_date']) : null;

    if ($title === '' || $body === '') {
        set_flash('error', 'Both Alert Title and Message Content are required.');
    } else {
        $stmt = $db->prepare('
            INSERT INTO site_alerts 
            (title, message, display_type, severity, target_pages, cta_label, cta_url, dismissible, status, start_date, end_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ');
        $stmt->execute([
            $title, $body, $display_type, $severity, $target_pages, $cta_label, $cta_url, $dismissible, $start_date, $end_date
        ]);
        watchdog('content', "Created {$display_type} alert: '{$title}'", 'notice');
        set_flash('success', "Alert / Modal '{$title}' configured and published.");
    }

    header('Location: ' . base_path('admin/alerts.php'));
    exit;
}

$alerts = $db->query('SELECT * FROM site_alerts ORDER BY id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Site Alerts & Modal Windows - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Communications &raquo; Site Alerts & Modals</h1>
            <?php render_flash_messages(); ?>

            <div class="grid-2col">
                <!-- Alerts Listing -->
                <section class="card">
                    <h3>Configured Broadcasts</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Broadcast Title</th>
                                <th>Format</th>
                                <th>Severity</th>
                                <th>Target Pages</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($alerts)): ?>
                                <tr><td colspan="6" style="text-align:center; color:#64748b; padding:25px;">No alerts or modal popups currently configured.</td></tr>
                            <?php else: ?>
                                <?php foreach ($alerts as $a): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($a['title']) ?></strong>
                                        <div style="font-size:0.8rem; color:#64748b; margin-top:2px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?= htmlspecialchars($a['message']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-type <?= $a['display_type'] === 'modal' ? 'type-modal' : 'type-banner' ?>">
                                            <?= htmlspecialchars($a['display_type']) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge-status status-published"><?= htmlspecialchars($a['severity']) ?></span></td>
                                    <td><code><?= htmlspecialchars($a['target_pages'] ?: '*') ?></code></td>
                                    <td>
                                        <a href="<?= base_path('admin/alerts.php?action=toggle&id=' . (int)$a['id'] . '&csrf_token=' . get_csrf_token()) ?>" style="text-decoration:none;">
                                            <span class="badge-status <?= $a['status'] ? 'status-published' : 'status-draft' ?>">
                                                <?= $a['status'] ? 'Active' : 'Muted' ?>
                                            </span>
                                        </a>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="<?= base_path('admin/alerts.php?action=delete&id=' . (int)$a['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                           data-confirm="Delete this alert?" 
                                           data-confirm-title="Delete Alert"
                                           data-confirm-btn="Delete"
                                           style="color:#c00; font-size:0.85rem; text-decoration: none;">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Form to Add Alert / Modal -->
                <section class="card">
                    <h3>Create Alert or Modal</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_alert">

                        <div class="form-row">
                            <label>Broadcast Title *</label>
                            <input type="text" name="title" required placeholder="e.g. Inclement Weather Site Delay">
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="form-row">
                                <label>Display Type</label>
                                <select name="display_type">
                                    <option value="modal">Modal Pop-Up Window</option>
                                    <option value="banner_top">Top Header Banner</option>
                                </select>
                            </div>
                            <div class="form-row">
                                <label>Severity Level</label>
                                <select name="severity">
                                    <option value="warning">Warning (Gold)</option>
                                    <option value="emergency">Emergency / Alert (Red)</option>
                                    <option value="info">Informational (Navy)</option>
                                    <option value="success">Success / Notice (Green)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Message Content *</label>
                            <textarea name="message" rows="4" required placeholder="Details displayed to visitors..."></textarea>
                        </div>

                        <div class="form-row">
                            <label>Page Target (One path per line)</label>
                            <textarea name="target_pages" rows="2" placeholder="<front>&#10;news/*"></textarea>
                            <small>Use <code>&lt;front&gt;</code> for the home page, <code>*</code> for all pages, or path slugs.</small>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="form-row">
                                <label>Button Label (Optional)</label>
                                <input type="text" name="cta_label" placeholder="e.g. Read Guidelines">
                            </div>
                            <div class="form-row">
                                <label>Button URL (Optional)</label>
                                <input type="text" name="cta_url" placeholder="/safety-bulletin">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="form-row">
                                <label>Start Schedule (Optional)</label>
                                <input type="datetime-local" name="start_date">
                            </div>
                            <div class="form-row">
                                <label>End Schedule (Optional)</label>
                                <input type="datetime-local" name="end_date">
                            </div>
                        </div>

                        <div class="form-row">
                            <label><input type="checkbox" name="dismissible" value="1" checked> Allow visitor dismissal (remembers closed state)</label>
                        </div>

                        <button type="submit" class="btn-primary" style="width:100%; margin-top:10px;">Publish Broadcast</button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>