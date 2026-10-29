<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_permission('administer_site');

ini_set('memory_limit', '512M');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$log_buffer = [];
$error_message = '';
$step = $_POST['step'] ?? 'upload';

function log_msg(string $msg, string $type = 'info'): void {
    global $log_buffer;
    $log_buffer[] = [
        'time' => date('H:i:s'),
        'type' => $type,
        'text' => $msg
    ];
}

// Helper: Stream large .sql / .mysql file to database in chunks without crashing memory
function execute_sql_dump(PDO $pdo, string $filepath): void {
    $fp = fopen($filepath, 'r');
    if (!$fp) {
        throw new RuntimeException("Cannot open uploaded database dump file for reading.");
    }

    $query = '';
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;

        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $query .= $line;
        if (str_ends_with($trimmed, ';')) {
            $pdo->exec($query);
            $query = '';
        }
    }
    fclose($fp);
}

// Helper: Ensure target table exists or create it dynamically
function ensure_target_table_exists(PDO $target_db, PDO $staging_db, string $target_table, string $staging_table): bool {
    $exists = $target_db->query("SHOW TABLES LIKE '{$target_table}'")->rowCount() > 0;
    if (!$exists) {
        $create_stmt = $staging_db->query("SHOW CREATE TABLE `{$staging_table}`")->fetch();
        if ($create_stmt && isset($create_stmt['Create Table'])) {
            $sql = str_replace("CREATE TABLE `{$staging_table}`", "CREATE TABLE IF NOT EXISTS `{$target_table}`", $create_stmt['Create Table']);
            $target_db->exec($sql);
            return true;
        }
    }
    return false;
}

// Session storage helper for staging db name during multi-step flow
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$staging_dbname = $_SESSION['staging_dbname'] ?? '';

