<?php
/**
 * Admin Training Management Module
 * File: admin/training.php
 */
declare(strict_types=1);
date_default_timezone_set('America/Chicago');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/mailer.php';

if (function_exists('require_login')) {
    require_login();
} elseif (function_exists('require_admin')) {
    require_admin();
}

$db = get_db();

// -----------------------------------------------------------------------------
// 1. Seed Training Permissions & Resolve Current User Access
// -----------------------------------------------------------------------------
$training_permissions = [
    'add_training'      => 'Upload and create new training modules',
    'view_any_training' => 'View and manage any training module',
    'view_own_training' => 'View and manage only own uploaded training modules',
];
$check_perm = $db->prepare('SELECT id FROM permissions WHERE machine_name = ? LIMIT 1');
$insert_perm = $db->prepare('INSERT INTO permissions (machine_name, description) VALUES (?, ?)');
foreach ($training_permissions as $machine_name => $description) {
    $check_perm->execute([$machine_name]);
    if (!$check_perm->fetchColumn()) {
        $insert_perm->execute([$machine_name, $description]);
    }
}

function training_current_user_id(): int {
    if (function_exists('current_user_id')) {
        return (int)current_user_id();
    }
    if (function_exists('current_user')) {
        $u = current_user();
        if (is_array($u) && isset($u['id'])) return (int)$u['id'];
    }
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    if (!empty($_SESSION['uid'])) return (int)$_SESSION['uid'];
    if (!empty($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
    return 0;
}

function training_has_perm(PDO $db, string $perm): bool {
    if (function_exists('has_permission')) {
        return (bool)has_permission($perm);
    }
    if (function_exists('user_has_permission')) {
        return (bool)user_has_permission($perm);
    }
    if (function_exists('can')) {
        return (bool)can($perm);
    }

    $uid = training_current_user_id();
    if ($uid <= 0) return false;

    try {
        // Check administrator role or matching permission via user_roles / users.role_id
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = ?
              AND (r.machine_name = 'administrator' OR p.machine_name = ? OR p.machine_name = 'administer_site')
        ");
        $stmt->execute([$uid, $perm]);
        if ((int)$stmt->fetchColumn() > 0) return true;
    } catch (\Throwable $e) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM users u
                JOIN roles r ON r.id = u.role_id
                LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions p ON p.id = rp.permission_id
                WHERE u.id = ?
                  AND (r.machine_name = 'administrator' OR p.machine_name = ? OR p.machine_name = 'administer_site')
            ");
            $stmt->execute([$uid, $perm]);
            if ((int)$stmt->fetchColumn() > 0) return true;
        } catch (\Throwable $e2) {}
    }

    return false;
}

$currentUserId   = training_current_user_id();
$canAddTraining  = training_has_perm($db, 'add_training');
$canViewAny      = training_has_perm($db, 'view_any_training');
$canViewOwn      = training_has_perm($db, 'view_own_training') || $canAddTraining;

if (!$canViewAny && !$canViewOwn && !$canAddTraining) {
    http_response_code(403);
    die('Access denied: You do not have permission to view or manage training modules.');
}

// -----------------------------------------------------------------------------
// 2. Auto-Create Tables, Ensure created_by Column & Storage Directory
// -----------------------------------------------------------------------------
$uploadDir = __DIR__ . '/../assets/training_docs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$db->exec("
CREATE TABLE IF NOT EXISTS training_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    trainee_name VARCHAR(255) NOT NULL,
    trainee_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'completed', 'expired') NOT NULL DEFAULT 'pending',
    notified_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module_id),
    INDEX idx_token (token),
    CONSTRAINT fk_training_module FOREIGN KEY (module_id) REFERENCES training_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    assignment_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    details VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_module (module_id),
    INDEX idx_log_assign (assignment_id),
    CONSTRAINT fk_log_module FOREIGN KEY (module_id) REFERENCES training_modules(id) ON DELETE CASCADE,
    CONSTRAINT fk_log_assignment FOREIGN KEY (assignment_id) REFERENCES training_assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Auto-add created_by column if upgrading existing table
$colCheck = $db->query("SHOW COLUMNS FROM training_modules LIKE 'created_by'")->fetch();
if (!$colCheck) {
    $db->exec("ALTER TABLE training_modules ADD COLUMN created_by INT NOT NULL DEFAULT 0 AFTER expires_at, ADD INDEX idx_created_by (created_by)");
}

function canAccessTrainingModule(array $module, bool $canViewAny, bool $canViewOwn, int $currentUserId): bool {
    if ($canViewAny) {
        return true;
    }
    if ($canViewOwn && $currentUserId > 0 && (int)($module['created_by'] ?? 0) === $currentUserId) {
        return true;
    }
    return false;
}

function logTrainingEvent(PDO $db, int $moduleId, int $assignmentId, string $eventType, string $details = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);
    $stmt = $db->prepare("
        INSERT INTO training_logs (module_id, assignment_id, event_type, details, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$moduleId, $assignmentId, $eventType, substr($details, 0, 250), $ip, $ua]);
}

function syncExpiredTrainings(PDO $db): void {
    $stmt = $db->query("
        SELECT a.id AS assignment_id, a.module_id, m.expires_at
        FROM training_assignments a
        JOIN training_modules m ON a.module_id = m.id
        WHERE a.status = 'pending' AND m.expires_at < NOW()
    ");
    $expiredRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expiredRows as $row) {
        $upd = $db->prepare("UPDATE training_assignments SET status = 'expired' WHERE id = ? AND status = 'pending'");
        $upd->execute([$row['assignment_id']]);
        if ($upd->rowCount() > 0) {
            logTrainingEvent(
                $db,
                (int)$row['module_id'],
                (int)$row['assignment_id'],
                'Training Expired',
                'Training was not completed before expiration date (' . $row['expires_at'] . ')'
            );
        }
    }
}

