-- 1. Public Comments Period & Settings
CREATE TABLE IF NOT EXISTS `public_comments_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Public Comment Period',
  `description` TEXT NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `closed_message` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default comment window (active for 30 days)
INSERT INTO `public_comments_settings` (`id`, `title`, `description`, `start_date`, `end_date`, `is_enabled`, `closed_message`) 
VALUES (
  1, 
  'Formal Public Comment Submission', 
  'We invite formal public comments regarding ongoing site projects and operational evaluations.', 
  NOW(), 
  DATE_ADD(NOW(), INTERVAL 30 DAY), 
  1, 
  'The public comment period for this matter is currently closed. Submissions are no longer being accepted.'
) ON DUPLICATE KEY UPDATE `id`=`id`;

-- 2. Public Comments Submissions Table
CREATE TABLE IF NOT EXISTS `public_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `comment` LONGTEXT NOT NULL,
  `visitor_id` VARCHAR(64) NOT NULL,
  `session_id` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `geo_country` VARCHAR(100) NULL,
  `geo_region` VARCHAR(100) NULL,
  `geo_city` VARCHAR(100) NULL,
  `geo_org` VARCHAR(255) NULL,
  `user_agent` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visitor_id` (`visitor_id`),
  KEY `idx_email` (`email`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Visitor Traffic & Activity Log
CREATE TABLE IF NOT EXISTS `visitor_activity_log` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `visitor_id` VARCHAR(64) NOT NULL,
  `session_id` VARCHAR(64) NULL,
  `event_type` ENUM('pageview', 'dwell_time', 'link_click', 'file_download') NOT NULL DEFAULT 'pageview',
  `page_url` VARCHAR(1000) NOT NULL,
  `page_title` VARCHAR(255) NULL,
  `target_url` VARCHAR(1000) NULL,
  `dwell_seconds` INT(11) NOT NULL DEFAULT 0,
  `referrer` VARCHAR(1000) NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `meta_data` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visitor_activity` (`visitor_id`, `created_at`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
