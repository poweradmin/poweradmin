CREATE SEQUENCE log_users_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "log_users"
(
    "id"         integer   DEFAULT nextval('log_users_id_seq') NOT NULL,
    "event"      character varying(2048),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
    "priority"   integer,
    CONSTRAINT "log_users_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE SEQUENCE log_zones_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "log_zones"
(
    "id"         integer   DEFAULT nextval('log_zones_id_seq') NOT NULL,
    "event"      character varying(2048),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
    "priority"   integer,
    "zone_id"    integer,
    CONSTRAINT "log_zones_pkey" PRIMARY KEY ("id")
) WITH (oids = false);
