<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/content.php';

/**
 * Returns the protected backup storage directory (/vault/backups)
 * and ensures Apache/IIS access restrictions are in place.
 */
function get_backup_storage_dir(): string {
    $dir = __DIR__ . '/../vault/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }

    $webconfig = $dir . '/web.config';
    if (!file_exists($webconfig)) {
        @file_put_contents(
            $webconfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n"
        );
    }

    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php http_response_code(403); exit('Access Denied.');\n");
    }

    return $dir;
}

/**
 * Resolves the active database name for backup file naming.
 */
function get_backup_db_prefix(): string {
    try {
        $db = get_db();
        $name = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        return $clean !== '' ? $clean : 'frnp_cms';
    } catch (\Throwable $e) {
        return 'frnp_cms';
    }
}

/**
 * Builds the full SQL schema + data dump string for the current database.
 */
function build_database_sql_dump(): string {
    $db = get_db();
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP CLI';

    $sqlDump  = "-- FRNP CMS Automated Database Snapshot\n";
    $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- Server: " . $server_soft . "\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        if (!$createStmt || !isset($createStmt[1])) {
            continue;
        }
        $sqlDump .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sqlDump .= $createStmt[1] . ";\n\n";

        $stmt = $db->query("SELECT * FROM `{$table}`");
        $firstRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($firstRow) {
            $cols = array_keys($firstRow);
            $colList = implode('`, `', $cols);

            $quoteRow = static function (array $row) use ($db): string {
                $values = array_map(static function ($val) use ($db) {
                    return $val === null ? 'NULL' : $db->quote((string)$val);
                }, array_values($row));
                return '(' . implode(', ', $values) . ')';
            };

            $sqlDump .= "INSERT INTO `{$table}` (`{$colList}`) VALUES " . $quoteRow($firstRow) . ";\n";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sqlDump .= "INSERT INTO `{$table}` (`{$colList}`) VALUES " . $quoteRow($row) . ";\n";
            }
            $sqlDump .= "\n";
        }
    }

    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sqlDump;
}

/**
 * Deletes any .sql backup files in the backup folder older than $retention_days (default 14 days).
 */
function prune_old_backups(int $retention_days = 14): int {
    $dir = get_backup_storage_dir();
    $cutoff = time() - ($retention_days * 86400);
    $deleted = 0;

    foreach (glob($dir . '/*_backup_*.sql') ?: [] as $filepath) {
        if (!is_file($filepath)) {
            continue;
        }
        $file_ts = @filemtime($filepath) ?: 0;

        // Also parse the YYYY_MMDD suffix if present so date-named files are accurately pruned
        if (preg_match('/_backup_(\d{4})_(\d{2})(\d{2})\.sql$/', basename($filepath), $m)) {
            $parsed_ts = strtotime("{$m[1]}-{$m[2]}-{$m[3]} 23:59:59");
            if ($parsed_ts !== false) {
                $file_ts = $parsed_ts;
            }
        }

        if ($file_ts > 0 && $file_ts < $cutoff) {
            if (@unlink($filepath)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/**
 * Checks if today's daily backup (xxxxx_backup_YYYY_MMDD.sql) exists.
 * If not, creates it and prunes backups older than 14 days.
 */
function ensure_daily_backup(bool $force = false): ?string {
    try {
        $dir = get_backup_storage_dir();
        $prefix = get_backup_db_prefix();
        $date_suffix = date('Y_md'); // e.g. 2026_1117
        $filename = sprintf('%s_backup_%s.sql', $prefix, $date_suffix);
        $filepath = $dir . '/' . $filename;

        // If any backup for today's date suffix already exists (and not forced), skip
        $existing_today = glob($dir . '/*_backup_' . $date_suffix . '.sql') ?: [];
        if (!$force && (!empty($existing_today) || file_exists($filepath))) {
            return null;
        }

        // Use a non-blocking lock file so concurrent pageviews don't write simultaneously
        $lock_file = $dir . '/.backup.lock';
        $lock_fp = @fopen($lock_file, 'c+');
        if ($lock_fp && !flock($lock_fp, LOCK_EX | LOCK_NB)) {
            fclose($lock_fp);
            return null;
        }

        $sqlDump = build_database_sql_dump();
        $tmp_file = $filepath . '.tmp.' . getmypid();

        if (@file_put_contents($tmp_file, $sqlDump) !== false) {
            @rename($tmp_file, $filepath);
        }

        // Prune backups older than 14 days whenever a backup is created
        prune_old_backups(14);

        if ($lock_fp) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        if (function_exists('watchdog')) {
            watchdog('backup', "Automated daily database backup created: {$filename}", 'notice');
        }

        return $filename;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Formats byte counts into human-readable units (B, KB, MB, GB).
 */
function format_backup_size(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

// If included from index.php or templates/layout.php as a helper, stop here before running admin UI
if (defined('FRNP_BACKUP_HELPER_ONLY')) {
    return;
}

// --- Admin Page Execution ---
require_once __DIR__ . '/../includes/auth.php';
require_permission('administer_site');

$backup_dir = get_backup_storage_dir();

// Ensure today's backup exists when visiting the admin page as well
ensure_daily_backup(false);

// 1. Download a specific saved backup file from the safe folder
if (isset($_GET['action']) && $_GET['action'] === 'download_file' && !empty($_GET['file'])) {
    $file = basename((string)$_GET['file']);
    $target = $backup_dir . '/' . $file;

    if (preg_match('/^[a-zA-Z0-9_\-]+_backup_\d{4}_\d{4}\.sql$/', $file) && is_file($target)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string)filesize($target));
        readfile($target);
        exit;
    }
    set_flash('error', 'Requested backup file could not be found.');
    header('Location: ' . base_path('admin/backup.php'));
    exit;
}

// 2. On-demand live SQL stream download
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $sqlDump = build_database_sql_dump();
    $prefix = get_backup_db_prefix();
    $filename = sprintf('%s_backup_%s.sql', $prefix, date('Y_md'));

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sqlDump));
    echo $sqlDump;
    exit;
}

// 3. Handle POST actions (Force Refresh Today's Backup / Delete Specific Backup / Prune Old Backups)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'run_now') {
        $created = ensure_daily_backup(true);
        if ($created) {
            set_flash('success', "Daily backup refreshed and saved: {$created}");
        } else {
            set_flash('error', 'Unable to write backup file. Check folder permissions on /vault/backups.');
        }
    } elseif ($post_action === 'delete_file' && !empty($_POST['file'])) {
        $file = basename((string)$_POST['file']);
        $target = $backup_dir . '/' . $file;
        if (preg_match('/\.sql$/i', $file) && is_file($target) && @unlink($target)) {
            set_flash('success', "Deleted backup: {$file}");
        } else {
            set_flash('error', 'Could not delete the specified backup file.');
        }
    } elseif ($post_action === 'prune_now') {
        $removed = prune_old_backups(14);
        set_flash('success', "Retention cleanup complete. Removed {$removed} backup(s) older than 14 days.");
    }

    header('Location: ' . base_path('admin/backup.php'));
    exit;
}

