CREATE DATABASE IF NOT EXISTS `frnp_cms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `frnp_cms`;

-- User & Authentication Tables
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(60) NOT NULL UNIQUE,
    `email` VARCHAR(254) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `machine_name` VARCHAR(32) NOT NULL UNIQUE,
    `label` VARCHAR(64) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE `user_roles` (
    `user_id` INT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `permissions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `machine_name` VARCHAR(64) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Content Architecture Tables (Drupal Node/Type Clone)
CREATE TABLE `content_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(32) NOT NULL UNIQUE,
    `name` VARCHAR(64) NOT NULL,
    `description` TEXT NULL,
    `has_body` TINYINT(1) NOT NULL DEFAULT 1,
    `fields_schema` JSON NULL, -- Defines custom extra fields (text, date, file, etc.)
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `nodes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(32) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `body` MEDIUMTEXT NULL,
    `fields_data` JSON NULL, -- Dynamic values matching fields_schema
    `uid` INT UNSIGNED NOT NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1, -- 1=Published, 0=Draft
    `template` VARCHAR(64) NULL, -- Custom template override if needed
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`type`) REFERENCES `content_types`(`type`) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (`uid`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Default Seed Data
INSERT INTO `roles` (`id`, `machine_name`, `label`) VALUES
(1, 'administrator', 'Administrator'),
(2, 'editor', 'Content Editor'),
(3, 'authenticated', 'Authenticated User');

INSERT INTO `permissions` (`id`, `machine_name`, `description`) VALUES
(1, 'administer_site', 'Full administrative access'),
(2, 'administer_users', 'Create and modify user accounts and roles'),
(3, 'administer_content_types', 'Create and modify content types and schemas'),
(4, 'create_content', 'Create new pages, articles, and nodes'),
(5, 'edit_any_content', 'Edit nodes created by any user'),
(6, 'delete_any_content', 'Delete nodes created by any user');

-- Grant all permissions to Administrator
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Editor permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(2, 4), (2, 5);

-- Seed Initial Content Types
INSERT INTO `content_types` (`type`, `name`, `description`, `has_body`, `fields_schema`) VALUES
('page', 'Basic Page', 'Standard static informational page (e.g., About, Environmental Services).', 1, '[]'),
('article', 'News & Updates', 'Time-sensitive press releases, news items, and announcements.', 1, 
 '[{"name": "subtitle", "label": "Subtitle / Deck", "type": "text"}, {"name": "publication_date", "label": "Original Release Date", "type": "date"}]');

-- Default Admin User (Password: Admin@FRNP2026!)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `status`) VALUES
(1, 'admin', 'admin@fourriversnuclearpartnership.com', '$2y$10$w850cQ4u.rU4l5v/Bfvq..bCcfQ5p9oWlW5o70W0t6xZ83B6b6Hde', 1);

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (1, 1);