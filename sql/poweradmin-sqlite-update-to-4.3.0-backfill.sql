-- Poweradmin schema update to 4.3.0, zone backfill for SQLite
-- Run after poweradmin-sqlite-update-to-4.3.0.sql, only on a database that holds the
-- PowerDNS tables (SQL backend). It copies zone_name, zone_type and zone_master from
-- domains into existing zones; SQL mode reads these from domains and does not need
-- them, they matter only before switching a database to API mode.
-- Only updates the lowest-id row per domain_id to respect the UNIQUE index on zone_name.
UPDATE zones
SET zone_name = (SELECT d.name FROM domains d WHERE d.id = zones.domain_id),
    zone_type = (SELECT d.type FROM domains d WHERE d.id = zones.domain_id),
    zone_master = (SELECT d.master FROM domains d WHERE d.id = zones.domain_id)
WHERE zones.zone_name IS NULL
  AND zones.domain_id IS NOT NULL
  AND zones.id = (
    SELECT MIN(z2.id) FROM zones z2 WHERE z2.domain_id = zones.domain_id
  );
