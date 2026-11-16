<?php
/**
 * CMS_RNP - Cyber Security Operations & Integrity Dashboard
 * File: admin/security_dashboard.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Load cms_rnp Database & Auth
$rootDir = realpath(__DIR__ . '/..');
$dbLoaded = false;
$pdo = null;

if (file_exists($rootDir . '/config/database.php')) {
    require_once $rootDir . '/config/database.php';
}
if (file_exists($rootDir . '/includes/auth.php')) {
    require_once $rootDir . '/includes/auth.php';
}

// Enforce authentication if cms_rnp auth helper exists
if (function_exists('require_permission')) {
    // Allow admin access; fallback to login check if permission key differs
    if (function_exists('current_user') && !current_user()) {
        header('Location: index.php');
        exit;
    }
} elseif (empty($_SESSION['user_id']) && empty($_SESSION['uid']) && empty($_SESSION['admin_logged_in']) && empty($_SESSION['user'])) {
    // Optional fallback if session variable naming varies
}

// Connect to Database via cms_rnp get_db() or global $pdo/$db
try {
    if (function_exists('get_db')) {
        $pdo = get_db();
        $dbLoaded = ($pdo instanceof PDO);
    } elseif (isset($db) && $db instanceof PDO) {
        $pdo = $db;
        $dbLoaded = true;
    }
} catch (Throwable $e) {
    $dbLoaded = false;
}

// Ensure CSRF token exists in session
if (empty($_SESSION['sec_csrf_token'])) {
    $_SESSION['sec_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['sec_csrf_token'];

// 2. Define Known cms_rnp Sensitive Webroot Artifacts to Monitor
function get_monitored_artifacts(): array {
    return [
        'reset-admin.php' => [
            'severity' => 'CRITICAL',
            'category' => 'Auth Bypass / Reset Utility',
            'desc'     => 'Administrative credential reset script in webroot. Must not remain publicly accessible.'
        ],
        'fix.php' => [
            'severity' => 'CRITICAL',
            'category' => 'Maintenance Script',
            'desc'     => 'Database/schema patch script exposed in root directory.'
        ],
        'setup.php' => [
            'severity' => 'CRITICAL',
            'category' => 'Installer / Provisioning',
            'desc'     => 'Initial CMS setup and table provisioning script.'
        ],
        'test-db.php' => [
            'severity' => 'HIGH',
            'category' => 'Diagnostic Probe',
            'desc'     => 'Database connection test script; can leak DB driver/host details.'
        ],
        'testsmtp.php' => [
            'severity' => 'HIGH',
            'category' => 'Diagnostic Probe',
            'desc'     => 'SMTP relay testing script; risk of mail relay abuse or config disclosure.'
        ],
        'schema.sql' => [
            'severity' => 'HIGH',
            'category' => 'SQL Schema Dump',
            'desc'     => 'Full database schema blueprint downloadable over HTTP.'
        ],
        'comment_sql.sql' => [
            'severity' => 'HIGH',
            'category' => 'SQL Schema Dump',
            'desc'     => 'Comments table SQL structure exposed in webroot.'
        ],
        'pr_photo_migration.sql' => [
            'severity' => 'HIGH',
            'category' => 'SQL Migration Dump',
            'desc'     => 'Press release photo migration SQL queries and content data.'
        ],
        'schema.txt' => [
            'severity' => 'MEDIUM',
            'category' => 'Architecture Doc',
            'desc'     => 'Plaintext database documentation in public webroot.'
        ],
        'sql_cmds.md' => [
            'severity' => 'MEDIUM',
            'category' => 'Architecture Doc',
            'desc'     => 'Markdown file containing internal SQL operational commands.'
        ],
        'admin/tmp' => [
            'severity' => 'LOW',
            'category' => 'Temp Artifact',
            'desc'     => 'Temporary file/folder artifact inside /admin.'
        ],
        'config/tmp' => [
            'severity' => 'LOW',
            'category' => 'Temp Artifact',
            'desc'     => 'Temporary file/folder artifact inside /config.'
        ],
        'includes/tmp' => [
            'severity' => 'LOW',
            'category' => 'Temp Artifact',
            'desc'     => 'Temporary file/folder artifact inside /includes.'
        ],
        'templates/tmp' => [
            'severity' => 'LOW',
            'category' => 'Temp Artifact',
            'desc'     => 'Temporary file/folder artifact inside /templates.'
        ],
        'assets/css/tmp' => [
            'severity' => 'LOW',
            'category' => 'Temp Artifact',
            'desc'     => 'Temporary file/folder artifact inside /assets/css.'
        ]
    ];
}

// Helper: Scan filesystem status of monitored artifacts
function scan_artifacts(string $rootDir): array {
    $quarantineDir = __DIR__ . '/quarantine';
    $results = [];
    foreach (get_monitored_artifacts() as $relPath => $meta) {
        $fullPath = $rootDir . '/' . $relPath;
        $qName    = str_replace('/', '__', $relPath);
        $qPath    = $quarantineDir . '/' . $qName;

        $exists      = file_exists($fullPath);
        $quarantined = !$exists && file_exists($qPath);

        $size  = 0;
        $mtime = null;
        $perms = '----';
        $hash  = null;

        if ($exists) {
            $size  = is_file($fullPath) ? @filesize($fullPath) : 0;
            $mtime = @filemtime($fullPath);
            $perms = substr(sprintf('%o', @fileperms($fullPath)), -4);
            $hash  = is_file($fullPath) ? substr(@hash_file('sha256', $fullPath) ?: '', 0, 12) : 'DIR';
        } elseif ($quarantined) {
            $size  = is_file($qPath) ? @filesize($qPath) : 0;
            $mtime = @filemtime($qPath);
        }

        $results[] = [
            'path'        => $relPath,
            'severity'    => $meta['severity'],
            'category'    => $meta['category'],
            'desc'        => $meta['desc'],
            'status'      => $exists ? 'EXPOSED' : ($quarantined ? 'QUARANTINED' : 'CLEAN'),
            'size'        => $size,
            'mtime'       => $mtime ? date('Y-m-d H:i:s', $mtime) : 'N/A',
            'perms'       => $perms,
            'sha256_short'=> $hash
        ];
    }
    return $results;
}

// Helper: Ensure quarantine folder exists and is locked down for both IIS & Apache
function ensure_quarantine_dir(): string {
    $qDir = __DIR__ . '/quarantine';
    if (!is_dir($qDir)) {
        @mkdir($qDir, 0700, true);
    }
    $htaccess = $qDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    $webConfig = $qDir . '/web.config';
    if (!file_exists($webConfig)) {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n";
        @file_put_contents($webConfig, $xml);
    }
    return $qDir;
}

// Helper: Log security actions to cms_rnp watchdog table
function log_security_watchdog(?PDO $pdo, string $type, string $message, string $severity = 'warning'): void {
    if (!$pdo) return;
    $uid = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? 1;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo->prepare("INSERT INTO watchdog (uid, type, message, severity, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$uid, $type, $message, $severity, $ip]);
    } catch (Throwable $e) {
        // Ignore if table schema differs slightly
    }
}

// 3. Handle AJAX / JSON Action Requests
if (isset($_REQUEST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['ajax_action'];

    // Verify CSRF on state-changing POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($csrfToken, $postedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token. Reload the page and try again.']);
            exit;
        }
    }

    // Action: Run live integrity scan
    if ($action === 'scan_integrity') {
        $artifacts = scan_artifacts($rootDir);
        $exposedCount = count(array_filter($artifacts, fn($a) => $a['status'] === 'EXPOSED'));
        $critCount    = count(array_filter($artifacts, fn($a) => $a['status'] === 'EXPOSED' && $a['severity'] === 'CRITICAL'));
        echo json_encode([
            'ok'           => true,
            'timestamp'    => date('Y-m-d H:i:s'),
            'exposedCount' => $exposedCount,
            'critCount'    => $critCount,
            'artifacts'    => $artifacts
        ]);
        exit;
    }

    // Action: Inspect a monitored file (first 40 lines, read-only)
    if ($action === 'inspect_file') {
        $target = $_GET['path'] ?? '';
        $monitored = get_monitored_artifacts();
        if (!isset($monitored[$target])) {
            echo json_encode(['ok' => false, 'error' => 'File is not in the monitored artifact list.']);
            exit;
        }
        $fullPath = $rootDir . '/' . $target;
        $qPath    = __DIR__ . '/quarantine/' . str_replace('/', '__', $target);
        $readPath = file_exists($fullPath) ? $fullPath : (file_exists($qPath) ? $qPath : null);

        if (!$readPath || !is_file($readPath)) {
            echo json_encode(['ok' => false, 'error' => 'File does not exist or is a directory.']);
            exit;
        }

        $lines = @file($readPath);
        $preview = $lines ? implode('', array_slice($lines, 0, 45)) : 'Unable to read file preview.';
        if (count($lines ?: []) > 45) {
            $preview .= "\n\n/* ... Truncated (" . count($lines) . " total lines) ... */";
        }

        echo json_encode([
            'ok'       => true,
            'path'     => $target,
            'size'     => @filesize($readPath),
            'mtime'    => date('Y-m-d H:i:s', @filemtime($readPath)),
            'sha256'   => @hash_file('sha256', $readPath),
            'preview'  => $preview
        ]);
        exit;
    }

    // Action: Quarantine a single file
    if ($action === 'quarantine_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $target = $_POST['path'] ?? '';
        $monitored = get_monitored_artifacts();
        if (!isset($monitored[$target])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid target artifact specified.']);
            exit;
        }

        $fullPath = $rootDir . '/' . $target;
        if (!file_exists($fullPath)) {
            echo json_encode(['ok' => false, 'error' => 'Target file is already removed or quarantined.']);
            exit;
        }

        $qDir  = ensure_quarantine_dir();
        $qDest = $qDir . '/' . str_replace('/', '__', $target);

        if (@rename($fullPath, $qDest)) {
            log_security_watchdog($pdo, 'security', "Quarantined exposed webroot artifact: {$target}", 'warning');
            echo json_encode([
                'ok'        => true,
                'message'   => "Moved {$target} to admin/quarantine/",
                'artifacts' => scan_artifacts($rootDir)
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => "Filesystem permission denied when moving {$target}."]);
        }
        exit;
    }

    // Action: Restore a quarantined file
    if ($action === 'restore_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $target = $_POST['path'] ?? '';
        $monitored = get_monitored_artifacts();
        if (!isset($monitored[$target])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid target artifact specified.']);
            exit;
        }

        $qPath    = __DIR__ . '/quarantine/' . str_replace('/', '__', $target);
        $fullPath = $rootDir . '/' . $target;

        if (!file_exists($qPath)) {
            echo json_encode(['ok' => false, 'error' => 'Quarantined file not found.']);
            exit;
        }

        if (@rename($qPath, $fullPath)) {
            log_security_watchdog($pdo, 'security', "Restored quarantined artifact to webroot: {$target}", 'warning');
            echo json_encode([
                'ok'        => true,
                'message'   => "Restored {$target} to webroot.",
                'artifacts' => scan_artifacts($rootDir)
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => "Could not restore {$target}. Check directory permissions."]);
        }
        exit;
    }

    // Action: Quarantine ALL Critical & High Exposed Files
    if ($action === 'quarantine_all_critical' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $qDir   = ensure_quarantine_dir();
        $moved  = [];
        $failed = [];

        foreach (get_monitored_artifacts() as $relPath => $meta) {
            if (!in_array($meta['severity'], ['CRITICAL', 'HIGH'], true)) {
                continue;
            }
            $fullPath = $rootDir . '/' . $relPath;
            if (file_exists($fullPath) && is_file($fullPath)) {
                $qDest = $qDir . '/' . str_replace('/', '__', $relPath);
                if (@rename($fullPath, $qDest)) {
                    $moved[] = $relPath;
                } else {
                    $failed[] = $relPath;
                }
            }
        }

        if (!empty($moved)) {
            log_security_watchdog($pdo, 'security', 'Bulk quarantined exposed files: ' . implode(', ', $moved), 'warning');
        }

        echo json_encode([
            'ok'        => true,
            'moved'     => $moved,
            'failed'    => $failed,
            'artifacts' => scan_artifacts($rootDir)
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// 4. Gather Initial Page Telemetry
$artifacts       = scan_artifacts($rootDir);
$exposedArtifacts = array_filter($artifacts, fn($a) => $a['status'] === 'EXPOSED');
$criticalExposed  = array_filter($exposedArtifacts, fn($a) => $a['severity'] === 'CRITICAL');
$highExposed      = array_filter($exposedArtifacts, fn($a) => $a['severity'] === 'HIGH');

// Database Telemetry Queries (Defensive against schema differences)
$watchdogLogs     = [];
$watchdogStats    = ['total_24h' => 0, 'auth_failures' => 0, 'high_sev' => 0];
$topOffendingIps  = [];
$suspiciousHits   = [];
$hourlyTraffic    = array_fill(0, 12, ['label' => '', 'hits' => 0, 'alerts' => 0]);
$intakeCounts     = [
    'users'            => 0,
    'public_comments'  => 0,
    'submissions'      => 0,
    'registrations'    => 0,
    'secure_transfers' => 0
];

// Initialize 12-hour labels
for ($i = 11; $i >= 0; $i--) {
    $idx = 11 - $i;
    $hourlyTraffic[$idx]['label'] = date('H:00', strtotime("-{$i} hours"));
}

if ($dbLoaded && $pdo) {
    // 4a. Recent Watchdog Events
    try {
        $stmt = $pdo->query("SELECT * FROM watchdog ORDER BY id DESC LIMIT 75");
        $watchdogLogs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $watchdogLogs = [];
    }

    // Calculate Watchdog summary metrics
    foreach ($watchdogLogs as $row) {
        $type = strtolower((string)($row['type'] ?? ''));
        $sev  = strtolower((string)($row['severity'] ?? ''));
        $msg  = strtolower((string)($row['message'] ?? ''));

        $watchdogStats['total_24h']++;
        if (in_array($sev, ['error', 'critical', 'alert', 'emergency', 'warning'], true)) {
            $watchdogStats['high_sev']++;
        }
        if (
            strpos($type, 'auth') !== false ||
            strpos($type, 'login') !== false ||
            strpos($msg, 'failed') !== false ||
            strpos($msg, 'invalid') !== false ||
            strpos($msg, 'denied') !== false ||
            strpos($msg, 'csrf') !== false
        ) {
            $watchdogStats['auth_failures']++;
        }
    }

    // 4b. Top Offending IPs from Watchdog & Analytics
    try {
        $stmt = $pdo->query("
            SELECT ip_address, COUNT(*) AS event_count, MAX(type) AS last_type
            FROM watchdog
            WHERE ip_address IS NOT NULL AND ip_address != ''
            GROUP BY ip_address
            ORDER BY event_count DESC
            LIMIT 8
        ");
        $topOffendingIps = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $topOffendingIps = [];
    }

    // 4c. Inspect analytics_hits for Suspicious Path Probes & Traffic Velocity
    try {
        $stmt = $pdo->query("SELECT * FROM analytics_hits ORDER BY id DESC LIMIT 250");
        $recentHits = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($recentHits as $hit) {
            $path = (string)($hit['path'] ?? '');
            $ip   = (string)($hit['ip_address'] ?? 'Unknown');
            $dev  = (string)($hit['device_type'] ?? 'unknown');
            $ts   = $hit['created_at'] ?? $hit['timestamp'] ?? null;

            // Bucket into hourly chart if timestamp available
            if ($ts) {
                $hourLabel = date('H:00', strtotime((string)$ts));
                foreach ($hourlyTraffic as &$slot) {
                    if ($slot['label'] === $hourLabel) {
                        $slot['hits']++;
                    }
                }
                unset($slot);
            }

            // Detect suspicious probes in analytics_hits path
            $lowerPath = strtolower($path);
            $matchedRule = null;
            if (preg_match('/(\.\.\/|\.\.\\\\|etc\/passwd|win\.ini)/i', $path)) {
                $matchedRule = 'Path Traversal Probe';
            } elseif (preg_match('/(union\s+select|information_schema|sleep\(|benchmark\(|or\s+1=1)/i', $path)) {
                $matchedRule = 'SQL Injection Probe';
            } elseif (preg_match('/(<script|javascript:|onerror=|onload=)/i', $path)) {
                $matchedRule = 'Cross-Site Scripting (XSS)';
            } elseif (preg_match('/(reset-admin\.php|setup\.php|fix\.php|test-db\.php|testsmtp\.php|\.sql|\.env)/i', $lowerPath)) {
                $matchedRule = 'Exposed Artifact Probe';
            }

            if ($matchedRule) {
                $suspiciousHits[] = [
                    'rule'   => $matchedRule,
                    'path'   => $path,
                    'ip'     => $ip,
                    'device' => $dev,
                    'time'   => $ts ?: 'Recent'
                ];
            }
        }
    } catch (Throwable $e) {
        // analytics_hits may be empty or use different column names
    }

    // 4d. Count records in key CMS intake tables
    $tableMap = [
        'users'            => 'users',
        'public_comments'  => 'public_comments',
        'submissions'      => 'contact_submissions',
        'registrations'    => 'registrations',
        'secure_transfers' => 'secure_transfers'
    ];
    foreach ($tableMap as $key => $tableName) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$tableName}");
            if ($stmt) {
                $intakeCounts[$key] = (int)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $intakeCounts[$key] = 0;
        }
    }
}

// 5. Evaluate PHP & Web Server Runtime Hardening Checks
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
$runtimeChecks = [
    [
        'name'   => 'Display Errors Disabled',
        'pass'   => !in_array(strtolower((string)ini_get('display_errors')), ['1', 'on', 'true'], true),
        'value'  => ini_get('display_errors') ?: '0',
        'advice' => 'Set display_errors = Off in php.ini to prevent stack trace disclosure.'
    ],
    [
        'name'   => 'Session HttpOnly Cookies',
        'pass'   => in_array(strtolower((string)ini_get('session.cookie_httponly')), ['1', 'on', 'true'], true),
        'value'  => ini_get('session.cookie_httponly') ?: '0',
        'advice' => 'Enable session.cookie_httponly = 1 to protect session IDs from XSS.'
    ],
    [
        'name'   => 'Session Strict Mode',
        'pass'   => in_array(strtolower((string)ini_get('session.use_strict_mode')), ['1', 'on', 'true'], true),
        'value'  => ini_get('session.use_strict_mode') ?: '0',
        'advice' => 'Enable session.use_strict_mode = 1 to prevent session fixation.'
    ],
    [
        'name'   => 'HTTPS / TLS Transport',
        'pass'   => $isHttps,
        'value'  => $isHttps ? 'TLS Active' : 'HTTP (Unencrypted)',
        'advice' => 'Enforce HTTPS bindings in IIS / Apache for all /admin and /transfers routes.'
    ],
    [
        'name'   => 'Remote File Inclusion Disabled',
        'pass'   => !in_array(strtolower((string)ini_get('allow_url_include')), ['1', 'on', 'true'], true),
        'value'  => ini_get('allow_url_include') ?: '0',
        'advice' => 'Ensure allow_url_include = Off in php.ini.'
    ],
    [
        'name'   => 'PHP Version Header Hidden',
        'pass'   => !in_array(strtolower((string)ini_get('expose_php')), ['1', 'on', 'true'], true),
        'value'  => ini_get('expose_php') ?: '0',
        'advice' => 'Set expose_php = Off to hide X-Powered-By header.'
    ]
];

// Calculate Overall Security Posture Score (0 - 100)
$score = 100;
$score -= count($criticalExposed) * 18;
$score -= count($highExposed) * 7;
$score -= (count($exposedArtifacts) - count($criticalExposed) - count($highExposed)) * 2;
foreach ($runtimeChecks as $chk) {
    if (!$chk['pass']) $score -= 4;
}
$score = max(5, min(100, $score));
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS_RNP Security Operations Center | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body {
            background-color: #090d16;
            color: #e2e8f0;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        .card-panel {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(51, 65, 85, 0.7);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Top SOC Navigation Header -->
    <header class="sticky top-0 z-30 bg-slate-950/95 backdrop-blur border-b border-slate-800 px-6 py-3.5">
        <div class="max-w-[1600px] mx-auto flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-cyan-500/10 border border-cyan-500/40 flex items-center justify-center text-cyan-400 font-bold mono">
                    SOC
                </div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-lg font-bold tracking-tight text-white">CMS_RNP Security Operations Center</h1>
                        <span class="px-2 py-0.5 text-[11px] font-semibold uppercase rounded bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 mono">
                            LIVE TELEMETRY
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mono">
                        Host: <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'PHP Web Server') ?> |
                        DB: <?= $dbLoaded ? '<span class="text-emerald-400">PDO Connected</span>' : '<span class="text-amber-400">Filesystem-Only Mode</span>' ?> |
                        Root: <?= htmlspecialchars($rootDir ?: __DIR__) ?>
                    </p>
                </div>
            </div>

            <!-- Action Controls & CMS Admin Links -->
            <div class="flex flex-wrap items-center gap-2.5">
                <a href="index.php" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">
                    ← Admin Home
                </a>
                <a href="watchdog.php" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">
                    Watchdog Logs
                </a>
                <a href="status.php" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">
                    System Status
                </a>
                <button type="button" onclick="runIntegrityScan()" id="btnScan"
                    class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-cyan-600 hover:bg-cyan-500 text-white shadow-sm transition flex items-center gap-1.5">
                    <span id="scanSpinner" class="hidden animate-spin h-3.5 w-3.5 border-2 border-white border-t-transparent rounded-full"></span>
                    <span>Run Integrity Scan</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Dashboard Container -->
    <main class="flex-1 max-w-[1600px] w-full mx-auto p-6 space-y-6">

        <!-- Status Toast Banner -->
        <div id="statusToast" class="hidden rounded-lg p-3.5 border text-sm flex items-center justify-between transition-all">
            <span id="statusToastMsg" class="font-medium"></span>
            <button type="button" onclick="document.getElementById('statusToast').classList.add('hidden')" class="text-xs underline ml-4">Dismiss</button>
        </div>

        <!-- Top KPI Row -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- KPI 1: Hardening Score -->
            <div class="card-panel rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Hardening Posture</span>
                    <span class="text-xs mono px-2 py-0.5 rounded <?= $score >= 80 ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : ($score >= 50 ? 'bg-amber-500/15 text-amber-300 border border-amber-500/30' : 'bg-rose-500/15 text-rose-300 border border-rose-500/30') ?>">
                        <?= $score >= 80 ? 'HARDENED' : ($score >= 50 ? 'ELEVATED RISK' : 'ACTION REQUIRED') ?>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span id="kpiScore" class="text-3xl font-bold mono <?= $score >= 80 ? 'text-emerald-400' : ($score >= 50 ? 'text-amber-400' : 'text-rose-400') ?>">
                        <?= $score ?>/100
                    </span>
                    <span class="text-xs text-slate-400">composite score</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    Based on exposed root files & PHP INI runtime controls.
                </p>
            </div>

            <!-- KPI 2: Exposed Webroot Artifacts -->
            <div class="card-panel rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Exposed Webroot Files</span>
                    <span id="kpiCritBadge" class="text-xs mono px-2 py-0.5 rounded <?= count($criticalExposed) > 0 ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' ?>">
                        <?= count($criticalExposed) ?> CRITICAL
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span id="kpiExposedCount" class="text-3xl font-bold mono text-white">
                        <?= count($exposedArtifacts) ?>
                    </span>
                    <span class="text-xs text-slate-400">of <?= count($artifacts) ?> audited paths</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    Checks <code class="text-slate-300">reset-admin.php</code>, <code class="text-slate-300">setup.php</code>, <code class="text-slate-300">*.sql</code>, etc.
                </p>
            </div>

            <!-- KPI 3: Watchdog Auth & Security Alerts -->
            <div class="card-panel rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Watchdog Security Events</span>
                    <span class="text-xs mono px-2 py-0.5 rounded bg-amber-500/15 text-amber-300 border border-amber-500/30">
                        <?= $watchdogStats['auth_failures'] ?> AUTH / ACCESS
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-bold mono text-white"><?= $watchdogStats['total_24h'] ?></span>
                    <span class="text-xs text-slate-400">recent log entries</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    <?= $watchdogStats['high_sev'] ?> warning/error severity events in <code class="text-slate-300">watchdog</code>.
                </p>
            </div>

            <!-- KPI 4: Suspicious Request Probes -->
            <div class="card-panel rounded-xl p-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Telemetry Anomaly Hits</span>
                    <span class="text-xs mono px-2 py-0.5 rounded bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                        analytics_hits
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-bold mono text-cyan-400"><?= count($suspiciousHits) ?></span>
                    <span class="text-xs text-slate-400">flagged URI probes</span>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    Users: <?= $intakeCounts['users'] ?> | Comments: <?= $intakeCounts['public_comments'] ?> | Transfers: <?= $intakeCounts['secure_transfers'] ?>
                </p>
            </div>
        </section>

        <!-- Primary Grid: Webroot Attack Surface Scanner + Runtime & Chart -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Left 7 Cols: Live Webroot Attack Surface Scanner -->
            <div class="lg:col-span-7 card-panel rounded-xl flex flex-col overflow-hidden">
                <div class="p-4 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-white flex items-center gap-2">
                            <span>Webroot Attack Surface & Artifact Scanner</span>
                            <span id="lastScanTime" class="text-[11px] font-normal text-slate-400 mono">
                                (Scanned <?= date('H:i:s') ?>)
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Audits dangerous installation, reset, diagnostic, and SQL dump files in the <code class="text-slate-300">cms_rnp</code> root.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="quarantineAllCritical()"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-600 hover:bg-rose-500 text-white transition shadow-sm">
                            Quarantine All Critical/High
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto max-h-[460px]">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-slate-900/90 text-slate-400 uppercase mono sticky top-0 z-10 border-b border-slate-800">
                            <tr>
                                <th class="py-2.5 px-4">Artifact Path</th>
                                <th class="py-2.5 px-3">Risk</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3">Size / Perms</th>
                                <th class="py-2.5 px-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="artifactTableBody" class="divide-y divide-slate-800/70">
                            <?php foreach ($artifacts as $item): ?>
                                <?php
                                $sevBadge = match ($item['severity']) {
                                    'CRITICAL' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                    'HIGH'     => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                                    'MEDIUM'   => 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30',
                                    default    => 'bg-slate-700/60 text-slate-300 border-slate-600'
                                };
                                $statusBadge = match ($item['status']) {
                                    'EXPOSED'     => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                    'QUARANTINED' => 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30',
                                    default       => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
                                };
                                ?>
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="py-2.5 px-4">
                                        <div class="font-semibold text-slate-100 mono">/<?= htmlspecialchars($item['path']) ?></div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars($item['desc']) ?></div>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="px-2 py-0.5 rounded border text-[10px] font-bold mono <?= $sevBadge ?>">
                                            <?= htmlspecialchars($item['severity']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="px-2 py-0.5 rounded border text-[10px] font-bold mono <?= $statusBadge ?>">
                                            <?= htmlspecialchars($item['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 mono text-slate-300">
                                        <?= $item['size'] > 0 ? number_format($item['size']) . ' B' : '-' ?>
                                        <span class="text-slate-500 block text-[10px]">chmod <?= htmlspecialchars($item['perms']) ?></span>
                                    </td>
                                    <td class="py-2.5 px-4 text-right space-x-1.5 whitespace-nowrap">
                                        <?php if ($item['status'] !== 'CLEAN'): ?>
                                            <button type="button" onclick="inspectFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">
                                                Inspect
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($item['status'] === 'EXPOSED'): ?>
                                            <button type="button" onclick="quarantineFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1 rounded bg-rose-600/80 hover:bg-rose-600 text-white font-medium transition">
                                                Quarantine
                                            </button>
                                        <?php elseif ($item['status'] === 'QUARANTINED'): ?>
                                            <button type="button" onclick="restoreFile('<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>')"
                                                class="px-2.5 py-1 rounded bg-slate-800 hover:bg-amber-600/40 text-amber-300 border border-amber-500/30 transition">
                                                Restore
                                            </button>
                                        <?php else: ?>
                                            <span class="text-emerald-400 mono text-[11px]">Verified Clean</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right 5 Cols: Traffic Velocity Chart + PHP/IIS Runtime Hardening -->
            <div class="lg:col-span-5 flex flex-col gap-6">
                <!-- Chart Card -->
                <div class="card-panel rounded-xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h2 class="text-sm font-bold uppercase tracking-wider text-white">Telemetry Hit Velocity</h2>
                            <p class="text-xs text-slate-400">12-hour request distribution from <code class="text-slate-300">analytics_hits</code></p>
                        </div>
                        <span class="text-xs mono text-cyan-400">Live PDO Feed</span>
                    </div>
                    <div class="h-48">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>

                <!-- Runtime Configuration Checklist -->
                <div class="card-panel rounded-xl p-4 flex-1">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-white mb-1">
                        PHP & Server Runtime Hardening
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Live inspection of active <code class="text-slate-300">php.ini</code> and transport directives.
                    </p>
                    <div class="space-y-2">
                        <?php foreach ($runtimeChecks as $chk): ?>
                            <div class="p-2.5 rounded-lg bg-slate-900/80 border border-slate-800 flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-xs font-semibold text-slate-200 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full <?= $chk['pass'] ? 'bg-emerald-400' : 'bg-amber-400' ?>"></span>
                                        <?= htmlspecialchars($chk['name']) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars($chk['advice']) ?></div>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[11px] mono font-semibold <?= $chk['pass'] ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-amber-500/15 text-amber-300 border border-amber-500/30' ?>">
                                    <?= htmlspecialchars((string)$chk['value']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bottom Grid: Watchdog Event Stream + Top IPs / Anomaly Detections -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Watchdog Event Stream (8 Cols) -->
            <div class="lg:col-span-8 card-panel rounded-xl flex flex-col overflow-hidden">
                <div class="p-4 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-white">
                            Watchdog Security & Audit Event Stream
                        </h2>
                        <p class="text-xs text-slate-400">
                            Live records from <code class="text-slate-300">watchdog</code> table (logins, permission denials, CSRF blocks, file actions).
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" id="watchdogSearch" oninput="filterWatchdogTable()"
                            placeholder="Filter IP, type, message..."
                            class="px-3 py-1.5 rounded-lg text-xs bg-slate-900 border border-slate-700 text-slate-200 focus:outline-none focus:border-cyan-500">
                        <select id="watchdogSeverityFilter" onchange="filterWatchdogTable()"
                            class="px-2.5 py-1.5 rounded-lg text-xs bg-slate-900 border border-slate-700 text-slate-200">
                            <option value="">All Severities</option>
                            <option value="error">Error / Critical</option>
                            <option value="warning">Warning</option>
                            <option value="info">Info / Notice</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto max-h-[380px]">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead class="bg-slate-900/90 text-slate-400 uppercase mono sticky top-0 border-b border-slate-800">
                            <tr>
                                <th class="py-2.5 px-4">ID / Time</th>
                                <th class="py-2.5 px-3">Type</th>
                                <th class="py-2.5 px-3">Severity</th>
                                <th class="py-2.5 px-3">Source IP / UID</th>
                                <th class="py-2.5 px-4">Event Message</th>
                            </tr>
                        </thead>
                        <tbody id="watchdogTableBody" class="divide-y divide-slate-800/70">
                            <?php if (empty($watchdogLogs)): ?>
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-slate-400">
                                        No watchdog records found (or database table is currently empty).
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($watchdogLogs as $log): ?>
                                    <?php
                                    $sev = strtolower((string)($log['severity'] ?? 'info'));
                                    $sevClass = match (true) {
                                        in_array($sev, ['error', 'critical', 'alert', 'emergency'], true) => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                                        $sev === 'warning' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                                        default            => 'bg-slate-800 text-slate-300 border-slate-700'
                                    };
                                    $timeVal = $log['created_at'] ?? $log['timestamp'] ?? '';
                                    ?>
                                    <tr class="watchdog-row hover:bg-slate-800/40 transition"
                                        data-sev="<?= htmlspecialchars($sev) ?>">
                                        <td class="py-2.5 px-4 mono text-slate-300 whitespace-nowrap">
                                            <span class="text-slate-500">#<?= (int)($log['id'] ?? 0) ?></span>
                                            <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)$timeVal) ?></div>
                                        </td>
                                        <td class="py-2.5 px-3 mono font-semibold text-cyan-300">
                                            <?= htmlspecialchars((string)($log['type'] ?? 'system')) ?>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <span class="px-2 py-0.5 rounded border text-[10px] font-bold uppercase mono <?= $sevClass ?>">
                                                <?= htmlspecialchars($sev) ?>
                                            </span>
                                        </td>
                                        <td class="py-2.5 px-3 mono text-slate-300">
                                            <?= htmlspecialchars((string)($log['ip_address'] ?? 'N/A')) ?>
                                            <span class="block text-[10px] text-slate-500">uid: <?= htmlspecialchars((string)($log['uid'] ?? '-')) ?></span>
                                        </td>
                                        <td class="py-2.5 px-4 text-slate-200">
                                            <?= htmlspecialchars((string)($log['message'] ?? '')) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right 4 Cols: Top Source IPs & Suspicious URI Probes -->
            <div class="lg:col-span-4 flex flex-col gap-6">
                <!-- Suspicious URI Probes -->
                <div class="card-panel rounded-xl p-4">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-white mb-1">
                        Flagged URI Anomaly Probes
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        SQLi, XSS, path traversal, or exposed script hits in <code class="text-slate-300">analytics_hits</code>.
                    </p>
                    <div class="space-y-2 max-h-44 overflow-y-auto">
                        <?php if (empty($suspiciousHits)): ?>
                            <div class="p-3 rounded-lg bg-emerald-950/30 border border-emerald-700/30 text-xs text-emerald-300">
                                No SQLi, XSS, or sensitive script probe patterns detected in recent traffic.
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($suspiciousHits, 0, 8) as $probe): ?>
                                <div class="p-2.5 rounded-lg bg-rose-950/30 border border-rose-500/30 text-xs">
                                    <div class="flex items-center justify-between font-semibold text-rose-300">
                                        <span><?= htmlspecialchars($probe['rule']) ?></span>
                                        <span class="mono text-[11px]"><?= htmlspecialchars($probe['ip']) ?></span>
                                    </div>
                                    <div class="mono text-[11px] text-slate-300 truncate mt-1">
                                        <?= htmlspecialchars($probe['path']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Source IPs -->
                <div class="card-panel rounded-xl p-4 flex-1">
                    <h2 class="text-sm font-bold uppercase tracking-wider text-white mb-1">
                        Top Logged Source IPs
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Most frequent IP addresses recorded in <code class="text-slate-300">watchdog</code>.
                    </p>
                    <div class="space-y-2">
                        <?php if (empty($topOffendingIps)): ?>
                            <div class="text-xs text-slate-400 py-4 text-center">No IP addresses recorded yet.</div>
                        <?php else: ?>
                            <?php foreach ($topOffendingIps as $ipRow): ?>
                                <div class="p-2 rounded-lg bg-slate-900/80 border border-slate-800 flex items-center justify-between text-xs">
                                    <div>
                                        <span class="mono font-semibold text-slate-200"><?= htmlspecialchars((string)$ipRow['ip_address']) ?></span>
                                        <span class="text-[11px] text-slate-400 ml-2">(<?= htmlspecialchars((string)$ipRow['last_type']) ?>)</span>
                                    </div>
                                    <span class="px-2 py-0.5 rounded bg-slate-800 text-cyan-300 mono font-semibold">
                                        <?= (int)$ipRow['event_count'] ?> events
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </section>
    </main>

    <!-- File Inspector Modal -->
    <div id="inspectModal" class="fixed inset-0 z-50 hidden bg-black/75 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="card-panel rounded-xl max-w-3xl w-full overflow-hidden border border-slate-700">
            <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h3 id="inspectModalTitle" class="text-sm font-bold text-white mono">Inspect Artifact</h3>
                    <p id="inspectModalMeta" class="text-xs text-slate-400 mono mt-0.5"></p>
                </div>
                <button type="button" onclick="closeInspectModal()" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-xs text-slate-300">
                    Close
                </button>
            </div>
            <div class="p-4 bg-slate-950 max-h-[420px] overflow-y-auto">
                <pre id="inspectModalCode" class="text-xs mono text-emerald-300 whitespace-pre-wrap break-all"></pre>
            </div>
        </div>
    </div>

    <!-- Dashboard Client-Side Controller -->
    <script>
        const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
        const HOURLY_DATA = <?= json_encode($hourlyTraffic) ?>;

        function showToast(message, isError = false) {
            const toast = document.getElementById('statusToast');
            const msgEl = document.getElementById('statusToastMsg');
            toast.className = 'rounded-lg p-3.5 border text-sm flex items-center justify-between transition-all ' +
                (isError
                    ? 'bg-rose-950/80 border-rose-500/50 text-rose-200'
                    : 'bg-emerald-950/80 border-emerald-500/50 text-emerald-200');
            msgEl.textContent = message;
            toast.classList.remove('hidden');
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function renderArtifactRows(artifacts) {
            const tbody = document.getElementById('artifactTableBody');
            if (!tbody || !Array.isArray(artifacts)) return;

            let exposedTotal = 0;
            let critTotal = 0;

            tbody.innerHTML = artifacts.map(item => {
                if (item.status === 'EXPOSED') {
                    exposedTotal++;
                    if (item.severity === 'CRITICAL') critTotal++;
                }

                const sevBadge = item.severity === 'CRITICAL'
                    ? 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                    : (item.severity === 'HIGH'
                        ? 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                        : (item.severity === 'MEDIUM'
                            ? 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30'
                            : 'bg-slate-700/60 text-slate-300 border-slate-600'));

                const statusBadge = item.status === 'EXPOSED'
                    ? 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                    : (item.status === 'QUARANTINED'
                        ? 'bg-cyan-500/15 text-cyan-300 border-cyan-500/30'
                        : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30');

                const inspectBtn = item.status !== 'CLEAN'
                    ? `<button type="button" onclick="inspectFile('${escapeHtml(item.path)}')" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">Inspect</button>`
                    : '';

                let actionBtn = '<span class="text-emerald-400 mono text-[11px]">Verified Clean</span>';
                if (item.status === 'EXPOSED') {
                    actionBtn = `<button type="button" onclick="quarantineFile('${escapeHtml(item.path)}')" class="px-2.5 py-1 rounded bg-rose-600/80 hover:bg-rose-600 text-white font-medium transition">Quarantine</button>`;
                } else if (item.status === 'QUARANTINED') {
                    actionBtn = `<button type="button" onclick="restoreFile('${escapeHtml(item.path)}')" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-amber-600/40 text-amber-300 border border-amber-500/30 transition">Restore</button>`;
                }

                const sizeFormatted = item.size > 0 ? Number(item.size).toLocaleString() + ' B' : '-';

                return `
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-2.5 px-4">
                            <div class="font-semibold text-slate-100 mono">/${escapeHtml(item.path)}</div>
                            <div class="text-[11px] text-slate-400">${escapeHtml(item.desc)}</div>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded border text-[10px] font-bold mono ${sevBadge}">${escapeHtml(item.severity)}</span>
                        </td>
                        <td class="py-2.5 px-3">
                            <span class="px-2 py-0.5 rounded border text-[10px] font-bold mono ${statusBadge}">${escapeHtml(item.status)}</span>
                        </td>
                        <td class="py-2.5 px-3 mono text-slate-300">
                            ${sizeFormatted}
                            <span class="text-slate-500 block text-[10px]">chmod ${escapeHtml(item.perms)}</span>
                        </td>
                        <td class="py-2.5 px-4 text-right space-x-1.5 whitespace-nowrap">
                            ${inspectBtn}
                            ${actionBtn}
                        </td>
                    </tr>
                `;
            }).join('');

            const expEl = document.getElementById('kpiExposedCount');
            const critEl = document.getElementById('kpiCritBadge');
            if (expEl) expEl.textContent = exposedTotal;
            if (critEl) critEl.textContent = critTotal + ' CRITICAL';
        }

        async function runIntegrityScan() {
            const spinner = document.getElementById('scanSpinner');
            if (spinner) spinner.classList.remove('hidden');

            try {
                const res = await fetch('security_dashboard.php?ajax_action=scan_integrity', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (data.ok) {
                    renderArtifactRows(data.artifacts);
                    const timeEl = document.getElementById('lastScanTime');
                    if (timeEl) timeEl.textContent = '(Scanned ' + data.timestamp + ')';
                    showToast(`Integrity scan complete: ${data.exposedCount} exposed artifacts detected (${data.critCount} critical).`);
                } else {
                    showToast(data.error || 'Scan failed.', true);
                }
            } catch (err) {
                showToast('Network error while running integrity scan: ' + err.message, true);
            } finally {
                if (spinner) spinner.classList.add('hidden');
            }
        }

        async function quarantineFile(path) {
            if (!confirm(`Move /${path} out of the public webroot into admin/quarantine/?`)) return;

            const body = new URLSearchParams({
                ajax_action: 'quarantine_file',
                path: path,
                csrf_token: CSRF_TOKEN
            });

            try {
                const res = await fetch('security_dashboard.php', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    renderArtifactRows(data.artifacts);
                    showToast(data.message);
                } else {
                    showToast(data.error || 'Quarantine failed.', true);
                }
            } catch (err) {
                showToast('Error quarantining file: ' + err.message, true);
            }
        }

        async function restoreFile(path) {
            if (!confirm(`Restore /${path} back to the public webroot?`)) return;

            const body = new URLSearchParams({
                ajax_action: 'restore_file',
                path: path,
                csrf_token: CSRF_TOKEN
            });

            try {
                const res = await fetch('security_dashboard.php', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    renderArtifactRows(data.artifacts);
                    showToast(data.message);
                } else {
                    showToast(data.error || 'Restore failed.', true);
                }
            } catch (err) {
                showToast('Error restoring file: ' + err.message, true);
            }
        }

        async function quarantineAllCritical() {
            if (!confirm('Quarantine ALL exposed CRITICAL and HIGH risk files (reset-admin.php, setup.php, fix.php, test scripts, and .sql dumps) into admin/quarantine/?')) {
                return;
            }

            const body = new URLSearchParams({
                ajax_action: 'quarantine_all_critical',
                csrf_token: CSRF_TOKEN
            });

            try {
                const res = await fetch('security_dashboard.php', { method: 'POST', body });
                const data = await res.json();
                if (data.ok) {
                    renderArtifactRows(data.artifacts);
                    const movedCount = (data.moved || []).length;
                    showToast(`Bulk quarantine complete: ${movedCount} critical/high-risk files moved to admin/quarantine/.`);
                } else {
                    showToast(data.error || 'Bulk quarantine failed.', true);
                }
            } catch (err) {
                showToast('Error running bulk quarantine: ' + err.message, true);
            }
        }

        async function inspectFile(path) {
            try {
                const res = await fetch('security_dashboard.php?ajax_action=inspect_file&path=' + encodeURIComponent(path));
                const data = await res.json();
                if (!data.ok) {
                    showToast(data.error || 'Could not inspect file.', true);
                    return;
                }
                document.getElementById('inspectModalTitle').textContent = '/' + data.path;
                document.getElementById('inspectModalMeta').textContent =
                    `Size: ${data.size} bytes | Modified: ${data.mtime} | SHA-256: ${data.sha256}`;
                document.getElementById('inspectModalCode').textContent = data.preview;
                document.getElementById('inspectModal').classList.remove('hidden');
            } catch (err) {
                showToast('Failed to inspect artifact: ' + err.message, true);
            }
        }

        function closeInspectModal() {
            document.getElementById('inspectModal').classList.add('hidden');
        }

        function filterWatchdogTable() {
            const q = (document.getElementById('watchdogSearch')?.value || '').toLowerCase();
            const sev = (document.getElementById('watchdogSeverityFilter')?.value || '').toLowerCase();
            const rows = document.querySelectorAll('#watchdogTableBody .watchdog-row');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const rowSev = (row.getAttribute('data-sev') || '').toLowerCase();
                const matchesQuery = !q || text.includes(q);
                const matchesSev = !sev || rowSev.includes(sev);
                row.style.display = (matchesQuery && matchesSev) ? '' : 'none';
            });
        }

        // Initialize Chart.js Telemetry Velocity Chart
        document.addEventListener('DOMContentLoaded', () => {
            const canvas = document.getElementById('trafficChart');
            if (!canvas || typeof Chart === 'undefined') return;

            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: HOURLY_DATA.map(d => d.label),
                    datasets: [{
                        label: 'Requests (analytics_hits)',
                        data: HOURLY_DATA.map(d => d.hits),
                        borderColor: '#22d3ee',
                        backgroundColor: 'rgba(34, 211, 238, 0.12)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#94a3b8', font: { size: 11 } } }
                    },
                    scales: {
                        x: { ticks: { color: '#64748b', font: { size: 10 } }, grid: { color: 'rgba(51, 65, 85, 0.3)' } },
                        y: { beginAtZero: true, ticks: { color: '#64748b', font: { size: 10 }, precision: 0 }, grid: { color: 'rgba(51, 65, 85, 0.3)' } }
                    }
                }
            });
        });
    </script>
</body>
</html>
