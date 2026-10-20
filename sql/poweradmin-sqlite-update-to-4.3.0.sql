-- Poweradmin schema update to 4.3.0
-- Add zone_name, zone_type, zone_master columns to zones table for API-mode support
-- Make domain_id nullable (API-mode zones don't have a PowerDNS domain ID)
-- The zone backfill from the PowerDNS domains table is in poweradmin-sqlite-update-to-4.3.0-backfill.sql
--
-- Note: SQLite doesn't support ALTER COLUMN, so we recreate the table to make
-- domain_id nullable.
-- Run once, only on a database that has not had this update: it rebuilds tables and a
-- second run drops the columns later updates added (and can empty zones in API mode).

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

-- All existing users default to 'admin' (conservative). The SSO flow will set
-- perm_templ_source = 'sso' on the next login when a group mapping matches.

-- Widen record_comment_links.record_id to support API-mode encoded string IDs
-- SQLite has no ALTER COLUMN; recreate table
CREATE TABLE record_comment_links_new (
    record_id VARCHAR(4096) NOT NULL,
    comment_id INTEGER NOT NULL,
    PRIMARY KEY (record_id),
    UNIQUE (comment_id)
);

INSERT INTO record_comment_links_new (record_id, comment_id)
SELECT CAST(record_id AS TEXT), comment_id FROM record_comment_links;

DROP TABLE record_comment_links;
ALTER TABLE record_comment_links_new RENAME TO record_comment_links;

CREATE INDEX idx_record_comment_links_comment ON record_comment_links(comment_id);

-- Create separate log table for API key events
CREATE TABLE IF NOT EXISTS log_api (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL);

-- Migrate existing API key log entries from log_users to log_api
INSERT INTO log_api (event, created_at, priority)
SELECT event, created_at, priority
FROM log_users
WHERE event LIKE '%operation:api_key_%';

-- Remove migrated API key entries from log_users
DELETE FROM log_users WHERE event LIKE '%operation:api_key_%';

COMMIT;

-- Existing zones get zone_name, zone_type and zone_master from the PowerDNS domains
-- table in poweradmin-sqlite-update-to-4.3.0-backfill.sql. Run it after this script
-- when the database holds the PowerDNS tables (SQL backend); SQLite cannot skip a
-- statement on a missing table, so it is kept out of this script.
