-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- Backfill zone metadata from PowerDNS domains table for existing zones
--
-- Note: SQLite doesn't support ALTER COLUMN, so we recreate the table to make
-- domain_id nullable.

BEGIN TRANSACTION;

-- Create new zones table with nullable domain_id and new API columns
CREATE TABLE zones_new (
    id INTEGER PRIMARY KEY,
    domain_id INTEGER NULL DEFAULT NULL,
    owner INTEGER NULL DEFAULT NULL,
    comment VARCHAR(1024),
    zone_templ_id INTEGER NOT NULL,
    zone_name VARCHAR(255) DEFAULT NULL,
    zone_type VARCHAR(8) DEFAULT NULL,
    zone_master VARCHAR(255) DEFAULT NULL
);

-- Copy data from old table
INSERT INTO zones_new (id, domain_id, owner, comment, zone_templ_id)
SELECT id, domain_id, owner, comment, zone_templ_id FROM zones;

-- Backfill zone_name, zone_type, zone_master from PowerDNS domains table.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
UPDATE zones_new
SET zone_name = (SELECT d.name FROM domains d WHERE d.id = zones_new.domain_id),
    zone_type = (SELECT d.type FROM domains d WHERE d.id = zones_new.domain_id),
    zone_master = (SELECT d.master FROM domains d WHERE d.id = zones_new.domain_id)
WHERE zones_new.zone_name IS NULL
  AND zones_new.domain_id IS NOT NULL
  AND zones_new.id = (
    SELECT MIN(z2.id) FROM zones_new z2 WHERE z2.domain_id = zones_new.domain_id
  );

-- Drop old table
DROP TABLE zones;

-- Rename new table
ALTER TABLE zones_new RENAME TO zones;

-- Recreate indexes
CREATE INDEX idx_zones_domain_id ON zones(domain_id);
CREATE INDEX idx_zones_owner ON zones(owner);
CREATE INDEX idx_zones_zone_templ_id ON zones(zone_templ_id);
CREATE UNIQUE INDEX idx_zones_zone_name ON zones(zone_name);

-- Add perm_templ_source column to track how permission template was assigned
-- Values: 'admin' (manually by admin), 'sso' (via SSO group mapping or default)
ALTER TABLE users ADD COLUMN perm_templ_source VARCHAR(20) NOT NULL DEFAULT 'admin';

-- Set existing SSO users to 'sso' source based on auth_method
UPDATE users SET perm_templ_source = 'sso' WHERE auth_method IN ('oidc', 'saml');

COMMIT;