syncExpiredTrainings($db);

function parseAndInsertTrainees(PDO $db, int $moduleId, string $expiresAt, string $assignMode, string $singleName, string $singleEmail, string $bulkInput): int {
    $entries = [];
    if ($assignMode === 'single') {
        if ($singleName !== '' && $singleEmail !== '') {
            $entries[] = ['name' => $singleName, 'email' => $singleEmail];
        }
    } elseif ($assignMode === 'bulk') {
        if ($bulkInput !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $bulkInput);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (preg_match('/^([^,<]+)[,<]\s*([^>]+)>?$/', $line, $m)) {
                    $entries[] = ['name' => trim($m[1]), 'email' => trim($m[2])];
                }
            }
        }
    }

    if (empty($entries)) {
        return 0;
    }

    $addedCount = 0;
    $isCurrentlyExpired = (strtotime($expiresAt) < time());
    $initialStatus = $isCurrentlyExpired ? 'expired' : 'pending';

    foreach ($entries as $item) {
        if (!filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $token = bin2hex(random_bytes(24));
        $ins = $db->prepare("
            INSERT INTO training_assignments (module_id, trainee_name, trainee_email, token, status, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$moduleId, $item['name'], $item['email'], $token, $initialStatus]);
        $assignId = (int)$db->lastInsertId();

        logTrainingEvent($db, $moduleId, $assignId, 'Training Assigned', "Assigned to {$item['name']} ({$item['email']})");
        if ($isCurrentlyExpired) {
            logTrainingEvent($db, $moduleId, $assignId, 'Training Expired', "Module already expired at time of assignment");
        }
        $addedCount++;
    }

    return $addedCount;
}

