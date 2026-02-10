-- ============================================================================
-- Poweradmin 4.2.0 Migration (MySQL)
-- ============================================================================

-- Rename default permission templates for consistency
-- DNS Editor -> Editor, Read Only -> Viewer, No Access -> Guest
UPDATE `perm_templ` SET `name` = 'Editor', `descr` = 'Edit own zone records but cannot modify SOA and NS records.'
WHERE `name` = 'DNS Editor';

UPDATE `perm_templ` SET `name` = 'Viewer', `descr` = 'Read-only access to own zones with search capability.'
WHERE `name` = 'Read Only';

UPDATE `perm_templ` SET `name` = 'Guest', `descr` = 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.'
WHERE `name` = 'No Access';

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

-- ============================================================================
-- Per-Record Comments Support (Issue #858)
-- ============================================================================
-- Create linking table to associate individual records with comments
-- This allows per-record comments instead of per-RRset comments
-- The PowerDNS comments table stores comments by (domain_id, name, type),
-- this linking table maps individual record IDs to specific comment IDs

CREATE TABLE IF NOT EXISTS `record_comment_links` (
    `record_id` INT NOT NULL,
    `comment_id` INT NOT NULL,
    PRIMARY KEY (`record_id`),
    UNIQUE KEY `idx_record_comment_links_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
