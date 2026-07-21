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

-- Register dedicated log-view permissions so admins can grant access to the
-- activity logs without granting ueberuser. Zone logs split into own/others
-- like zone_content_view; user and group logs are global. Existing templates
-- are NOT auto-granted these; admins opt in via the permission template editor.
INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_logs_view_own', 'User is allowed to view activity logs for zones he owns.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_logs_view_own');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_logs_view_others', 'User is allowed to view activity logs for zones he does not own.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_logs_view_others');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'user_logs_view', 'User is allowed to view the user activity logs.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'user_logs_view');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'group_logs_view', 'User is allowed to view the group activity logs.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'group_logs_view');

-- Register zone_content_edit_ns_subzone so client-level editors can manage
-- delegation NS records below the zone apex. Existing templates are NOT
-- auto-granted the new permission; admins opt in via the permission template editor.
INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_content_edit_ns_subzone', 'User is allowed to edit NS records below the zone apex, but not SOA and apex NS records.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_content_edit_ns_subzone');

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

-- OIDC/SAML external subject identifiers must match one-for-one. The default
-- utf8mb4_unicode_ci collation is case- and accent-insensitive, so distinct
-- subjects such as "victim" and "victím" compare as equal and resolve to the
-- same local account. Force a binary collation on the identity columns.
ALTER TABLE `oidc_user_links`
    MODIFY COLUMN `provider_id` VARCHAR(50) NOT NULL COLLATE utf8mb4_bin,
    MODIFY COLUMN `oidc_subject` VARCHAR(255) NOT NULL COLLATE utf8mb4_bin;
ALTER TABLE `saml_user_links`
    MODIFY COLUMN `provider_id` VARCHAR(50) NOT NULL COLLATE utf8mb4_bin,
    MODIFY COLUMN `saml_subject` VARCHAR(255) NOT NULL COLLATE utf8mb4_bin;

-- Register dedicated view permissions for zone metadata and zone ownership,
-- split out of zone_content_view (closes #1354). Unlike opt-in permissions,
-- templates holding zone_content_view_* ARE auto-granted the matching new
-- permissions below: metadata and ownership were visible to content viewers
-- before this release, so upgrades keep what users could already see. Admins
-- revoke the new permissions per template to hide those sections.
INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_metadata_view_own', 'User is allowed to see the meta data of zones he owns.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_metadata_view_own');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_metadata_view_others', 'User is allowed to see the meta data of zones he does not own.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_metadata_view_others');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_ownership_view_own', 'User is allowed to see the owners of zones he owns.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_ownership_view_own');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_ownership_view_others', 'User is allowed to see the owners of zones he does not own.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_ownership_view_others');

INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pti.`templ_id`, np.`id`
FROM `perm_templ_items` pti
JOIN `perm_items` cp ON cp.`id` = pti.`perm_id` AND cp.`name` = 'zone_content_view_own'
JOIN `perm_items` np ON np.`name` = 'zone_metadata_view_own'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` x WHERE x.`templ_id` = pti.`templ_id` AND x.`perm_id` = np.`id`);

INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pti.`templ_id`, np.`id`
FROM `perm_templ_items` pti
JOIN `perm_items` cp ON cp.`id` = pti.`perm_id` AND cp.`name` = 'zone_content_view_own'
JOIN `perm_items` np ON np.`name` = 'zone_ownership_view_own'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` x WHERE x.`templ_id` = pti.`templ_id` AND x.`perm_id` = np.`id`);

INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pti.`templ_id`, np.`id`
FROM `perm_templ_items` pti
JOIN `perm_items` cp ON cp.`id` = pti.`perm_id` AND cp.`name` = 'zone_content_view_others'
JOIN `perm_items` np ON np.`name` = 'zone_metadata_view_others'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` x WHERE x.`templ_id` = pti.`templ_id` AND x.`perm_id` = np.`id`);

INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT pti.`templ_id`, np.`id`
FROM `perm_templ_items` pti
JOIN `perm_items` cp ON cp.`id` = pti.`perm_id` AND cp.`name` = 'zone_content_view_others'
JOIN `perm_items` np ON np.`name` = 'zone_ownership_view_others'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` x WHERE x.`templ_id` = pti.`templ_id` AND x.`perm_id` = np.`id`);

-- Content view no longer covers metadata; keep the catalog text accurate.
UPDATE `perm_items` SET `descr` = 'User is allowed to see the content of zones he owns.'
WHERE `name` = 'zone_content_view_own';

UPDATE `perm_items` SET `descr` = 'User is allowed to see the content of zones he does not own.'
WHERE `name` = 'zone_content_view_others';

-- Index the API log timestamp so per-request logging (closes #1137) can prune
-- old rows by retention date without a full scan.
ALTER TABLE `log_api` ADD INDEX `idx_log_api_created_at` (`created_at`);
