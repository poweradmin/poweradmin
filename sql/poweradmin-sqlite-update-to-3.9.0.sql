BEGIN;
CREATE TABLE zone_templ_records_new (
    "id"            integer NULL PRIMARY KEY AUTOINCREMENT,
    "zone_templ_id" integer NOT NULL,
    "name"          text    NOT NULL,
    "type"          text    NOT NULL,
    "content"       text(2048) NOT NULL,
    "ttl"           integer NOT NULL,
    "prio"          integer NOT NULL
);

INSERT INTO zone_templ_records_new SELECT * FROM zone_templ_records;
DROP TABLE zone_templ_records;
ALTER TABLE zone_templ_records_new RENAME TO zone_templ_records;
COMMIT;
