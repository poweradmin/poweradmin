-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE TABLE IF NOT EXISTS `log_record_changes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zone_id` int(11) DEFAULT NULL,
    `record_id` text DEFAULT NULL,
    `action` varchar(32) NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `username` varchar(64) NOT NULL,
    `before_state` text DEFAULT NULL,
    `after_state` text DEFAULT NULL,
    `client_ip` varchar(64) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_log_record_changes_created_at` (`created_at`),
    KEY `idx_log_record_changes_zone_id` (`zone_id`),
    KEY `idx_log_record_changes_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mapping table for API-backed zones whose record IDs are encoded strings
-- (RecordIdentifier base64url) and don't fit in records_zone_templ.record_id
-- (INT). Populated only when applying templates against PowerDNS via the API.
CREATE TABLE IF NOT EXISTS `records_zone_templ_api` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `domain_id` int(11) NOT NULL,
    `record_id` varchar(255) NOT NULL,
    `zone_templ_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_records_zone_templ_api_domain_id` (`domain_id`),
    KEY `idx_records_zone_templ_api_zone_templ_id` (`zone_templ_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-record-type default TTLs managed via the admin UI (closes #1032).
-- When a record is created without an explicit TTL, the server first looks
-- up the type in this table; if no row exists the legacy fallback chain
-- (dns.ttl_reverse for PTR, then dns.ttl) applies.
CREATE TABLE IF NOT EXISTS `record_type_defaults` (
    `record_type` varchar(20) NOT NULL,
    `ttl` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`record_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generic admin-managed settings, layered above config/settings.php.
-- AppSettingsService reads this table first and falls back to ConfigurationManager.
-- No setting is migrated automatically; this is plumbing for future features.
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` varchar(128) COLLATE utf8mb4_bin NOT NULL,
    `setting_value` text NOT NULL,
    `value_type` varchar(16) NOT NULL DEFAULT 'string',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Give zones.zone_templ_id an explicit DEFAULT 0 so inserts that omit the
-- column land on the same "no template" sentinel used by every active write
-- path (DomainManager, ZoneSyncService, RecordManager, ApiDnsBackendProvider).
ALTER TABLE `zones` MODIFY `zone_templ_id` int(11) NOT NULL DEFAULT 0;

-- Register zone_dnssec_manage_own so admins can grant DNSSEC key management
-- separately from general zone editing. Existing templates are NOT auto-granted
-- the new permission; admins must opt in via the permission template editor.
-- `perm_items.name` has no unique constraint, so guard against duplicates by hand.
INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_dnssec_manage_own', 'User is allowed to manage DNSSEC keys for zones he owns.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_dnssec_manage_own');

-- Widen password_reset_tokens.token so the new sha256$<64hex> storage format fits.
-- Plaintext rows issued before this upgrade naturally expire within the
-- token_lifetime (1 hour default) and remain unreadable to the new validator;
-- affected users simply request a new reset link.
ALTER TABLE `password_reset_tokens` MODIFY COLUMN `token` varchar(128) NOT NULL;

-- Tag each login_attempts row with the authentication stage that produced it
-- (password / mfa). Lets MfaVerifyController throttle the second factor without
-- letting a fresh first-factor success clear the MFA failure counter.
ALTER TABLE `login_attempts` ADD COLUMN `attempt_type` varchar(16) NOT NULL DEFAULT 'password';
ALTER TABLE `login_attempts` ADD KEY `idx_attempt_type` (`attempt_type`);

-- Granular API key permissions (closes #795). New columns and the api_key_zones
-- table are optional: existing keys get is_readonly=0, allowed_operations=NULL and
-- no zone rows, which means "unrestricted" - identical to the previous behavior.
ALTER TABLE `api_keys` ADD COLUMN `is_readonly` tinyint(1) NOT NULL DEFAULT '0';
ALTER TABLE `api_keys` ADD COLUMN `allowed_operations` varchar(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `api_key_zones` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `api_key_id` int(11) NOT NULL,
    `zone_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_api_key_zones_unique` (`api_key_id`, `zone_id`),
    KEY `idx_api_key_zones_api_key_id` (`api_key_id`),
    KEY `idx_api_key_zones_zone_id` (`zone_id`),
    CONSTRAINT `fk_api_key_zones_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
