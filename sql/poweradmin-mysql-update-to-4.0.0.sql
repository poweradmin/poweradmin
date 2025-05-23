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

-- Add zone template permissions
INSERT INTO `perm_items` (`id`, `name`, `descr`) VALUES
(63, 'zone_templ_add', 'User is allowed to add new zone templates.'),
(64, 'zone_templ_edit', 'User is allowed to edit existing zone templates.');

-- Add created_by column to zone_templ table
ALTER TABLE `zone_templ` ADD COLUMN `created_by` int(11) DEFAULT NULL;
UPDATE `zone_templ` SET `created_by` = `owner` WHERE `owner` != 0;
ALTER TABLE `zone_templ` ADD CONSTRAINT `fk_zone_templ_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Add user_preferences table
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
