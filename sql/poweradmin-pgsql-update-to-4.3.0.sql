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

-- All existing users default to 'admin' (conservative). The SSO flow will set
-- perm_templ_source = 'sso' on the next login when a group mapping matches.

-- Widen record_comment_links.record_id to support API-mode encoded string IDs
ALTER TABLE record_comment_links ALTER COLUMN record_id TYPE VARCHAR(4096);

-- Create separate log table for API key events
CREATE SEQUENCE IF NOT EXISTS log_api_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."log_api" (
    "id" integer DEFAULT nextval('log_api_id_seq1') NOT NULL,
    "event" character varying(2048),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
    "priority" integer,
    CONSTRAINT "log_api_pkey" PRIMARY KEY ("id")
);

-- Migrate existing API key log entries from log_users to log_api
INSERT INTO log_api (event, created_at, priority)
SELECT event, created_at, priority
FROM log_users
WHERE event LIKE '%operation:api_key_%';

-- Remove migrated API key entries from log_users
DELETE FROM log_users WHERE event LIKE '%operation:api_key_%';
