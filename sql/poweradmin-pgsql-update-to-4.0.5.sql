-- Poweradmin PostgreSQL update script for version 4.0.5
-- This script adds a primary key to the records_zone_templ table
-- and synchronizes sequences to prevent duplicate key errors

-- Create sequence for records_zone_templ
CREATE SEQUENCE IF NOT EXISTS records_zone_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

-- Add id column with sequence as primary key to records_zone_templ (closes #906)
ALTER TABLE records_zone_templ
    ADD COLUMN id integer DEFAULT nextval('records_zone_templ_id_seq') NOT NULL;

ALTER TABLE records_zone_templ
    ADD CONSTRAINT records_zone_templ_pkey PRIMARY KEY (id);

ALTER SEQUENCE records_zone_templ_id_seq OWNED BY records_zone_templ.id;

-- Synchronize sequences with actual max values to prevent duplicate key errors (closes #942)
-- This fixes "duplicate key value violates unique constraint" errors when adding records
SELECT setval('perm_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_items), 1));
SELECT setval('perm_templ_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ), 1));
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));
