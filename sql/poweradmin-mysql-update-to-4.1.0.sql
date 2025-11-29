-- Add API key management permission
INSERT IGNORE INTO `perm_items` (`name`, `descr`) VALUES
('api_manage_keys', 'User is allowed to create and manage API keys.');

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
INSERT IGNORE INTO `perm_items` (`name`, `descr`) VALUES
    ('zone_delete_own', 'User is allowed to delete zones they own.'),
    ('zone_delete_others', 'User is allowed to delete zones owned by others.');

-- Grant delete permissions to users with existing edit permissions
-- This ensures backward compatibility - users who could edit can now delete
-- Users with edit_own get delete_own
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT DISTINCT templ_id, (SELECT id FROM `perm_items` WHERE name = 'zone_delete_own')
FROM `perm_templ_items`
WHERE perm_id = 44; -- zone_content_edit_own

-- Users with edit_others get delete_others
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT DISTINCT templ_id, (SELECT id FROM `perm_items` WHERE name = 'zone_delete_others')
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

-- Editor: Basic record editing without SOA/NS access
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'Editor', 'Edit own zone records but cannot modify SOA and NS records.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Editor');

-- Viewer: View-only access for auditing
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'Viewer', 'Read-only access to own zones with search capability.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Viewer');

-- Guest: Placeholder for temporary/pending users
INSERT INTO `perm_templ` (`name`, `descr`)
SELECT 'Guest', 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Guest');

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

-- Assign permissions to Editor template
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Editor'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewer template
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Viewer'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Guest template intentionally has no permissions assigned

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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_zone_group` (`domain_id`, `group_id`),
    KEY `idx_domain_id` (`domain_id`),
    KEY `idx_group_id` (`group_id`),
    CONSTRAINT `fk_zones_groups_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: log_groups
-- Description: Audit log for group operations (create, update, delete, member/zone changes)
CREATE TABLE IF NOT EXISTS `log_groups` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `event` VARCHAR(2048) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `priority` INT(11) NOT NULL,
    `group_id` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_log_groups_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ============================================================================
-- Permission Template Types (distinguish user vs group templates)
-- ============================================================================

-- Add template_type column to perm_templ table
ALTER TABLE `perm_templ` ADD COLUMN `template_type` ENUM('user','group') NOT NULL DEFAULT 'user' AFTER `descr`;

-- Set default template type for all existing templates
-- All existing templates default to 'user' type
-- Administrators can change this later if templates are used for groups
UPDATE `perm_templ` SET `template_type` = 'user' WHERE `template_type` = 'user';

-- ============================================================================
-- Group-type Permission Templates
-- ============================================================================
-- Create permission templates with template_type='group' for use with user groups
-- These have the same permissions as user templates but are specifically for groups

INSERT INTO `perm_templ` (`name`, `descr`, `template_type`)
SELECT 'Administrators', 'Full administrative access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Administrators' AND `template_type` = 'group');

INSERT INTO `perm_templ` (`name`, `descr`, `template_type`)
SELECT 'Zone Managers', 'Full zone management for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Zone Managers' AND `template_type` = 'group');

INSERT INTO `perm_templ` (`name`, `descr`, `template_type`)
SELECT 'Editors', 'Edit zone records (no SOA/NS) for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Editors' AND `template_type` = 'group');

INSERT INTO `perm_templ` (`name`, `descr`, `template_type`)
SELECT 'Viewers', 'Read-only zone access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Viewers' AND `template_type` = 'group');

INSERT INTO `perm_templ` (`name`, `descr`, `template_type`)
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `name` = 'Guests' AND `template_type` = 'group');

-- Guests group template intentionally has no permissions assigned

-- Assign permissions to Administrators group template (same as Administrator user template)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND pi.name = 'user_is_ueberuser'
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Zone Managers group template (same as Zone Manager user template)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Editors group template (same as DNS Editor user template)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewers group template (same as Read Only user template)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pt.id, pi.id
FROM `perm_templ` pt
CROSS JOIN `perm_items` pi
WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM `perm_templ_items` pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- ============================================================================
-- Default User Groups
-- ============================================================================
-- Create default user groups that map to group-type permission templates
-- These groups can be used for LDAP group mapping in the future

INSERT INTO `user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT 'Administrators', 'Full administrative access to all system functions.', pt.id, NULL
FROM `perm_templ` pt WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Administrators');

INSERT INTO `user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT 'Zone Managers', 'Full zone management including creation, editing, and deletion.', pt.id, NULL
FROM `perm_templ` pt WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Zone Managers');

INSERT INTO `user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT 'Editors', 'Edit zone records but cannot modify SOA and NS records.', pt.id, NULL
FROM `perm_templ` pt WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Editors');

INSERT INTO `user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT 'Viewers', 'Read-only access to zones with search capability.', pt.id, NULL
FROM `perm_templ` pt WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Viewers');

INSERT INTO `user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', pt.id, NULL
FROM `perm_templ` pt WHERE pt.name = 'Guests' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Guests');

-- Add MFA enforcement permission
-- This permission allows enforcing MFA for users/groups when mfa.enforced is enabled
INSERT IGNORE INTO `perm_items` (`name`, `descr`) VALUES
    ('user_enforce_mfa', 'User is required to use multi-factor authentication.');
