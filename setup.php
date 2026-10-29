<?php declare(strict_types=1);

// 0. Dynamic Runtime Base Path Resolver (Supports root '/' or any subfolder deployment)
$script_path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$dir_path = dirname($script_path);
$base_path = ($dir_path === '/' || $dir_path === '\\' || $dir_path === '.') ? '' : rtrim($dir_path, '/');
$home_url = ($base_path !== '' ? $base_path : '') . '/';
$login_url = ($base_path !== '' ? $base_path : '') . '/admin/index.php';
$css_url = ($base_path !== '' ? $base_path : '') . '/assets/css/style.css';

// Prevent re-installation if already locked
$lock_file = __DIR__ . '/config/installed.lock';
if (file_exists($lock_file)) {
    die('<!DOCTYPE html><html><head><title>CMS Already Installed</title><link rel="stylesheet" href="' . htmlspecialchars($css_url) . '"></head><body style="padding:40px; font-family:sans-serif; text-align:center;"><h2>Installation Locked</h2><p>This CMS instance is already installed and locked for security. To re-run setup, delete <code>config/installed.lock</code>.</p><p><a href="' . htmlspecialchars($home_url) . '" style="display:inline-block; padding:10px 20px; background:#0b2545; color:#fff; text-decoration:none; border-radius:4px;">Go to Site Home</a></p></body></html>');
}

$errors = [];
$success = false;

// 1. Environment & Prerequisite Checks
$env_checks = [
    'PHP Version >= 8.1'            => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO Extension Installed'       => extension_loaded('pdo'),
    'PDO MySQL Driver Available'    => extension_loaded('pdo_mysql'),
    'JSON Extension Enabled'        => extension_loaded('json'),
    'GD Library (Image Resizing)'   => extension_loaded('gd'),
    'Config Directory Writable'     => is_writable(__DIR__ . '/config') || (!file_exists(__DIR__ . '/config') && is_writable(__DIR__)),
    'Uploads Directory Writable'    => is_writable(__DIR__ . '/uploads') || (!file_exists(__DIR__ . '/uploads') && is_writable(__DIR__)),
];
$can_install = !in_array(false, $env_checks, true);

