-- Add indexes for performance improvement on zones table
-- Issue: Missing indexes on zones.domain_id and zones.owner caused slow queries with large datasets

BEGIN;

CREATE INDEX IF NOT EXISTS zones_domain_id_idx ON zones(domain_id);
CREATE INDEX IF NOT EXISTS zones_owner_idx ON zones(owner);

COMMIT;
