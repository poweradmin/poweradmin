ALTER TABLE users ADD COLUMN use_ldap smallint NOT NULL DEFAULT 0;

CREATE TABLE records_zone_templ (
    domain_id integer NOT NULL,
    record_id integer NOT NULL,
    zone_templ_id integer NOT NULL
);

CREATE TABLE migrations (
    version varchar(255) NOT NULL,
    apply_time integer NOT NULL,
);
