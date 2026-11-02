<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = get_db();

// 1. Resolve Transfer Token
$token = trim((string)($_GET['token'] ?? $_GET['t'] ?? ''));

if ($token === '' && isset($_GET['q'])) {
    if (preg_match('#^transfer/([a-f0-9]{32,64})#i', trim((string)$_GET['q'], '/'), $m)) {
        $token = $m[1];
    }
}

if ($token === '') {
    $uri_path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (preg_match('#(?:^|/)transfer/([a-f0-9]{32,64})$#i', $uri_path, $matches)) {
        $token = $matches[1];
    }
}

if ($token === '') {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:sans-serif;padding:50px;text-align:center;color:#334155;}h2{color:#0b2545;}</style></head><body><h2>Transfer Not Found</h2><p>No valid security token was detected in the request URL.</p></body></html>');
}

// 2. Locate Transfer Package
$stmt = $db->prepare('SELECT * FROM secure_transfers WHERE token = :t LIMIT 1');
$stmt->execute(['t' => $token]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:sans-serif;padding:50px;text-align:center;color:#334155;}h2{color:#0b2545;}</style></head><body><h2>Transfer Package Unavailable</h2><p>This secure document package does not exist or has been removed.</p></body></html>');
}

$transfer_id = (int)$transfer['id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$vault_dir = __DIR__ . '/vault';
$file_path = $vault_dir . '/' . $transfer['stored_filename'];

$log_event = function(string $event, ?string $email = null, ?int $recip_id = null, int $bytes = 0) use ($db, $transfer_id, $ip, $ua): void {
    try {
        $stmt = $db->prepare('
            INSERT INTO secure_transfer_logs (transfer_id, recipient_id, event_type, email_entered, ip_address, user_agent, bytes_sent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$transfer_id, $recip_id, $event, $email, $ip, $ua, $bytes]);
    } catch (\Throwable $e) {}
};

// Check Package Expiration
$is_expired = (!empty($transfer['expires_at']) && strtotime($transfer['expires_at']) < time());
if ($is_expired && $transfer['status'] === 'active') {
    $db->prepare("UPDATE secure_transfers SET status = 'expired' WHERE id = ?")->execute([$transfer_id]);
    $transfer['status'] = 'expired';
}

$is_admin = function_exists('user_has_permission') && user_has_permission('administer_site');

$session_auth_key = 'auth_transfer_' . $token;
$session_otp_key  = 'pending_otp_' . $token;

$current_recipient = null;
if (!empty($_SESSION[$session_auth_key]['recipient_id'])) {
    $r_stmt = $db->prepare('SELECT * FROM secure_transfer_recipients WHERE id = :rid AND transfer_id = :tid LIMIT 1');
    $r_stmt->execute(['rid' => (int)$_SESSION[$session_auth_key]['recipient_id'], 'tid' => $transfer_id]);
    $current_recipient = $r_stmt->fetch(PDO::FETCH_ASSOC);
}

$is_authorized = ($current_recipient && $current_recipient['status'] === 'active') || $is_admin;
$auth_error = '';
$otp_screen = false;

// 3. Action: Reset Verification Session
if (isset($_GET['reset_auth'])) {
    unset($_SESSION[$session_auth_key], $_SESSION[$session_otp_key]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 4. Action: Recipient Deactivates Their Own Link ("Self-Burn")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_deactivate']) && $is_authorized) {
    if ($current_recipient) {
        $db->prepare("UPDATE secure_transfer_recipients SET status = 'deactivated', deactivated_at = NOW() WHERE id = ?")
           ->execute([(int)$current_recipient['id']]);
        $log_event('deactivated_by_user', $current_recipient['recipient_email'], (int)$current_recipient['id']);
    } else {
        $log_event('deactivated_by_user', 'admin');
    }

    unset($_SESSION[$session_auth_key], $_SESSION[$session_otp_key]);
    $is_authorized = false;
    $deactivated_just_now = true;
}

// 5. Action: Step 1 - Enter Email (Direct Match or Send OTP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_email'])) {
    $entered_email = strtolower(trim((string)($_POST['recipient_email'] ?? '')));

    $chk_stmt = $db->prepare('SELECT * FROM secure_transfer_recipients WHERE transfer_id = :tid AND LOWER(recipient_email) = :em LIMIT 1');
    $chk_stmt->execute(['tid' => $transfer_id, 'em' => $entered_email]);
    $recip = $chk_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recip) {
        $log_event('auth_failed', $entered_email);
        $auth_error = 'This email address is not an authorized recipient for this document package.';
    } elseif ($recip['status'] === 'deactivated') {
        $log_event('auth_failed', $entered_email, (int)$recip['id']);
        $auth_error = 'Your download link was marked as received and permanently deactivated on ' . date('M j, Y g:i A', strtotime($recip['deactivated_at']));
    } else {
        // Recipient is valid and active
        if ($transfer['auth_mode'] === 'otp') {
            // Generate 4-digit numeric code
            $otp_code = str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $otp_exp  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $db->prepare('UPDATE secure_transfer_recipients SET otp_code = ?, otp_expires_at = ?, otp_attempts = 0 WHERE id = ?')
               ->execute([$otp_code, $otp_exp, (int)$recip['id']]);

            // Email the OTP code immediately
            $site_name = function_exists('get_site_setting') ? get_site_setting('site_name', 'Four Rivers Nuclear Partnership') : 'Four Rivers Nuclear Partnership';
            $otp_body  = "<p>Your 4-digit verification code to unlock <strong>" . htmlspecialchars($transfer['title']) . "</strong> is:</p>";
            $otp_body .= "<div style='background:#f1f5f9; border:2px dashed #0b2545; border-radius:6px; padding:16px; text-align:center; margin:20px 0;'>";
            $otp_body .= "  <span style='font-size:32px; font-weight:800; letter-spacing:6px; color:#0b2545; font-family:monospace;'>{$otp_code}</span>";
            $otp_body .= "</div>";
            $otp_body .= "<p style='color:#64748b; font-size:13px;'>This code is valid for 15 minutes. If you did not request this file, you may safely disregard this message.</p>";

            $email_html = function_exists('build_frnp_email_html')
                ? build_frnp_email_html("Verification PIN: {$transfer['title']}", $otp_body)
                : $otp_body;

            queue_email($recip['recipient_email'], "Verification Code: {$otp_code} for {$transfer['title']}", $email_html, '', null, 1);
            if (function_exists('process_email_queue')) {
                process_email_queue(1);
            }

            $_SESSION[$session_otp_key] = [
                'recipient_id'    => (int)$recip['id'],
                'recipient_email' => $recip['recipient_email']
            ];
            $log_event('otp_sent', $entered_email, (int)$recip['id']);
            $otp_screen = true;
        } else {
            // Direct Email Match: unlock immediately
            $_SESSION[$session_auth_key] = [
                'recipient_id'    => (int)$recip['id'],
                'recipient_email' => $recip['recipient_email']
            ];
            $current_recipient = $recip;
            $is_authorized = true;
            $log_event('auth_success', $entered_email, (int)$recip['id']);
        }
    }
}