// Handle Step Transitions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_and_inspect') {
        $file = $_FILES['sql_file'] ?? null;
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $error_message = "File upload failed or no file selected.";
        } elseif (!in_array($ext, ['sql', 'mysql'], true)) {
            $error_message = "Invalid file extension. Please upload a plain .sql or .mysql database dump file.";
        } else {
            try {
                $target_db = get_db();
                $staging_dbname = 'drupal_staging_' . bin2hex(random_bytes(3));
                $_SESSION['staging_dbname'] = $staging_dbname;
                $_SESSION['table_prefix'] = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_prefix'] ?? '');

                $target_db->exec("CREATE DATABASE IF NOT EXISTS `{$staging_dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

                $staging_dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $staging_dbname);
                $staging_db = new PDO($staging_dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                $staging_db->exec("SET FOREIGN_KEY_CHECKS=0;");
                execute_sql_dump($staging_db, $file['tmp_name']);

                $step = 'preview';
            } catch (Throwable $e) {
                $error_message = "Inspection Error: " . $e->getMessage();
                $step = 'upload';
            }
        }
    } elseif ($action === 'run_migration') {
        $step = 'results';
        $selected_tables = $_POST['selected_tables'] ?? [];
        $create_missing = isset($_POST['create_missing_tables']);
        $cleanup_staging = isset($_POST['cleanup_staging']);
        $table_prefix = $_SESSION['table_prefix'] ?? '';

        try {
            $target_db = get_db();
            $staging_dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $staging_dbname);
            $staging_db = new PDO($staging_dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $target_db->exec("SET FOREIGN_KEY_CHECKS=0;");

            // Nodes & Aliases Migration
            if (in_array('nodes', $selected_tables, true)) {
                log_msg("Migrating content nodes and URL aliases...", 'info');
                if ($create_missing) ensure_target_table_exists($target_db, $staging_db, 'nodes', 'node');

                $aliases = [];
                if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}url_alias'")->rowCount() > 0) {
                    $alias_stmt = $staging_db->query("SELECT source, alias FROM `{$table_prefix}url_alias` WHERE source LIKE 'node/%'");
                    while ($ar = $alias_stmt->fetch()) {
                        $aliases[$ar['source']] = trim($ar['alias'], '/');
                    }
                }

                $node_stmt = $staging_db->query("
                    SELECT n.nid, n.type, n.title, n.uid, n.status, n.created, n.changed, b.body_value
                    FROM `{$table_prefix}node` n
                    LEFT JOIN `{$table_prefix}field_data_body` b 
                        ON b.entity_id = n.nid AND b.entity_type = 'node' AND b.deleted = 0
                    ORDER BY n.nid ASC
                ");

                $ins_node = $target_db->prepare("
                    INSERT INTO nodes (id, type, title, slug, body, fields_data, uid, status, created_at, updated_at)
                    VALUES (:id, :type, :title, :slug, :body, :fields_data, :uid, :status, :created_at, :updated_at)
                    ON DUPLICATE KEY UPDATE title = VALUES(title), slug = VALUES(slug), body = VALUES(body), fields_data = VALUES(fields_data), status = VALUES(status), updated_at = VALUES(updated_at)
                ");

                $existing_slugs = [];
                $node_count = 0;

                while ($n = $node_stmt->fetch()) {
                    $nid = (int)$n['nid'];
                    $src = 'node/' . $nid;

                    if (!empty($aliases[$src])) {
                        $slug = strtolower($aliases[$src]);
                    } else {
                        $clean = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $n['title']), '-'));
                        $slug = ($n['type'] === 'article') ? 'news/' . date('Y', (int)$n['created']) . '/' . $clean : $clean;
                    }

                    if (isset($existing_slugs[$slug])) {
                        $slug .= '-' . $nid;
                    }
                    $existing_slugs[$slug] = true;

                    $fields_data = [];
                    if ($n['type'] === 'article') {
                        $fields_data['publication_date'] = date('Y-m-d', (int)$n['created']);
                        $fields_data['subtitle'] = '';
                    }

                    $body = str_replace('sites/default/files/', 'uploads/files/', $n['body_value'] ?? '');

                    $ins_node->execute([
                        'id'          => $nid,
                        'type'        => $n['type'],
                        'title'       => $n['title'],
                        'slug'        => $slug,
                        'body'        => $body,
                        'fields_data' => json_encode($fields_data),
                        'uid'         => (int)$n['uid'] > 0 ? (int)$n['uid'] : 1,
                        'status'      => ((int)$n['status'] === 1) ? 'published' : 'draft',
                        'created_at'  => date('Y-m-d H:i:s', (int)$n['created']),
                        'updated_at'  => date('Y-m-d H:i:s', (int)$n['changed']),
                    ]);
                    $node_count++;
                }
                log_msg("Transferred {$node_count} content nodes.", 'success');
            }

            if ($cleanup_staging && !empty($staging_dbname)) {
                log_msg("Cleaning up staging database `{$staging_dbname}`...", 'info');
                $target_db->exec("DROP DATABASE IF EXISTS `{$staging_dbname}`;");
                unset($_SESSION['staging_dbname']);
            }

            log_msg("Migration completed successfully!", 'success');
            require_once __DIR__ . '/../includes/cache.php';
            cache_clear();

        } catch (Throwable $e) {
            $error_message = $e->getMessage();
            log_msg("Fatal Error: " . $e->getMessage(), 'error');
        } finally {
            if (isset($target_db)) {
                $target_db->exec("SET FOREIGN_KEY_CHECKS=1;");
            }
        }
    }
}

