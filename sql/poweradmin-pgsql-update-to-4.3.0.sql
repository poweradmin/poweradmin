-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- Backfill zone metadata from PowerDNS domains table for existing zones

ALTER TABLE zones ALTER COLUMN domain_id DROP NOT NULL;
ALTER TABLE zones ALTER COLUMN domain_id SET DEFAULT NULL;
ALTER TABLE zones ADD COLUMN zone_name character varying(255) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN zone_type character varying(8) DEFAULT NULL;
ALTER TABLE zones ADD COLUMN zone_master character varying(255) DEFAULT NULL;

-- Backfill zone_name, zone_type, zone_master from PowerDNS domains table.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
UPDATE zones
SET zone_name = d.name,
    zone_type = d.type,
    zone_master = d.master
FROM domains d
WHERE zones.domain_id = d.id
  AND zones.zone_name IS NULL
  AND zones.id = (
    SELECT MIN(z2.id) FROM zones z2 WHERE z2.domain_id = zones.domain_id
  );

CREATE UNIQUE INDEX idx_zones_zone_name ON zones (zone_name);

-- Add perm_templ_source column to track how permission template was assigned
-- Values: 'admin' (manually by admin), 'sso' (via SSO group mapping or default)
ALTER TABLE "users" ADD COLUMN "perm_templ_source" character varying(20) NOT NULL DEFAULT 'admin';

-- Set existing SSO users to 'sso' source based on auth_method
UPDATE "users" SET "perm_templ_source" = 'sso' WHERE "auth_method" IN ('oidc', 'saml');