// 6. Action: Step 2 - Verify 4-Digit OTP PIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_otp'])) {
    $pending = $_SESSION[$session_otp_key] ?? null;
    $entered_pin = trim((string)($_POST['pin_code'] ?? ''));

    if (!$pending) {
        $auth_error = 'Verification session expired. Please re-enter your email.';
    } else {
        $rid = (int)$pending['recipient_id'];
        $chk_stmt = $db->prepare('SELECT * FROM secure_transfer_recipients WHERE id = :rid LIMIT 1');
        $chk_stmt->execute(['rid' => $rid]);
        $recip = $chk_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$recip || empty($recip['otp_code'])) {
            $auth_error = 'Verification code not found or already used. Please request a new code.';
        } elseif (strtotime($recip['otp_expires_at']) < time()) {
            $auth_error = 'Verification code has expired. Please enter your email again to receive a fresh PIN.';
        } elseif ($recip['otp_code'] !== $entered_pin) {
            $db->prepare('UPDATE secure_transfer_recipients SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$rid]);
            $log_event('otp_failed', $pending['recipient_email'], $rid);
            $auth_error = 'Invalid 4-digit code. Please verify the code sent to ' . htmlspecialchars($pending['recipient_email']) . ' and try again.';
            $otp_screen = true;
        } else {
            // OTP Success
            $db->prepare('UPDATE secure_transfer_recipients SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?')->execute([$rid]);
            $_SESSION[$session_auth_key] = [
                'recipient_id'    => (int)$recip['id'],
                'recipient_email' => $recip['recipient_email']
            ];
            unset($_SESSION[$session_otp_key]);
            $current_recipient = $recip;
            $is_authorized = true;
            $log_event('auth_success', $pending['recipient_email'], $rid);
        }
    }
}

