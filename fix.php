<?php declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
if (file_exists(__DIR__ . '/includes/content.php')) {
    require_once __DIR__ . '/includes/content.php';
}

$is_cli = (php_sapi_name() === 'cli');
$log = [];

function record_step(string $message, bool $success = true): void {
    global $log, $is_cli;
    $status = $success ? '[PASS]' : '[FAIL]';
    $log[] = "{$status} {$message}";
    if ($is_cli) {
        echo "{$status} {$message}\n";
    }
}

function column_exists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = ? 
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

try {
    $db = get_db();

    // 1. Convert all empty string slugs to NULL in registration_forms
    $cleaned_slugs = $db->exec("
        UPDATE registration_forms 
        SET slug = NULL 
        WHERE slug = '' OR TRIM(slug) = ''
    ");
    record_step("Converted {$cleaned_slugs} empty form slug(s) to NULL.");

    // 2. Ensure registration_forms.slug allows NULL with DEFAULT NULL
    $db->exec("
        ALTER TABLE registration_forms 
        MODIFY COLUMN slug VARCHAR(255) NULL DEFAULT NULL
    ");
    record_step("Modified registration_forms.slug to VARCHAR(255) NULL DEFAULT NULL.");

    // 3. Resolve Error 1364: Permit NULL values and set DEFAULT NULL for registration_forms.node_id
    if (column_exists($db, 'registration_forms', 'node_id')) {
        $db->exec("
            ALTER TABLE registration_forms 
            MODIFY COLUMN node_id INT UNSIGNED NULL DEFAULT NULL
        ");
        record_step("Modified registration_forms.node_id to INT UNSIGNED NULL DEFAULT NULL.");
    } else {
        record_step("Column node_id does not exist on registration_forms (skipped).");
    }

    // Defensive check: If registrations table actually has a legacy node_id column, modify it safely
    if (column_exists($db, 'registrations', 'node_id')) {
        $db->exec("
            ALTER TABLE registrations 
            MODIFY COLUMN node_id INT UNSIGNED NULL DEFAULT NULL
        ");
        record_step("Modified legacy registrations.node_id to INT UNSIGNED NULL DEFAULT NULL.");
    }

    // 4. Harden attendee columns against MySQL strict mode defensively
    $optional_columns = [
        'user_id'         => 'INT UNSIGNED NULL DEFAULT NULL',
        'name'            => 'VARCHAR(120) NULL DEFAULT NULL',
        'phone'           => 'VARCHAR(30) NULL DEFAULT NULL',
        'organization'    => 'VARCHAR(150) NULL DEFAULT NULL',
        'submission_data' => 'LONGTEXT NULL DEFAULT NULL' // Safe across MySQL 5.5 through 8.x
    ];

    foreach ($optional_columns as $col => $definition) {
        if (column_exists($db, 'registrations', $col)) {
            $db->exec("ALTER TABLE registrations MODIFY COLUMN `{$col}` {$definition}");
            record_step("Hardened registrations.{$col} ({$definition}).");
        } else {
            record_step("Column registrations.{$col} not present (skipped).");
        }
    }

    // 5. Auto-heal registration_forms: Add unique_email column if missing
    if (!column_exists($db, 'registration_forms', 'unique_email')) {
        $db->exec("
            ALTER TABLE registration_forms 
            ADD COLUMN unique_email TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_waitlist
        ");
        record_step("Added unique_email column to registration_forms.");
    } else {
        record_step("Column unique_email already exists on registration_forms.");
    }

    // 6. Auto-heal registration_forms: Add form_type ENUM('general', 'tour') if missing
    if (!column_exists($db, 'registration_forms', 'form_type')) {
        $db->exec("
            ALTER TABLE registration_forms 
            ADD COLUMN form_type ENUM('general', 'tour') NOT NULL DEFAULT 'general' AFTER host_type
        ");
        record_step("Added form_type column ENUM('general', 'tour') to registration_forms.");
    } else {
        record_step("Column form_type already exists on registration_forms.");
    }

    // 7. Auto-heal registration_forms: Add tour_date column if missing
    if (!column_exists($db, 'registration_forms', 'tour_date')) {
        $db->exec("
            ALTER TABLE registration_forms 
            ADD COLUMN tour_date DATE NULL DEFAULT NULL AFTER close_date
        ");
        record_step("Added tour_date column (DATE NULL) to registration_forms.");
    } else {
        record_step("Column tour_date already exists on registration_forms.");
    }

    // 8. Auto-heal registration_forms: Add tour_time column if missing
    if (!column_exists($db, 'registration_forms', 'tour_time')) {
        $db->exec("
            ALTER TABLE registration_forms 
            ADD COLUMN tour_time VARCHAR(60) NULL DEFAULT NULL AFTER tour_date
        ");
        record_step("Added tour_time column (VARCHAR(60) NULL) to registration_forms.");
    } else {
        record_step("Column tour_time already exists on registration_forms.");
    }

    // 9. Auto-heal composite index for tour queries: idx_rf_tour_lookup
    $has_tour_index = (bool)$db->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'registration_forms' 
          AND INDEX_NAME = 'idx_rf_tour_lookup'
    ")->fetchColumn();

    if (!$has_tour_index) {
        $db->exec("
            ALTER TABLE registration_forms 
            ADD INDEX idx_rf_tour_lookup (form_type, status, tour_date)
        ");
        record_step("Added index idx_rf_tour_lookup (form_type, status, tour_date) on registration_forms.");
    } else {
        record_step("Index idx_rf_tour_lookup already exists on registration_forms.");
    }

    // 10. Auto-heal multi-time slots child table: registration_time_slots
    $has_slots_table = (bool)$db->query("
        SELECT COUNT(*) FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'registration_time_slots'
    ")->fetchColumn();

    if (!$has_slots_table) {
        $db->exec("
            CREATE TABLE `registration_time_slots` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT UNSIGNED NOT NULL,
                `slot_time` VARCHAR(60) NOT NULL,
                `capacity` INT UNSIGNED NOT NULL DEFAULT 20,
                `allow_waitlist` TINYINT(1) NOT NULL DEFAULT 1,
                `weight` INT NOT NULL DEFAULT 0,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_slot_form` (`form_id`),
                CONSTRAINT `fk_slot_form_rf` FOREIGN KEY (`form_id`) REFERENCES `registration_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        record_step("Created table registration_time_slots for multiple tour times.");
    } else {
        record_step("Table registration_time_slots already exists.");
    }

    // 11. Auto-heal registrations: Add slot_id column if missing
    if (!column_exists($db, 'registrations', 'slot_id')) {
        $db->exec("
            ALTER TABLE registrations 
            ADD COLUMN slot_id INT UNSIGNED NULL DEFAULT NULL AFTER form_id,
            ADD INDEX idx_reg_slot (slot_id)
        ");
        record_step("Added slot_id column and index idx_reg_slot to registrations.");
    } else {
        record_step("Column slot_id already exists on registrations.");
    }

    // 12. Backward Compatibility: Migrate existing single tour_time rows into registration_time_slots
    $legacy_tours = $db->query("
        SELECT rf.id, rf.capacity, rf.allow_waitlist, rf.tour_time 
        FROM registration_forms rf 
        WHERE rf.form_type = 'tour' 
          AND rf.tour_time IS NOT NULL 
          AND TRIM(rf.tour_time) != ''
          AND NOT EXISTS (
              SELECT 1 FROM registration_time_slots rts WHERE rts.form_id = rf.id
          )
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($legacy_tours)) {
        $ins_slot = $db->prepare("
            INSERT INTO registration_time_slots (form_id, slot_time, capacity, allow_waitlist, weight) 
            VALUES (?, ?, ?, ?, 0)
        ");
        $link_regs = $db->prepare("
            UPDATE registrations SET slot_id = ? WHERE form_id = ? AND (slot_id IS NULL OR slot_id = 0)
        ");
        $migrated_count = 0;
        foreach ($legacy_tours as $lt) {
            $cap = (int)$lt['capacity'] > 0 ? (int)$lt['capacity'] : 20;
            $ins_slot->execute([$lt['id'], trim($lt['tour_time']), $cap, (int)$lt['allow_waitlist']]);
            $new_slot_id = (int)$db->lastInsertId();
            $link_regs->execute([$new_slot_id, $lt['id']]);
            $migrated_count++;
        }
        record_step("Migrated {$migrated_count} legacy single-time tour(s) into registration_time_slots.");
    }

    // 13. Auto-heal nodes: Add SEO metadata columns if missing
    if (!column_exists($db, 'nodes', 'seo_title')) {
        $db->exec("
            ALTER TABLE `nodes` 
            ADD COLUMN `seo_title` VARCHAR(255) NULL DEFAULT NULL AFTER `title`,
            ADD COLUMN `seo_description` VARCHAR(500) NULL DEFAULT NULL AFTER `seo_title`,
            ADD COLUMN `seo_focus_keyword` VARCHAR(100) NULL DEFAULT NULL AFTER `seo_description`
        ");
        record_step("Added SEO columns (seo_title, seo_description, seo_focus_keyword) to nodes table.");
    } else {
        record_step("SEO columns already exist on nodes table.");
    }

    // 14. Auto-heal node_revisions: Add SEO metadata columns if missing
    if (!column_exists($db, 'node_revisions', 'seo_title')) {
        $db->exec("
            ALTER TABLE `node_revisions` 
            ADD COLUMN `seo_title` VARCHAR(255) NULL DEFAULT NULL AFTER `title`,
            ADD COLUMN `seo_description` VARCHAR(500) NULL DEFAULT NULL AFTER `seo_title`,
            ADD COLUMN `seo_focus_keyword` VARCHAR(100) NULL DEFAULT NULL AFTER `seo_description`
        ");
        record_step("Added SEO columns (seo_title, seo_description, seo_focus_keyword) to node_revisions table.");
    } else {
        record_step("SEO columns already exist on node_revisions table.");
    }

    // 15. Auto-heal Secure Document Transfers: secure_transfers table
    $has_secure_transfers = (bool)$db->query("
        SELECT COUNT(*) FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'secure_transfers'
    ")->fetchColumn();

    if (!$has_secure_transfers) {
        $db->exec("
            CREATE TABLE `secure_transfers` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `original_filename` VARCHAR(255) NOT NULL,
                `stored_filename` VARCHAR(255) NOT NULL,
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
                `file_hash` CHAR(64) NULL,
                `auth_mode` ENUM('direct', 'otp') NOT NULL DEFAULT 'direct',
                `max_downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
                `expires_at` DATETIME NULL,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_st_token` (`token`),
                INDEX `idx_st_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        record_step("Created table secure_transfers.");
    } else {
        record_step("Table secure_transfers already exists.");
        if (!column_exists($db, 'secure_transfers', 'auth_mode')) {
            $db->exec("ALTER TABLE `secure_transfers` ADD COLUMN `auth_mode` ENUM('direct', 'otp') NOT NULL DEFAULT 'direct' AFTER `file_hash`");
            record_step("Added auth_mode ENUM('direct','otp') to secure_transfers.");
        }
    }

    // 16. Auto-heal: Child Table secure_transfer_recipients
    $has_recipients_table = (bool)$db->query("
        SELECT COUNT(*) FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'secure_transfer_recipients'
    ")->fetchColumn();

    if (!$has_recipients_table) {
        $db->exec("
            CREATE TABLE `secure_transfer_recipients` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `transfer_id` INT UNSIGNED NOT NULL,
                `recipient_email` VARCHAR(254) NOT NULL,
                `status` ENUM('active', 'deactivated') NOT NULL DEFAULT 'active',
                `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `otp_code` CHAR(4) NULL DEFAULT NULL,
                `otp_expires_at` DATETIME NULL DEFAULT NULL,
                `otp_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `deactivated_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_str_transfer` (`transfer_id`),
                INDEX `idx_str_email` (`recipient_email`),
                CONSTRAINT `fk_str_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `secure_transfers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        record_step("Created table secure_transfer_recipients for independent recipient access.");

        if (column_exists($db, 'secure_transfers', 'recipient_email')) {
            $legacy_rows = $db->query("
                SELECT id, recipient_email, download_count, status, deactivated_at 
                FROM secure_transfers 
                WHERE recipient_email IS NOT NULL AND TRIM(recipient_email) != ''
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($legacy_rows)) {
                $ins_recip = $db->prepare("
                    INSERT INTO secure_transfer_recipients 
                    (transfer_id, recipient_email, status, download_count, deactivated_at) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $migrated_recips = 0;
                foreach ($legacy_rows as $lr) {
                    $st = ($lr['status'] === 'deactivated') ? 'deactivated' : 'active';
                    $ins_recip->execute([$lr['id'], strtolower(trim($lr['recipient_email'])), $st, (int)$lr['download_count'], $lr['deactivated_at']]);
                    $migrated_recips++;
                }
                record_step("Migrated {$migrated_recips} legacy recipient(s) into secure_transfer_recipients.");
            }
        }
    } else {
        record_step("Table secure_transfer_recipients already exists.");
    }

    // 17. Auto-heal Secure Document Transfers: secure_transfer_logs audit table
    $has_secure_transfer_logs = (bool)$db->query("
        SELECT COUNT(*) FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'secure_transfer_logs'
    ")->fetchColumn();

    if (!$has_secure_transfer_logs) {
        $db->exec("
            CREATE TABLE `secure_transfer_logs` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `transfer_id` INT UNSIGNED NOT NULL,
                `recipient_id` INT UNSIGNED NULL DEFAULT NULL,
                `event_type` ENUM('page_view', 'auth_failed', 'otp_sent', 'otp_failed', 'auth_success', 'download_started', 'download_completed', 'deactivated_by_user', 'revoked_by_admin') NOT NULL,
                `email_entered` VARCHAR(254) NULL DEFAULT NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `user_agent` VARCHAR(255) NULL DEFAULT NULL,
                `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_stl_transfer` (`transfer_id`),
                CONSTRAINT `fk_stl_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `secure_transfers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        record_step("Created table secure_transfer_logs for forensic audit tracking.");
    } else {
        record_step("Table secure_transfer_logs already exists.");
        if (!column_exists($db, 'secure_transfer_logs', 'recipient_id')) {
            $db->exec("ALTER TABLE `secure_transfer_logs` ADD COLUMN `recipient_id` INT UNSIGNED NULL DEFAULT NULL AFTER `transfer_id`");
            record_step("Added recipient_id column to secure_transfer_logs.");
        }
    }

    // 18. Auto-provision secure vault storage directory and server access locks
    $vault_dir = __DIR__ . '/vault';
    if (!is_dir($vault_dir)) {
        if (@mkdir($vault_dir, 0750, true)) {
            record_step("Created isolated vault storage directory: /vault");
        } else {
            record_step("Failed to create directory /vault. Please verify filesystem permissions.", false);
        }
    } else {
        record_step("Isolated vault storage directory (/vault) already exists.");
    }

    $htaccess_file = $vault_dir . '/.htaccess';
    if (!file_exists($htaccess_file) && is_dir($vault_dir)) {
        @file_put_contents($htaccess_file, "Order Deny,Allow\nDeny from all\n");
        record_step("Wrote Apache access deny rule: /vault/.htaccess");
    }

    $webconfig_file = $vault_dir . '/web.config';
    if (!file_exists($webconfig_file) && is_dir($vault_dir)) {
        $iis_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <authorization>\n            <deny users=\"*\" />\n        </authorization>\n    </system.webServer>\n</configuration>\n";
        @file_put_contents($webconfig_file, $iis_xml);
        record_step("Wrote IIS access deny rule: /vault/web.config");
    }

    // 19. Clear application data cache
    if (file_exists(__DIR__ . '/includes/cache.php')) {
        require_once __DIR__ . '/includes/cache.php';
        cache_clear();
        record_step("Cleared cache_data table.");
    }

} catch (PDOException $e) {
    record_step("Database operation aborted: " . $e->getMessage(), false);
}

if (!$is_cli): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Schema & Vault Provisioning - FRNP CMS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 40px; }
        .card { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 25px 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h2 { color: #0b2545; margin-top: 0; }
        .log { background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 0.88rem; line-height: 1.6; max-height: 480px; overflow-y: auto; }
        .success { color: #10b981; }
        .fail { color: #ef4444; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 18px; background: #0b2545; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Database Schema Migration & Vault Provisioning</h2>
        <div class="log">
            <?php foreach ($log as $line): ?>
                <div class="<?= str_starts_with($line, '[PASS]') ? 'success' : 'fail' ?>"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top: 20px; font-size: 0.9rem; color: #64748b;">
            Delete this script from your web root after execution completes.
        </p>
        <a href="<?= function_exists('base_path') ? base_path('admin/secure_transfers.php') : '/admin/secure_transfers.php' ?>" class="btn">&laquo; Go to Secure Transfers Admin</a>
    </div>
</body>
</html>
<?php endif; ?>
