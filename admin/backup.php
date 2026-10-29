<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_permission('administer_site');

if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $db = get_db();
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $sqlDump = "-- FRNP CMS Automated Database Snapshot\n";
    $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- Server: " . $_SERVER['SERVER_SOFTWARE'] . "\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $sqlDump .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sqlDump .= $createStmt[1] . ";\n\n";

        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
            $colList = implode('`, `', $cols);

            foreach ($rows as $row) {
                $values = array_map(function ($val) use ($db) {
                    return $val === null ? 'NULL' : $db->quote((string)$val);
                }, array_values($row));
                $sqlDump .= "INSERT INTO `{$table}` (`{$colList}`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $sqlDump .= "\n";
        }
    }
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = sprintf('frnp_cms_backup_%s.sql', date('Ymd_His'));
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sqlDump));
    echo $sqlDump;
    exit;
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
        <main class="admin-main" style="max-width: 800px;">
            <h1>Maintenance &raquo; Database Backup</h1>
            <section class="card">
                <h3>Export SQL Dump</h3>
                <p style="margin-bottom: 20px; color:#555;">
                    Generate and download a complete structural schema and data dump of the <code>frnp_cms</code> database (nodes, revisions, taxonomy, users, permissions, and media mappings).
                </p>
                <a href="<?= base_path('admin/backup.php?action=download') ?>" class="btn-primary" style="padding: 12px 20px;">
                    ⬇ Download Backup (.sql)
                </a>
            </section>
        </main>
    </div>
</body>
</html>