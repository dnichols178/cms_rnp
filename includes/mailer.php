<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/content.php';

/**
 * Queue an email for background dispatch.
 */
function queue_email(string $recipient, string $subject, string $html_body, string $text_body = '', ?string $name = null, int $priority = 3): int {
    $db = get_db();
    
    if ($text_body === '') {
        $text_body = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $html_body));
    }

    $stmt = $db->prepare('
        INSERT INTO email_queue (recipient, recipient_name, subject, body_html, body_text, priority)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([trim($recipient), $name !== null ? trim($name) : null, trim($subject), $html_body, trim($text_body), $priority]);
    return (int)$db->lastInsertId();
}

/**
 * Wraps content in the branded FRNP HTML notification layout.
 */
function build_frnp_email_html(string $title, string $message_content, ?string $call_to_action_url = null, ?string $call_to_action_label = null): string {
    $logo_title = htmlspecialchars(get_site_setting('site_name', 'Four Rivers Nuclear Partnership'));
    $slogan = htmlspecialchars(get_site_setting('site_slogan', 'Paducah Deactivation Project'));
    $footer_text = htmlspecialchars(get_site_setting('footer_disclaimer', 'FRNP manages the Deactivation Project at the Paducah Gaseous Diffusion Plant for the U.S. DOE.'));

    $cta_block = '';
    if ($call_to_action_url && $call_to_action_label) {
        $cta_block = '
        <div style="margin: 25px 0 10px;">
            <a href="' . htmlspecialchars($call_to_action_url) . '" 
               style="background-color: #0b2545; color: #ffffff; text-decoration: none; padding: 10px 20px; font-weight: 600; font-size: 14px; border-radius: 4px; display: inline-block;">
                ' . htmlspecialchars($call_to_action_label) . ' &raquo;
            </a>
        </div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$title}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#2c3e50;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:30px 10px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:6px; overflow:hidden; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#071b30; padding:18px 24px; border-bottom:3px solid #c59b27;">
                            <div style="color:#ffffff; font-size:18px; font-weight:900; letter-spacing:1px; line-height:1.2;">FOUR RIVERS</div>
                            <div style="color:#c59b27; font-size:11px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase;">NUCLEAR PARTNERSHIP, LLC</div>
                            <div style="color:#94a3b8; font-size:10px; margin-top:3px;">{$slogan}</div>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:30px 30px 25px;">
                            <h2 style="margin:0 0 16px; color:#0b2545; font-size:20px; font-weight:700;">{$title}</h2>
                            <div style="font-size:15px; line-height:1.6; color:#2c3e50;">
                                {$message_content}
                            </div>
                            {$cta_block}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8fafc; padding:18px 30px; border-top:1px solid #e2e8f0; font-size:11px; color:#64748b; line-height:1.5;">
                            <p style="margin:0 0 6px;">{$footer_text}</p>
                            <p style="margin:0;">&copy; Four Rivers Nuclear Partnership, LLC &bull; Paducah Site Operations</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Direct SMTP Socket Transmission implementation.
 */
function send_smtp_socket(string $to, string $subject, string $html_message, string $text_message, ?string $to_name = null): bool {
    $host       = get_site_setting('smtp_host', '127.0.0.1');
    $port       = (int)get_site_setting('smtp_port', '25');
    $encryption = strtolower(get_site_setting('smtp_encryption', 'none'));
    $user       = get_site_setting('smtp_user', '');
    $pass       = get_site_setting('smtp_pass', '');
    $timeout    = (int)get_site_setting('smtp_timeout', '15');
    $from_email = get_site_setting('mail_from_address', 'no-reply@fourriversnuclearpartnership.com');
    $from_name  = get_site_setting('mail_from_name', 'Four Rivers Nuclear Partnership');

    $socket_target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($socket_target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new \RuntimeException("Socket connection to {$socket_target} failed: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };

    $send = function(string $cmd) use ($socket, $read): string {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $greeting = $read();
    if (!str_starts_with($greeting, '220')) {
        fclose($socket);
        throw new \RuntimeException("Invalid greeting from server: {$greeting}");
    }

    $ehlo = $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

    // STARTTLS negotiation if configured
    if ($encryption === 'tls') {
        $tls_resp = $send("STARTTLS");
        if (!str_starts_with($tls_resp, '220')) {
            fclose($socket);
            throw new \RuntimeException("STARTTLS rejected: {$tls_resp}");
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($socket);
            throw new \RuntimeException("Crypto handshake failed after STARTTLS.");
        }
        $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    // SMTP Authentication if credentials provided
    if ($user !== '' && $pass !== '') {
        $auth_resp = $send("AUTH LOGIN");
        if (!str_starts_with($auth_resp, '334')) {
            fclose($socket);
            throw new \RuntimeException("AUTH LOGIN rejected: {$auth_resp}");
        }
        $send(base64_encode($user));
        $pass_resp = $send(base64_encode($pass));
        if (!str_starts_with($pass_resp, '235')) {
            fclose($socket);
            throw new \RuntimeException("SMTP Authentication failed: {$pass_resp}");
        }
    }

    // Envelope
    $mail_from_resp = $send("MAIL FROM:<{$from_email}>");
    if (!str_starts_with($mail_from_resp, '250')) {
        fclose($socket);
        throw new \RuntimeException("MAIL FROM rejected: {$mail_from_resp}");
    }

    $rcpt_resp = $send("RCPT TO:<{$to}>");
    if (!str_starts_with($rcpt_resp, '250')) {
        fclose($socket);
        throw new \RuntimeException("RCPT TO rejected: {$rcpt_resp}");
    }

    $data_resp = $send("DATA");
    if (!str_starts_with($data_resp, '354')) {
        fclose($socket);
        throw new \RuntimeException("DATA rejected: {$data_resp}");
    }

    // Build MIME boundary & headers
    $boundary = '=_frnp_' . md5(uniqid((string)microtime(true), true));
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $to_header = ($to_name ? '"' . addslashes($to_name) . '" ' : '') . "<{$to}>";

    $headers = [
        "From: \"{$from_name}\" <{$from_email}>",
        "To: {$to_header}",
        "Date: " . date('r'),
        "Subject: {$encoded_subject}",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        "X-Mailer: FRNP CMS Core Mailer (PHP " . phpversion() . ")"
    ];

    $payload = implode("\r\n", $headers) . "\r\n\r\n";
    
    // Plain text section
    $payload .= "--{$boundary}\r\n";
    $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($text_message)) . "\r\n";

    // HTML section
    $payload .= "--{$boundary}\r\n";
    $payload .= "Content-Type: text/html; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($html_message)) . "\r\n";

    $payload .= "--{$boundary}--\r\n.";

    $finish = $send($payload);
    $send("QUIT");
    fclose($socket);

    if (!str_starts_with($finish, '250')) {
        throw new \RuntimeException("Message rejected by server: {$finish}");
    }

    return true;
}

/**
 * Dispatches an email using either configured SMTP socket or PHP mail().
 */
function send_raw_email(string $to, string $subject, string $html_body, string $text_body, ?string $to_name = null): bool {
    $driver = get_site_setting('mail_driver', 'mail');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $db = get_db();

    try {
        if ($driver === 'smtp') {
            send_smtp_socket($to, $subject, $html_body, $text_body, $to_name);
        } else {
            // Standard mail() fallback
            $from_email = get_site_setting('mail_from_address', 'no-reply@fourriversnuclearpartnership.com');
            $from_name  = get_site_setting('mail_from_name', 'Four Rivers Nuclear Partnership');
            $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                "From: \"{$from_name}\" <{$from_email}>",
                "X-Mailer: FRNP CMS Mailer (PHP " . phpversion() . ")"
            ];
            $success = @mail($to, $encoded_subject, $html_body, implode("\r\n", $headers));
            if (!$success) {
                throw new \RuntimeException("Native PHP mail() returned false. Check sendmail_path.");
            }
        }

        // Audit Log Success
        $log_stmt = $db->prepare('INSERT INTO email_logs (recipient, subject, status, ip_address) VALUES (?, ?, "sent", ?)');
        $log_stmt->execute([$to, $subject, $ip]);
        return true;
    } catch (\Throwable $e) {
        $log_stmt = $db->prepare('INSERT INTO email_logs (recipient, subject, status, error_message, ip_address) VALUES (?, ?, "failed", ?, ?)');
        $log_stmt->execute([$to, $subject, $e->getMessage(), $ip]);
        throw $e;
    }
}

/**
 * Worker routine to process batches from email_queue.
 */
function process_email_queue(int $batch_limit = 25): array {
    $db = get_db();
    
    // Lock pending items
    $db->beginTransaction();
    $stmt = $db->prepare('
        SELECT * FROM email_queue 
        WHERE attempts < max_attempts 
          AND (locked_until IS NULL OR locked_until < NOW())
        ORDER BY priority ASC, id ASC
        LIMIT :lim
        FOR UPDATE
    ');
    $stmt->bindValue(':lim', $batch_limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    if (empty($items)) {
        $db->commit();
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $lock_stmt = $db->prepare('UPDATE email_queue SET locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?');
    foreach ($items as $item) {
        $lock_stmt->execute([$item['id']]);
    }
    $db->commit();

    $sent = 0;
    $failed = 0;

    foreach ($items as $mail) {
        try {
            send_raw_email(
                $mail['recipient'], 
                $mail['subject'], 
                $mail['body_html'], 
                $mail['body_text'], 
                $mail['recipient_name']
            );
            $del = $db->prepare('DELETE FROM email_queue WHERE id = ?');
            $del->execute([$mail['id']]);
            $sent++;
        } catch (\Throwable $e) {
            $failed++;
            $upd = $db->prepare('
                UPDATE email_queue 
                SET attempts = attempts + 1, last_error = ?, locked_until = NULL 
                WHERE id = ?
            ');
            $upd->execute([$e->getMessage(), $mail['id']]);
        }
    }

    return ['processed' => count($items), 'sent' => $sent, 'failed' => $failed];
}