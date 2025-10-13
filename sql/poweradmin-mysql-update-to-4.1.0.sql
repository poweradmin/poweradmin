-- Add API key management permission
INSERT INTO `perm_items` (`id`, `name`, `descr`) VALUES
(65, 'api_manage_keys', 'User is allowed to create and manage API keys.');

-- Add authentication method column to users table
ALTER TABLE `users` ADD COLUMN `auth_method` VARCHAR(20) NOT NULL DEFAULT 'sql' AFTER `use_ldap`;

-- Update existing LDAP users to use 'ldap' auth method
UPDATE `users` SET `auth_method` = 'ldap' WHERE `use_ldap` = 1;

-- Add OIDC user links table for OpenID Connect authentication
CREATE TABLE IF NOT EXISTS `oidc_user_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `provider_id` VARCHAR(50) NOT NULL,
  `oidc_subject` VARCHAR(255) NOT NULL,
  `username` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_provider` (`user_id`, `provider_id`),
  UNIQUE KEY `unique_subject_provider` (`oidc_subject`, `provider_id`),
  KEY `idx_provider_id` (`provider_id`),
  KEY `idx_oidc_subject` (`oidc_subject`),
  CONSTRAINT `fk_oidc_user_links_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add SAML user links table for SAML authentication
CREATE TABLE IF NOT EXISTS `saml_user_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `provider_id` VARCHAR(50) NOT NULL,
  `saml_subject` VARCHAR(255) NOT NULL,
  `username` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_provider` (`user_id`, `provider_id`),
  UNIQUE KEY `unique_subject_provider` (`saml_subject`, `provider_id`),
  KEY `idx_provider_id` (`provider_id`),
  KEY `idx_saml_subject` (`saml_subject`),
  CONSTRAINT `fk_saml_user_links_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add performance indexes to existing tables
-- Issue: Missing indexes on foreign key columns caused slow queries with large datasets

ALTER TABLE `log_zones` ADD INDEX `idx_log_zones_zone_id` (`zone_id`);
ALTER TABLE `users` ADD INDEX `idx_users_perm_templ` (`perm_templ`);
ALTER TABLE `perm_templ_items` ADD INDEX `idx_perm_templ_items_templ_id` (`templ_id`);
ALTER TABLE `perm_templ_items` ADD INDEX `idx_perm_templ_items_perm_id` (`perm_id`);
ALTER TABLE `records_zone_templ` ADD INDEX `idx_records_zone_templ_domain_id` (`domain_id`);
ALTER TABLE `records_zone_templ` ADD INDEX `idx_records_zone_templ_zone_templ_id` (`zone_templ_id`);
ALTER TABLE `zones` ADD INDEX `idx_zones_zone_templ_id` (`zone_templ_id`);
ALTER TABLE `zone_templ` ADD INDEX `idx_zone_templ_owner` (`owner`);
ALTER TABLE `zone_templ` ADD INDEX `idx_zone_templ_created_by` (`created_by`);
ALTER TABLE `zone_templ_records` ADD INDEX `idx_zone_templ_records_zone_templ_id` (`zone_templ_id`);

-- Add username_recovery_requests table for username recovery functionality
-- This table tracks username recovery requests for rate limiting and security
CREATE TABLE IF NOT EXISTS `username_recovery_requests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `email` varchar(255) NOT NULL,
    `ip_address` varchar(45) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_urr_email` (`email`),
    KEY `idx_urr_ip` (`ip_address`),
    KEY `idx_urr_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add zone deletion permissions (Issue #97)
-- Separate permissions for zone deletion to allow fine-grained control
-- Previously, zone deletion was tied to zone_content_edit_* permissions
INSERT INTO `perm_items` (`id`, `name`, `descr`) VALUES
    (67, 'zone_delete_own', 'User is allowed to delete zones they own.'),
    (68, 'zone_delete_others', 'User is allowed to delete zones owned by others.');

-- Grant delete permissions to users with existing edit permissions
-- This ensures backward compatibility - users who could edit can now delete
-- Users with edit_own get delete_own
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT DISTINCT templ_id, 67
FROM `perm_templ_items`
WHERE perm_id = 44; -- zone_content_edit_own

-- Users with edit_others get delete_others
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT DISTINCT templ_id, 68
FROM `perm_templ_items`
WHERE perm_id = 47; -- zone_content_edit_others

-- Note: Users with 'user_is_ueberuser' (id 53) automatically have all permissions