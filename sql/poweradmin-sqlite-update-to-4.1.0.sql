-- Add primary key to records_zone_templ table (Issue #906)
-- SQLite requires table recreation to add a primary key

CREATE TABLE records_zone_templ_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL
);

INSERT INTO records_zone_templ_new (domain_id, record_id, zone_templ_id)
SELECT domain_id, record_id, zone_templ_id FROM records_zone_templ;

DROP TABLE records_zone_templ;

ALTER TABLE records_zone_templ_new RENAME TO records_zone_templ;