function dispatchTrainingEmail(string $toEmail, string $toName, string $moduleTitle, string $expiresAt, string $trainingUrl, ?string &$errorOut = null): bool {
    $subject = "Assigned Training: " . $moduleTitle;
    $expFormatted = date('F j, Y g:i A', strtotime($expiresAt));

    $messageContent = "
        <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
        <p>You have been assigned the following required training module:</p>
        <div style='background: #f8fafc; border-left: 4px solid #2563eb; padding: 12px 16px; margin: 16px 0;'>
            <div><strong>Course Title:</strong> " . htmlspecialchars($moduleTitle) . "</div>
            <div><strong>Expiration Date:</strong> " . htmlspecialchars($expFormatted) . "</div>
        </div>
        <p>Please select the button below to review the training material and complete your acknowledgment before the expiration date.</p>
        <p style='font-size: 13px; color: #64748b; margin-top: 16px;'>Direct Link: <a href='" . htmlspecialchars($trainingUrl) . "'>" . htmlspecialchars($trainingUrl) . "</a></p>
    ";

    $htmlBody = build_generic_email_html(
        'Training Assignment Notification',
        $messageContent,
        $trainingUrl,
        'Access Training Module'
    );

    $textBody = "Hello {$toName},\n\n"
              . "You have been assigned the following required training module:\n"
              . "Course Title: {$moduleTitle}\n"
              . "Expiration Date: {$expFormatted}\n\n"
              . "Access your training module here:\n{$trainingUrl}\n";

    try {
        return send_raw_email($toEmail, $subject, $htmlBody, $textBody, $toName);
    } catch (\Throwable $e) {
        $errorOut = $e->getMessage();
        try {
            queue_email($toEmail, $subject, $htmlBody, $textBody, $toName, 2);
        } catch (\Throwable $qe) {}
        return false;
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$publicBaseUrl = $protocol . '://' . $host . $baseDir;

$flashSuccess = '';
$flashError = '';

// -----------------------------------------------------------------------------
// 3. CSV Audit Log Export Handler (Respects view_any / view_own permission)
// -----------------------------------------------------------------------------
if (isset($_GET['export_csv']) && is_numeric($_GET['export_csv'])) {
    $moduleId = (int)$_GET['export_csv'];
    $modStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
    $modStmt->execute([$moduleId]);
    $module = $modStmt->fetch(PDO::FETCH_ASSOC);

    if (!$module || !canAccessTrainingModule($module, $canViewAny, $canViewOwn, $currentUserId)) {
        http_response_code(403);
        die("Training module not found or access denied.");
    }

    $logStmt = $db->prepare("
        SELECT 
            l.id AS log_id,
            l.created_at AS event_timestamp,
            l.event_type,
            l.details,
            l.ip_address,
            l.user_agent,
            a.trainee_name,
            a.trainee_email,
            a.status AS current_assignment_status,
            a.notified_at,
            a.completed_at
        FROM training_logs l
        JOIN training_assignments a ON l.assignment_id = a.id
        WHERE l.module_id = ?
        ORDER BY l.created_at ASC, l.id ASC
    ");
    $logStmt->execute([$moduleId]);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $module['title']);
    $filename = "training_audit_log_{$safeTitle}_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Log ID',
        'Course Title',
        'Course Expiration',
        'Trainee Name',
        'Trainee Email',
        'Event Type',
        'Event Timestamp',
        'Event Details',
        'Current Trainee Status',
        'Last Notified At',
        'Completed At',
        'IP Address',
        'User Agent'
    ]);

    foreach ($logs as $row) {
        fputcsv($out, [
            $row['log_id'],
            $module['title'],
            $module['expires_at'],
            $row['trainee_name'],
            $row['trainee_email'],
            $row['event_type'],
            $row['event_timestamp'],
            $row['details'],
            strtoupper($row['current_assignment_status']),
            $row['notified_at'] ?? '',
            $row['completed_at'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------------
// 4. POST Action Handlers
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A) Upload New Training Module (Requires add_training)
    if ($action === 'create_module') {
        if (!$canAddTraining) {
            $flashError = "You do not have permission to create training modules.";
        } else {
            $title = trim($_POST['title'] ?? '');
            $expiresAtInput = trim($_POST['expires_at'] ?? '');
            $assignMode = $_POST['assign_mode'] ?? 'single';
            $singleName = trim($_POST['trainee_name'] ?? '');
            $singleEmail = trim($_POST['trainee_email'] ?? '');
            $bulkInput = trim($_POST['bulk_trainees'] ?? '');

            if ($title === '' || $expiresAtInput === '') {
                $flashError = "Please provide both a training title and an expiration date.";
            } elseif (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                $flashError = "Please select a valid PDF file to upload.";
            } else {
                $fileTmp  = $_FILES['pdf_file']['tmp_name'];
                $origName = basename($_FILES['pdf_file']['name']);
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $fileTmp);
                finfo_close($finfo);

                if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                    $flashError = "Only valid .PDF files are allowed.";
                } else {
                    $cleanBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
                    $storedName = $cleanBase . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.pdf';
                    $destPath = $uploadDir . $storedName;
                    $publicRelPath = 'assets/training_docs/' . $storedName;

                    if (move_uploaded_file($fileTmp, $destPath)) {
                        $expiresFormatted = date('Y-m-d H:i:s', strtotime($expiresAtInput));
                        $stmt = $db->prepare("
                            INSERT INTO training_modules (title, file_name, file_path, expires_at, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$title, $origName, $publicRelPath, $expiresFormatted, $currentUserId]);
                        $newModId = (int)$db->lastInsertId();

                        $addedCount = parseAndInsertTrainees($db, $newModId, $expiresFormatted, $assignMode, $singleName, $singleEmail, $bulkInput);

                        if ($addedCount > 0) {
                            header("Location: training.php?module_id={$newModId}&msg=module_created&prompt_send=1");
                        } else {
                            header("Location: training.php?module_id={$newModId}&msg=module_created");
                        }
                        exit;
                    } else {
                        $flashError = "Failed to save uploaded PDF to assets/training_docs/.";
                    }
                }
            }
        }
    }

    // B) Update Expiration Date
    if ($action === 'update_expiration') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $newExpires = trim($_POST['expires_at'] ?? '');
        $mStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
        $mStmt->execute([$moduleId]);
        $mod = $mStmt->fetch(PDO::FETCH_ASSOC);

        if ($mod && canAccessTrainingModule($mod, $canViewAny, $canViewOwn, $currentUserId) && $newExpires !== '') {
            $expiresFormatted = date('Y-m-d H:i:s', strtotime($newExpires));
            $stmt = $db->prepare("UPDATE training_modules SET expires_at = ? WHERE id = ?");
            $stmt->execute([$expiresFormatted, $moduleId]);
            syncExpiredTrainings($db);
            $flashSuccess = "Training module expiration date updated.";
        } else {
            $flashError = "Access denied to modify this training module.";
        }
    }

    // C) Assign Trainees
    if ($action === 'assign_trainees') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $assignMode = $_POST['assign_mode'] ?? 'single';
        $singleName = trim($_POST['trainee_name'] ?? '');
        $singleEmail = trim($_POST['trainee_email'] ?? '');
        $bulkInput = trim($_POST['bulk_trainees'] ?? '');

        $mStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
        $mStmt->execute([$moduleId]);
        $mod = $mStmt->fetch(PDO::FETCH_ASSOC);

        if ($mod && canAccessTrainingModule($mod, $canViewAny, $canViewOwn, $currentUserId)) {
            $addedCount = parseAndInsertTrainees($db, $moduleId, $mod['expires_at'], $assignMode, $singleName, $singleEmail, $bulkInput);
            if ($addedCount > 0) {
                $flashSuccess = "Successfully assigned {$addedCount} trainee(s). Use the 'Send Notification' button to email their links.";
            } else {
                $flashError = "Please enter valid trainee Name and Email information.";
            }
        } else {
            $flashError = "Access denied to assign trainees to this module.";
        }
    }

    // D) Send Notification (Single Trainee)
    if ($action === 'send_notification') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT a.*, m.title AS module_title, m.expires_at, m.created_by
            FROM training_assignments a
            JOIN training_modules m ON a.module_id = m.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && canAccessTrainingModule($row, $canViewAny, $canViewOwn, $currentUserId)) {
            $link = $publicBaseUrl . '/training.php?token=' . urlencode($row['token']);
            $mailErr = null;
            $sent = dispatchTrainingEmail($row['trainee_email'], $row['trainee_name'], $row['module_title'], $row['expires_at'], $link, $mailErr);

            if ($sent) {
                $upd = $db->prepare("UPDATE training_assignments SET notified_at = NOW() WHERE id = ?");
                $upd->execute([$assignmentId]);
                logTrainingEvent($db, (int)$row['module_id'], $assignmentId, 'Training Sent', "Notification email sent to {$row['trainee_email']}");
                $flashSuccess = "Training notification sent to {$row['trainee_name']} ({$row['trainee_email']}).";
            } else {
                logTrainingEvent($db, (int)$row['module_id'], $assignmentId, 'Training Email Failed', "Failed to send to {$row['trainee_email']}: {$mailErr}");
                $flashError = "Email failed to send immediately (queued for retry): " . $mailErr;
            }
        }
    }

    // E) Send Notification to All Pending
    if ($action === 'send_all_pending') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $mStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
        $mStmt->execute([$moduleId]);
        $mod = $mStmt->fetch(PDO::FETCH_ASSOC);

        if ($mod && canAccessTrainingModule($mod, $canViewAny, $canViewOwn, $currentUserId)) {
            $stmt = $db->prepare("
                SELECT a.*, m.title AS module_title, m.expires_at
                FROM training_assignments a
                JOIN training_modules m ON a.module_id = m.id
                WHERE a.module_id = ? AND a.status = 'pending'
            ");
            $stmt->execute([$moduleId]);
            $pendingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sentCount = 0;
            $failCount = 0;
            $lastErr = '';

            foreach ($pendingList as $row) {
                $link = $publicBaseUrl . '/training.php?token=' . urlencode($row['token']);
                $mailErr = null;
                $sent = dispatchTrainingEmail($row['trainee_email'], $row['trainee_name'], $row['module_title'], $row['expires_at'], $link, $mailErr);

                if ($sent) {
                    $upd = $db->prepare("UPDATE training_assignments SET notified_at = NOW() WHERE id = ?");
                    $upd->execute([(int)$row['id']]);
                    logTrainingEvent($db, $moduleId, (int)$row['id'], 'Training Sent', "Bulk notification sent to {$row['trainee_email']}");
                    $sentCount++;
                } else {
                    logTrainingEvent($db, $moduleId, (int)$row['id'], 'Training Email Failed', "Bulk send failed for {$row['trainee_email']}: {$mailErr}");
                    $failCount++;
                    $lastErr = (string)$mailErr;
                }
            }

            if ($failCount === 0) {
                $flashSuccess = "Sent training notifications to {$sentCount} pending trainee(s).";
            } else {
                $flashError = "Sent {$sentCount} email(s), {$failCount} failed (queued for retry). Last error: {$lastErr}";
            }
        }
    }

    // F) Reactivate Training
    if ($action === 'reactivate_training') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT a.*, m.expires_at, m.created_by
            FROM training_assignments a
            JOIN training_modules m ON a.module_id = m.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && canAccessTrainingModule($row, $canViewAny, $canViewOwn, $currentUserId)) {
            if (strtotime($row['expires_at']) < time()) {
                $flashError = "Cannot reactivate trainee while the course expiration date is in the past. Please extend the module's expiration date first.";
            } else {
                $upd = $db->prepare("
                    UPDATE training_assignments
                    SET status = 'pending', completed_at = NULL
                    WHERE id = ?
                ");
                $upd->execute([$assignmentId]);

                logTrainingEvent(
                    $db,
                    (int)$row['module_id'],
                    $assignmentId,
                    'Training Reactivated',
                    "Admin reactivated training for {$row['trainee_name']} (previous status: {$row['status']}); status reset to pending"
                );
                $flashSuccess = "Training reactivated for {$row['trainee_name']}. Their link is active again and ready for completion.";
            }
        }
    }

    // G) Delete Module
    if ($action === 'delete_module') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
        $stmt->execute([$moduleId]);
        $mod = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mod && canAccessTrainingModule($mod, $canViewAny, $canViewOwn, $currentUserId)) {
            $diskPath = __DIR__ . '/../' . $mod['file_path'];
            if (is_file($diskPath)) {
                @unlink($diskPath);
            }
            $del = $db->prepare("DELETE FROM training_modules WHERE id = ?");
            $del->execute([$moduleId]);
            header("Location: training.php?msg=module_deleted");
            exit;
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'module_created') $flashSuccess = "Training module created successfully.";
    if ($_GET['msg'] === 'module_deleted') $flashSuccess = "Training module deleted.";
}

