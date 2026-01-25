-- Poweradmin SQLite update script for version 4.0.5
-- This script adds a primary key to the records_zone_templ table (closes #906)
-- SQLite requires recreating the table to add a primary key

-- Create new table with primary key
CREATE TABLE records_zone_templ_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL
);

-- Copy existing data
INSERT INTO records_zone_templ_new (domain_id, record_id, zone_templ_id)
SELECT domain_id, record_id, zone_templ_id FROM records_zone_templ;

-- Drop old table
DROP TABLE records_zone_templ;

-- Rename new table
ALTER TABLE records_zone_templ_new RENAME TO records_zone_templ;
