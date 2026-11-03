<?php
// comments.php - Integrated Public Comments
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/template.php';
require_once __DIR__ . '/includes/public_comments.php';

$pdo = function_exists('get_db') ? get_db() : ($db ?? null);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$settings = get_comments_settings($pdo);
$status = check_comment_period_status($settings);

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$status['allowed']) {
        $error_msg = "The public comment period is currently closed.";
    } else {
        $fullName   = trim($_POST['full_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $rawComment = trim($_POST['comment'] ?? '');
        $visitorId  = trim($_POST['visitor_id'] ?? '');

        if (empty($visitorId) && !empty($_COOKIE['rnp_vid'])) {
            $visitorId = $_COOKIE['rnp_vid'];
        }

        // Enforce strict plain text (strip all HTML)
        $cleanComment = strip_tags($rawComment);

        if (empty($fullName)) {
            $error_msg = "Please enter your full name.";
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address.";
        } elseif (empty($cleanComment)) {
            $error_msg = "Please enter your comment. HTML formatting is not permitted.";
        } else {
            if (empty($visitorId)) {
                $visitorId = 'anon_' . md5($email . time());
            }

            $clientIp  = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
            $geo       = get_visitor_geolocation($clientIp);
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO public_comments 
                    (full_name, email, comment, visitor_id, ip_address, geo_country, geo_region, geo_city, geo_org, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $fullName,
                    $email,
                    $cleanComment,
                    $visitorId,
                    $clientIp,
                    $geo['country'],
                    $geo['region'],
                    $geo['city'],
                    $geo['org'],
                    $userAgent
                ]);

                // Sync with registrations table if present
                try {
                    $regCheck = $pdo->prepare("SELECT id FROM registrations WHERE email = ? LIMIT 1");
                    $regCheck->execute([$email]);
                    if (!$regCheck->fetch()) {
                        $regInsert = $pdo->prepare("INSERT INTO registrations (full_name, email, created_at) VALUES (?, ?, NOW())");
                        $regInsert->execute([$fullName, $email]);
                    }
                } catch (Exception $re) {
                    // Suppress if schema differs
                }

                $success_msg = "Thank you. Your public comment has been successfully recorded.";
            } catch (Exception $e) {
                $error_msg = "An error occurred while saving your comment. Please try again.";
            }
        }
    }
}

// Set up metadata and node context expected by templates/layout.php
$page_title = $settings['title'] ?? 'Public Comments';
$title = $page_title;
$node = [
    'title' => $page_title,
    'type'  => 'page',
    'slug'  => 'comments'
];

$bPath = function_exists('base_path') ? base_path() : '/';

// Buffer the content block
ob_start();
?>
<div class="container main-content-container my-4">
    <div class="page-header mb-4">
        <h1 class="page-title"><?= htmlspecialchars($settings['title']) ?></h1>

        <div class="status-indicator my-3">
            <?php if ($status['allowed']): ?>
                <span class="badge" style="background:#2e7d32; color:#ffffff; padding:6px 14px; border-radius:4px; font-weight:600; font-size:0.9rem; display:inline-block;">
                    ● Comment Period Open (Closes <?= date('F j, Y \a\t g:i A', $status['end_timestamp']) ?>)
                </span>
            <?php else: ?>
                <span class="badge" style="background:#c62828; color:#ffffff; padding:6px 14px; border-radius:4px; font-weight:600; font-size:0.9rem; display:inline-block;">
                    ● Comment Period Closed
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($settings['description'])): ?>
            <div class="lead-text mt-3" style="color: #4a5568; line-height: 1.6; font-size: 1.05rem;">
                <?= nl2br(htmlspecialchars($settings['description'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success" style="padding:16px; margin-bottom:24px; border-radius:4px; background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9;">
            <strong>Success:</strong> <?= htmlspecialchars($success_msg) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger" style="padding:16px; margin-bottom:24px; border-radius:4px; background:#ffebee; color:#b71c1c; border:1px solid #ffcdd2;">
            <strong>Notice:</strong> <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php endif; ?>

    <?php if (!$status['allowed']): ?>
        <div class="card p-4 text-center my-4" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:32px;">
            <h3 style="margin-top:0; color:#2d3748;">Submissions are Closed</h3>
            <p style="color:#718096; font-size:1.05rem; margin-bottom:0;">
                <?= htmlspecialchars($status['message']) ?>
            </p>
        </div>
    <?php else: ?>
        <div class="card form-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; padding:28px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:30px;">
            <form action="<?= $bPath ?>comments.php" method="POST" id="publicCommentForm">
                <input type="hidden" name="visitor_id" id="visitor_id" value="">

                <div class="form-group mb-3" style="margin-bottom:18px;">
                    <label for="full_name" style="display:block; font-weight:600; margin-bottom:6px; color:#2d3748;">Full Name <span style="color:#e53e3e;">*</span></label>
                    <input type="text" name="full_name" id="full_name" class="form-control" style="width:100%; max-width:550px; padding:10px 12px; border:1px solid #cbd5e0; border-radius:4px; font-size:1rem;" required 
                           value="<?= isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : '' ?>">
                </div>

                <div class="form-group mb-3" style="margin-bottom:18px;">
                    <label for="email" style="display:block; font-weight:600; margin-bottom:6px; color:#2d3748;">Email Address <span style="color:#e53e3e;">*</span></label>
                    <input type="email" name="email" id="email" class="form-control" style="width:100%; max-width:550px; padding:10px 12px; border:1px solid #cbd5e0; border-radius:4px; font-size:1rem;" required
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>

                <div class="form-group mb-4" style="margin-bottom:22px;">
                    <label for="comment" style="display:block; font-weight:600; margin-bottom:6px; color:#2d3748;">Comment <span style="color:#e53e3e;">*</span></label>
                    <textarea name="comment" id="comment" rows="7" class="form-control" style="width:100%; padding:12px; border:1px solid #cbd5e0; border-radius:4px; font-size:1rem; font-family:inherit;" required placeholder="Type your formal comments here..."><?= isset($_POST['comment']) ? htmlspecialchars(strip_tags($_POST['comment'])) : '' ?></textarea>
                    <small style="color:#718096; font-size:0.85rem; display:block; margin-top:4px;">
                        Plain text only. HTML tags are not permitted and will be stripped.
                    </small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-size:1rem; font-weight:600; cursor:pointer;">
                        Submit Public Comment
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<script src="<?= $bPath ?>/assets/js/tracker.js"></script>
<?php
$content = ob_get_clean();

// Render into primary CMS layout
require __DIR__ . '/templates/layout.php';