// -----------------------------------------------------------------------------
// 5. Fetch Modules Filtered by view_any_training vs view_own_training
// -----------------------------------------------------------------------------
if ($canViewAny) {
    $modulesStmt = $db->query("
        SELECT 
            m.*,
            COUNT(a.id) AS total_assigned,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
            SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
            SUM(CASE WHEN a.status = 'expired' THEN 1 ELSE 0 END) AS total_expired
        FROM training_modules m
        LEFT JOIN training_assignments a ON m.id = a.module_id
        GROUP BY m.id
        ORDER BY m.created_at DESC
    ");
    $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $modulesStmt = $db->prepare("
        SELECT 
            m.*,
            COUNT(a.id) AS total_assigned,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS total_completed,
            SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
            SUM(CASE WHEN a.status = 'expired' THEN 1 ELSE 0 END) AS total_expired
        FROM training_modules m
        LEFT JOIN training_assignments a ON m.id = a.module_id
        WHERE m.created_by = ?
        GROUP BY m.id
        ORDER BY m.created_at DESC
    ");
    $modulesStmt->execute([$currentUserId]);
    $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Only load detail view when module_id is explicitly clicked and user has access
$selectedModuleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;
$selectedModule = null;
$assignments = [];
$pendingAssignments = [];
$moduleLogs = [];

if ($selectedModuleId > 0) {
    $mStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
    $mStmt->execute([$selectedModuleId]);
    $candidateModule = $mStmt->fetch(PDO::FETCH_ASSOC);

    if ($candidateModule && canAccessTrainingModule($candidateModule, $canViewAny, $canViewOwn, $currentUserId)) {
        $selectedModule = $candidateModule;

        $aStmt = $db->prepare("SELECT * FROM training_assignments WHERE module_id = ? ORDER BY created_at DESC");
        $aStmt->execute([$selectedModuleId]);
        $assignments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignments as $a) {
            if ($a['status'] === 'pending') {
                $pendingAssignments[] = $a;
            }
        }

        $lStmt = $db->prepare("
            SELECT l.*, a.trainee_name, a.trainee_email
            FROM training_logs l
            JOIN training_assignments a ON l.assignment_id = a.id
            WHERE l.module_id = ?
            ORDER BY l.created_at DESC, l.id DESC
        ");
        $lStmt->execute([$selectedModuleId]);
        $moduleLogs = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $flashError = "Training module not found or you do not have permission to view it.";
    }
}

$showSendConfirmModal = ($selectedModule && !empty($_GET['prompt_send']) && !empty($pendingAssignments));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Management - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-body" style="display:flex; min-height:100vh; margin:0; background:#f8fafc;">

    <?php if (file_exists(__DIR__ . '/nav.php')) include __DIR__ . '/nav.php'; ?>

    <main class="admin-main" style="flex:1; min-width:0; padding:28px 32px; overflow-x:hidden;">
        <div class="admin-content" style="max-width:1360px; margin:0 auto;">

            <!-- Top Page Header -->
            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:24px;">
                <div>
                    <?php if ($selectedModule): ?>
                        <div style="margin-bottom:6px;">
                            <a href="training.php" style="color:#0b4f6c; text-decoration:none; font-size:13.5px; font-weight:600;">← Back to All Training Modules</a>
                        </div>
                        <h1 style="margin:0; font-size:24px; color:#0f172a;"><?= htmlspecialchars($selectedModule['title']) ?></h1>
                    <?php else: ?>
                        <h1 style="margin:0; font-size:24px; color:#0f172a;">Training Modules</h1>
                        <p style="margin:4px 0 0 0; font-size:14px; color:#64748b;">
                            <?= $canViewAny ? 'Viewing all training modules.' : 'Viewing training modules created by your account.' ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($canAddTraining): ?>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="button" onclick="openModal('createTrainingModal')" style="padding:10px 18px; border-radius:6px; font-size:14px; font-weight:600; background:#0b4f6c; color:#fff; border:none; cursor:pointer;">
                            + Create Training
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($flashSuccess): ?>
                <div style="padding:12px 16px; border-radius:6px; margin-bottom:20px; background:#dcfce7; color:#166534; border:1px solid #86efac; font-size:14px;">
                    <?= htmlspecialchars($flashSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div style="padding:12px 16px; border-radius:6px; margin-bottom:20px; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-size:14px;">
                    <?= htmlspecialchars($flashError) ?>
                </div>
            <?php endif; ?>

            <?php if (!$selectedModule): ?>
                <!-- ========================================================= -->
                <!-- MAIN PAGE: List Uploaded Training Modules                 -->
                <!-- ========================================================= -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <h3 style="margin:0 0 16px 0; font-size:16px; color:#0f172a;">
                        <?= $canViewAny ? 'All Uploaded Training Modules' : 'Your Uploaded Training Modules' ?> (<?= count($modules) ?>)
                    </h3>

                    <?php if (empty($modules)): ?>
                        <p style="color:#64748b; font-size:14px; margin:0;">
                            No training modules found.<?= $canAddTraining ? ' Click <strong>+ Create Training</strong> above to get started.' : '' ?>
                        </p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                                <thead>
                                    <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                        <th style="padding:12px; text-align:left; font-weight:600; color:#475569;">Course Title</th>
                                        <th style="padding:12px; text-align:left; font-weight:600; color:#475569;">Document</th>
                                        <th style="padding:12px; text-align:left; font-weight:600; color:#475569;">Uploaded</th>
                                        <th style="padding:12px; text-align:left; font-weight:600; color:#475569;">Expiration</th>
                                        <th style="padding:12px; text-align:left; font-weight:600; color:#475569;">Progress</th>
                                        <th style="padding:12px; text-align:right; font-weight:600; color:#475569;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($modules as $mod): 
                                        $isExp = strtotime($mod['expires_at']) < time();
                                    ?>
                                        <tr style="border-bottom:1px solid #e2e8f0;">
                                            <td style="padding:12px;">
                                                <a href="training.php?module_id=<?= (int)$mod['id'] ?>" style="font-weight:700; color:#0b4f6c; text-decoration:none; font-size:15px;">
                                                    <?= htmlspecialchars($mod['title']) ?>
                                                </a>
                                            </td>
                                            <td style="padding:12px; color:#475569;"><?= htmlspecialchars($mod['file_name']) ?></td>
                                            <td style="padding:12px; color:#64748b;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($mod['created_at']))) ?></td>
                                            <td style="padding:12px;">
                                                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($mod['expires_at']))) ?>
                                                <?php if ($isExp): ?>
                                                    <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700; text-transform:uppercase; background:#fee2e2; color:#991b1b; margin-left:6px;">Expired</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:12px; font-size:13px; color:#334155;">
                                                Assigned: <strong><?= (int)$mod['total_assigned'] ?></strong>  | 
                                                Completed: <strong style="color:#166534;"><?= (int)$mod['total_completed'] ?></strong>  | 
                                                Pending: <strong style="color:#92400e;"><?= (int)$mod['total_pending'] ?></strong>
                                            </td>
                                            <td style="padding:12px; text-align:right;">
                                                <a href="training.php?module_id=<?= (int)$mod['id'] ?>" style="display:inline-block; padding:7px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#0f172a; text-decoration:none; border:1px solid #cbd5e1;">
                                                    Manage Course →
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: 
                $modExpired = strtotime($selectedModule['expires_at']) < time();
            ?>
                <!-- ========================================================= -->
                <!-- MODULE DETAIL VIEW: Metadata + Assigned Trainees          -->
                <!-- ========================================================= -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div>
                            <h2 style="margin:0 0 6px 0; font-size:20px; color:#0f172a;"><?= htmlspecialchars($selectedModule['title']) ?></h2>
                            <div style="font-size:13.5px; color:#475569;">
                                File: <a href="../<?= htmlspecialchars($selectedModule['file_path']) ?>" target="_blank" style="color:#0b4f6c; font-weight:600;"><?= htmlspecialchars($selectedModule['file_name']) ?></a>
                                  |  
                                Uploaded: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($selectedModule['created_at']))) ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <button type="button" onclick="openModal('auditLogModal')" style="padding:8px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#0f172a; border:1px solid #cbd5e1; cursor:pointer;">
                                View Audit Log (<?= count($moduleLogs) ?>)
                            </button>
                            <a href="training.php?export_csv=<?= (int)$selectedModule['id'] ?>" style="display:inline-block; padding:8px 14px; border-radius:5px; font-size:13px; font-weight:600; text-decoration:none; background:#0b4f6c; color:#fff;">
                                Download Audit CSV
                            </a>
                            <form method="post" onsubmit="return confirm('Delete this training module and all associated trainee records and logs?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_module">
                                <input type="hidden" name="module_id" value="<?= (int)$selectedModule['id'] ?>">
                                <button type="submit" style="padding:8px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#dc2626; color:#fff; border:none; cursor:pointer;">Delete Module</button>
                            </form>
                        </div>
                    </div>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:16px 0;">

                    <form method="post" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin:0;">
                        <input type="hidden" name="action" value="update_expiration">
                        <input type="hidden" name="module_id" value="<?= (int)$selectedModule['id'] ?>">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#334155;">Module Expiration Date & Time</label>
                            <input type="datetime-local" name="expires_at" value="<?= date('Y-m-d\TH:i', strtotime($selectedModule['expires_at'])) ?>" required style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:13.5px;">
                        </div>
                        <button type="submit" style="padding:8px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; cursor:pointer;">Update Expiration</button>
                        <?php if ($modExpired): ?>
                            <span style="display:inline-block; padding:6px 10px; border-radius:99px; font-size:11px; font-weight:700; text-transform:uppercase; background:#fee2e2; color:#991b1b;">Module Currently Expired (Links Disabled)</span>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Assigned Trainees Card -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:17px; color:#0f172a;">Assigned Trainees (<?= count($assignments) ?>)</h3>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <button type="button" onclick="openModal('assignTraineesModal')" style="padding:7px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#0b4f6c; color:#fff; border:none; cursor:pointer;">
                                + Assign Trainees
                            </button>
                            <?php if (!empty($assignments)): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="send_all_pending">
                                    <input type="hidden" name="module_id" value="<?= (int)$selectedModule['id'] ?>">
                                    <button type="submit" style="padding:7px 14px; border-radius:5px; font-size:13px; font-weight:600; background:#334155; color:#fff; border:none; cursor:pointer;">
                                        Send Notification to All Pending
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($assignments)): ?>
                        <p style="color:#64748b; font-size:14px; margin:0;">No individuals assigned to this module yet. Click <strong>+ Assign Trainees</strong> to add users.</p>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                                <thead>
                                    <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Name</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Email</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Status</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Last Notified</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Completed At</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Training Link</th>
                                        <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignments as $a): 
                                        $traineeUrl = $publicBaseUrl . '/training.php?token=' . urlencode($a['token']);
                                        if ($a['status'] === 'completed') {
                                            $badgeStyle = 'background:#dcfce7; color:#166534;';
                                        } elseif ($a['status'] === 'expired') {
                                            $badgeStyle = 'background:#fee2e2; color:#991b1b;';
                                        } else {
                                            $badgeStyle = 'background:#fef3c7; color:#92400e;';
                                        }
                                    ?>
                                        <tr style="border-bottom:1px solid #e2e8f0;">
                                            <td style="padding:10px 12px;"><strong><?= htmlspecialchars($a['trainee_name']) ?></strong></td>
                                            <td style="padding:10px 12px;"><?= htmlspecialchars($a['trainee_email']) ?></td>
                                            <td style="padding:10px 12px;">
                                                <span style="display:inline-block; padding:3px 8px; border-radius:99px; font-size:11px; font-weight:700; text-transform:uppercase; <?= $badgeStyle ?>">
                                                    <?= htmlspecialchars($a['status']) ?>
                                                </span>
                                            </td>
                                            <td style="padding:10px 12px;"><?= $a['notified_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($a['notified_at']))) : '<span style="color:#94a3b8;">Not sent</span>' ?></td>
                                            <td style="padding:10px 12px;"><?= $a['completed_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($a['completed_at']))) : '—' ?></td>
                                            <td style="padding:10px 12px;">
                                                <input type="text" readonly style="font-size:12px; width:190px; padding:5px 8px; border:1px solid #cbd5e1; border-radius:4px;" value="<?= htmlspecialchars($traineeUrl) ?>" onclick="this.select();">
                                            </td>
                                            <td style="padding:10px 12px; white-space:nowrap;">
                                                <form method="post" style="display:inline-block; margin:0 4px 0 0;">
                                                    <input type="hidden" name="action" value="send_notification">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                                    <button type="submit" style="padding:5px 10px; border-radius:4px; font-size:12px; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; cursor:pointer;">
                                                        <?= $a['notified_at'] ? 'Resend Email' : 'Send Notification' ?>
                                                    </button>
                                                </form>

                                                <?php if ($a['status'] !== 'pending'): ?>
                                                    <form method="post" style="display:inline-block; margin:0;" onsubmit="return confirm('Reactivate training for <?= htmlspecialchars(addslashes($a['trainee_name'])) ?>? Status will reset to pending.');">
                                                        <input type="hidden" name="action" value="reactivate_training">
                                                        <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                                        <button type="submit" style="padding:5px 10px; border-radius:4px; font-size:12px; font-weight:600; background:#f59e0b; color:#fff; border:none; cursor:pointer;">Reactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <?php if ($canAddTraining): ?>
        <!-- ================================================================= -->
        <!-- MODAL 1: Create Training (Requires add_training)                  -->
        <!-- ================================================================= -->
        <div id="createTrainingModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; width:100%; max-width:580px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; max-height:90vh; display:flex; flex-direction:column;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                    <h3 style="margin:0; font-size:17px; color:#0f172a;">Create New Training Module</h3>
                    <button type="button" onclick="closeModal('createTrainingModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">×</button>
                </div>
                <form method="post" enctype="multipart/form-data" style="padding:20px; overflow-y:auto; margin:0;">
                    <input type="hidden" name="action" value="create_module">

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">Training Title *</label>
                        <input type="text" name="title" required placeholder="e.g., Annual Radiation Safety Briefing" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">Expiration Date & Time *</label>
                        <input type="datetime-local" name="expires_at" required style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">Training Document (.PDF) *</label>
                        <input type="file" name="pdf_file" accept="application/pdf,.pdf" required style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                    </div>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:16px 0;">

                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:8px; color:#334155;">Assign Trainees Now (Optional)</label>
                        <input type="hidden" name="assign_mode" id="create_assign_mode" value="single">
                        <div style="display:inline-flex; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                            <button type="button" id="create_btn_single" onclick="switchAssignMode('create', 'single')" style="padding:7px 14px; font-size:13px; font-weight:600; border:none; cursor:pointer; background:#0b4f6c; color:#fff;">
                                Single User
                            </button>
                            <button type="button" id="create_btn_bulk" onclick="switchAssignMode('create', 'bulk')" style="padding:7px 14px; font-size:13px; font-weight:600; border:none; cursor:pointer; background:#f8fafc; color:#334155;">
                                Bulk Users
                            </button>
                        </div>
                    </div>

                    <div id="create_single_box" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px; color:#475569;">Trainee Name</label>
                            <input type="text" name="trainee_name" placeholder="Jane Doe" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px; color:#475569;">Trainee Email</label>
                            <input type="email" name="trainee_email" placeholder="jane.doe@example.com" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                        </div>
                    </div>

                    <div id="create_bulk_box" style="display:none; margin-bottom:16px;">
                        <label style="display:block; font-weight:600; font-size:12px; margin-bottom:4px; color:#475569;">One trainee per line: <code>Full Name, email@domain.com</code></label>
                        <textarea name="bulk_trainees" rows="4" placeholder="John Smith, john.smith@example.com
Sarah Carter, sarah.carter@example.com" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;"></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:8px;">
                        <button type="button" onclick="closeModal('createTrainingModal')" style="padding:9px 16px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; cursor:pointer;">Cancel</button>
                        <button type="submit" style="padding:9px 18px; border-radius:5px; font-size:13px; font-weight:600; background:#0b4f6c; color:#fff; border:none; cursor:pointer;">Upload & Save Training</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selectedModule): ?>
        <!-- ================================================================= -->
        <!-- MODAL 2: Assign Trainees to Existing Course                       -->
        <!-- ================================================================= -->
        <div id="assignTraineesModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; width:100%; max-width:540px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                    <h3 style="margin:0; font-size:17px; color:#0f172a;">Assign Trainees — <?= htmlspecialchars($selectedModule['title']) ?></h3>
                    <button type="button" onclick="closeModal('assignTraineesModal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">×</button>
                </div>
                <form method="post" style="padding:20px; margin:0;">
                    <input type="hidden" name="action" value="assign_trainees">
                    <input type="hidden" name="module_id" value="<?= (int)$selectedModule['id'] ?>">
                    <input type="hidden" name="assign_mode" id="assign_assign_mode" value="single">

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:8px; color:#334155;">Assignment Mode</label>
                        <div style="display:inline-flex; border:1px solid #cbd5e1; border-radius:6px; overflow:hidden;">
                            <button type="button" id="assign_btn_single" onclick="switchAssignMode('assign', 'single')" style="padding:7px 14px; font-size:13px; font-weight:600; border:none; cursor:pointer; background:#0b4f6c; color:#fff;">
                                Single User
                            </button>
                            <button type="button" id="assign_btn_bulk" onclick="switchAssignMode('assign', 'bulk')" style="padding:7px 14px; font-size:13px; font-weight:600; border:none; cursor:pointer; background:#f8fafc; color:#334155;">
                                Bulk Users
                            </button>
                        </div>
                    </div>

                    <div id="assign_single_box" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">Trainee Name</label>
                            <input type="text" name="trainee_name" placeholder="Jane Doe" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">Trainee Email</label>
                            <input type="email" name="trainee_email" placeholder="jane.doe@example.com" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;">
                        </div>
                    </div>

                    <div id="assign_bulk_box" style="display:none; margin-bottom:18px;">
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:5px; color:#334155;">One trainee per line: <code>Full Name, email@domain.com</code></label>
                        <textarea name="bulk_trainees" rows="5" placeholder="John Smith, john.smith@example.com
Sarah Carter, sarah.carter@example.com" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:5px; font-size:14px;"></textarea>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="closeModal('assignTraineesModal')" style="padding:8px 16px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; cursor:pointer;">Cancel</button>
                        <button type="submit" style="padding:8px 18px; border-radius:5px; font-size:13px; font-weight:600; background:#0b4f6c; color:#fff; border:none; cursor:pointer;">Assign Trainee(s)</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- MODAL 3: Course Audit Log Modal                                   -->
        <!-- ================================================================= -->
        <div id="auditLogModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; width:100%; max-width:960px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; max-height:88vh; display:flex; flex-direction:column;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                    <div>
                        <h3 style="margin:0; font-size:17px; color:#0f172a;">Course Audit Log — <?= htmlspecialchars($selectedModule['title']) ?></h3>
                        <span style="font-size:12px; color:#64748b;"><?= count($moduleLogs) ?> recorded event(s)</span>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <a href="training.php?export_csv=<?= (int)$selectedModule['id'] ?>" style="padding:6px 12px; border-radius:5px; font-size:12.5px; font-weight:600; text-decoration:none; background:#0b4f6c; color:#fff;">
                            Export Audit Log (.CSV)
                        </a>
                        <button type="button" onclick="closeModal('auditLogModal')" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">×</button>
                    </div>
                </div>

                <div style="padding:20px; overflow-y:auto; flex:1;">
                    <?php if (empty($moduleLogs)): ?>
                        <p style="color:#64748b; font-size:14px; margin:0;">No activity logged for this training module yet.</p>
                    <?php else: ?>
                        <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Timestamp</th>
                                    <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Trainee</th>
                                    <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Event Type</th>
                                    <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">Details</th>
                                    <th style="padding:10px 12px; text-align:left; font-weight:600; color:#475569;">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($moduleLogs as $log): ?>
                                    <tr style="border-bottom:1px solid #e2e8f0;">
                                        <td style="padding:10px 12px; white-space:nowrap;"><?= htmlspecialchars(date('M j, Y g:i:s A', strtotime($log['created_at']))) ?></td>
                                        <td style="padding:10px 12px;">
                                            <strong><?= htmlspecialchars($log['trainee_name']) ?></strong><br>
                                            <span style="font-size:12px; color:#64748b;"><?= htmlspecialchars($log['trainee_email']) ?></span>
                                        </td>
                                        <td style="padding:10px 12px;"><strong><?= htmlspecialchars($log['event_type']) ?></strong></td>
                                        <td style="padding:10px 12px;"><?= htmlspecialchars($log['details'] ?? '') ?></td>
                                        <td style="padding:10px 12px; font-family:monospace; font-size:12px;"><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="padding:12px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; text-align:right;">
                    <button type="button" onclick="closeModal('auditLogModal')" style="padding:7px 16px; border-radius:5px; font-size:13px; font-weight:600; background:#e2e8f0; color:#1e293b; border:none; cursor:pointer;">Close</button>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- MODAL 4: Post-Create Confirmation to Send Notifications Now       -->
        <!-- ================================================================= -->
        <div id="confirmSendModal" style="display:<?= $showSendConfirmModal ? 'flex' : 'none' ?>; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; width:100%; max-width:560px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.15); overflow:hidden; max-height:88vh; display:flex; flex-direction:column;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
                    <h3 style="margin:0; font-size:17px; color:#0f172a;">Send Training Notifications Now?</h3>
                    <button type="button" onclick="dismissConfirmSendModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">×</button>
                </div>

                <div style="padding:20px; overflow-y:auto; flex:1;">
                    <p style="margin:0 0 12px 0; font-size:14.5px; color:#334155; line-height:1.5;">
                        Your training course <strong><?= htmlspecialchars($selectedModule['title']) ?></strong> has been saved. Are you ready to send training notification emails to the following <strong><?= count($pendingAssignments) ?></strong> assigned user(s) now?
                    </p>

                    <div style="border:1px solid #e2e8f0; border-radius:6px; max-height:240px; overflow-y:auto; background:#f8fafc;">
                        <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                            <thead>
                                <tr style="background:#f1f5f9; border-bottom:1px solid #e2e8f0;">
                                    <th style="padding:8px 12px; text-align:left; font-weight:600; color:#475569;">Name</th>
                                    <th style="padding:8px 12px; text-align:left; font-weight:600; color:#475569;">Email Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingAssignments as $pa): ?>
                                    <tr style="border-bottom:1px solid #e2e8f0; background:#fff;">
                                        <td style="padding:8px 12px; font-weight:600; color:#0f172a;"><?= htmlspecialchars($pa['trainee_name']) ?></td>
                                        <td style="padding:8px 12px; color:#475569;"><?= htmlspecialchars($pa['trainee_email']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="dismissConfirmSendModal()" style="padding:9px 16px; border-radius:5px; font-size:13px; font-weight:600; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; cursor:pointer;">
                        No, Not Yet
                    </button>
                    <form method="post" action="training.php?module_id=<?= (int)$selectedModule['id'] ?>" style="margin:0;">
                        <input type="hidden" name="action" value="send_all_pending">
                        <input type="hidden" name="module_id" value="<?= (int)$selectedModule['id'] ?>">
                        <button type="submit" style="padding:9px 18px; border-radius:5px; font-size:13px; font-weight:600; background:#0b4f6c; color:#fff; border:none; cursor:pointer;">
                            Yes, Send Notifications Now
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
    function openModal(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    function dismissConfirmSendModal() {
        closeModal('confirmSendModal');
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('prompt_send');
            window.history.replaceState({}, document.title, url.toString());
        }
    }

    function switchAssignMode(prefix, mode) {
        var modeInput = document.getElementById(prefix + '_assign_mode');
        var singleBox = document.getElementById(prefix + '_single_box');
        var bulkBox   = document.getElementById(prefix + '_bulk_box');
        var btnSingle = document.getElementById(prefix + '_btn_single');
        var btnBulk   = document.getElementById(prefix + '_btn_bulk');

        if (!modeInput) return;
        modeInput.value = mode;

        if (mode === 'single') {
            singleBox.style.display = 'grid';
            bulkBox.style.display = 'none';
            btnSingle.style.background = '#0b4f6c';
            btnSingle.style.color = '#ffffff';
            btnBulk.style.background = '#f8fafc';
            btnBulk.style.color = '#334155';
        } else {
            singleBox.style.display = 'none';
            bulkBox.style.display = 'block';
            btnBulk.style.background = '#0b4f6c';
            btnBulk.style.color = '#ffffff';
            btnSingle.style.background = '#f8fafc';
            btnSingle.style.color = '#334155';
        }
    }

    window.addEventListener('click', function(e) {
        ['createTrainingModal', 'assignTraineesModal', 'auditLogModal', 'confirmSendModal'].forEach(function(id) {
            var modal = document.getElementById(id);
            if (modal && e.target === modal) {
                if (id === 'confirmSendModal') {
                    dismissConfirmSendModal();
                } else {
                    modal.style.display = 'none';
                }
            }
        });
    });
    </script>
</body>
</html>