// 2. Handle Installation POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_install) {
    $db_host     = trim($_POST['db_host'] ?? '127.0.0.1');
    $db_port     = (int)($_POST['db_port'] ?? 3306);
    $db_name     = trim($_POST['db_name'] ?? '');
    $db_user     = trim($_POST['db_user'] ?? '');
    $db_pass     = (string)($_POST['db_pass'] ?? '');
    $site_name   = trim($_POST['site_name'] ?? 'Four Rivers Nuclear Partnership');
    $admin_user  = trim($_POST['admin_user'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = (string)($_POST['admin_pass'] ?? '');

    if ($db_name === '' || $db_user === '') {
        $errors[] = 'Database Name and Database Username are required.';
    }
    if ($admin_user === '' || $admin_email === '' || strlen($admin_pass) < 8) {
        $errors[] = 'Admin credentials required (password must be at least 8 characters).';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid administrator email address.';
    }

    if (empty($errors)) {
        try {
            // Test Database Connection
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Execute Complete Structural Schema DDL
            $pdo->exec("
                -- 1. Users, Roles & Permissions
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(64) NOT NULL UNIQUE,
                    `password_hash` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(254) NOT NULL UNIQUE,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `roles` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `machine_name` VARCHAR(32) NOT NULL UNIQUE,
                    `label` VARCHAR(64) NOT NULL,
                    `description` VARCHAR(255) NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `user_roles` (
                    `user_id` INT UNSIGNED NOT NULL,
                    `role_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`user_id`, `role_id`),
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `permissions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `machine_name` VARCHAR(64) NOT NULL UNIQUE,
                    `description` VARCHAR(255) NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `role_permissions` (
                    `role_id` INT UNSIGNED NOT NULL,
                    `permission_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`role_id`, `permission_id`),
                    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 2. Content Architecture & Pathauto Patterns
                CREATE TABLE IF NOT EXISTS `content_types` (
                    `type` VARCHAR(32) PRIMARY KEY,
                    `name` VARCHAR(128) NOT NULL,
                    `description` TEXT NULL,
                    `fields_schema` JSON NOT NULL,
                    `has_body` TINYINT(1) NOT NULL DEFAULT 1,
                    `url_pattern` VARCHAR(255) NULL DEFAULT '[node:title]',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `nodes` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `type` VARCHAR(32) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(255) NOT NULL UNIQUE,
                    `auto_slug` TINYINT(1) NOT NULL DEFAULT 1,
                    `body` MEDIUMTEXT NULL,
                    `format` VARCHAR(32) NOT NULL DEFAULT 'filtered_html',
                    `keywords` TEXT NULL,
                    `fields_data` JSON NULL,
                    `uid` INT UNSIGNED NOT NULL DEFAULT 1,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                    `publish_on` DATETIME NULL,
                    `unpublish_on` DATETIME NULL,
                    `template` VARCHAR(64) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_node_type` (`type`),
                    INDEX `idx_node_status` (`status`)
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `node_revisions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `node_id` INT UNSIGNED NOT NULL,
                    `uid` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `body` MEDIUMTEXT NULL,
                    `keywords` TEXT NULL,
                    `fields_data` JSON NULL,
                    `log_message` VARCHAR(255) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`node_id`) REFERENCES `nodes`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 3. Taxonomy Vocabularies & Terms
                CREATE TABLE IF NOT EXISTS `taxonomy_vocabularies` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `machine_name` VARCHAR(32) NOT NULL UNIQUE,
                    `name` VARCHAR(64) NOT NULL,
                    `description` TEXT NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `taxonomy_terms` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `vocabulary_id` INT UNSIGNED NOT NULL,
                    `name` VARCHAR(128) NOT NULL,
                    `slug` VARCHAR(128) NOT NULL,
                    `weight` INT NOT NULL DEFAULT 0,
                    `description` TEXT NULL,
                    UNIQUE KEY `uk_term_vocab_slug` (`vocabulary_id`, `slug`),
                    FOREIGN KEY (`vocabulary_id`) REFERENCES `taxonomy_vocabularies`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `node_terms` (
                    `node_id` INT UNSIGNED NOT NULL,
                    `term_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`node_id`, `term_id`),
                    FOREIGN KEY (`node_id`) REFERENCES `nodes`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`term_id`) REFERENCES `taxonomy_terms`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 4. Menus & Hierarchy
                CREATE TABLE IF NOT EXISTS `menu_items` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `menu_name` VARCHAR(32) NOT NULL DEFAULT 'main',
                    `node_id` INT UNSIGNED NULL,
                    `title` VARCHAR(128) NOT NULL,
                    `url` VARCHAR(255) NOT NULL,
                    `weight` INT NOT NULL DEFAULT 0,
                    `parent_id` INT UNSIGNED NULL DEFAULT NULL,
                    INDEX `idx_menu_name` (`menu_name`),
                    INDEX `idx_parent_id` (`parent_id`)
                ) ENGINE=InnoDB;

                -- 5. Block Visibility Matrix
                CREATE TABLE IF NOT EXISTS `blocks` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `title` VARCHAR(128) NOT NULL,
                    `region` VARCHAR(32) NOT NULL DEFAULT 'sidebar',
                    `body` MEDIUMTEXT NOT NULL,
                    `weight` INT NOT NULL DEFAULT 0,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `visibility_pages` TEXT NULL,
                    `visibility_mode` ENUM('show_only', 'show_except') NOT NULL DEFAULT 'show_only',
                    `visibility_types` VARCHAR(255) NULL DEFAULT '',
                    `visibility_roles` VARCHAR(255) NULL DEFAULT '',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_region_status` (`region`, `status`)
                ) ENGINE=InnoDB;

                -- 6. Alerts & Modal Broadcasts
                CREATE TABLE IF NOT EXISTS `site_alerts` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `display_type` ENUM('modal', 'banner_top') NOT NULL DEFAULT 'modal',
                    `severity` ENUM('info', 'warning', 'emergency', 'success') NOT NULL DEFAULT 'warning',
                    `target_pages` VARCHAR(255) NULL DEFAULT '<front>',
                    `cta_label` VARCHAR(100) NULL,
                    `cta_url` VARCHAR(255) NULL,
                    `dismissible` TINYINT(1) NOT NULL DEFAULT 1,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `start_date` DATETIME NULL,
                    `end_date` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_alert_status` (`status`, `start_date`, `end_date`)
                ) ENGINE=InnoDB;

                -- 7. Custom Rules & Event Automation (ECA)
                CREATE TABLE IF NOT EXISTS `custom_rules` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `label` VARCHAR(128) NOT NULL,
                    `machine_name` VARCHAR(64) NOT NULL UNIQUE,
                    `event` VARCHAR(64) NOT NULL,
                    `conditions` JSON NULL,
                    `actions` JSON NOT NULL,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_event_status` (`event`, `status`)
                ) ENGINE=InnoDB;

                -- 8. Views Query Engine
                CREATE TABLE IF NOT EXISTS `views` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(128) NOT NULL,
                    `machine_name` VARCHAR(64) NOT NULL UNIQUE,
                    `description` TEXT NULL,
                    `base_table` VARCHAR(64) NOT NULL DEFAULT 'nodes',
                    `display_type` VARCHAR(20) NOT NULL DEFAULT 'block',
                    `path` VARCHAR(255) NULL,
                    `block_id` INT UNSIGNED NULL,
                    `configuration` LONGTEXT NOT NULL,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_views_machine` (`machine_name`),
                    INDEX `idx_views_status` (`status`),
                    INDEX `idx_views_path` (`path`)
                ) ENGINE=InnoDB;

                -- 9. Registration Subsystem & Submissions
                CREATE TABLE IF NOT EXISTS `registration_forms` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `title` VARCHAR(255) NOT NULL,
                    `description` TEXT NULL,
                    `host_type` ENUM('page', 'block') NOT NULL DEFAULT 'page',
                    `slug` VARCHAR(128) NULL,
                    `block_id` INT UNSIGNED NULL,
                    `capacity` INT UNSIGNED NOT NULL DEFAULT 0,
                    `allow_waitlist` TINYINT(1) NOT NULL DEFAULT 1,
                    `open_date` DATETIME NULL,
                    `close_date` DATETIME NULL,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `fields_schema` JSON NULL,
                    `confirmation_message` TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_rf_slug` (`slug`),
                    INDEX `idx_rf_status` (`status`)
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `registrations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `form_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `user_id` INT UNSIGNED NULL,
                    `name` VARCHAR(120) NULL,
                    `email` VARCHAR(254) NOT NULL,
                    `phone` VARCHAR(30) NULL,
                    `organization` VARCHAR(150) NULL,
                    `slots` INT UNSIGNED NOT NULL DEFAULT 1,
                    `state` ENUM('complete', 'waitlist', 'cancelled') NOT NULL DEFAULT 'complete',
                    `submission_data` JSON NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_reg_form` (`form_id`),
                    INDEX `idx_reg_email` (`email`),
                    INDEX `idx_reg_state` (`state`)
                ) ENGINE=InnoDB;

                -- 10. Real-Time Telemetry & Visitor Analytics
                CREATE TABLE IF NOT EXISTS `analytics_hits` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `path` VARCHAR(255) NOT NULL,
                    `node_id` INT UNSIGNED NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `user_agent` VARCHAR(255) NULL,
                    `referer` VARCHAR(255) NULL,
                    `device_type` ENUM('desktop', 'mobile', 'tablet', 'bot') NOT NULL DEFAULT 'desktop',
                    `session_hash` CHAR(32) NOT NULL,
                    `is_staff` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_created_path` (`created_at`, `path`),
                    INDEX `idx_session_time` (`session_hash`, `created_at`),
                    INDEX `idx_node_id` (`node_id`)
                ) ENGINE=InnoDB;

                -- 11. Inbound Forms, Submissions & Redirects
                CREATE TABLE IF NOT EXISTS `contact_submissions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(100) NOT NULL,
                    `email` VARCHAR(254) NOT NULL,
                    `subject` VARCHAR(200) NOT NULL,
                    `message` TEXT NOT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `status` ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `url_redirects` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `source_path` VARCHAR(255) NOT NULL UNIQUE,
                    `target_path` VARCHAR(255) NOT NULL,
                    `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 301,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                -- 12. Outbound Mailer Queue & Dispatch Logs
                CREATE TABLE IF NOT EXISTS `email_queue` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `recipient` VARCHAR(254) NOT NULL,
                    `recipient_name` VARCHAR(120) NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `body_html` MEDIUMTEXT NOT NULL,
                    `body_text` MEDIUMTEXT NULL,
                    `priority` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    `last_error` TEXT NULL,
                    `locked_until` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_dispatch` (`priority`, `attempts`, `locked_until`)
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `email_logs` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `recipient` VARCHAR(254) NOT NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `status` ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
                    `error_message` TEXT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `dispatched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                -- 13. System Buffer Cache, Watchdog & Settings
                CREATE TABLE IF NOT EXISTS `cache_data` (
                    `cid` VARCHAR(128) PRIMARY KEY,
                    `data` MEDIUMTEXT NOT NULL,
                    `expire` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `site_settings` (
                    `setting_key` VARCHAR(64) PRIMARY KEY,
                    `setting_value` TEXT NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `watchdog` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `uid` INT UNSIGNED NULL,
                    `type` VARCHAR(32) NOT NULL,
                    `message` TEXT NOT NULL,
                    `severity` VARCHAR(16) NOT NULL DEFAULT 'notice',
                    `link` VARCHAR(255) NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_type_severity` (`type`, `severity`)
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `tour_reservations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `full_name` VARCHAR(120) NOT NULL,
                    `email` VARCHAR(254) NOT NULL,
                    `phone` VARCHAR(30) NOT NULL,
                    `organization` VARCHAR(150) NULL,
                    `attendees` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `tour_date` DATE NOT NULL,
                    `us_citizen` TINYINT(1) NOT NULL DEFAULT 1,
                    `special_accommodations` TEXT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `status` ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;
            ");

            // Seed Roles & Permissions (Including Registration Permissions)
            $pdo->exec("
                INSERT IGNORE INTO `roles` (`id`, `machine_name`, `label`, `description`) VALUES
                (1, 'administrator', 'Administrator', 'Full platform and administrative system access.'),
                (2, 'editor', 'Content Editor', 'Can draft, update, and manage portal content.'),
                (3, 'authenticated', 'Authenticated User', 'Standard registered user account.');

                INSERT IGNORE INTO `permissions` (`id`, `machine_name`, `description`) VALUES
                (1, 'administer_site', 'Administer global site settings, modules, and database.'),
                (2, 'administer_users', 'Create and modify user accounts and roles.'),
                (3, 'administer_content_types', 'Configure content schemas and field display layouts.'),
                (4, 'create_content', 'Create new pages, articles, and media attachments.'),
                (5, 'edit_any_content', 'Edit and revise all existing content nodes.'),
                (6, 'delete_any_content', 'Permanently delete content nodes.'),
                (7, 'view_registrations', 'Access registration submission rosters and export attendee lists.'),
                (8, 'edit_registrations', 'Modify registration statuses and process waitlist approvals.'),
                (9, 'delete_registrations', 'Permanently delete registration submissions.');

                INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
                (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9),
                (2, 4), (2, 5), (2, 7), (2, 8);
            ");

            // Create Administrator Account
            $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
            $user_stmt = $pdo->prepare('INSERT INTO `users` (`username`, `password_hash`, `email`, `status`) VALUES (?, ?, ?, 1)');
            $user_stmt->execute([$admin_user, $hashed_pass, $admin_email]);
            $admin_uid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, 1)')->execute([$admin_uid]);

            // Seed Content Types with Upload Subdirectories
            $article_schema = json_encode([
                [
                    'name'          => 'field_article_photo',
                    'label'         => 'Lead Photo',
                    'type'          => 'image',
                    'upload_dir'    => 'files',
                    'label_display' => 'hidden',
                    'weight'        => 10,
                    'required'      => 0,
                    'help_text'     => 'Primary featured graphic.'
                ],
                [
                    'name'          => 'body',
                    'label'         => 'Body Content',
                    'type'          => 'body',
                    'label_display' => 'hidden',
                    'weight'        => 20,
                    'required'      => 0,
                    'help_text'     => 'Main article copy.'
                ],
                [
                    'name'          => 'subtitle',
                    'label'         => 'Article Subtitle',
                    'type'          => 'text',
                    'label_display' => 'hidden',
                    'weight'        => 30,
                    'required'      => 0,
                    'help_text'     => ''
                ],
                [
                    'name'          => 'publication_date',
                    'label'         => 'Release Date',
                    'type'          => 'date',
                    'label_display' => 'inline',
                    'weight'        => 40,
                    'required'      => 0,
                    'help_text'     => ''
                ]
            ]);

            $page_schema = json_encode([
                [
                    'name'          => 'body',
                    'label'         => 'Body Content',
                    'type'          => 'body',
                    'label_display' => 'hidden',
                    'weight'        => 10,
                    'required'      => 0,
                    'help_text'     => ''
                ]
            ]);

            $pdo->prepare("
                INSERT IGNORE INTO `content_types` (`type`, `name`, `description`, `fields_schema`, `has_body`, `url_pattern`) VALUES
                ('page', 'Basic Page', 'Static informational page integrated into site hierarchy.', ?, 1, '[node:title]'),
                ('article', 'News & Updates', 'Time-sensitive press releases and project announcements.', ?, 1, 'news/[node:created:Y]/[node:title]')
            ")->execute([$page_schema, $article_schema]);

            // Seed Taxonomy Vocabulary & Terms
            $pdo->exec("
                INSERT IGNORE INTO `taxonomy_vocabularies` (`id`, `machine_name`, `name`, `description`) VALUES
                (1, 'topics', 'Program Topics', 'Primary environmental cleanup classifications.');

                INSERT IGNORE INTO `taxonomy_terms` (`vocabulary_id`, `name`, `slug`, `weight`) VALUES
                (1, 'Environmental Remediation', 'environmental-remediation', 0),
                (1, 'Groundwater Protection', 'groundwater-protection', 10),
                (1, 'Facility Deactivation', 'facility-deactivation', 20),
                (1, 'Community Outreach', 'community-outreach', 30),
                (1, 'Safety & Training', 'safety-training', 40);
            ");

            // Seed Global Site Settings
            $settings_stmt = $pdo->prepare('INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
            $settings = [
                'site_name'            => $site_name,
                'site_slogan'          => 'Paducah Deactivation Project',
                'sub_tagline'          => 'A Department of Energy Cleanup Contractor',
                'footer_copyright'     => "{$site_name}, LLC. All Rights Reserved.",
                'footer_disclaimer'    => "{$site_name} manages the Deactivation Project at the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy.",
                'notify_contact_email' => $admin_email,
                'notify_workflow_email'=> $admin_email,
                'mail_driver'          => 'mail',
                'mail_from_address'    => $admin_email,
                'mail_from_name'       => $site_name,
                'ga_measurement_id'    => ''
            ];
            foreach ($settings as $k => $v) {
                $settings_stmt->execute([$k, $v]);
            }

            // Seed Starter Nodes with Clean Slugs
            $node_stmt = $pdo->prepare("
                INSERT INTO `nodes` (`type`, `title`, `slug`, `auto_slug`, `body`, `format`, `keywords`, `fields_data`, `uid`, `status`)
                VALUES (?, ?, ?, 1, ?, 'filtered_html', ?, '{}', ?, 'published')
            ");

            $node_stmt->execute([
                'page',
                'Welcome to the Paducah Deactivation Project',
                'home',
                '<p>Four Rivers Nuclear Partnership, LLC (FRNP) leads the safe, compliant deactivation and environmental remediation of the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy (DOE).</p><p>Our dedicated workforce prioritizes safety, operational excellence, and environmental stewardship across western Kentucky.</p>',
                'home, paducah, environmental remediation, DOE, cleanup',
                $admin_uid
            ]);
            $home_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'About FRNP Leadership & Operations',
                'about-us',
                '<p>Four Rivers Nuclear Partnership represents a world-class team of nuclear cleanup professionals dedicated to stabilizing infrastructure, remediating legacy facilities, and preparing the Paducah Site for future community missions.</p>',
                'about us, leadership, mission, stewardship',
                $admin_uid
            ]);
            $about_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'Program Areas & Scope',
                'program-areas',
                '<p>FRNP manages key operational programs encompassing Groundwater Protection, Environmental Monitoring, Stabilization & Deactivation, and Waste Disposition.</p>',
                'programs, groundwater, deactivation, waste',
                $admin_uid
            ]);
            $prog_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'Contact Communications',
                'contact',
                '<p>For public inquiries, media relations, or stakeholder briefings, contact Four Rivers Nuclear Partnership Communications at 5511 Hobbs Road, Kevil, KY 42053.</p>',
                'contact, office, email, phone, media inquiries',
                $admin_uid
            ]);
            $contact_id = (int)$pdo->lastInsertId();

            // Seed Default Menu Links (Home links directly to '/')
            $menu_stmt = $pdo->prepare('INSERT INTO `menu_items` (`menu_name`, `node_id`, `title`, `url`, `weight`, `parent_id`) VALUES (?, ?, ?, ?, ?, ?)');
            $menu_stmt->execute(['main', $home_id, 'Home', '/', 0, null]);
            $menu_stmt->execute(['main', $about_id, 'About Us', '/about-us', 10, null]);
            $parent_about_id = (int)$pdo->lastInsertId();
            $menu_stmt->execute(['main', $prog_id, 'Program Areas', '/program-areas', 20, null]);
            $menu_stmt->execute(['main', null, 'News & Releases', '/news', 30, null]);
            $menu_stmt->execute(['main', $contact_id, 'Contact', '/contact', 40, null]);
            $menu_stmt->execute(['main', null, 'Site Tours', '/tours', 10, $parent_about_id]);

            // Seed Sidebar Blocks (Outreach block & Related News system block)
            $pdo->exec("
                INSERT IGNORE INTO `blocks` (`title`, `region`, `body`, `weight`, `status`, `visibility_pages`, `visibility_mode`, `visibility_types`, `visibility_roles`) VALUES
                ('Paducah Community Outreach', 'sidebar', '<p>FRNP supports educational development, STEM initiatives, and community service across western Kentucky.</p>', 10, 1, '', 'show_only', '', ''),
                ('Related News', 'sidebar', '[similar_content:2]', 25, 1, '', 'show_only', 'article', '');
            ");

            // Write Generated `config/database.php`
            $config_dir = __DIR__ . '/config';
            if (!is_dir($config_dir)) {
                mkdir($config_dir, 0755, true);
            }
            $db_config_code = "<?php\ndeclare(strict_types=1);\n\ndefine('DB_HOST', " . var_export($db_host, true) . ");\ndefine('DB_PORT', " . (int)$db_port . ");\ndefine('DB_NAME', " . var_export($db_name, true) . ");\ndefine('DB_USER', " . var_export($db_user, true) . ");\ndefine('DB_PASS', " . var_export($db_pass, true) . ");\n\nfunction get_db(): \\PDO {\n    static \$pdo = null;\n    if (\$pdo === null) {\n        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);\n        \$pdo = new \\PDO(\$dsn, DB_USER, DB_PASS, [\n            \\PDO::ATTR_ERRMODE            => \\PDO::ERRMODE_EXCEPTION,\n            \\PDO::ATTR_DEFAULT_FETCH_MODE => \\PDO::FETCH_ASSOC,\n            \\PDO::ATTR_EMULATE_PREPARES   => false,\n        ]);\n    }\n    return \$pdo;\n}\n";
            file_put_contents($config_dir . '/database.php', $db_config_code);

            // Ensure Uploads & System Folders Exist (including custom directories)
            $subdirs = [
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/files',
                __DIR__ . '/uploads/attachments',
                __DIR__ . '/custom_pages'
            ];
            foreach ($subdirs as $d) {
                if (!is_dir($d)) {
                    mkdir($d, 0755, true);
                }
            }

            // Write Security Lock File
            file_put_contents($lock_file, "Installed on " . date('Y-m-d H:i:s') . " by UID {$admin_uid}\n");
            $success = true;
        } catch (\Throwable $e) {
            $errors[] = 'Database installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Deployment & Installation Wizard</title>
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
            padding: 24px 20px;
            border-bottom: 4px solid var(--frnp-gold);
            text-align: center;
        }
        .header h1 { font-size: 1.5rem; letter-spacing: 0.5px; }
        .header p { color: #94a3b8; font-size: 0.88rem; margin-top: 4px; }
        .container {
            max-width: 820px;
            margin: 30px auto 0;
            background: #ffffff;
            border: 1px solid var(--frnp-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            padding: 35px 40px;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-size: 0.92rem;
        }
        .alert-danger { background: #fef2f2; border-left: 4px solid #b91c1c; color: #991b1b; }
        .alert-success { background: #ecfdf5; border-left: 4px solid #059669; color: #065f46; }
        .env-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 0.88rem;
        }
        .env-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .status-pass { color: #059669; font-weight: 700; text-align: right; }
        .status-fail { color: #dc2626; font-weight: 700; text-align: right; }
        .section-title {
            color: var(--frnp-navy);
            font-size: 1.15rem;
            margin: 25px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f1f5f9;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 15px;
        }
        .form-row { margin-bottom: 15px; }
        .form-row label {
            display: block;
            font-size: 0.86rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }
        .form-row input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--frnp-border);
            border-radius: 4px;
            font-size: 0.9rem;
            background: #fff;
        }
        .form-row input:focus {
            outline: none;
            border-color: var(--frnp-navy);
            box-shadow: 0 0 0 2px rgba(11, 37, 69, 0.15);
        }
        .btn-install {
            background: var(--frnp-navy);
            color: #fff;
            padding: 12px 28px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: background 0.15s ease;
        }
        .btn-install:hover { background: var(--frnp-gold); }
    </style>
</head>
<body>
    <div class="header">
        <h1>FOUR RIVERS NUCLEAR PARTNERSHIP</h1>
        <p>CMS Platform Deployment & Database Provisioning Wizard</p>
    </div>
    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success">
                <h3 style="margin-bottom:8px; font-size:1.1rem;">Installation Completed Successfully!</h3>
                <p>All database tables, Pathauto rules, custom events, telemetry handlers, views engine, registration workflows, and default starter nodes have been created.</p>
                <p style="margin-top:8px;">The installation lock (<code>config/installed.lock</code>) has been written to prevent duplicate setup executions.</p>
            </div>
            <div style="text-align: center; margin-top: 30px; display:flex; gap:15px; justify-content:center;">
                <a href="<?= htmlspecialchars($login_url) ?>" style="background:#0b2545; color:#fff; text-decoration:none; padding:12px 24px; border-radius:4px; font-weight:600;">Log In to Dashboard</a>
                <a href="<?= htmlspecialchars($home_url) ?>" style="background:#f1f5f9; color:#0b2545; border:1px solid #cbd5e1; text-decoration:none; padding:12px 24px; border-radius:4px; font-weight:600;">View Published Site</a>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div>&bull; <?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 class="section-title">1. Server Environment Verification</h3>
            <table class="env-table">
                <tbody>
                    <?php foreach ($env_checks as $check => $passed): ?>
                        <tr>
                            <td><?= htmlspecialchars($check) ?></td>
                            <td class="<?= $passed ? 'status-pass' : 'status-fail' ?>">
                                <?= $passed ? 'PASS &#10003;' : 'FAIL &#10007;' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$can_install): ?>
                <div class="alert alert-danger">
                    Please correct the failed environment requirements above (e.g. directory permissions on <code>/config</code> and <code>/uploads</code>, or enable GD) before proceeding.
                </div>
            <?php else: ?>
                <form method="POST">
                    <h3 class="section-title">2. Database Credentials (Blank Database)</h3>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>MySQL Host *</label>
                            <input type="text" name="db_host" value="127.0.0.1" required>
                        </div>
                        <div class="form-row">
                            <label>Port</label>
                            <input type="number" name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>Database Name *</label>
                            <input type="text" name="db_name" placeholder="frnp_cms" required autofocus>
                        </div>
                        <div class="form-row">
                            <label>Database Username *</label>
                            <input type="text" name="db_user" placeholder="root or db_user" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Database Password</label>
                        <input type="password" name="db_pass" placeholder="Leave blank if no password">
                    </div>

                    <h3 class="section-title">3. Site Name & Administrator Account</h3>
                    <div class="form-row">
                        <label>Site / Organization Name *</label>
                        <input type="text" name="site_name" value="Four Rivers Nuclear Partnership" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>Admin Username *</label>
                            <input type="text" name="admin_user" value="admin" required>
                        </div>
                        <div class="form-row">
                            <label>Admin Email *</label>
                            <input type="email" name="admin_email" placeholder="communications@fourriversnuclearpartnership.com" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Admin Password (8+ characters) *</label>
                        <input type="password" name="admin_pass" minlength="8" required>
                    </div>
                    <button type="submit" class="btn-install">Initialize Database & Install Platform</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>