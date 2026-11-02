<?php
/**
 * Drupal 7 SMTP Verification & Diagnostic Tester
 * 
 * Run from CLI: php test_smtp.php
 * Or access via web browser from the web root.
 */

// --- CONFIGURATION ---
$db_config = [
    'enabled'  => true,               // Set to false if entering settings manually below
    'host'     => '127.0.0.1',
    'port'     => '3306',
    'database' => 'frnp_cms',         // Your Drupal 7 database name
    'username' => 'root',
    'password' => '',
];

// Recipient for the test delivery
$test_recipient = 'test-recipient@yourdomain.com';

// Manual overrides (used if not querying DB, or to test a password since the DB value was empty)
$manual_overrides = [
    'smtp_host'     => '',            // e.g., 'smtp.office365.com' or internal relay IP
    'smtp_port'     => '25',          // 25, 465, or 587
    'smtp_protocol' => 'standard',    // 'standard', 'ssl', or 'tls'
    'smtp_username' => '',            // Leave empty to test unauthenticated IP relay
    'smtp_password' => '',            // Enter password to test authentication
    'smtp_from'     => 'webmaster@yourdomain.com',
];

// --- INITIALIZATION ---
if (php_sapi_name() !== 'cli') {
    echo "<pre style='font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px;'>";
}

echo "=== Drupal 7 SMTP Diagnostic Suite ===\n\n";

$settings = [];

if ($db_config['enabled']) {
    try {
        $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        echo "[+] Connected to database '{$db_config['database']}'.\n";

        $stmt = $pdo->query("SELECT name, CAST(value AS CHAR) as val FROM variable WHERE name LIKE 'smtp_%' OR name = 'site_mail'");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $unserialized = @unserialize($row['val']);
            $settings[$row['name']] = ($unserialized !== false || $row['val'] === 'b:0;') ? $unserialized : $row['val'];
        }

        echo "[+] Extracted Drupal 7 SMTP Settings:\n";
        echo "    - smtp_on:       " . (!empty($settings['smtp_on']) ? 'Enabled (1)' : 'Disabled (0)') . "\n";
        echo "    - smtp_host:     " . ($settings['smtp_host'] ?? '(not set)') . "\n";
        echo "    - smtp_port:     " . ($settings['smtp_port'] ?? '25') . "\n";
        echo "    - smtp_protocol: " . ($settings['smtp_protocol'] ?? 'standard') . "\n";
        echo "    - smtp_username: " . (!empty($settings['smtp_username']) ? $settings['smtp_username'] : '(empty / none)') . "\n";
        echo "    - smtp_password: " . (!empty($settings['smtp_password']) ? '[MASKED: ' . strlen($settings['smtp_password']) . ' chars]' : '(empty)') . "\n";
        echo "    - smtp_from:     " . ($settings['smtp_from'] ?? $settings['site_mail'] ?? '(not set)') . "\n\n";

    } catch (PDOException $e) {
        echo "[-] DB Connection failed: " . $e->getMessage() . "\n";
        echo "[!] Falling back to manual override settings.\n\n";
    }
}

// Merge overrides
foreach ($manual_overrides as $key => $val) {
    if (!empty($val) || !isset($settings[$key])) {
        $settings[$key] = $val;
    }
}

$host     = trim($settings['smtp_host'] ?? '');
$port     = (int)($settings['smtp_port'] ?? 25);
$protocol = strtolower($settings['smtp_protocol'] ?? 'standard');
$username = trim($settings['smtp_username'] ?? '');
$password = $settings['smtp_password'] ?? '';
$from     = trim(!empty($settings['smtp_from']) ? $settings['smtp_from'] : 'test@' . gethostname());

if (empty($host)) {
    die("[-] ERROR: SMTP Host is not defined. Set it in the database or manual overrides.\n");
}

// --- SMTP PROTOCOL HANDSHAKE ---
function smtp_read($socket) {
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 512);
        if ($line === false) break;
        $data .= $line;
        // RFC 5321: continuation lines use hyphens (250-), final line uses space (250 )
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
}

