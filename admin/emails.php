<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/mailer.php';
require_permission('administer_site');

$db = get_db();

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    validate_csrf();
    $keys = [
        'mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'smtp_user', 'smtp_timeout', 'mail_from_address',
        'mail_from_name', 'notify_contact_email', 'notify_workflow_email'
    ];
    foreach ($keys as $k) {
        set_site_setting($k, trim($_POST[$k] ?? ''));
    }
    // Only update password if provided
    if (!empty($_POST['smtp_pass'])) {
        set_site_setting('smtp_pass', trim($_POST['smtp_pass']));
    }
    set_flash('success', 'Email & SMTP transport settings saved.');
    header('Location: ' . base_path('admin/emails.php'));
    exit;
}

// Handle Test Email Transmission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_test') {
    validate_csrf();
    $test_recipient = trim($_POST['test_recipient'] ?? '');
    if (!filter_var($test_recipient, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Please specify a valid recipient email for diagnostic delivery.');
    } else {
        try {
            $body = build_frnp_email_html(
                "SMTP Socket Diagnostics Test",
                "<p>This diagnostic verification verifies direct socket communication and transport through the Four Rivers Nuclear Partnership CMS mailer.</p>
                 <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s T') . "<br><strong>Driver:</strong> " . htmlspecialchars(get_site_setting('mail_driver')) . "</p>",
                "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path(),
                "Visit Portal"
            );
            send_raw_email($test_recipient, "FRNP CMS Diagnostics Verification", $body, "Diagnostics test passed.", "Test Recipient");
            set_flash('success', "Test email dispatched successfully to {$test_recipient}.");
        } catch (\Throwable $e) {
            set_flash('error', "Transmission Failure: " . $e->getMessage());
        }
    }
    header('Location: ' . base_path('admin/emails.php'));
    exit;
}

// Handle Manual Queue Flush
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'flush_queue') {
    validate_csrf();
    $res = process_email_queue(50);
    set_flash('success', "Queue processed: {$res['sent']} sent, {$res['failed']} failed ({$res['processed']} attempted).");
    header('Location: ' . base_path('admin/emails.php'));
    exit;
}

// Fetch stats & queues
$queued_count = $db->query('SELECT COUNT(*) FROM email_queue')->fetchColumn();
$queue_items = $db->query('SELECT * FROM email_queue ORDER BY priority ASC, created_at ASC LIMIT 15')->fetchAll();
$recent_logs = $db->query('SELECT * FROM email_logs ORDER BY dispatched_at DESC LIMIT 15')->fetchAll();

