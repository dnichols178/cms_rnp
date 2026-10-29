<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../config/database.php';

require_permission('administer_site');

$db = get_db();

// 1. Database Connection & Server Diagnostics
$db_connected = false;
$db_error = null;
$active_db_name = '';
$db_server_version = '';
$db_driver = '';
$db_conn_status = '';
$total_tables = 0;
$db_size_mb = 0.0;

try {
    $active_db_name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $db_server_version = (string)$db->getAttribute(PDO::ATTR_SERVER_VERSION);
    $db_driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $db_conn_status = (string)$db->getAttribute(PDO::ATTR_CONNECTION_STATUS);

    // Calculate total tables and database size
    $stat_stmt = $db->prepare('
        SELECT COUNT(*) AS tbl_count, 
               COALESCE(ROUND(SUM(data_length + index_length) / 1024 / 1024, 2), 0) AS size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = :schema
    ');
    $stat_stmt->execute(['schema' => $active_db_name]);
    $stats = $stat_stmt->fetch(PDO::FETCH_ASSOC);
    $total_tables = (int)($stats['tbl_count'] ?? 0);
    $db_size_mb = (float)($stats['size_mb'] ?? 0.0);

    $db_connected = true;
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

// 2. Directory & Permission Checks
$root_dir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$dir_checks = [
    'Config Directory (/config)'           => ['path' => $root_dir . '/config', 'required' => 'writable'],
    'Database Config (config/database.php)' => ['path' => $root_dir . '/config/database.php', 'required' => 'readable'],
    'Install Lock (config/installed.lock)'  => ['path' => $root_dir . '/config/installed.lock', 'required' => 'exists'],
    'Uploads Root (/uploads)'              => ['path' => $root_dir . '/uploads', 'required' => 'writable'],
    'Uploads Files (/uploads/files)'       => ['path' => $root_dir . '/uploads/files', 'required' => 'writable'],
    'Uploads Attachments (/uploads/attachments)' => ['path' => $root_dir . '/uploads/attachments', 'required' => 'writable'],
];

// 3. PHP Environment & Extensions
$required_extensions = [
    'pdo'       => 'PDO Core',
    'pdo_mysql' => 'PDO MySQL Driver',
    'json'      => 'JSON Parser',
    'gd'        => 'GD Graphics (Image Resize 750px)',
    'mbstring'  => 'Multibyte String',
    'fileinfo'  => 'MIME Fileinfo',
    'curl'      => 'cURL Transport',
    'openssl'   => 'OpenSSL Encryption'
];

// 4. Content & Activity Metrics
$total_nodes = 0;
$published_nodes = 0;
$total_users = 0;
$total_types = 0;
$pending_emails = 0;
$recent_errors = 0;

if ($db_connected) {
    $total_nodes = (int)$db->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
    $published_nodes = (int)$db->query("SELECT COUNT(*) FROM nodes WHERE status = 'published' OR status = '1'")->fetchColumn();
    $total_users = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $total_types = (int)$db->query('SELECT COUNT(*) FROM content_types')->fetchColumn();
    $pending_emails = (int)$db->query('SELECT COUNT(*) FROM email_queue WHERE attempts < max_attempts')->fetchColumn();
    $recent_errors = (int)$db->query("SELECT COUNT(*) FROM watchdog WHERE severity IN ('error', 'critical', 'emergency') AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System & Site Status - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 18px 20px;
            border-top: 4px solid #0b2545;
        }
        .stat-card.stat-success { border-top-color: #059669; }
        .stat-card.stat-warning { border-top-color: #d97706; }
        .stat-card.stat-danger  { border-top-color: #dc2626; }
        .stat-label {
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .stat-value {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0b2545;
            line-height: 1.2;
            word-break: break-all;
        }
        .stat-sub {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 4px;
        }
        .status-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .status-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 0.82rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .status-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            vertical-align: middle;
        }
        .badge-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-pass { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-fail { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .badge-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>

        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <div>
                    <h1>Reports &raquo; Site & System Status</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                        Environment configuration, active database connection, storage health, and core subsystems.
                    </p>
                </div>
                <div>
                    <a href="<?= base_path('admin/status.php') ?>" class="btn-secondary" style="font-size:0.85rem;">↻ Refresh Status</a>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <!-- High-Level Metric Tiles -->
            <div class="status-grid">
                <div class="stat-card <?= $db_connected ? 'stat-success' : 'stat-danger' ?>">
                    <div class="stat-label">Active Database</div>
                    <div class="stat-value"><?= htmlspecialchars($active_db_name ?: 'Disconnected') ?></div>
                    <div class="stat-sub">
                        <?= $db_connected ? htmlspecialchars(defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ':' . (defined('DB_PORT') ? DB_PORT : 3306) : 'Connection offline' ?>
                    </div>
                </div>

                <div class="stat-card stat-success">
                    <div class="stat-label">PHP Runtime</div>
                    <div class="stat-value"><?= PHP_VERSION ?></div>
                    <div class="stat-sub"><?= htmlspecialchars(PHP_OS_FAMILY) ?> &bull; <?= htmlspecialchars(PHP_SAPI) ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Database Footprint</div>
                    <div class="stat-value"><?= number_format($db_size_mb, 2) ?> MB</div>
                    <div class="stat-sub"><?= $total_tables ?> Tables in <code><?= htmlspecialchars($active_db_name) ?></code></div>
                </div>

                <div class="stat-card <?= $recent_errors > 0 ? 'stat-warning' : 'stat-success' ?>">
                    <div class="stat-label">24h Watchdog Errors</div>
                    <div class="stat-value"><?= $recent_errors ?></div>
                    <div class="stat-sub">
                        <a href="<?= base_path('admin/watchdog.php') ?>" style="color: inherit; text-decoration: underline;">View system logs</a>
                    </div>
                </div>
            </div>

            <!-- Database Connection Details -->
            <section class="card" style="margin-bottom: 24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                    <h3 style="margin:0;">1. Database Connection & Engine</h3>
                    <span class="badge-pill <?= $db_connected ? 'badge-pass' : 'badge-fail' ?>">
                        <?= $db_connected ? 'Connected & Healthy' : 'Connection Failed' ?>
                    </span>
                </div>

                <?php if ($db_error): ?>
                    <div class="notice" style="background:#fef2f2; border-left:4px solid #dc2626; color:#991b1b; padding:12px; margin-bottom:15px;">
                        <strong>Connection Error:</strong> <?= htmlspecialchars($db_error) ?>
                    </div>
                <?php endif; ?>

                <table class="status-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Parameter</th>
                            <th style="width: 50%;">Current Configuration</th>
                            <th style="width: 20%; text-align: right;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Database Name in Use</strong></td>
                            <td><strong style="color: #0b2545; font-size: 0.95rem;"><code><?= htmlspecialchars($active_db_name) ?></code></strong></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">ACTIVE</span></td>
                        </tr>
                        <tr>
                            <td><strong>Configured DB Constant</strong></td>
                            <td><code><?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'Not defined') ?></code></td>
                            <td style="text-align: right;">
                                <span class="badge-pill <?= (defined('DB_NAME') && DB_NAME === $active_db_name) ? 'badge-pass' : 'badge-info' ?>">
                                    <?= (defined('DB_NAME') && DB_NAME === $active_db_name) ? 'MATCH' : 'CUSTOM' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Host & Port</strong></td>
                            <td><?= htmlspecialchars((string)(defined('DB_HOST') ? DB_HOST : '127.0.0.1')) ?>:<?= (int)(defined('DB_PORT') ? DB_PORT : 3306) ?></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">OK</span></td>
                        </tr>
                        <tr>
                            <td><strong>Database Username</strong></td>
                            <td><code><?= htmlspecialchars((string)(defined('DB_USER') ? DB_USER : 'root')) ?></code></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">OK</span></td>
                        </tr>
                        <tr>
                            <td><strong>Database Engine / Server Version</strong></td>
                            <td><?= htmlspecialchars($db_driver) ?> &bull; <?= htmlspecialchars($db_server_version) ?></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">PASS</span></td>
                        </tr>
                        <tr>
                            <td><strong>Connection Info String</strong></td>
                            <td><code style="font-size:0.8rem;"><?= htmlspecialchars($db_conn_status) ?></code></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">ESTABLISHED</span></td>
                        </tr>
                        <tr>
                            <td><strong>Tables & Aggregate Size</strong></td>
                            <td><?= $total_tables ?> tables (<?= number_format($db_size_mb, 2) ?> MB data & index)</td>
                            <td style="text-align: right;"><span class="badge-pill badge-info">INFO</span></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Server Environment & PHP Runtime -->
            <section class="card" style="margin-bottom: 24px;">
                <h3 style="margin-top:0; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">2. PHP Environment & Resources</h3>
                <table class="status-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Variable</th>
                            <th style="width: 50%;">Assigned Value</th>
                            <th style="width: 20%; text-align: right;">Evaluation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Web Server Software</strong></td>
                            <td><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Command Line / Embedded') ?></td>
                            <td style="text-align: right;"><span class="badge-pill badge-info">SERVER</span></td>
                        </tr>
                        <tr>
                            <td><strong>Memory Limit</strong></td>
                            <td><code><?= htmlspecialchars((string)ini_get('memory_limit')) ?></code></td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">PASS</span></td>
                        </tr>
                        <tr>
                            <td><strong>Max Execution Time</strong></td>
                            <td><?= htmlspecialchars((string)ini_get('max_execution_time')) ?> seconds</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">PASS</span></td>
                        </tr>
                        <tr>
                            <td><strong>Upload Max File Size</strong></td>
                            <td><code><?= htmlspecialchars((string)ini_get('upload_max_filesize')) ?></code> (Post Max: <code><?= htmlspecialchars((string)ini_get('post_max_size')) ?></code>)</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">PASS</span></td>
                        </tr>
                        <tr>
                            <td><strong>Default Timezone</strong></td>
                            <td><?= htmlspecialchars(date_default_timezone_get()) ?> (Local: <?= date('Y-m-d H:i:s T') ?>)</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">SYNC</span></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Extension Verification -->
            <section class="card" style="margin-bottom: 24px;">
                <h3 style="margin-top:0; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">3. PHP Extensions</h3>
                <table class="status-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Extension</th>
                            <th style="width: 50%;">Component Purpose</th>
                            <th style="width: 20%; text-align: right;">Installed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($required_extensions as $ext_key => $ext_desc): ?>
                            <?php $is_loaded = extension_loaded($ext_key); ?>
                            <tr>
                                <td><code><?= htmlspecialchars($ext_key) ?></code></td>
                                <td><?= htmlspecialchars($ext_desc) ?></td>
                                <td style="text-align: right;">
                                    <span class="badge-pill <?= $is_loaded ? 'badge-pass' : 'badge-fail' ?>">
                                        <?= $is_loaded ? 'ENABLED' : 'MISSING' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- File Storage & Directory Permissions -->
            <section class="card" style="margin-bottom: 24px;">
                <h3 style="margin-top:0; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">4. File Storage & Directory Permissions</h3>
                <table class="status-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">File / Directory Path</th>
                            <th style="width: 40%;">Condition</th>
                            <th style="width: 20%; text-align: right;">Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dir_checks as $label => $spec): ?>
                            <?php
                                $path = $spec['path'];
                                $req = $spec['required'];
                                $pass = false;
                                if ($req === 'writable') {
                                    $pass = is_dir($path) && is_writable($path);
                                    $state_text = $pass ? 'Writable' : (is_dir($path) ? 'Not Writable' : 'Missing Directory');
                                } elseif ($req === 'readable') {
                                    $pass = file_exists($path) && is_readable($path);
                                    $state_text = $pass ? 'Readable' : 'Unreadable';
                                } else {
                                    $pass = file_exists($path);
                                    $state_text = $pass ? 'Present' : 'Missing';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($label) ?></strong><br>
                                    <code style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($path) ?></code>
                                </td>
                                <td>Must be <?= htmlspecialchars($req) ?></td>
                                <td style="text-align: right;">
                                    <span class="badge-pill <?= $pass ? 'badge-pass' : 'badge-fail' ?>">
                                        <?= htmlspecialchars($state_text) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <!-- CMS Subsystems Summary -->
            <section class="card">
                <h3 style="margin-top:0; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">5. CMS Subsystem Readiness</h3>
                <table class="status-table">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Component</th>
                            <th style="width: 45%;">Count / State</th>
                            <th style="width: 20%; text-align: right;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Content Nodes</strong></td>
                            <td><?= $published_nodes ?> published of <?= $total_nodes ?> total</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">OK</span></td>
                        </tr>
                        <tr>
                            <td><strong>Configured Content Types</strong></td>
                            <td><?= $total_types ?> content types registered</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">OK</span></td>
                        </tr>
                        <tr>
                            <td><strong>User Accounts</strong></td>
                            <td><?= $total_users ?> users in database</td>
                            <td style="text-align: right;"><span class="badge-pill badge-pass">OK</span></td>
                        </tr>
                        <tr>
                            <td><strong>Pending Mailer Queue</strong></td>
                            <td><?= $pending_emails ?> message(s) queued</td>
                            <td style="text-align: right;">
                                <span class="badge-pill <?= $pending_emails > 20 ? 'badge-fail' : 'badge-pass' ?>">
                                    <?= $pending_emails > 0 ? 'QUEUED' : 'CLEAR' ?>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>