function smtp_cmd($socket, $cmd, $mask = false) {
    echo "--> " . ($mask ? "******" : $cmd) . "\n";
    fputs($socket, $cmd . "\r\n");
    $response = smtp_read($socket);
    echo "<-- " . trim($response) . "\n\n";
    return $response;
}

echo "=== Initiating Connection to {$host}:{$port} ===\n";

$socket_target = ($protocol === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
$context = stream_context_create([
    'ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]
]);

$socket = @stream_socket_client($socket_target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);

if (!$socket) {
    die("[-] Connection Failed: [{$errno}] {$errstr}\n");
}

stream_set_timeout($socket, 15);
echo "<-- " . trim(smtp_read($socket)) . "\n\n";

// EHLO
$client_hostname = gethostname() ?: 'localhost';
$ehlo = smtp_cmd($socket, "EHLO {$client_hostname}");

// STARTTLS Negotiation
if ($protocol === 'tls' || ($protocol === 'standard' && strpos($ehlo, 'STARTTLS') !== false && $port != 465)) {
    echo "[*] Negotiating STARTTLS...\n";
    $tls_resp = smtp_cmd($socket, "STARTTLS");
    if (substr($tls_resp, 0, 3) === '220') {
        $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto) {
            echo "[+] TLS handshake successful.\n\n";
            // Re-EHLO over encrypted tunnel
            $ehlo = smtp_cmd($socket, "EHLO {$client_hostname}");
        } else {
            echo "[-] TLS handshake failed.\n\n";
        }
    }
}

// Authentication
if (!empty($username)) {
    echo "[*] Authenticating as: {$username}\n";
    $auth_resp = smtp_cmd($socket, "AUTH LOGIN");
    if (substr($auth_resp, 0, 3) === '334') {
        smtp_cmd($socket, base64_encode($username));
        $pass_resp = smtp_cmd($socket, base64_encode($password), true);

        if (substr($pass_resp, 0, 3) !== '235') {
            echo "[-] Authentication Rejected.\n";
        } else {
            echo "[+] Authentication Succeeded.\n\n";
        }
    }
} else {
    echo "[!] No username supplied - Testing as Open/Internal IP Relay.\n\n";
}

// Mail Transaction
echo "=== Sending Test Message ===\n";
$mail_from_resp = smtp_cmd($socket, "MAIL FROM:<{$from}>");
if (substr($mail_from_resp, 0, 3) !== '250') {
    die("[-] Server rejected MAIL FROM sender.\n");
}

$rcpt_to_resp = smtp_cmd($socket, "RCPT TO:<{$test_recipient}>");
if (substr($rcpt_to_resp, 0, 3) !== '250' && substr($rcpt_to_resp, 0, 3) !== '251') {
    echo "[-] Server rejected RCPT TO recipient. (If this is an internal relay, recipient domain might require relay permissions)\n";
    smtp_cmd($socket, "QUIT");
    exit;
}

$data_resp = smtp_cmd($socket, "DATA");
if (substr($data_resp, 0, 3) === '354') {
    $msg  = "From: {$from}\r\n";
    $msg .= "To: {$test_recipient}\r\n";
    $msg .= "Date: " . date('r') . "\r\n";
    $msg .= "Subject: Drupal 7 SMTP Verification Test\r\n";
    $msg .= "X-Mailer: D7-SMTP-Diagnostic-Script\r\n";
    $msg .= "\r\n";
    $msg .= "This test message confirms connectivity and configuration between this server and the SMTP gateway.\r\n";
    $msg .= "Timestamp: " . date('Y-m-d H:i:s') . "\r\n";
    $msg .= ".";

    $send_resp = smtp_cmd($socket, $msg);
    if (substr($send_resp, 0, 3) === '250') {
        echo "[SUCCESS] Email accepted by SMTP server for delivery.\n";
    } else {
        echo "[-] Server rejected message content.\n";
    }
}

smtp_cmd($socket, "QUIT");
fclose($socket);

if (php_sapi_name() !== 'cli') {
    echo "</pre>";
}
