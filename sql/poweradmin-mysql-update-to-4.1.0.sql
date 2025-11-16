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

-- Add standard permission templates for common use cases
-- These templates provide out-of-the-box permission profiles for typical DNS hosting scenarios
-- If a template with the same name already exists, this will be skipped (WHERE NOT EXISTS)

-- Zone Manager: Full self-service zone management
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Zone Manager');

-- DNS Editor: Basic record editing without SOA/NS access
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'DNS Editor', 'Edit own zone records but cannot modify SOA and NS records.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'DNS Editor');

-- Read Only: View-only access for auditing
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'Read Only', 'Read-only access to own zones with search capability.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Read Only');

-- No Access: Placeholder for inactive/suspended accounts
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'No Access', 'Template with no permissions assigned. Suitable for inactive accounts or users pending permission assignment.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'No Access');

-- Assign permissions to Zone Manager template
-- Only insert if the template exists and permission doesn't already exist
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Zone Manager'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to DNS Editor template
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'DNS Editor'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Read Only template
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Read Only'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- No Access template intentionally has no permissions assigned

-- ============================================================================
-- Group-Based Permissions (Issue #480)
-- ============================================================================

-- Table: user_groups
-- Description: Stores user groups with permission templates
CREATE TABLE IF NOT EXISTS `user_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `perm_templ` INT NOT NULL,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_name` (`name`),
    KEY `idx_perm_templ` (`perm_templ`),
    KEY `idx_created_by` (`created_by`),
    KEY `idx_name` (`name`(191)),
    CONSTRAINT `fk_user_groups_perm_templ` FOREIGN KEY (`perm_templ`) REFERENCES `perm_templ`(`id`),
    CONSTRAINT `fk_user_groups_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: user_group_members
-- Description: Junction table for user-group membership (many-to-many)
CREATE TABLE IF NOT EXISTS `user_group_members` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id` INT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_member` (`group_id`, `user_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_group_id` (`group_id`),
    CONSTRAINT `fk_user_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_group_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modify zones table to allow nullable owner for group-only ownership
-- Description: Allow zones to be owned only by groups without requiring a user owner
ALTER TABLE `zones` MODIFY `owner` INT(11) NULL DEFAULT NULL;

-- Table: zones_groups
-- Description: Junction table for zone-group ownership (many-to-many)
CREATE TABLE IF NOT EXISTS `zones_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` INT NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `zone_templ_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_zone_group` (`domain_id`, `group_id`),
    KEY `idx_domain_id` (`domain_id`),
    KEY `idx_group_id` (`group_id`),
    KEY `idx_zone_templ_id` (`zone_templ_id`),
    CONSTRAINT `fk_zones_groups_domain` FOREIGN KEY (`domain_id`) REFERENCES `domains`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_zones_groups_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_zones_groups_zone_templ` FOREIGN KEY (`zone_templ_id`) REFERENCES `zone_templ`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;