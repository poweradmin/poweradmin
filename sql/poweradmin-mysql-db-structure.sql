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
                             PRIMARY KEY (`id`)
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
                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`) VALUES
    (1,	'admin',	'$2y$12$10ei/WGJPcUY9Ea8/eVage9zBbxr0xxW82qJF/cfSyev/jX84WHQe',	'Administrator',	'admin@example.net',	'Administrator with full rights.',	1,	1,	0);

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


CREATE TABLE `migrations` (
                              `version` bigint(20) NOT NULL,
                              `migration_name` varchar(100) DEFAULT NULL,
                              `start_time` timestamp NULL DEFAULT NULL,
                              `end_time` timestamp NULL DEFAULT NULL,
                              `breakpoint` tinyint(1) NOT NULL DEFAULT '0',
                              PRIMARY KEY (`version`)
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
                                                     (64,	'zone_templ_edit',	'User is allowed to edit existing zone templates.');

CREATE TABLE `perm_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ` (`id`, `name`, `descr`) VALUES
    (1,	'Administrator',	'Administrator template with full rights.');

CREATE TABLE `perm_templ_items` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `templ_id` int(11) NOT NULL,
                                    `perm_id` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ_items` (`id`, `templ_id`, `perm_id`) VALUES
    (1,	1,	53);

CREATE TABLE `records_zone_templ` (
                                      `domain_id` int(11) NOT NULL,
                                      `record_id` int(11) NOT NULL,
                                      `zone_templ_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `zones` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `domain_id` int(11) NOT NULL,
                         `owner` int(11) NOT NULL,
                         `comment` varchar(1024) DEFAULT NULL,
                         `zone_templ_id` int(11) NOT NULL,
                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `zone_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              `owner` int(11) NOT NULL,
                              `created_by` int(11) DEFAULT NULL,
                              PRIMARY KEY (`id`),
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
                                      PRIMARY KEY (`id`)
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