// Connect to staging DB to extract preview counts and preview records if in preview step
$preview_data = [];
$table_prefix = $_SESSION['table_prefix'] ?? '';
if ($step === 'preview' && !empty($staging_dbname)) {
    try {
        $staging_dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $staging_dbname);
        $sdb = new PDO($staging_dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        if ($sdb->query("SHOW TABLES LIKE '{$table_prefix}node'")->rowCount() > 0) {
            $preview_data['nodes'] = $sdb->query("SELECT nid AS id, title, type AS detail FROM `{$table_prefix}node` ORDER BY nid DESC LIMIT 15")->fetchAll();
        }
    } catch (Throwable $e) {
        $error_message = "Preview Loading Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drupal SQL Migration - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .console-output { background: #1e293b; color: #f8fafc; font-family: monospace; font-size: 0.85rem; padding: 20px; border-radius: 4px; max-height: 420px; overflow-y: auto; margin-top: 20px; line-height: 1.6; }
        .log-time { color: #94a3b8; margin-right: 10px; }
        .log-info { color: #38bdf8; }
        .log-success { color: #4ade80; font-weight: bold; }
        .log-error { color: #f87171; font-weight: bold; }
        .preview-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.88rem; }
        .preview-table th, .preview-table td { border: 1px solid #e2e8f0; padding: 6px 10px; text-align: left; }
        .preview-table th { background: #f8fafc; color: #0b2545; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main" style="max-width: 950px;">
            <h1>Maintenance &raquo; Drupal Database Import</h1>

            <?php if ($error_message): ?>
                <div class="notice" style="background:#fee; border-color:#c00; color:#c00; padding: 12px; margin-bottom: 20px; border-radius: 4px;">
                    <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'upload'): ?>
                <section class="card">
                    <h3>Step 1: Upload Drupal Database Dump</h3>
                    <p style="margin-bottom: 20px; color:#555; font-size:0.95rem; line-height: 1.5;">
                        Upload an uncompressed <code>.sql</code> or <code>.mysql</code> database export from your Drupal installation. You will be able to preview records in the next step.
                    </p>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="upload_and_inspect">

                        <div class="form-row">
                            <label>Select Drupal .SQL / .MYSQL File *</label>
                            <input type="file" name="sql_file" accept=".sql,.mysql" required style="padding: 8px; border: 1px dashed #cbd5e1; width: 100%; background: #f8fafc;">
                        </div>

                        <div class="form-row">
                            <label>Table Prefix (if any)</label>
                            <input type="text" name="table_prefix" placeholder="e.g. d7_ or leave blank">
                        </div>

                        <button type="submit" class="btn-primary" style="padding: 10px 24px; font-size: 0.95rem; margin-top: 10px;">
                            Upload & Inspect Database &raquo;
                        </button>
                    </form>
                </section>

            <?php elseif ($step === 'preview'): ?>
                <section class="card">
                    <h3>Step 2: Preview Content Nodes to Migrate</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                        Review the extracted content nodes below before committing them to FRNP CMS.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="run_migration">

                        <div style="display: flex; flex-direction: column; gap: 25px;">
                            <?php if (isset($preview_data['nodes'])): ?>
                                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; background: #fafbfc;">
                                    <label style="font-weight: 700; display: flex; align-items: center; gap: 8px; font-size: 1rem; color: #0b2545;">
                                        <input type="checkbox" name="selected_tables[]" value="nodes" checked style="width: 18px; height: 18px;">
                                        Content Nodes & Aliases (<code><?= htmlspecialchars($table_prefix) ?>node</code>)
                                    </label>
                                    <table class="preview-table">
                                        <thead><tr><th>Node ID</th><th>Title</th><th>Type</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($preview_data['nodes'] as $row): ?>
                                                <tr>
                                                    <td><?= (int)$row['id'] ?></td>
                                                    <td><?= htmlspecialchars((string)($row['title'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($row['detail'] ?? '')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p style="color: #64748b;">No node table found in the uploaded dump.</p>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 10px;">
                            <label style="font-weight: normal; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="create_missing_tables" value="1" checked>
                                Automatically create missing tables in target database if they do not exist
                            </label>
                            <label style="font-weight: normal; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="cleanup_staging" value="1" checked>
                                Automatically drop temporary staging database after migration
                            </label>
                        </div>

                        <div style="margin-top: 25px; display: flex; gap: 15px;">
                            <button type="submit" class="btn-primary" style="padding: 10px 24px; font-size: 0.95rem;">
                                Execute Migration Now &raquo;
                            </button>
                            <a href="<?= base_path('admin/migrate_drupal.php') ?>" class="btn-secondary">Start Over</a>
                        </div>
                    </form>
                </section>

            <?php elseif ($step === 'results'): ?>
                <section class="card">
                    <h3>Migration Execution Output Log</h3>
                    <div class="console-output">
                        <?php foreach ($log_buffer as $entry): ?>
                            <div>
                                <span class="log-time">[<?= htmlspecialchars($entry['time']) ?>]</span>
                                <span class="log-<?= htmlspecialchars($entry['type']) ?>"><?= htmlspecialchars($entry['text']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 20px; display: flex; gap: 12px;">
                        <a href="<?= base_path('admin/nodes.php') ?>" class="btn-primary">View Imported Content</a>
                        <a href="<?= base_path('admin/migrate_drupal.php') ?>" class="btn-secondary">Migrate Another File</a>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>