$current_driver = get_site_setting('mail_driver', 'mail');
$current_enc = get_site_setting('smtp_encryption', 'none');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Transport & Workflows - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .grid-2col { display: grid; grid-template-columns: 1.2fr 1fr; gap: 25px; }
        .queue-badge { padding: 2px 7px; border-radius: 3px; font-size: 0.75rem; font-weight: bold; }
        .p-high { background: #fee2e2; color: #991b1b; }
        .p-norm { background: #f1f5f9; color: #334155; }
        .code-box { background: #1e293b; color: #f8fafc; padding: 12px; border-radius: 4px; font-size: 0.85rem; font-family: monospace; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Communications &raquo; Email Settings & Queue</h1>
            <?php render_flash_messages(); ?>

            <div class="grid-2col">
                <!-- Transport Configuration -->
                <section class="card">
                    <h3>Transport Configuration</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_settings">

                        <div class="form-row">
                            <label>Mail Delivery Driver</label>
                            <select name="mail_driver" style="width:100%; padding:8px;">
                                <option value="mail" <?= $current_driver === 'mail' ? 'selected' : '' ?>>PHP Built-in mail() / local sendmail</option>
                                <option value="smtp" <?= $current_driver === 'smtp' ? 'selected' : '' ?>>Direct SMTP Socket Connection</option>
                            </select>
                        </div>

                        <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px;">
                            <div class="form-row">
                                <label>SMTP Host / Relay IP</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars(get_site_setting('smtp_host', '127.0.0.1')) ?>">
                            </div>
                            <div class="form-row">
                                <label>Port</label>
                                <input type="text" name="smtp_port" value="<?= htmlspecialchars(get_site_setting('smtp_port', '25')) ?>">
                            </div>
                            <div class="form-row">
                                <label>Encryption</label>
                                <select name="smtp_encryption" style="width:100%; padding:9px;">
                                    <option value="none" <?= $current_enc === 'none' ? 'selected' : '' ?>>None</option>
                                    <option value="tls" <?= $current_enc === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS</option>
                                    <option value="ssl" <?= $current_enc === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-row">
                                <label>SMTP Username (Optional)</label>
                                <input type="text" name="smtp_user" value="<?= htmlspecialchars(get_site_setting('smtp_user')) ?>" placeholder="Leave blank for open relay">
                            </div>
                            <div class="form-row">
                                <label>SMTP Password (Optional)</label>
                                <input type="password" name="smtp_pass" placeholder="••••••••">
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Connection Timeout (Seconds)</label>
                            <input type="number" name="smtp_timeout" value="<?= htmlspecialchars(get_site_setting('smtp_timeout', '15')) ?>">
                        </div>

                        <h3 style="margin-top:25px;">Sender & Routing Defaults</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-row">
                                <label>From Email Address</label>
                                <input type="email" name="mail_from_address" value="<?= htmlspecialchars(get_site_setting('mail_from_address')) ?>" required>
                            </div>
                            <div class="form-row">
                                <label>From Display Name</label>
                                <input type="text" name="mail_from_name" value="<?= htmlspecialchars(get_site_setting('mail_from_name')) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Contact Form Notification Destination</label>
                            <input type="email" name="notify_contact_email" value="<?= htmlspecialchars(get_site_setting('notify_contact_email')) ?>" required>
                        </div>

                        <div class="form-row">
                            <label>Editorial Workflow Reviewer Notification Destination</label>
                            <input type="email" name="notify_workflow_email" value="<?= htmlspecialchars(get_site_setting('notify_workflow_email')) ?>" required>
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top:10px;">Save Mail Settings</button>
                    </form>
                </section>

                <!-- Queue Status & Direct Diagnostics -->
                <div>
                    <section class="card">
                        <h3>SMTP Delivery Diagnostic Test</h3>
                        <p style="font-size:0.85rem; color:#666; margin-bottom:15px;">Send a live test message to verify the host, handshake, and envelope credentials.</p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="send_test">
                            <div class="form-row">
                                <label>Recipient Email Address</label>
                                <input type="email" name="test_recipient" required placeholder="name@domain.com">
                            </div>
                            <button type="submit" class="btn-primary" style="background:#0b2545;">Execute Test Send</button>
                        </form>
                    </section>

                    <section class="card">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <h3>Outbound Dispatch Queue</h3>
                            <span class="badge" style="background:#c59b27;"><?= (int)$queued_count ?> In Queue</span>
                        </div>
                        <p style="font-size:0.85rem; color:#666; margin:10px 0 15px;">Messages queued asynchronously by forms or workflow state changes.</p>
                        
                        <form method="POST" style="margin-bottom:15px;">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="flush_queue">
                            <button type="submit" class="btn-secondary" style="font-size:0.85rem;">⚡ Process Queue Now</button>
                        </form>

                        <table>
                            <thead>
                                <tr><th>Recipient</th><th>Subject</th><th>Priority</th><th>Attempts</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($queue_items)): ?>
                                    <tr><td colspan="4" style="text-align:center; color:#777;">Queue is clear.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($queue_items as $q): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($q['recipient']) ?></td>
                                        <td style="font-size:0.8rem;"><?= htmlspecialchars($q['subject']) ?></td>
                                        <td>
                                            <span class="queue-badge <?= $q['priority'] <= 1 ? 'p-high' : 'p-norm' ?>">
                                                P<?= $q['priority'] ?>
                                            </span>
                                        </td>
                                        <td><?= $q['attempts'] ?> / <?= $q['max_attempts'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>

            <!-- Recent Dispatch Audit Trail -->
            <section class="card" style="margin-top:25px;">
                <h3>Recent Transmission Logs</h3>
                <table>
                    <thead>
                        <tr><th>Status</th><th>Timestamp</th><th>Recipient</th><th>Subject</th><th>Details / Diagnostics</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_logs)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#777;">No email activity recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_logs as $log): ?>
                            <tr>
                                <td>
                                    <span class="badge-status <?= $log['status'] === 'sent' ? 'status-published' : 'status-archived' ?>">
                                        <?= strtoupper($log['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('Y-m-d H:i:s', strtotime($log['dispatched_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($log['recipient']) ?></strong></td>
                                <td><?= htmlspecialchars($log['subject']) ?></td>
                                <td style="font-size:0.8rem; color:#555;">
                                    <?= !empty($log['error_message']) ? '<span style="color:#c00;">' . htmlspecialchars($log['error_message']) . '</span>' : 'OK (250 Dispatched)' ?>
                                </td>
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