if (!empty($_SESSION[$session_otp_key]) && !$is_authorized) {
    $otp_screen = true;
}

// 7. Action: Stream Binary File
if (isset($_GET['download']) && $is_authorized) {
    if ($transfer['status'] !== 'active') {
        die('This transfer package is no longer active.');
    }
    if (!file_exists($file_path)) {
        die('The document file could not be located in secure vault storage. Please contact the administrator.');
    }

    $recip_id   = $current_recipient ? (int)$current_recipient['id'] : null;
    $auth_email = $current_recipient ? $current_recipient['recipient_email'] : ($is_admin ? 'admin' : 'unknown');

    $log_event('download_started', $auth_email, $recip_id);

    if ($current_recipient) {
        $db->prepare('UPDATE secure_transfer_recipients SET download_count = download_count + 1 WHERE id = ?')->execute([$recip_id]);
    }

    set_time_limit(0);
    while (ob_get_level()) { ob_end_clean(); }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($transfer['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($transfer['original_filename']) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . (int)$transfer['file_size']);

    $bytes_sent = 0;
    $chunk_size = 1024 * 1024 * 8; // 8MB chunks
    $handle = fopen($file_path, 'rb');
    if ($handle !== false) {
        while (!feof($handle) && connection_status() === 0) {
            $buffer = fread($handle, $chunk_size);
            echo $buffer;
            flush();
            $bytes_sent += strlen($buffer);
        }
        fclose($handle);
    }

    $log_event('download_completed', $auth_email, $recip_id, $bytes_sent);
    exit;
}

function fmt_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

$download_param = (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'download=1';
$download_url   = htmlspecialchars($_SERVER['REQUEST_URI'] . $download_param);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($transfer['title']) ?> &mdash; Secure Document Transfer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --frnp-navy: #0b2545;
            --frnp-navy-dark: #07172c;
            --frnp-gold: #c59b27;
            --frnp-bg: #f8fafc;
            --frnp-border: #cbd5e1;
            --frnp-text: #1e293b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--frnp-bg);
            color: var(--frnp-text);
            line-height: 1.5;
            padding-bottom: 60px;
        }
        .header {
            background: var(--frnp-navy);
            color: #fff;
            padding: 22px 20px;
            border-bottom: 4px solid var(--frnp-gold);
            text-align: center;
        }
        .header h1 { font-size: 1.35rem; letter-spacing: 0.5px; }
        .header p { color: #94a3b8; font-size: 0.85rem; margin-top: 4px; }
        .container {
            max-width: 680px;
            margin: 35px auto 0;
            background: #ffffff;
            border: 1px solid var(--frnp-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            padding: 35px 40px;
        }
        .file-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .btn-download {
            background: var(--frnp-navy);
            color: #ffffff !important;
            font-weight: 700;
            font-size: 1.05rem;
            padding: 15px 32px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.15s ease;
            box-shadow: 0 4px 10px rgba(11, 37, 69, 0.2);
        }
        .btn-download:hover {
            background: #133966;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(11, 37, 69, 0.25);
        }
        .btn-deactivate {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 11px 22px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-deactivate:hover {
            background: #fee2e2;
            border-color: #f87171;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>FOUR RIVERS NUCLEAR PARTNERSHIP</h1>
        <p>Paducah Deactivation Project &bull; Secure Document Transfer</p>
    </div>

    <div class="container">
        <?php if (!empty($deactivated_just_now)): ?>
            <!-- RECEIPT: USER JUST DEACTIVATED THEIR OWN LINK -->
            <div style="text-align: center; padding: 25px 0;">
                <div style="font-size: 3rem; margin-bottom: 10px;">🔒</div>
                <h2 style="color: #0b2545; margin-bottom: 8px;">Transfer Access Closed</h2>
                <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6;">
                    Thank you. Your individual access to <strong><?= htmlspecialchars($transfer['title']) ?></strong> has been permanently deactivated.
                </p>
                <div style="margin-top: 25px; padding-top: 18px; border-top: 1px solid #f1f5f9; font-size: 0.82rem; color: #94a3b8;">
                    Deactivation recorded at: <?= date('M j, Y g:i A') ?>
                </div>
            </div>

        <?php elseif ($transfer['status'] !== 'active'): ?>
            <!-- PACKAGE-LEVEL EXPIRED OR REVOKED -->
            <div style="text-align: center; padding: 25px 0;">
                <div style="font-size: 3rem; margin-bottom: 10px;">🔒</div>
                <h2 style="color: #0b2545; margin-bottom: 8px;">Transfer Package Unavailable</h2>
                <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6;">
                    <?= ($transfer['status'] === 'revoked') ? 'This transfer package was revoked by the sender.' : 'This transfer package has expired.' ?>
                </p>
            </div>

        <?php elseif (!$is_authorized && $otp_screen): ?>
            <!-- STEP 1B: ENTER 4-DIGIT PIN -->
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px 16px; margin-bottom: 22px;">
                <div style="font-weight: 700; color: #0b2545; font-size: 0.95rem; margin-bottom: 4px;">
                    ✉️ 4-Digit Verification Code Sent
                </div>
                <div style="color: #475569; font-size: 0.88rem; line-height: 1.5;">
                    A one-time code was dispatched to <strong><?= htmlspecialchars($_SESSION[$session_otp_key]['recipient_email'] ?? '') ?></strong>. Enter the 4 digits below to unlock your file.
                </div>
            </div>

            <?php if ($auth_error): ?>
                <div style="background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 0.9rem;">
                    <?= htmlspecialchars($auth_error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="submit_otp" value="1">
                <div style="margin-bottom: 20px; text-align: center;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 700; color: #0b2545; margin-bottom: 10px;">
                        Enter 4-Digit PIN Code
                    </label>
                    <input type="text" name="pin_code" maxlength="4" pattern="[0-9]{4}" required placeholder="0000" 
                           style="width: 200px; text-align: center; font-size: 2rem; font-weight: 800; letter-spacing: 10px; padding: 10px; border: 2px solid #0b2545; border-radius: 6px; font-family: monospace;" autofocus>
                </div>
                <button type="submit" style="background: var(--frnp-navy); color: #fff; border: none; font-weight: 700; font-size: 1rem; padding: 12px 24px; border-radius: 5px; cursor: pointer; width: 100%;">
                    Verify PIN & Unlock Download &raquo;
                </button>
            </form>

            <div style="margin-top: 18px; text-align: center;">
                <a href="?reset_auth=1" style="font-size: 0.84rem; color: #64748b; text-decoration: underline;">
                    Use a different email address
                </a>
            </div>

        <?php elseif (!$is_authorized): ?>
            <!-- STEP 1A: EMAIL IDENTIFICATION GATE -->
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px 16px; margin-bottom: 22px;">
                <div style="font-weight: 700; color: #0b2545; font-size: 0.95rem; margin-bottom: 4px;">
                    🔒 Recipient Verification Required
                </div>
                <div style="color: #475569; font-size: 0.88rem; line-height: 1.5;">
                    <?= ($transfer['auth_mode'] === 'otp')
                        ? 'To unlock this package, enter your email. We will send an instant 4-digit code to verify your identity.'
                        : 'To protect project confidentiality, enter your authorized email address to unlock the download.' ?>
                </div>
            </div>

            <h2 style="color: #0b2545; font-size: 1.3rem; margin-bottom: 6px;"><?= htmlspecialchars($transfer['title']) ?></h2>
            <p style="color: #64748b; font-size: 0.88rem; margin-bottom: 20px;">
                File: <strong><?= htmlspecialchars($transfer['original_filename']) ?></strong> (<?= fmt_bytes((int)$transfer['file_size']) ?>)
            </p>

            <?php if ($auth_error): ?>
                <div style="background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 0.9rem;">
                    <?= htmlspecialchars($auth_error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="submit_email" value="1">
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 0.88rem; font-weight: 700; color: #0b2545; margin-bottom: 6px;">
                        Authorized Email Address *
                    </label>
                    <input type="email" name="recipient_email" required placeholder="name@domain.com" 
                           style="width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 0.95rem;" autofocus>
                </div>
                <button type="submit" style="background: var(--frnp-navy); color: #fff; border: none; font-weight: 700; font-size: 1rem; padding: 12px 24px; border-radius: 5px; cursor: pointer; width: 100%;">
                    <?= ($transfer['auth_mode'] === 'otp') ? 'Send 4-Digit Verification Code &raquo;' : 'Verify & Unlock Download &raquo;' ?>
                </button>
            </form>

        <?php else: ?>
            <!-- STEP 2: UNLOCKED DOWNLOAD DASHBOARD -->
            <?php if ($is_admin): ?>
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 14px; margin-bottom: 18px; font-size: 0.84rem; color: #166534; font-weight: 600;">
                    🛡️ Administrator Preview Active &mdash; Verification gate bypassed for Site Administrator.
                </div>
            <?php endif; ?>

            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 15px;">
                <div>
                    <h2 style="color: #0b2545; font-size: 1.45rem; line-height: 1.25; margin-bottom: 6px;"><?= htmlspecialchars($transfer['title']) ?></h2>
                    <span style="font-size: 0.82rem; color: #059669; font-weight: 700; background: #ecfdf5; padding: 3px 9px; border-radius: 4px; border: 1px solid #a7f3d0;">
                        ✓ Identity Verified (<?= htmlspecialchars($current_recipient['recipient_email'] ?? 'admin') ?>)
                    </span>
                </div>
            </div>

            <?php if (!empty($transfer['description'])): ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin: 16px 0; font-size: 0.92rem; color: #334155;">
                    <?= nl2br(htmlspecialchars($transfer['description'])) ?>
                </div>
            <?php endif; ?>

            <div class="file-card">
                <div style="font-size: 2.5rem;">📄</div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #0b2545; font-size: 1.05rem; word-break: break-all;">
                        <?= htmlspecialchars($transfer['original_filename']) ?>
                    </div>
                    <div style="color: #64748b; font-size: 0.84rem; margin-top: 4px;">
                        Size: <strong><?= fmt_bytes((int)$transfer['file_size']) ?></strong> 
                        &bull; <?= !empty($transfer['expires_at']) ? 'Expires: <strong>' . date('M j, Y g:i A', strtotime($transfer['expires_at'])) . '</strong>' : '<span style="color:#059669; font-weight:700;">No expiration</span>' ?>
                    </div>
                </div>
            </div>

            <!-- PROMINENT DOWNLOAD BUTTON -->
            <div style="margin: 30px 0; text-align: center;">
                <a href="<?= $download_url ?>" class="btn-download">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Download Document (<?= fmt_bytes((int)$transfer['file_size']) ?>)
                </a>
            </div>

            <!-- RECIPIENT KILL SWITCH / DEACTIVATION BUTTON -->
            <div style="background: #fff; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 22px; text-align: center; margin-top: 35px;">
                <h4 style="color: #0b2545; font-size: 0.95rem; margin-bottom: 6px;">Finished Downloading?</h4>
                <p style="color: #64748b; font-size: 0.84rem; margin-bottom: 14px;">
                    To protect project materials, click below once your download completes to immediately deactivate your access. Other designated recipients will not be affected.
                </p>
                <form method="POST">
                    <input type="hidden" name="action_deactivate" value="1">
                    <button type="submit" class="btn-deactivate" onclick="return confirm('Confirm permanent deactivation?\n\nThis will immediately void YOUR access. You will not be able to download this file again.');">
                        ✓ I Have Downloaded the Files (Deactivate Link)
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
