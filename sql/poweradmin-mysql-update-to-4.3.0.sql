-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- Backfill zone metadata from PowerDNS domains table for existing zones
--
-- NOTE (MySQL/MariaDB only): If pdns_db_name is set (PowerDNS tables in a separate
-- database), set @pdns_db at the end of this script to that database name first.
-- If an earlier run of this script stopped at the backfill, do not re-run it: the
-- upgrade guide lists the statements that are still missing.
-- See: https://docs.poweradmin.org/upgrading/v4.3.0/#step-3-run-database-updates

ALTER TABLE `zones` MODIFY `domain_id` int(11) NULL DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_name` varchar(255) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_type` varchar(8) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_master` varchar(255) DEFAULT NULL;

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

-- Backfill zone_name, zone_type, zone_master from PowerDNS domains table.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
-- The backfill runs last and only when the domains table exists in @pdns_db, so a
-- database without PowerDNS tables (API backend, or pdns_db_name left unset here)
-- still gets every statement above. SQL mode reads zone names from domains and does
-- not need the backfill; it matters only before switching a database to API mode.
-- Edit here when pdns_db_name is set, e.g. SET @pdns_db = 'pdns';
SET @pdns_db = DATABASE();

SET @has_domains = (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = @pdns_db AND table_name = 'domains'
);
SET @backfill = IF(@has_domains > 0, CONCAT(
    'UPDATE `zones` z ',
    'INNER JOIN `', @pdns_db, '`.`domains` d ON z.domain_id = d.id ',
    'INNER JOIN (SELECT domain_id, MIN(id) AS min_id FROM `zones` GROUP BY domain_id) m ',
    '    ON m.domain_id = z.domain_id AND m.min_id = z.id ',
    'SET z.zone_name = d.name, z.zone_type = d.type, z.zone_master = d.master ',
    'WHERE z.zone_name IS NULL'),
    'SELECT ''PowerDNS domains table not found, zone backfill skipped'' AS notice');
PREPARE backfill FROM @backfill;
EXECUTE backfill;
DEALLOCATE PREPARE backfill;
