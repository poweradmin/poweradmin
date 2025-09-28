-- Fix password_reset_tokens used field default value if it exists but lacks proper default
-- This handles cases where the 4.0.0 migration created the table but the used field doesn't have DEFAULT 0
ALTER TABLE `password_reset_tokens` MODIFY `used` TINYINT(1) NOT NULL DEFAULT 0;

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