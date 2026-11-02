<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/mailer.php';

require_permission('administer_site');

$db = get_db();

$vault_dir = dirname(__DIR__) . '/vault';
if (!is_dir($vault_dir)) {
    @mkdir($vault_dir, 0750, true);
    @file_put_contents($vault_dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
}

$action = $_REQUEST['action'] ?? 'list';

// =========================================================================
// 0. AJAX & EXPORT ENDPOINTS (Must execute before any HTML output)
// =========================================================================
if ($action === 'fetch_recipients' && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $t_id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM secure_transfer_recipients WHERE transfer_id = :id ORDER BY id ASC');
    $stmt->execute(['id' => $t_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'fetch_logs' && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $log_id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM secure_transfer_logs WHERE transfer_id = :id ORDER BY id DESC LIMIT 100');
    $stmt->execute(['id' => $log_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Export Package Logs to CSV
if ($action === 'export_logs' && isset($_GET['id'])) {
    if (isset($_GET['csrf_token']) && !empty($_SESSION['csrf_token'])) {
        if (!hash_equals($_SESSION['csrf_token'], (string)$_GET['csrf_token'])) {
            http_response_code(403);
            die('403 Forbidden: Invalid or expired security token.');
        }
    }

    $log_transfer_id = (int)$_GET['id'];

    $t_stmt = $db->prepare('SELECT title, original_filename FROM secure_transfers WHERE id = :id LIMIT 1');
    $t_stmt->execute(['id' => $log_transfer_id]);
    $transfer_pkg = $t_stmt->fetch(PDO::FETCH_ASSOC);

    $pkg_title = $transfer_pkg['title'] ?? ('package-' . $log_transfer_id);
    $clean_filename = 'transfer-logs-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($pkg_title)) . '-' . date('Y-m-d') . '.csv';

    $stmt = $db->prepare('
        SELECT l.*, r.recipient_email AS registered_email
        FROM secure_transfer_logs l
        LEFT JOIN secure_transfer_recipients r ON r.id = l.recipient_id
        WHERE l.transfer_id = :id
        ORDER BY l.id ASC
    ');
    $stmt->execute(['id' => $log_transfer_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $clean_filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'Timestamp', 'Event Type', 'Recipient / Entered Email', 'IP Address', 'Bytes Sent', 'User Agent']);

    foreach ($rows as $r) {
        $email = !empty($r['email_entered']) ? $r['email_entered'] : (!empty($r['registered_email']) ? $r['registered_email'] : '');
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            strtoupper(str_replace('_', ' ', $r['event_type'])),
            $email,
            $r['ip_address'],
            $r['bytes_sent'],
            $r['user_agent'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// =========================================================================
// Helper Functions
// =========================================================================
function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function parse_recipient_emails(string $raw_input): array {
    $parts = preg_split('/[\r\n,;\s]+/', $raw_input);
    $clean = [];
    foreach ($parts as $p) {
        $email = strtolower(trim($p));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[$email] = true;
        }
    }
    return array_keys($clean);
}

function send_recipient_transfer_notification(array $transfer, string $recipient_email, string $public_url): bool {
    $site_name = function_exists('get_site_setting') ? get_site_setting('site_name', 'Four Rivers Nuclear Partnership') : 'Four Rivers Nuclear Partnership';
    $title     = htmlspecialchars($transfer['title']);
    $orig_name = htmlspecialchars($transfer['original_filename']);
    $filesize  = format_bytes((int)$transfer['file_size']);
    $desc      = !empty($transfer['description']) ? nl2br(htmlspecialchars($transfer['description'])) : '';
    $exp_text  = !empty($transfer['expires_at']) ? date('l, F j, Y g:i A', strtotime($transfer['expires_at'])) : 'No expiration (Never)';
    $is_otp    = (($transfer['auth_mode'] ?? 'direct') === 'otp');

    $auth_instruction = $is_otp
        ? "When you open the link, enter your email address to receive an immediate 4-digit verification code to unlock your download."
        : "When you open the link, verify your email address (<code>" . htmlspecialchars($recipient_email) . "</code>) to download.";

    $msg_content  = "<p>A secure document package has been prepared for you via the {$site_name} secure transfer portal.</p>";
    $msg_content .= "<table style='width:100%; border-collapse:collapse; margin:15px 0; font-size:14px;'>";
    $msg_content .= "<tr><td style='padding:6px 0; font-weight:bold; width:30%;'>Document:</td><td style='padding:6px 0;'>{$orig_name} ({$filesize})</td></tr>";
    if ($desc !== '') {
        $msg_content .= "<tr><td style='padding:6px 0; font-weight:bold; vertical-align:top;'>Notes:</td><td style='padding:6px 0;'>{$desc}</td></tr>";
    }
    $msg_content .= "<tr><td style='padding:6px 0; font-weight:bold;'>Expiration:</td><td style='padding:6px 0;'>{$exp_text}</td></tr>";
    $msg_content .= "</table>";
    $msg_content .= "<p>{$auth_instruction}</p>";
    $msg_content .= "<p style='color:#64748b; font-size:13px;'><em>Once your download finishes, click the <strong>&ldquo;I Have Downloaded the Files&rdquo;</strong> button on the portal to permanently deactivate your access.</em></p>";

    $html = function_exists('build_frnp_email_html')
        ? build_frnp_email_html("Secure Document Transfer: {$title}", $msg_content, $public_url, "Access & Download Document")
        : $msg_content;

    queue_email($recipient_email, "Secure Document Transfer: {$transfer['title']}", $html, '', null, 1);
    return true;
}

// =========================================================================
// 1. Action: Create Transfer Package with Multiple Recipients
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_transfer'])) {
    validate_csrf();

    $title            = trim($_POST['title'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $raw_recipients   = trim($_POST['recipient_emails'] ?? '');
    $auth_mode        = in_array($_POST['auth_mode'] ?? '', ['direct', 'otp'], true) ? $_POST['auth_mode'] : 'direct';
    $raw_expire_days  = (int)($_POST['expire_days'] ?? 7);
    $notify_recipient = !empty($_POST['notify_recipient']);

    $emails = parse_recipient_emails($raw_recipients);

    if ($title === '') {
        set_flash('error', 'Please provide a title for the transfer package.');
    } elseif (empty($emails)) {
        set_flash('error', 'Please enter at least one valid recipient email address.');
    } elseif (empty($_FILES['package_file']['name']) || $_FILES['package_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Please select a valid document or archive file to upload.');
    } else {
        $file_tmp  = $_FILES['package_file']['tmp_name'];
        $orig_name = basename($_FILES['package_file']['name']);
        $file_size = (int)filesize($file_tmp);
        $file_hash = hash_file('sha256', $file_tmp);
        $mime_type = mime_content_type($file_tmp) ?: 'application/octet-stream';

        $token           = bin2hex(random_bytes(24));
        $stored_filename = 'sec_' . bin2hex(random_bytes(16)) . '.bin';
        $dest_path       = $vault_dir . '/' . $stored_filename;

        if (move_uploaded_file($file_tmp, $dest_path)) {
            $expires_at = null;
            if ($raw_expire_days > 0) {
                $expire_days = max(1, min(60, $raw_expire_days));
                $expires_at  = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
            }

            $user = current_user();

            $stmt = $db->prepare('
                INSERT INTO secure_transfers 
                (token, title, description, original_filename, stored_filename, file_size, mime_type, file_hash, auth_mode, expires_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $token, $title, $description, $orig_name, $stored_filename,
                $file_size, $mime_type, $file_hash, $auth_mode, $expires_at, $user['id'] ?? null
            ]);
            $new_transfer_id = (int)$db->lastInsertId();

            $ins_recip = $db->prepare('INSERT INTO secure_transfer_recipients (transfer_id, recipient_email) VALUES (?, ?)');
            foreach ($emails as $email) {
                $ins_recip->execute([$new_transfer_id, $email]);
            }

            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $public_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path('transfer/' . $token);

            $notified_count = 0;
            if ($notify_recipient) {
                $transfer_record = [
                    'id'                => $new_transfer_id,
                    'title'             => $title,
                    'original_filename' => $orig_name,
                    'file_size'         => $file_size,
                    'description'       => $description,
                    'expires_at'        => $expires_at,
                    'auth_mode'         => $auth_mode
                ];
                foreach ($emails as $email) {
                    send_recipient_transfer_notification($transfer_record, $email, $public_url);
                    $notified_count++;
                }
                if (function_exists('process_email_queue')) {
                    process_email_queue($notified_count);
                }
            }

            $recip_count = count($emails);
            watchdog('security', "Created secure transfer '{$title}' (#{$new_transfer_id}) with {$recip_count} recipient(s) [Auth: {$auth_mode}].", 'notice');
            set_flash('success', "Transfer package created with {$recip_count} recipient(s)." . ($notify_recipient ? " Notifications dispatched." : ""));
            header('Location: ' . base_path('admin/secure_transfers.php'));
            exit;
        } else {
            set_flash('error', 'Failed to store file in vault. Check write permissions on /vault.');
        }
    }
}

// =========================================================================
// 2. Action: Resend Notification to a Specific Recipient
// =========================================================================
if ($action === 'resend_recipient' && isset($_GET['recipient_id'])) {
    validate_csrf();
    $recip_id = (int)$_GET['recipient_id'];
    $stmt = $db->prepare('
        SELECT r.*, t.token, t.title, t.original_filename, t.file_size, t.description, t.expires_at, t.auth_mode 
        FROM secure_transfer_recipients r
        JOIN secure_transfers t ON t.id = r.transfer_id
        WHERE r.id = :rid LIMIT 1
    ');
    $stmt->execute(['rid' => $recip_id]);
    $recip_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($recip_data) {
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $public_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path('transfer/' . $recip_data['token']);
        send_recipient_transfer_notification($recip_data, $recip_data['recipient_email'], $public_url);
        if (function_exists('process_email_queue')) {
            process_email_queue(2);
        }
        set_flash('success', "Notification email resent to " . htmlspecialchars($recip_data['recipient_email']) . ".");
    } else {
        set_flash('error', "Recipient record not found.");
    }
    header('Location: ' . base_path('admin/secure_transfers.php'));
    exit;
}

// =========================================================================
// 3. Action: Revoke Entire Transfer Package
// =========================================================================
if ($action === 'revoke' && isset($_GET['id'])) {
    validate_csrf();
    $rev_id = (int)$_GET['id'];
    $stmt = $db->prepare("UPDATE secure_transfers SET status = 'revoked' WHERE id = :id");
    $stmt->execute(['id' => $rev_id]);

    $log_stmt = $db->prepare('INSERT INTO secure_transfer_logs (transfer_id, event_type, ip_address, user_agent) VALUES (?, "revoked_by_admin", ?, ?)');
    $log_stmt->execute([$rev_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

    set_flash('success', "Transfer package #{$rev_id} has been revoked for all recipients.");
    header('Location: ' . base_path('admin/secure_transfers.php'));
    exit;
}

// =========================================================================
// 4. Action: Delete Transfer & Remove File
// =========================================================================
if ($action === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $del_id = (int)$_GET['id'];
    $stmt = $db->prepare('SELECT stored_filename FROM secure_transfers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $del_id]);
    $row = $stmt->fetch();

    if ($row && !empty($row['stored_filename'])) {
        $target_file = $vault_dir . '/' . $row['stored_filename'];
        if (file_exists($target_file)) {
            @unlink($target_file);
        }
    }

    $db->prepare('DELETE FROM secure_transfers WHERE id = :id')->execute(['id' => $del_id]);
    set_flash('success', 'Transfer package and stored file removed permanently.');
    header('Location: ' . base_path('admin/secure_transfers.php'));
    exit;
}

// =========================================================================
// 5. Query Transfers List for Dashboard View
// =========================================================================
$transfers = $db->query('
    SELECT st.*, u.username AS creator_name,
           COUNT(r.id) AS total_recipients,
           COALESCE(SUM(CASE WHEN r.status = "active" THEN 1 ELSE 0 END), 0) AS active_recipients,
           COALESCE(SUM(CASE WHEN r.status = "deactivated" THEN 1 ELSE 0 END), 0) AS deactivated_recipients,
           COALESCE(SUM(r.download_count), 0) AS total_downloads,
           (SELECT COUNT(*) FROM secure_transfer_logs WHERE transfer_id = st.id) AS total_logs
    FROM secure_transfers st
    LEFT JOIN secure_transfer_recipients r ON r.transfer_id = st.id
    LEFT JOIN users u ON u.id = st.created_by
    GROUP BY st.id
    ORDER BY st.created_at DESC
')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Secure Document Transfers - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .status-badge-active { background: #dcfce7; color: #15803d; font-weight: 700; padding: 2px 7px; border-radius: 4px; font-size: 0.74rem; }
        .status-badge-deactivated { background: #e2e8f0; color: #475569; font-weight: 700; padding: 2px 7px; border-radius: 4px; font-size: 0.74rem; }
        .status-badge-revoked { background: #fee2e2; color: #b91c1c; font-weight: 700; padding: 2px 7px; border-radius: 4px; font-size: 0.74rem; }
        .status-badge-expired { background: #fef3c7; color: #92400e; font-weight: 700; padding: 2px 7px; border-radius: 4px; font-size: 0.74rem; }
        .badge-otp { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; }
        .badge-direct { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem; }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(2px); z-index: 9999; justify-content: center; align-items: center;
        }
        .modal-dialog {
            background: #ffffff; width: 780px; max-width: 95vw; max-height: 90vh;
            overflow-y: auto; border-radius: 8px; padding: 24px 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1400px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h1 style="margin: 0 0 4px 0;">Content &raquo; Secure Document Transfers</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                        Distribute large files outside the public uploads folder with multi-recipient verification and individual kill switches.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn-primary" onclick="openUploadModal()">+ Upload & Share Document</button>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <section class="card">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid #cbd5e1; text-align: left;">
                            <th style="padding: 10px 12px;">Package Title / Stored File</th>
                            <th style="padding: 10px 12px;">Verification Mode</th>
                            <th style="padding: 10px 12px; text-align: center;">Recipients</th>
                            <th style="padding: 10px 12px; text-align: center;">Size</th>
                            <th style="padding: 10px 12px; text-align: center;">Downloads</th>
                            <th style="padding: 10px 12px; text-align: center;">Status</th>
                            <th style="padding: 10px 12px;">Expiration</th>
                            <th style="padding: 10px 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: #94a3b8; padding: 35px; font-style: italic;">
                                    No secure transfers created yet. Click "+ Upload & Share Document" to distribute a file.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                                <?php 
                                    $is_expired = (!empty($t['expires_at']) && strtotime($t['expires_at']) < time());
                                    $display_status = $is_expired ? 'expired' : $t['status'];
                                    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                                    $public_url = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path('transfer/' . $t['token']);
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 12px;">
                                        <strong style="color: #0b2545; font-size: 0.95rem;"><?= htmlspecialchars($t['title']) ?></strong>
                                        <div style="color: #64748b; font-size: 0.78rem; margin-top: 2px;">
                                            File: <code><?= htmlspecialchars($t['original_filename']) ?></code>
                                        </div>
                                    </td>
                                    <td style="padding: 10px 12px;">
                                        <?php if ($t['auth_mode'] === 'otp'): ?>
                                            <span class="badge-otp">🔒 4-DIGIT PIN</span>
                                        <?php else: ?>
                                            <span class="badge-direct">✉️ DIRECT MATCH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: center;">
                                        <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 3px 8px;" onclick="viewRecipients(<?= (int)$t['id'] ?>)">
                                            <?= (int)$t['total_recipients'] ?> Recipient<?= (int)$t['total_recipients'] === 1 ? '' : 's' ?>
                                        </button>
                                        <div style="font-size: 0.72rem; color: #64748b; margin-top: 3px;">
                                            <span style="color:#15803d; font-weight:700;"><?= (int)$t['active_recipients'] ?> active</span> &bull; 
                                            <span><?= (int)$t['deactivated_recipients'] ?> burned</span>
                                        </div>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 600;">
                                        <?= format_bytes((int)$t['file_size']) ?>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: center; font-weight: 700;">
                                        <?= (int)$t['total_downloads'] ?>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: center;">
                                        <span class="status-badge-<?= htmlspecialchars($display_status) ?>">
                                            <?= strtoupper($display_status) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 12px; font-size: 0.82rem;">
                                        <?php if (!empty($t['expires_at'])): ?>
                                            <span style="color: #64748b;"><?= date('M j, Y g:i A', strtotime($t['expires_at'])) ?></span>
                                        <?php else: ?>
                                            <span style="color: #059669; font-weight: 700; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 4px; font-size: 0.74rem;">NEVER</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px 12px; text-align: right; white-space: nowrap;">
                                        <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 3px 8px;" onclick="copyLink('<?= htmlspecialchars($public_url, ENT_QUOTES, 'UTF-8') ?>')">
                                            Copy Link
                                        </button>
                                        <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 3px 8px; margin-left: 4px;" onclick="viewLogs(<?= (int)$t['id'] ?>)">
                                            Logs (<?= (int)$t['total_logs'] ?>)
                                        </button>
                                        <?php if ($display_status === 'active'): ?>
                                            <a href="<?= base_path('admin/secure_transfers.php?action=revoke&id=' . $t['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                               onclick="return confirm('Immediately revoke this link for ALL recipients?');"
                                               style="color: #dc2626; font-size: 0.8rem; margin-left: 6px; text-decoration: none;">
                                                Revoke
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= base_path('admin/secure_transfers.php?action=delete&id=' . $t['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                           onclick="return confirm('Permanently delete this transfer record and remove the stored vault file?');"
                                           style="color: #94a3b8; font-size: 0.8rem; margin-left: 6px; text-decoration: none;">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- MODAL: UPLOAD PACKAGE -->
    <div id="uploadModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <h3 style="margin: 0; color: #0b2545;">Upload & Share Secure Document</h3>
                <button type="button" onclick="closeUploadModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>

            <form method="POST" action="<?= base_path('admin/secure_transfers.php') ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="create_transfer" value="1">

                <div class="form-row" style="margin-bottom: 14px;">
                    <label>Package Title *</label>
                    <input type="text" name="title" required placeholder="e.g. C-400 Remediation Phase II Design Dossier">
                </div>

                <div class="form-row" style="margin-bottom: 14px;">
                    <label>Description / Notes for Recipients (Optional)</label>
                    <textarea name="description" rows="2" placeholder="Brief briefing notes, clearance instructions, or contents summary..."></textarea>
                </div>

                <div class="form-row" style="margin-bottom: 14px;">
                    <label>Authorized Recipient Email(s) *</label>
                    <textarea name="recipient_emails" rows="3" required placeholder="Enter one or multiple email addresses (separated by commas or new lines)&#10;e.g. engineer@partner.gov, contractor@cleanupsite.com"></textarea>
                    <small style="color: #64748b; font-size: 0.78rem;">Each recipient will verify independently and can deactivate their own access upon download.</small>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="form-row">
                        <label>Identity Verification Mode *</label>
                        <select name="auth_mode" style="width: 100%;">
                            <option value="direct" selected>Direct Email Match (Faster Access)</option>
                            <option value="otp">Email + 4-Digit One-Time PIN (Higher Security)</option>
                        </select>
                        <small style="color: #64748b; font-size: 0.78rem;">OTP generates and emails an instant 4-digit PIN upon arrival.</small>
                    </div>

                    <div class="form-row">
                        <label>Expiration Window</label>
                        <select name="expire_days" style="width: 100%;">
                            <option value="0">No Expiration (Never)</option>
                            <option value="1">24 Hours</option>
                            <option value="3">3 Days</option>
                            <option value="7" selected>7 Days</option>
                            <option value="14">14 Days</option>
                            <option value="30">30 Days</option>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="margin-bottom: 16px;">
                    <label>Select Document File * (Stored in protected /vault/)</label>
                    <input type="file" name="package_file" required style="border: 1px dashed #cbd5e1; padding: 12px; background: #f8fafc; width: 100%; border-radius: 4px;">
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 14px; border-radius: 5px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #0b2545; cursor: pointer; font-size: 0.88rem; margin: 0;">
                        <input type="checkbox" name="notify_recipient" value="1" checked style="width: 16px; height: 16px;">
                        <span>Send email notification with secure link to all recipients immediately</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                    <button type="button" class="btn-secondary" onclick="closeUploadModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="background: #0b2545;">Upload & Share Package</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: RECIPIENTS BREAKDOWN -->
    <div id="recipientsModal" class="modal-overlay">
        <div class="modal-dialog" style="width: 780px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <h3 style="margin: 0; color: #0b2545;">Package Recipients & Individual Status</h3>
                <button type="button" onclick="closeRecipientsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <div id="recipientsModalContent" style="font-size: 0.85rem; color: #334155;">
                Loading recipients...
            </div>
        </div>
    </div>

    <!-- MODAL: AUDIT LOGS VIEWER WITH EXPORT TO CSV -->
    <div id="logsModal" class="modal-overlay">
        <div class="modal-dialog" style="width: 860px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <div>
                    <h3 style="margin: 0; color: #0b2545;">Transfer Forensic Audit Logs</h3>
                    <div id="logsModalSubtitle" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;"></div>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <a id="btnExportLogsCsv" href="#" class="btn-primary" style="font-size: 0.78rem; padding: 5px 12px; text-decoration: none; display: none;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 4px; vertical-align: middle;">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Export CSV
                    </a>
                    <button type="button" onclick="closeLogsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
                </div>
            </div>
            <div id="logsModalContent" style="font-size: 0.85rem; color: #334155;">
                Loading audit trail...
            </div>
        </div>
    </div>

    <script>
        function openUploadModal() { document.getElementById('uploadModal').style.display = 'flex'; }
        function closeUploadModal() { document.getElementById('uploadModal').style.display = 'none'; }
        function closeRecipientsModal() { document.getElementById('recipientsModal').style.display = 'none'; }
        function closeLogsModal() { document.getElementById('logsModal').style.display = 'none'; }

        function copyLink(url) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function() {
                    alert('Secure Transfer Link copied to clipboard:\n' + url);
                }).catch(function() {
                    fallbackCopyText(url);
                });
            } else {
                fallbackCopyText(url);
            }
        }

        function fallbackCopyText(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.top = "0";
            textArea.style.left = "-9999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    alert('Secure Transfer Link copied to clipboard:\n' + text);
                } else {
                    prompt('Copy this link using Ctrl+C / Cmd+C:', text);
                }
            } catch (err) {
                prompt('Copy this link using Ctrl+C / Cmd+C:', text);
            }
            document.body.removeChild(textArea);
        }

        function viewRecipients(transferId) {
            const modal = document.getElementById('recipientsModal');
            const content = document.getElementById('recipientsModalContent');
            modal.style.display = 'flex';
            content.innerHTML = '<p style="color:#64748b;">Loading recipients breakdown...</p>';

            fetch('<?= base_path("admin/secure_transfers.php?action=fetch_recipients&ajax=1&id=") ?>' + transferId)
                .then(res => res.json())
                .then(data => {
                    if (!data.length) {
                        content.innerHTML = '<p style="color:#64748b; font-style:italic;">No recipients found for this package.</p>';
                        return;
                    }
                    let html = `
                        <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                            <thead>
                                <tr style="border-bottom:2px solid #cbd5e1; text-align:left; font-size:0.8rem; color:#0b2545;">
                                    <th style="padding:8px;">Recipient Email</th>
                                    <th style="padding:8px; text-align:center;">Status</th>
                                    <th style="padding:8px; text-align:center;">Downloads</th>
                                    <th style="padding:8px;">Deactivated At</th>
                                    <th style="padding:8px; text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    data.forEach(r => {
                        const isDeact = (r.status === 'deactivated');
                        const statusBadge = isDeact 
                            ? `<span class="status-badge-deactivated">BURNED / CLOSED</span>`
                            : `<span class="status-badge-active">ACTIVE</span>`;
                        const deactDate = r.deactivated_at ? r.deactivated_at : '<span style="color:#94a3b8;">&mdash;</span>';

                        html += `
                            <tr style="border-bottom:1px solid #f1f5f9; font-size:0.84rem;">
                                <td style="padding:8px; font-weight:600; color:#0b2545;">${r.recipient_email}</td>
                                <td style="padding:8px; text-align:center;">${statusBadge}</td>
                                <td style="padding:8px; text-align:center; font-weight:700;">${r.download_count}</td>
                                <td style="padding:8px; font-size:0.8rem; color:#64748b;">${deactDate}</td>
                                <td style="padding:8px; text-align:right;">
                                    <a href="<?= base_path('admin/secure_transfers.php?action=resend_recipient&recipient_id=') ?>${r.id}&csrf_token=<?= get_csrf_token() ?>" 
                                       class="btn-secondary" style="font-size:0.75rem; padding:3px 7px; text-decoration:none;"
                                       onclick="return confirm('Resend download notification to ${r.recipient_email}?');">
                                        Resend Email
                                    </a>
                                </td>
                            </tr>
                        `;
                    });
                    html += `</tbody></table>`;
                    content.innerHTML = html;
                })
                .catch(err => {
                    content.innerHTML = '<p style="color:#dc2626;">Error retrieving recipients.</p>';
                });
        }

        function viewLogs(transferId) {
            const modal = document.getElementById('logsModal');
            const content = document.getElementById('logsModalContent');
            const exportBtn = document.getElementById('btnExportLogsCsv');
            const subtitle = document.getElementById('logsModalSubtitle');

            modal.style.display = 'flex';
            content.innerHTML = '<p style="color:#64748b;">Loading forensic log entries...</p>';
            if (subtitle) subtitle.innerText = 'Package ID #' + transferId;

            if (exportBtn) {
                exportBtn.href = '<?= base_path("admin/secure_transfers.php?action=export_logs&id=") ?>' + transferId + '&csrf_token=<?= get_csrf_token() ?>';
                exportBtn.style.display = 'none';
            }

            fetch('<?= base_path("admin/secure_transfers.php?action=fetch_logs&ajax=1&id=") ?>' + transferId)
                .then(res => res.json())
                .then(data => {
                    if (!data.length) {
                        content.innerHTML = '<p style="color:#64748b; font-style:italic;">No events recorded for this package yet.</p>';
                        if (exportBtn) exportBtn.style.display = 'none';
                        return;
                    }
                    if (exportBtn) exportBtn.style.display = 'inline-flex';

                    let html = `
                        <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                            <thead>
                                <tr style="border-bottom:2px solid #cbd5e1; text-align:left; font-size:0.8rem; color:#0b2545;">
                                    <th style="padding:6px;">Timestamp</th>
                                    <th style="padding:6px;">Event</th>
                                    <th style="padding:6px;">Recipient / Email</th>
                                    <th style="padding:6px;">IP Address</th>
                                    <th style="padding:6px;">User Agent</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    data.forEach(log => {
                        let badgeColor = '#64748b';
                        if (log.event_type === 'auth_success') badgeColor = '#059669';
                        if (log.event_type === 'otp_sent') badgeColor = '#2563eb';
                        if (log.event_type === 'download_started' || log.event_type === 'download_completed') badgeColor = '#0284c7';
                        if (log.event_type === 'deactivated_by_user') badgeColor = '#9333ea';
                        if (log.event_type === 'auth_failed' || log.event_type === 'otp_failed' || log.event_type === 'revoked_by_admin') badgeColor = '#dc2626';

                        html += `
                            <tr style="border-bottom:1px solid #f1f5f9; font-size:0.82rem;">
                                <td style="padding:7px 6px; white-space:nowrap;">${log.created_at}</td>
                                <td style="padding:7px 6px;">
                                    <span style="background:${badgeColor}; color:#fff; padding:2px 6px; border-radius:3px; font-weight:700; font-size:0.7rem; text-transform:uppercase;">
                                        ${log.event_type.replace(/_/g, ' ')}
                                    </span>
                                </td>
                                <td style="padding:7px 6px; font-weight:600;">${log.email_entered ? log.email_entered : '<span style="color:#94a3b8;">&mdash;</span>'}</td>
                                <td style="padding:7px 6px; font-family:monospace;">${log.ip_address}</td>
                                <td style="padding:7px 6px; font-size:0.75rem; color:#64748b; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${log.user_agent || ''}">
                                    ${log.user_agent || '&mdash;'}
                                </td>
                            </tr>
                        `;
                    });
                    html += `</tbody></table>`;
                    content.innerHTML = html;
                })
                .catch(err => {
                    content.innerHTML = '<p style="color:#dc2626;">Error retrieving logs.</p>';
                    if (exportBtn) exportBtn.style.display = 'none';
                });
        }

        window.onclick = function(e) {
            ['uploadModal', 'recipientsModal', 'logsModal'].forEach(id => {
                const el = document.getElementById(id);
                if (el && e.target === el) el.style.display = 'none';
            });
        };
    </script>
</body>
</html>
