<?php
/**
 * Public Trainee Portal
 * File: training.php (Project Root)
 */
session_start();
date_default_timezone_set('America/Chicago');

$dbConfigReturn = require_once __DIR__ . '/config/database.php';

$db = $pdo ?? $db ?? $conn ?? $dbh ?? $database ?? null;

if (!$db instanceof PDO) {
    if ($dbConfigReturn instanceof PDO) {
        $db = $dbConfigReturn;
    } elseif (function_exists('get_db')) {
        $db = get_db();
    } elseif (function_exists('getDB')) {
        $db = getDB();
    } elseif (function_exists('db_connect')) {
        $db = db_connect();
    } elseif (function_exists('db')) {
        $db = db();
    } elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
        $dsn = 'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $db = new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } elseif (is_array($dbConfigReturn) && isset($dbConfigReturn['host'], $dbConfigReturn['dbname'], $dbConfigReturn['user'])) {
        $dsn = 'mysql:host=' . $dbConfigReturn['host'] . (!empty($dbConfigReturn['port']) ? ';port=' . $dbConfigReturn['port'] : '') . ';dbname=' . $dbConfigReturn['dbname'] . ';charset=utf8mb4';
        $db = new PDO($dsn, $dbConfigReturn['user'], $dbConfigReturn['pass'] ?? $dbConfigReturn['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
}

if (!$db instanceof PDO) {
    die("Database connection not found.");
}

function logPublicTrainingEvent(PDO $db, int $moduleId, int $assignmentId, string $eventType, string $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);
    $stmt = $db->prepare("
        INSERT INTO training_logs (module_id, assignment_id, event_type, details, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$moduleId, $assignmentId, $eventType, $details, $ip, $ua]);
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$assignment = null;

if ($token !== '') {
    $stmt = $db->prepare("
        SELECT 
            a.*,
            m.title AS module_title,
            m.file_name,
            m.file_path,
            m.expires_at
        FROM training_assignments a
        JOIN training_modules m ON a.module_id = m.id
        WHERE a.token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$assignment) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Training Link Not Found</title>
        <style>
            body { font-family: system-ui, sans-serif; background: #f8fafc; padding: 60px 20px; text-align: center; color: #1e293b; }
            .box { max-width: 500px; margin: 0 auto; background: #fff; padding: 32px; border-radius: 8px; border: 1px solid #e2e8f0; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>Invalid or Unknown Training Link</h2>
            <p>Please check the link in your training notification email or contact your training administrator.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------------------------------------------------------
// 1. Check Expiration & Log if Newly Expired
// -----------------------------------------------------------------------------
$isPastExpiration = (strtotime($assignment['expires_at']) < time());
if ($assignment['status'] === 'pending' && $isPastExpiration) {
    $upd = $db->prepare("UPDATE training_assignments SET status = 'expired' WHERE id = ? AND status = 'pending'");
    $upd->execute([(int)$assignment['id']]);
    if ($upd->rowCount() > 0) {
        logPublicTrainingEvent(
            $db,
            (int)$assignment['module_id'],
            (int)$assignment['id'],
            'Training Expired',
            'Training was not completed before expiration date (' . $assignment['expires_at'] . ')'
        );
    }
    $assignment['status'] = 'expired';
}

// Block all actions if Completed or Expired
$isLinkActive = ($assignment['status'] === 'pending' && !$isPastExpiration);

// -----------------------------------------------------------------------------
// 2. Handle "Download PDF" Action (Logs "Training Downloaded")
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download' && $isLinkActive) {
    $diskFile = __DIR__ . '/' . ltrim($assignment['file_path'], '/');
    if (!is_file($diskFile)) {
        die("Training PDF file not found on server.");
    }

    logPublicTrainingEvent(
        $db,
        (int)$assignment['module_id'],
        (int)$assignment['id'],
        'Training Downloaded',
        "Trainee selected 'Download PDF' ({$assignment['file_name']})"
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($assignment['file_name']) . '"');
    header('Content-Length: ' . filesize($diskFile));
    readfile($diskFile);
    exit;
}

// -----------------------------------------------------------------------------
// 3. Handle AJAX "View Training" Action (Logs "Training Viewed")
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'log_view' && $isLinkActive) {
    header('Content-Type: application/json');
    logPublicTrainingEvent(
        $db,
        (int)$assignment['module_id'],
        (int)$assignment['id'],
        'Training Viewed',
        "Trainee selected 'View Training' to load embedded PDF ({$assignment['file_name']})"
    );
    echo json_encode(['ok' => true, 'pdf_url' => $assignment['file_path']]);
    exit;
}

// -----------------------------------------------------------------------------
// 4. Handle "Complete Training" Form Submission
// -----------------------------------------------------------------------------
$justCompleted = false;
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_training') {
    if (!$isLinkActive) {
        $errorMsg = "This training link is no longer active.";
    } elseif (empty($_POST['acknowledge_check'])) {
        $errorMsg = "You must check 'I have read and understand the training' to complete this course.";
    } else {
        $upd = $db->prepare("
            UPDATE training_assignments
            SET status = 'completed', completed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $upd->execute([(int)$assignment['id']]);

        if ($upd->rowCount() > 0) {
            logPublicTrainingEvent(
                $db,
                (int)$assignment['module_id'],
                (int)$assignment['id'],
                'Training Completed',
                "Trainee checked acknowledgment and completed training"
            );
            $assignment['status'] = 'completed';
            $assignment['completed_at'] = date('Y-m-d H:i:s');
            $isLinkActive = false;
            $justCompleted = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($assignment['module_title']) ?> - Training Portal</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; margin: 0; padding: 32px 16px; color: #1e293b; }
        .container { max-width: 960px; margin: 0 auto; background: #ffffff; border-radius: 10px; border: 1px solid #cbd5e1; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #0f172a; font-size: 24px; }
        .meta-box { background: #f8fafc; border-left: 4px solid #0b4f6c; padding: 14px 18px; border-radius: 4px; margin-bottom: 24px; font-size: 14px; line-height: 1.6; }
        .btn-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 11px 20px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; border: none; transition: opacity 0.15s; }
        .btn-primary { background: #0b4f6c; color: #fff; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-success { background: #15803d; color: #fff; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; opacity: 0.7; }
        #pdfViewerContainer { display: none; margin: 20px 0; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; background: #334155; }
        #pdfFrame { width: 100%; height: 680px; border: none; display: block; }
        .ack-card { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; margin-top: 24px; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 600; cursor: pointer; user-select: none; margin-bottom: 16px; }
        .checkbox-label input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; }
        .status-banner { padding: 20px; border-radius: 8px; text-align: center; margin-top: 16px; }
        .status-completed { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
        .status-expired { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
    </style>
</head>
<body>
<div class="container">
    <h1><?= htmlspecialchars($assignment['module_title']) ?></h1>

    <div class="meta-box">
        <div><strong>Assigned Trainee:</strong> <?= htmlspecialchars($assignment['trainee_name']) ?> (<?= htmlspecialchars($assignment['trainee_email']) ?>)</div>
        <div><strong>Expiration Date:</strong> <?= htmlspecialchars(date('F j, Y g:i A', strtotime($assignment['expires_at']))) ?></div>
    </div>

    <?php if ($errorMsg): ?>
        <div class="status-banner status-expired" style="margin-bottom:20px;"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <?php if ($assignment['status'] === 'completed'): ?>
        <div class="status-banner status-completed">
            <h2 style="margin-top:0;">Training Complete</h2>
            <?php if ($justCompleted): ?>
                <p>Thank you! Your training acknowledgment has been recorded and this training link is now closed.</p>
            <?php else: ?>
                <p>This training link is disabled because you already completed this training on <strong><?= htmlspecialchars(date('F j, Y g:i A', strtotime($assignment['completed_at']))) ?></strong>.</p>
            <?php endif; ?>
        </div>

    <?php elseif ($assignment['status'] === 'expired' || $isPastExpiration): ?>
        <div class="status-banner status-expired">
            <h2 style="margin-top:0;">Training Link Expired</h2>
            <p>This training module expired on <strong><?= htmlspecialchars(date('F j, Y g:i A', strtotime($assignment['expires_at']))) ?></strong> and the link has been disabled. Please contact your administrator if you need your training reactivated.</p>
        </div>

    <?php else: ?>
        <!-- Active Pending Training Interface -->
        <p style="font-size:15px; color:#334155;">
            Select <strong>View Training</strong> to load the training document directly on this page, or select <strong>Download PDF</strong> to save a copy to your device.
        </p>

        <div class="btn-bar">
            <button type="button" id="btnViewTraining" class="btn btn-primary">
                View Training
            </button>
            <a href="training.php?token=<?= urlencode($assignment['token']) ?>&action=download" id="btnDownloadTraining" class="btn btn-secondary">
                Download PDF
            </a>
        </div>

        <!-- Embedded PDF Container (Loaded when "View Training" is clicked) -->
        <div id="pdfViewerContainer">
            <iframe id="pdfFrame" src="" title="Training PDF Document"></iframe>
        </div>

        <!-- Acknowledgment & Complete Form -->
        <form method="post" class="ack-card">
            <input type="hidden" name="action" value="complete_training">
            <input type="hidden" name="token" value="<?= htmlspecialchars($assignment['token']) ?>">

            <label class="checkbox-label">
                <input type="checkbox" name="acknowledge_check" id="acknowledgeCheck" value="1">
                <span>I have read and understand the training</span>
            </label>

            <button type="submit" id="btnCompleteTraining" class="btn btn-success" disabled>
                Complete Training
            </button>
        </form>

        <script>
        (function() {
            const btnView = document.getElementById('btnViewTraining');
            const viewerContainer = document.getElementById('pdfViewerContainer');
            const pdfFrame = document.getElementById('pdfFrame');
            const ackCheck = document.getElementById('acknowledgeCheck');
            const btnComplete = document.getElementById('btnCompleteTraining');
            let pdfLoaded = false;

            // 1. Toggle Complete Training button when checkbox is checked
            ackCheck.addEventListener('change', function() {
                btnComplete.disabled = !this.checked;
            });

            // 2. View Training button: logs "Training Viewed" and embeds the PDF
            btnView.addEventListener('click', function() {
                const formData = new FormData();
                formData.append('ajax_action', 'log_view');
                formData.append('token', <?= json_encode($assignment['token']) ?>);

                fetch('training.php?token=' + encodeURIComponent(<?= json_encode($assignment['token']) ?>), {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.ok) {
                        viewerContainer.style.display = 'block';
                        if (!pdfLoaded) {
                            pdfFrame.src = data.pdf_url;
                            pdfLoaded = true;
                        }
                        viewerContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                })
                .catch(() => {
                    viewerContainer.style.display = 'block';
                    if (!pdfLoaded) {
                        pdfFrame.src = <?= json_encode($assignment['file_path']) ?>;
                        pdfLoaded = true;
                    }
                });
            });
        })();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
