-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- Backfill zone metadata from PowerDNS domains table for existing zones
--
-- NOTE (MySQL/MariaDB only): If pdns_db_name is set (PowerDNS tables in a separate
-- database), the `domains` reference in the UPDATE below will not resolve. Before
-- running this script, replace `domains` with the qualified name, e.g. `pdns`.`domains`.
-- See: https://docs.poweradmin.org/upgrading/v4.3.0/#step-3-run-database-updates

ALTER TABLE `zones` MODIFY `domain_id` int(11) NULL DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_name` varchar(255) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_type` varchar(8) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_master` varchar(255) DEFAULT NULL;

-- Backfill zone_name, zone_type, zone_master from PowerDNS domains table.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
UPDATE `zones` z
INNER JOIN `domains` d ON z.domain_id = d.id
INNER JOIN (
    SELECT domain_id, MIN(id) AS min_id FROM `zones` GROUP BY domain_id
) m ON m.domain_id = z.domain_id AND m.min_id = z.id
SET z.zone_name = d.name,
    z.zone_type = d.type,
    z.zone_master = d.master
WHERE z.zone_name IS NULL;

CREATE UNIQUE INDEX `idx_zones_zone_name` ON `zones` (`zone_name`);

-- Add perm_templ_source column to track how permission template was assigned
-- Values: 'admin' (manually by admin), 'sso' (via SSO group mapping or default)
ALTER TABLE `users` ADD COLUMN `perm_templ_source` varchar(20) NOT NULL DEFAULT 'admin';

-- All existing users default to 'admin' (conservative). The SSO flow will set
-- perm_templ_source = 'sso' on the next login when a group mapping matches.

-- Widen record_comment_links.record_id to support API-mode encoded string IDs
ALTER TABLE `record_comment_links` MODIFY `record_id` VARCHAR(3072) CHARACTER SET ascii NOT NULL;

-- Create separate log table for API key events
CREATE TABLE IF NOT EXISTS `log_api` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `event` varchar(2048) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `priority` int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing API key log entries from log_users to log_api
INSERT INTO `log_api` (`event`, `created_at`, `priority`)
SELECT `event`, `created_at`, `priority`
FROM `log_users`
WHERE `event` LIKE '%operation:api_key_%';

-- Remove migrated API key entries from log_users
DELETE FROM `log_users` WHERE `event` LIKE '%operation:api_key_%';