// Gather all available .sql backups in the safe folder
$backup_files = [];
$total_size_bytes = 0;

foreach (glob($backup_dir . '/*.sql') ?: [] as $filepath) {
    if (!is_file($filepath)) {
        continue;
    }
    $size = (int)(@filesize($filepath) ?: 0);
    $mtime = (int)(@filemtime($filepath) ?: 0);
    $total_size_bytes += $size;

    $backup_files[] = [
        'name'  => basename($filepath),
        'size'  => $size,
        'mtime' => $mtime,
    ];
}

// Sort newest first
usort($backup_files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

$today_suffix = date('Y_md');
$has_today_backup = false;
foreach ($backup_files as $bf) {
    if (str_contains($bf['name'], '_backup_' . $today_suffix . '.sql')) {
        $has_today_backup = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Backup - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 980px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px;">
                <h1 style="margin: 0;">Maintenance » Database Backup</h1>
                <span style="background: <?= $has_today_backup ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $has_today_backup ? '#065f46' : '#991b1b' ?>; border: 1px solid <?= $has_today_backup ? '#a7f3d0' : '#fecaca' ?>; padding: 6px 12px; border-radius: 20px; font-size: 0.82rem; font-weight: 700;">
                    <?= $has_today_backup ? '✓ Today\'s Automated Backup Complete (' . date('Y_md') . ')' : '⚠ Today\'s Backup Pending' ?>
                </span>
            </div>

            <?php if (function_exists('render_flash_messages')) render_flash_messages(); ?>

            <!-- Summary Metrics Bar -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <div class="card" style="margin: 0; padding: 16px 20px; border-left: 4px solid #0b2545;">
                    <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">Available Backups</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #0b2545; margin-top: 4px;">
                        <?= count($backup_files) ?> <span style="font-size: 0.9rem; font-weight: 500; color: #64748b;">file<?= count($backup_files) === 1 ? '' : 's' ?></span>
                    </div>
                </div>

                <div class="card" style="margin: 0; padding: 16px 20px; border-left: 4px solid #c59b27;">
                    <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">Total Folder Size</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #0b2545; margin-top: 4px;">
                        <?= format_backup_size($total_size_bytes) ?>
                    </div>
                </div>

                <div class="card" style="margin: 0; padding: 16px 20px; border-left: 4px solid #059669;">
                    <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">Retention Policy</div>
                    <div style="font-size: 1.15rem; font-weight: 700; color: #065f46; margin-top: 8px;">
                        14 Days <span style="font-size: 0.82rem; font-weight: 500; color: #64748b;">(Protected in <code>/vault/backups/</code>)</span>
                    </div>
                </div>
            </div>

            <!-- Manual Actions Card -->
            <section class="card" style="margin-bottom: 24px;">
                <h3 style="margin-top: 0;">Backup Controls & On-Demand Export</h3>
                <p style="margin-bottom: 18px; color: #555; line-height: 1.5;">
                    The system automatically generates one daily snapshot (<code><?= htmlspecialchars(get_backup_db_prefix()) ?>_backup_<?= date('Y_md') ?>.sql</code>) on the first visitor hit each day and prunes snapshots older than 14 days. You can also refresh today's snapshot or download a copy directly below.
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <a href="<?= base_path('admin/backup.php?action=download') ?>" class="btn-primary" style="padding: 10px 18px; text-decoration: none;">
                        ⬇ Download Live Backup (.sql)
                    </a>

                    <form method="POST" action="" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="run_now">
                        <button type="submit" class="btn-secondary" style="padding: 10px 16px; cursor: pointer;">
                            ↻ Refresh Today's Stored Backup
                        </button>
                    </form>

                    <form method="POST" action="" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="prune_now">
                        <button type="submit" class="btn-secondary" style="padding: 10px 16px; cursor: pointer;">
                            🧹 Prune Backups > 14 Days
                        </button>
                    </form>
                </div>
            </section>

            <!-- Stored Backups Table -->
            <section class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <h3 style="margin: 0;">Stored Daily Backups</h3>
                    <span style="font-size: 0.85rem; color: #64748b; font-weight: 600;">
                        Total Storage Used: <strong style="color: #0b2545;"><?= format_backup_size($total_size_bytes) ?></strong> (<?= number_format($total_size_bytes) ?> bytes)
                    </span>
                </div>

                <?php if (empty($backup_files)): ?>
                    <p style="color: #64748b; font-style: italic; margin: 12px 0;">No backup files found in <code>/vault/backups/</code>.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="admin-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #cbd5e1; text-align: left;">
                                    <th style="padding: 10px 12px;">Backup Filename</th>
                                    <th style="padding: 10px 12px;">Created / Modified</th>
                                    <th style="padding: 10px 12px;">File Size</th>
                                    <th style="padding: 10px 12px; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backup_files as $bf): ?>
                                    <?php $is_today = str_contains($bf['name'], '_backup_' . $today_suffix . '.sql'); ?>
                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 10px 12px; font-family: monospace; font-size: 0.9rem; font-weight: 600; color: #0b2545;">
                                            <?= htmlspecialchars($bf['name']) ?>
                                            <?php if ($is_today): ?>
                                                <span style="background: #dbeafe; color: #1e40af; font-family: sans-serif; font-size: 0.7rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-left: 6px; text-transform: uppercase;">Today</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 10px 12px; font-size: 0.88rem; color: #475569;">
                                            <?= date('M j, Y \a\t g:i:s A', $bf['mtime']) ?>
                                        </td>
                                        <td style="padding: 10px 12px; font-size: 0.88rem; font-weight: 600; color: #334155;">
                                            <?= format_backup_size($bf['size']) ?>
                                        </td>
                                        <td style="padding: 10px 12px; text-align: right; white-space: nowrap;">
                                            <a href="<?= base_path('admin/backup.php?action=download_file&file=' . rawurlencode($bf['name'])) ?>" 
                                               class="btn-secondary" 
                                               style="padding: 5px 10px; font-size: 0.8rem; text-decoration: none; margin-right: 4px;">
                                                ⬇ Download
                                            </a>
                                            <form method="POST" action="" style="display: inline; margin: 0;" onsubmit="return confirm('Delete backup <?= htmlspecialchars(addslashes($bf['name'])) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                <input type="hidden" name="action" value="delete_file">
                                                <input type="hidden" name="file" value="<?= htmlspecialchars($bf['name']) ?>">
                                                <button type="submit" class="btn-secondary" style="padding: 5px 10px; font-size: 0.8rem; color: #b91c1c; cursor: pointer;">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: #f8fafc; border-top: 2px solid #cbd5e1; font-weight: 700;">
                                    <td colspan="2" style="padding: 10px 12px; color: #0b2545;">
                                        Total (<?= count($backup_files) ?> backup<?= count($backup_files) === 1 ? '' : 's' ?>)
                                    </td>
                                    <td style="padding: 10px 12px; color: #0b2545;">
                                        <?= format_backup_size($total_size_bytes) ?>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
