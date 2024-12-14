-- Adminer 4.8.1 PostgreSQL 14.5 (Debian 14.5-1.pgdg110+1) dump

CREATE SEQUENCE log_users_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."log_users" (
                                      "id" integer DEFAULT nextval('log_users_id_seq1') NOT NULL,
                                      "event" character varying(2048),
                                      "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
                                      "priority" integer,
                                      CONSTRAINT "log_users_pkey" PRIMARY KEY ("id")
) WITH (oids = false);


CREATE SEQUENCE log_zones_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."log_zones" (
                                      "id" integer DEFAULT nextval('log_zones_id_seq1') NOT NULL,
                                      "event" character varying(2048),
                                      "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
                                      "priority" integer,
                                      "zone_id" integer,
                                      CONSTRAINT "log_zones_pkey" PRIMARY KEY ("id")
) WITH (oids = false);


CREATE TABLE "public"."migrations" (
                                       "version" character varying(255),
                                       "apply_time" integer
) WITH (oids = false);


CREATE SEQUENCE perm_items_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."perm_items" (
                                       "id" integer DEFAULT nextval('perm_items_id_seq') NOT NULL,
                                       "name" character varying(64),
                                       "descr" character varying(1024),
                                       CONSTRAINT "perm_items_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

INSERT INTO "perm_items" ("id", "name", "descr") VALUES
                                                     (41,	'zone_master_add',	'User is allowed to add new master zones.'),
                                                     (42,	'zone_slave_add',	'User is allowed to add new slave zones.'),
                                                     (43,	'zone_content_view_own',	'User is allowed to see the content and meta data of zones he owns.'),
                                                     (44,	'zone_content_edit_own',	'User is allowed to edit the content of zones he owns.'),
                                                     (45,	'zone_meta_edit_own',	'User is allowed to edit the meta data of zones he owns.'),
                                                     (46,	'zone_content_view_others',	'User is allowed to see the content and meta data of zones he does not own.'),
                                                     (47,	'zone_content_edit_others',	'User is allowed to edit the content of zones he does not own.'),
                                                     (48,	'zone_meta_edit_others',	'User is allowed to edit the meta data of zones he does not own.'),
                                                     (49,	'search',	'User is allowed to perform searches.'),
                                                     (50,	'supermaster_view',	'User is allowed to view supermasters.'),
                                                     (51,	'supermaster_add',	'User is allowed to add new supermasters.'),
                                                     (52,	'supermaster_edit',	'User is allowed to edit supermasters.'),
                                                     (53,	'user_is_ueberuser',	'User has full access. God-like. Redeemer.'),
                                                     (54,	'user_view_others',	'User is allowed to see other users and their details.'),
                                                     (55,	'user_add_new',	'User is allowed to add new users.'),
                                                     (56,	'user_edit_own',	'User is allowed to edit their own details.'),
                                                     (57,	'user_edit_others',	'User is allowed to edit other users.'),
                                                     (58,	'user_passwd_edit_others',	'User is allowed to edit the password of other users.'),
                                                     (59,	'user_edit_templ_perm',	'User is allowed to change the permission template that is assigned to a user.'),
                                                     (60,	'templ_perm_add',	'User is allowed to add new permission templates.'),
                                                     (61,	'templ_perm_edit',	'User is allowed to edit existing permission templates.'),
                                                     (62,	'zone_content_edit_own_as_client',	'User is allowed to edit record, but not SOA and NS.');

CREATE SEQUENCE perm_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."perm_templ" (
                                       "id" integer DEFAULT nextval('perm_templ_id_seq') NOT NULL,
                                       "name" character varying(128),
                                       "descr" character varying(1024),
                                       CONSTRAINT "perm_templ_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

INSERT INTO "perm_templ" ("id", "name", "descr") VALUES
    (1,	'Administrator',	'Administrator template with full rights.');

CREATE SEQUENCE perm_templ_items_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."perm_templ_items" (
                                             "id" integer DEFAULT nextval('perm_templ_items_id_seq') NOT NULL,
                                             "templ_id" integer,
                                             "perm_id" integer,
                                             CONSTRAINT "perm_templ_items_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES
    (1,	1,	53);

CREATE TABLE "public"."records_zone_templ" (
                                               "domain_id" integer,
                                               "record_id" integer,
                                               "zone_templ_id" integer
) WITH (oids = false);


CREATE SEQUENCE users_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."users" (
                                  "id" integer DEFAULT nextval('users_id_seq') NOT NULL,
                                  "username" character varying(64),
                                  "password" character varying(128),
                                  "fullname" character varying(255),
                                  "email" character varying(255),
                                  "description" character varying(1024),
                                  "perm_templ" integer,
                                  "active" integer,
                                  "use_ldap" integer,
                                  CONSTRAINT "users_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

INSERT INTO "users" ("id", "username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap") VALUES
    (1,	'admin',	'$2y$12$10ei/WGJPcUY9Ea8/eVage9zBbxr0xxW82qJF/cfSyev/jX84WHQe',	'Administrator',	'admin@example.net',	'Administrator with full rights.',	1,	1,	0);

CREATE SEQUENCE zone_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."zone_templ" (
                                       "id" integer DEFAULT nextval('zone_templ_id_seq') NOT NULL,
                                       "name" character varying(128),
                                       "descr" character varying(1024),
                                       "owner" integer,
                                       CONSTRAINT "zone_templ_pkey" PRIMARY KEY ("id")
) WITH (oids = false);


CREATE SEQUENCE zone_templ_records_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."zone_templ_records" (
                                               "id" integer DEFAULT nextval('zone_templ_records_id_seq') NOT NULL,
                                               "zone_templ_id" integer,
                                               "name" character varying(255),
                                               "type" character varying(6),
                                               "content" character varying(2048),
                                               "ttl" integer,
                                               "prio" integer,
                                               CONSTRAINT "zone_templ_records_pkey" PRIMARY KEY ("id")
) WITH (oids = false);


CREATE SEQUENCE zones_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."zones" (
                                  "id" integer DEFAULT nextval('zones_id_seq') NOT NULL,
                                  "domain_id" integer,
                                  "owner" integer,
                                  "comment" character varying(1024),
                                  "zone_templ_id" integer,
                                  CONSTRAINT "zones_pkey" PRIMARY KEY ("id")
) WITH (oids = false);


-- 2022-09-29 19:10:39.890321+00