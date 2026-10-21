-- Poweradmin schema update to 4.6.0, zone name backfill for SQLite (#1648)
-- Run after poweradmin-sqlite-update-to-4.6.0.sql, only on a database that holds the
-- PowerDNS tables (SQL backend); SQLite cannot skip a statement on a missing table.
-- The API backend finds zones only by zones.zone_name, so an unnamed zone would come back
-- after a switch as a new, ownerless zone. Same rows and values as the zones_without_names
-- consistency fix:
--  - a zone with zones rows but no named row gets its lowest-id row named after its domain;
--  - a zone with grants or API key restrictions but no zones row gets an ownerless named row.
-- A zone that has a named row is left alone, and so is one whose name another zones row holds.
-- Type and master are left to the API sync. Safe to re-run.
UPDATE zones
SET zone_name = (SELECT d.name FROM domains d WHERE d.id = zones.domain_id)
WHERE zones.zone_name IS NULL
  AND zones.domain_id IS NOT NULL
  AND zones.id = (SELECT MIN(z2.id) FROM zones z2 WHERE z2.domain_id = zones.domain_id)
  AND NOT EXISTS (SELECT 1 FROM zones n WHERE n.domain_id = zones.domain_id AND n.zone_name IS NOT NULL)
  AND EXISTS (SELECT 1 FROM domains d WHERE d.id = zones.domain_id)
  AND NOT EXISTS (
    SELECT 1 FROM zones o JOIN domains d ON d.id = zones.domain_id WHERE o.zone_name = d.name
  );

INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name)
SELECT d.id, NULL, 0, d.name
FROM domains d
WHERE NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id)
  AND NOT EXISTS (SELECT 1 FROM zones o WHERE o.zone_name = d.name)
  AND (EXISTS (SELECT 1 FROM zones_groups g WHERE g.domain_id = d.id)
       OR EXISTS (SELECT 1 FROM api_key_zones k WHERE k.zone_id = d.id));
