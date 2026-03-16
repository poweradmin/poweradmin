-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- Backfill zone metadata from PowerDNS domains table for existing zones
--
-- NOTE: If you use a separate database for PowerDNS tables (pdns_db_name config),
-- replace `domains` below with the fully qualified table name (e.g., `pdns.domains`).

ALTER TABLE `zones` MODIFY `domain_id` int(11) NULL DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_name` varchar(255) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_type` varchar(8) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_master` varchar(255) DEFAULT NULL;

-- Backfill zone_name, zone_type, zone_master from PowerDNS domains table.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
UPDATE `zones` z
INNER JOIN `domains` d ON z.domain_id = d.id
SET z.zone_name = d.name,
    z.zone_type = d.type,
    z.zone_master = d.master
WHERE z.zone_name IS NULL
  AND z.id = (
    SELECT min_id FROM (
      SELECT MIN(id) AS min_id FROM `zones` WHERE domain_id = z.domain_id
    ) AS t
  );

CREATE UNIQUE INDEX `idx_zones_zone_name` ON `zones` (`zone_name`);

-- Add perm_templ_source column to track how permission template was assigned
-- Values: 'admin' (manually by admin), 'sso' (via SSO group mapping or default)
ALTER TABLE `users` ADD COLUMN `perm_templ_source` varchar(20) NOT NULL DEFAULT 'admin';

-- All existing users default to 'admin' (conservative). The SSO flow will set
-- perm_templ_source = 'sso' on the next login when a group mapping matches.
