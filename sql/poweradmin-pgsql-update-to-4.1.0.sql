-- Add primary key to records_zone_templ table (Issue #906)
-- Adding primary key for database cluster compatibility
CREATE SEQUENCE IF NOT EXISTS records_zone_templ_id_seq;
ALTER TABLE records_zone_templ ADD COLUMN id INTEGER NOT NULL DEFAULT nextval('records_zone_templ_id_seq');
ALTER TABLE records_zone_templ ADD PRIMARY KEY (id);
ALTER SEQUENCE records_zone_templ_id_seq OWNED BY records_zone_templ.id;
