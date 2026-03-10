-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support

ALTER TABLE zones ADD COLUMN zone_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN zone_type VARCHAR(8) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN zone_master VARCHAR(255) DEFAULT NULL;
CREATE UNIQUE INDEX idx_zones_zone_name ON zones (zone_name);
