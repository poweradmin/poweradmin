-- Add indexes for performance improvement on zones table
-- Issue: Missing indexes on zones.domain_id and zones.owner caused slow queries with large datasets

ALTER TABLE `zones` ADD INDEX `zones_domain_id_idx` (`domain_id`);
ALTER TABLE `zones` ADD INDEX `zones_owner_idx` (`owner`);
