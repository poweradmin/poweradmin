-- Adminer 4.8.1 MySQL 5.5.5-10.9.3-MariaDB-1:10.9.3+maria~ubu2204 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

CREATE TABLE `log_users` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `event` varchar(2048) NOT NULL,
                             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                             `priority` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `log_zones` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `event` varchar(2048) NOT NULL,
                             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                             `priority` int(11) NOT NULL,
                             `zone_id` int(11) DEFAULT NULL,
                             PRIMARY KEY (`id`),
                             KEY `idx_log_zones_zone_id` (`zone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `log_groups` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `event` varchar(2048) NOT NULL,
                              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                              `priority` int(11) NOT NULL,
                              `group_id` int(11) DEFAULT NULL,
                              PRIMARY KEY (`id`),
                              KEY `idx_log_groups_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `username` varchar(64) NOT NULL,
                         `password` varchar(128) NOT NULL,
                         `fullname` varchar(255) NOT NULL,
                         `email` varchar(255) NOT NULL,
                         `description` varchar(1024) NOT NULL,
                         `perm_templ` int(11) NOT NULL,
                         `active` int(1) NOT NULL,
                         `use_ldap` int(1) NOT NULL,
                         `auth_method` varchar(20) NOT NULL DEFAULT 'sql',
                         PRIMARY KEY (`id`),
                         KEY `idx_users_perm_templ` (`perm_templ`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `login_attempts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NULL,
    `ip_address` varchar(45) NOT NULL,
    `timestamp` int(11) NOT NULL,
    `successful` tinyint(1) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_timestamp` (`timestamp`),
    CONSTRAINT `fk_login_attempts_users`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE `perm_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(64) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_items` (`id`, `name`, `descr`) VALUES
                                                     (41,	'zone_master_add',	'User is allowed to add new master zones.'),
                                                     (42,	'zone_slave_add',	'User is allowed to add new slave zones.'),
                                                     (43,	'zone_content_view_own',	'User is allowed to see the content and meta data of zones he owns.'),
                                                     (44,	'zone_content_edit_own',	'User is allowed to edit the content of zones he owns.'),
                                                     (45,	'zone_meta_edit_own',	'User is allowed to edit the meta data of zones he owns.'),
                                                     (46,	'zone_content_view_others',	'User is allowed to see the content and meta data of zones he does not own.'),
                                                     (47,	'zone_content_edit_others',	'User is allowed to edit the content of zones he does not own.'),
                                                     (48,	'zone_meta_edit_others',	'User is allowed to edit the meta data of zones he does not own.'),
                                                     (49,	'search',	'User is allowed to perform searches.'),
                                                     (50,	'supermaster_view',	'User is allowed to view supermasters.'),
                                                     (51,	'supermaster_add',	'User is allowed to add new supermasters.'),
                                                     (52,	'supermaster_edit',	'User is allowed to edit supermasters.'),
                                                     (53,	'user_is_ueberuser',	'User has full access. God-like. Redeemer.'),
                                                     (54,	'user_view_others',	'User is allowed to see other users and their details.'),
                                                     (55,	'user_add_new',	'User is allowed to add new users.'),
                                                     (56,	'user_edit_own',	'User is allowed to edit their own details.'),
                                                     (57,	'user_edit_others',	'User is allowed to edit other users.'),
                                                     (58,	'user_passwd_edit_others',	'User is allowed to edit the password of other users.'),
                                                     (59,	'user_edit_templ_perm',	'User is allowed to change the permission template that is assigned to a user.'),
                                                     (60,	'templ_perm_add',	'User is allowed to add new permission templates.'),
                                                     (61,	'templ_perm_edit',	'User is allowed to edit existing permission templates.'),
                                                     (62,	'zone_content_edit_own_as_client',	'User is allowed to edit record, but not SOA and NS.'),
                                                     (63,	'zone_templ_add',	'User is allowed to add new zone templates.'),
                                                     (64,	'zone_templ_edit',	'User is allowed to edit existing zone templates.'),
                                                     (65,	'api_manage_keys',	'User is allowed to create and manage API keys.'),
                                                     (67,	'zone_delete_own',	'User is allowed to delete zones they own.'),
                                                     (68,	'zone_delete_others',	'User is allowed to delete zones owned by others.'),
                                                     (69,	'user_enforce_mfa',	'User is required to use multi-factor authentication.');

CREATE TABLE `perm_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              `template_type` enum('user','group') NOT NULL DEFAULT 'user',
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ` (`id`, `name`, `descr`, `template_type`) VALUES
    (1,	'Administrator',	'Administrator template with full rights.',	'user'),
    (2,	'Zone Manager',	'Full management of own zones including creation, editing, deletion, and templates.',	'user'),
    (3,	'Editor',	'Edit own zone records but cannot modify SOA and NS records.',	'user'),
    (4,	'Viewer',	'Read-only access to own zones with search capability.',	'user'),
    (5,	'Guest',	'Temporary access with no permissions. Suitable for users awaiting approval or limited access.',	'user'),
    (6,	'Administrators',	'Full administrative access for group members.',	'group'),
    (7,	'Zone Managers',	'Full zone management for group members.',	'group'),
    (8,	'Editors',	'Edit zone records (no SOA/NS) for group members.',	'group'),
    (9,	'Viewers',	'Read-only zone access for group members.',	'group'),
    (10,	'Guests',	'Temporary group with no permissions. Suitable for users awaiting approval.',	'group');

CREATE TABLE `perm_templ_items` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `templ_id` int(11) NOT NULL,
                                    `perm_id` int(11) NOT NULL,
                                    PRIMARY KEY (`id`),
                                    KEY `idx_perm_templ_items_templ_id` (`templ_id`),
                                    KEY `idx_perm_templ_items_perm_id` (`perm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ_items` (`id`, `templ_id`, `perm_id`) VALUES
    (1,	1,	53),
    (2,	2,	41),
    (3,	2,	42),
    (4,	2,	43),
    (5,	2,	44),
    (6,	2,	45),
    (7,	2,	49),
    (8,	2,	56),
    (9,	2,	63),
    (10,	2,	64),
    (11,	2,	65),
    (12,	2,	67),
    (13,	3,	43),
    (14,	3,	49),
    (15,	3,	56),
    (16,	3,	62),
    (17,	4,	43),
    (18,	4,	49),
    (19,	6,	53),
    (20,	7,	41),
    (21,	7,	42),
    (22,	7,	43),
    (23,	7,	44),
    (24,	7,	45),
    (25,	7,	49),
    (26,	7,	56),
    (27,	7,	63),
    (28,	7,	64),
    (29,	7,	65),
    (30,	7,	67),
    (31,	8,	43),
    (32,	8,	49),
    (33,	8,	56),
    (34,	8,	62),
    (35,	9,	43),
    (36,	9,	49);

CREATE TABLE `records_zone_templ` (
                                      `domain_id` int(11) NOT NULL,
                                      `record_id` int(11) NOT NULL,
                                      `zone_templ_id` int(11) NOT NULL,
                                      KEY `idx_records_zone_templ_domain_id` (`domain_id`),
                                      KEY `idx_records_zone_templ_zone_templ_id` (`zone_templ_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `zones` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `domain_id` int(11) NOT NULL,
                         `owner` int(11) NULL DEFAULT NULL,
                         `comment` varchar(1024) DEFAULT NULL,
                         `zone_templ_id` int(11) NOT NULL,
                         PRIMARY KEY (`id`),
                         KEY `idx_zones_domain_id` (`domain_id`),
                         KEY `idx_zones_owner` (`owner`),
                         KEY `idx_zones_zone_templ_id` (`zone_templ_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `zone_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              `owner` int(11) NOT NULL,
                              `created_by` int(11) DEFAULT NULL,
                              PRIMARY KEY (`id`),
                              KEY `idx_zone_templ_owner` (`owner`),
                              KEY `idx_zone_templ_created_by` (`created_by`),
                              CONSTRAINT `fk_zone_templ_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `zone_templ_records` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `zone_templ_id` int(11) NOT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `type` varchar(6) NOT NULL,
                                      `content` varchar(2048) NOT NULL,
                                      `ttl` int(11) NOT NULL,
                                      `prio` int(11) NOT NULL,
                                      PRIMARY KEY (`id`),
                                      KEY `idx_zone_templ_records_zone_templ_id` (`zone_templ_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `api_keys` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `secret_key` varchar(255) NOT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` timestamp NULL DEFAULT NULL,
    `disabled` tinyint(1) NOT NULL DEFAULT '0',
    `expires_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_api_keys_secret_key` (`secret_key`),
    KEY `idx_api_keys_created_by` (`created_by`),
    KEY `idx_api_keys_disabled` (`disabled`),
    CONSTRAINT `fk_api_keys_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_mfa` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT 0,
    `secret` varchar(255) DEFAULT NULL,
    `recovery_codes` text DEFAULT NULL,
    `type` varchar(20) NOT NULL DEFAULT 'app',
    `last_used_at` datetime DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `verification_data` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_mfa_user_id` (`user_id`),
    KEY `idx_user_mfa_enabled` (`enabled`),
    CONSTRAINT `fk_user_mfa_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_preferences` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `preference_key` varchar(100) NOT NULL,
    `preference_value` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_preferences_user_key` (`user_id`, `preference_key`),
    KEY `idx_user_preferences_user_id` (`user_id`),
    CONSTRAINT `fk_user_preferences_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `zone_template_sync` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zone_id` int(11) NOT NULL,
    `zone_templ_id` int(11) NOT NULL,
    `last_synced` timestamp NULL DEFAULT NULL,
    `template_last_modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `needs_sync` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_zone_template_unique` (`zone_id`, `zone_templ_id`),
    KEY `idx_zone_templ_id` (`zone_templ_id`),
    KEY `idx_needs_sync` (`needs_sync`),
    CONSTRAINT `fk_zone_template_sync_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_zone_template_sync_templ` FOREIGN KEY (`zone_templ_id`) REFERENCES `zone_templ` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2022-09-29 19:08:10

CREATE TABLE `password_reset_tokens` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `email` varchar(255) NOT NULL,
    `token` varchar(64) NOT NULL,
    `expires_at` timestamp NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `used` tinyint(1) NOT NULL DEFAULT 0,
    `ip_address` varchar(45) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token` (`token`),
    KEY `idx_email` (`email`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `username_recovery_requests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `email` varchar(255) NOT NULL,
    `ip_address` varchar(45) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_urr_email` (`email`),
    KEY `idx_urr_ip` (`ip_address`),
    KEY `idx_urr_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_agreements` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `agreement_version` varchar(50) NOT NULL,
    `accepted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_user_agreement` (`user_id`, `agreement_version`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_agreement_version` (`agreement_version`),
    CONSTRAINT `fk_user_agreements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `oidc_user_links` (
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

CREATE TABLE `saml_user_links` (
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

CREATE TABLE `user_groups` (
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

INSERT INTO `user_groups` (`id`, `name`, `description`, `perm_templ`, `created_by`) VALUES
    (1, 'Administrators', 'Full administrative access to all system functions.', 6, NULL),
    (2, 'Zone Managers', 'Full zone management including creation, editing, and deletion.', 7, NULL),
    (3, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 8, NULL),
    (4, 'Viewers', 'Read-only access to zones with search capability.', 9, NULL),
    (5, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 10, NULL);

CREATE TABLE `user_group_members` (
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

CREATE TABLE `zones_groups` (
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

CREATE TABLE `record_comment_links` (
    `record_id` INT NOT NULL,
    `comment_id` INT NOT NULL,
    PRIMARY KEY (`record_id`),
    UNIQUE KEY `idx_record_comment_links_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
