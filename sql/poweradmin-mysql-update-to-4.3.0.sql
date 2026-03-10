-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)

ALTER TABLE `zones` MODIFY `domain_id` int(11) NULL DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_name` varchar(255) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_type` varchar(8) DEFAULT NULL;
ALTER TABLE `zones` ADD COLUMN `zone_master` varchar(255) DEFAULT NULL;
CREATE UNIQUE INDEX `idx_zones_zone_name` ON `zones` (`zone_name`);
