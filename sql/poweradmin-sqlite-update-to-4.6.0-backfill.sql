-- Poweradmin schema update to 4.6.0, zone name backfill for SQLite (#1648)
-- Run after poweradmin-sqlite-update-to-4.6.0.sql, only on a database that holds the
-- PowerDNS tables (SQL backend); SQLite cannot skip a statement on a missing table.
-- The API backend finds zones only by zones.zone_name, so an unnamed zone would come back
-- after a switch as a new, ownerless zone. Same rows and values as the zones_without_names
-- consistency fix:
--  - a zone with zones rows but no named row gets its lowest-id row named after its domain;
--  - a zone with grants or API key restrictions but no zones row gets an ownerless named row.
-- A zone that has a named row is left alone, and so is one whose name another zones row holds,
-- except an unused copy the API backend's zone sync added before 4.6.0 (#1855): a named row
-- keyed by an id that is not its domain's, with no owner, template or comment, and no other
-- zones row, group grant or API key restriction on that id. It is removed first when the
-- domain has zones rows here, so the zone keeps its owners; logs, template links and change
-- requests on the copy's id move to the zone.
-- Type and master are left to the API sync. Safe to re-run.
-- The unused copies, each with its own canonical id and its domain's id. Logs, template links
-- and change requests on that id move to the domain's id before the copy is removed.
DROP TABLE IF EXISTS temp.zone_copies;
CREATE TEMP TABLE zone_copies AS
SELECT c.id, COALESCE(NULLIF(c.domain_id, 0), c.id) AS zone_id, d.id AS domain_id
FROM zones c
JOIN domains d ON d.name = c.zone_name
WHERE d.id <> COALESCE(NULLIF(c.domain_id, 0), c.id)
  AND (c.owner IS NULL OR c.owner = 0) AND c.zone_templ_id = 0
  AND (c.comment IS NULL OR c.comment = '')
  AND NOT EXISTS (SELECT 1 FROM zones e WHERE e.id <> c.id AND e.domain_id = COALESCE(NULLIF(c.domain_id, 0), c.id))
  AND NOT EXISTS (SELECT 1 FROM zones_groups g WHERE g.domain_id = COALESCE(NULLIF(c.domain_id, 0), c.id))
  AND NOT EXISTS (SELECT 1 FROM api_key_zones k WHERE k.zone_id = COALESCE(NULLIF(c.domain_id, 0), c.id))
  AND NOT EXISTS (SELECT 1 FROM zones n WHERE COALESCE(NULLIF(n.domain_id, 0), n.id) = d.id AND n.zone_name IS NOT NULL)
  AND EXISTS (SELECT 1 FROM zones s WHERE s.domain_id = d.id);

UPDATE log_zones SET zone_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = log_zones.zone_id)
WHERE zone_id IN (SELECT zone_id FROM zone_copies);
UPDATE log_changesets SET zone_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = log_changesets.zone_id)
WHERE zone_id IN (SELECT zone_id FROM zone_copies);
UPDATE log_record_changes SET zone_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = log_record_changes.zone_id)
WHERE zone_id IN (SELECT zone_id FROM zone_copies);
UPDATE zone_change_requests SET zone_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = zone_change_requests.zone_id)
WHERE zone_id IN (SELECT zone_id FROM zone_copies);
UPDATE records_zone_templ SET domain_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = records_zone_templ.domain_id)
WHERE domain_id IN (SELECT zone_id FROM zone_copies);
UPDATE records_zone_templ_api SET domain_id = (SELECT c.domain_id FROM zone_copies c WHERE c.zone_id = records_zone_templ_api.domain_id)
WHERE domain_id IN (SELECT zone_id FROM zone_copies);
DELETE FROM zones WHERE id IN (SELECT id FROM zone_copies);
DROP TABLE temp.zone_copies;

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

-- The zone sync marks the database as used in API mode (backend.zone_ids), which makes 4.6.0
-- refuse the SQL backend. Once no zones row is keyed by an id that is not its domain's, the
-- mark is cleared and the next SQL-mode page load checks the zones rows again.
DELETE FROM app_settings
WHERE setting_key = 'backend.zone_ids' AND setting_value = 'api'
  AND NOT EXISTS (
    SELECT 1 FROM zones z
    WHERE z.domain_id IS NULL OR z.domain_id = 0
       OR (z.zone_name IS NOT NULL AND z.domain_id = z.id AND (
           EXISTS (SELECT 1 FROM domains d WHERE d.name = LOWER(z.zone_name) AND d.id <> z.domain_id)
           OR EXISTS (SELECT 1 FROM domains d WHERE d.id = z.domain_id AND LOWER(d.name) <> LOWER(z.zone_name))
       ))
  );
