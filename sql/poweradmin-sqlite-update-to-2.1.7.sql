ALTER TABLE users ADD use_ldap BOOLEAN NOT NULL DEFAULT 0;

CREATE TABLE records_zone_templ (
    domain_id int(11) NOT NULL,
    record_id int(11) NOT NULL,
    zone_templ_id int(11) NOT NULL
);

CREATE TABLE migrations (
    version varchar(255) NOT NULL,
    apply_time int(11) NOT NULL
);
