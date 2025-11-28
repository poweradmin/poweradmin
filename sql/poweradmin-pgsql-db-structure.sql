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

CREATE INDEX "idx_log_zones_zone_id" ON "public"."log_zones" USING btree ("zone_id");


CREATE SEQUENCE log_groups_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."log_groups" (
                                       "id" integer DEFAULT nextval('log_groups_id_seq1') NOT NULL,
                                       "event" character varying(2048),
                                       "created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
                                       "priority" integer,
                                       "group_id" integer,
                                       CONSTRAINT "log_groups_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "idx_log_groups_group_id" ON "public"."log_groups" USING btree ("group_id");


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
                                                     (62,	'zone_content_edit_own_as_client',	'User is allowed to edit record, but not SOA and NS.'),
                                                     (63,	'zone_templ_add',	'User is allowed to add new zone templates.'),
                                                     (64,	'zone_templ_edit',	'User is allowed to edit existing zone templates.'),
                                                     (65,	'api_manage_keys',	'User is allowed to create and manage API keys.'),
                                                     (67,	'zone_delete_own',	'User is allowed to delete zones they own.'),
                                                     (68,	'zone_delete_others',	'User is allowed to delete zones owned by others.');

CREATE SEQUENCE perm_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."perm_templ" (
                                       "id" integer DEFAULT nextval('perm_templ_id_seq') NOT NULL,
                                       "name" character varying(128),
                                       "descr" character varying(1024),
                                       "template_type" character varying(10) DEFAULT 'user' NOT NULL,
                                       CONSTRAINT "perm_templ_pkey" PRIMARY KEY ("id"),
                                       CONSTRAINT "perm_templ_template_type_check" CHECK (template_type IN ('user', 'group'))
) WITH (oids = false);

INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES
    (1,	'Administrator',	'Administrator template with full rights.',	'user'),
    (2,	'Zone Manager',	'Full management of own zones including creation, editing, deletion, and templates.',	'user'),
    (3,	'Editor',	'Edit own zone records but cannot modify SOA and NS records.',	'user'),
    (4,	'Viewer',	'Read-only access to own zones with search capability.',	'user'),
    (5,	'Guest',	'Temporary access with no permissions. Suitable for users awaiting approval or limited access.',	'user'),
    (6,	'Administrators',	'Full administrative access for group members.',	'group'),
    (7,	'Zone Managers',	'Full zone management for group members.',	'group'),
    (8,	'Editors',	'Edit zone records (no SOA/NS) for group members.',	'group'),
    (9,	'Viewers',	'Read-only zone access for group members.',	'group'),
    (10,	'Guests',	'Temporary group with no permissions. Suitable for users awaiting approval.',	'group');

SELECT setval('perm_templ_id_seq', 10);

CREATE SEQUENCE perm_templ_items_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."perm_templ_items" (
                                             "id" integer DEFAULT nextval('perm_templ_items_id_seq') NOT NULL,
                                             "templ_id" integer,
                                             "perm_id" integer,
                                             CONSTRAINT "perm_templ_items_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "idx_perm_templ_items_templ_id" ON "public"."perm_templ_items" USING btree ("templ_id");
CREATE INDEX "idx_perm_templ_items_perm_id" ON "public"."perm_templ_items" USING btree ("perm_id");

INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES
    (1,	1,	53),
    (2,	2,	41),
    (3,	2,	42),
    (4,	2,	43),
    (5,	2,	44),
    (6,	2,	45),
    (7,	2,	49),
    (8,	2,	56),
    (9,	2,	63),
    (10,	2,	64),
    (11,	2,	65),
    (12,	2,	67),
    (13,	3,	43),
    (14,	3,	49),
    (15,	3,	56),
    (16,	3,	62),
    (17,	4,	43),
    (18,	4,	49),
    (19,	6,	53),
    (20,	7,	41),
    (21,	7,	42),
    (22,	7,	43),
    (23,	7,	44),
    (24,	7,	45),
    (25,	7,	49),
    (26,	7,	56),
    (27,	7,	63),
    (28,	7,	64),
    (29,	7,	65),
    (30,	7,	67),
    (31,	8,	43),
    (32,	8,	49),
    (33,	8,	56),
    (34,	8,	62),
    (35,	9,	43),
    (36,	9,	49);

SELECT setval('perm_templ_items_id_seq', 36);

CREATE TABLE "public"."records_zone_templ" (
                                               "domain_id" integer,
                                               "record_id" integer,
                                               "zone_templ_id" integer
) WITH (oids = false);

CREATE INDEX "idx_records_zone_templ_domain_id" ON "public"."records_zone_templ" USING btree ("domain_id");
CREATE INDEX "idx_records_zone_templ_zone_templ_id" ON "public"."records_zone_templ" USING btree ("zone_templ_id");


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
                                  "auth_method" character varying(20) DEFAULT 'sql' NOT NULL,
                                  CONSTRAINT "users_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "idx_users_perm_templ" ON "public"."users" USING btree ("perm_templ");

CREATE SEQUENCE login_attempts_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."login_attempts" (
    "id" integer DEFAULT nextval('login_attempts_id_seq') NOT NULL,
    "user_id" integer NULL,
    "ip_address" character varying(45) NOT NULL,
    "timestamp" integer NOT NULL,
    "successful" boolean NOT NULL,
    CONSTRAINT "login_attempts_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_login_attempts_users" FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) WITH (oids = false);

CREATE INDEX "idx_login_attempts_user_id" ON "public"."login_attempts" USING btree ("user_id");
CREATE INDEX "idx_login_attempts_ip_address" ON "public"."login_attempts" USING btree ("ip_address");
CREATE INDEX "idx_login_attempts_timestamp" ON "public"."login_attempts" USING btree ("timestamp");

CREATE SEQUENCE zone_templ_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."zone_templ" (
                                       "id" integer DEFAULT nextval('zone_templ_id_seq') NOT NULL,
                                       "name" character varying(128),
                                       "descr" character varying(1024),
                                       "owner" integer,
                                       "created_by" integer,
                                       CONSTRAINT "zone_templ_pkey" PRIMARY KEY ("id"),
                                       CONSTRAINT "fk_zone_templ_users" FOREIGN KEY ("created_by") REFERENCES "users" ("id") ON DELETE SET NULL
) WITH (oids = false);

CREATE INDEX "idx_zone_templ_owner" ON "public"."zone_templ" USING btree ("owner");
CREATE INDEX "idx_zone_templ_created_by" ON "public"."zone_templ" USING btree ("created_by");


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

CREATE INDEX "idx_zone_templ_records_zone_templ_id" ON "public"."zone_templ_records" USING btree ("zone_templ_id");


CREATE SEQUENCE zones_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."zones" (
                                  "id" integer DEFAULT nextval('zones_id_seq') NOT NULL,
                                  "domain_id" integer,
                                  "owner" integer DEFAULT NULL,
                                  "comment" character varying(1024),
                                  "zone_templ_id" integer,
                                  CONSTRAINT "zones_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "idx_zones_domain_id" ON "public"."zones" USING btree ("domain_id");
CREATE INDEX "idx_zones_owner" ON "public"."zones" USING btree ("owner");
CREATE INDEX "idx_zones_zone_templ_id" ON "public"."zones" USING btree ("zone_templ_id");

CREATE SEQUENCE api_keys_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."api_keys" (
    "id" integer DEFAULT nextval('api_keys_id_seq') NOT NULL,
    "name" character varying(255) NOT NULL,
    "secret_key" character varying(255) NOT NULL,
    "created_by" integer,
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "last_used_at" timestamp,
    "disabled" boolean DEFAULT false NOT NULL,
    "expires_at" timestamp,
    CONSTRAINT "api_keys_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_api_keys_users" FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) WITH (oids = false);

CREATE UNIQUE INDEX "idx_api_keys_secret_key" ON "public"."api_keys" USING btree ("secret_key");
CREATE INDEX "idx_api_keys_created_by" ON "public"."api_keys" USING btree ("created_by");
CREATE INDEX "idx_api_keys_disabled" ON "public"."api_keys" USING btree ("disabled");

CREATE SEQUENCE user_mfa_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "public"."user_mfa" (
    "id" integer DEFAULT nextval('user_mfa_id_seq') NOT NULL,
    "user_id" integer NOT NULL,
    "enabled" boolean DEFAULT false NOT NULL,
    "secret" character varying(255),
    "recovery_codes" text,
    "type" character varying(20) DEFAULT 'app' NOT NULL,
    "last_used_at" timestamp,
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updated_at" timestamp,
    "verification_data" text,
    CONSTRAINT "user_mfa_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_user_mfa_users" FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) WITH (oids = false);

CREATE UNIQUE INDEX "idx_user_mfa_user_id" ON "public"."user_mfa" USING btree ("user_id");
CREATE INDEX "idx_user_mfa_enabled" ON "public"."user_mfa" USING btree ("enabled");

CREATE TABLE "user_preferences" (
    "id" serial NOT NULL,
    "user_id" integer NOT NULL,
    "preference_key" character varying(100) NOT NULL,
    "preference_value" text,
    CONSTRAINT "user_preferences_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_user_preferences_users" FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) WITH (oids = false);

CREATE UNIQUE INDEX "idx_user_preferences_user_key" ON "public"."user_preferences" USING btree ("user_id", "preference_key");
CREATE INDEX "idx_user_preferences_user_id" ON "public"."user_preferences" USING btree ("user_id");

CREATE TABLE "zone_template_sync" (
    "id" serial NOT NULL,
    "zone_id" integer NOT NULL,
    "zone_templ_id" integer NOT NULL,
    "last_synced" timestamp,
    "template_last_modified" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "needs_sync" boolean NOT NULL DEFAULT false,
    "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "zone_template_sync_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_zone_template_sync_zone" FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    CONSTRAINT "fk_zone_template_sync_templ" FOREIGN KEY (zone_templ_id) REFERENCES zone_templ(id) ON DELETE CASCADE
) WITH (oids = false);

CREATE UNIQUE INDEX "idx_zone_template_unique" ON "public"."zone_template_sync" USING btree ("zone_id", "zone_templ_id");
CREATE INDEX "idx_zone_templ_id" ON "public"."zone_template_sync" USING btree ("zone_templ_id");
CREATE INDEX "idx_needs_sync" ON "public"."zone_template_sync" USING btree ("needs_sync");

-- 2022-09-29 19:10:39.890321+00
CREATE TABLE password_reset_tokens (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    ip_address VARCHAR(45) DEFAULT NULL
);

CREATE INDEX idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX idx_prt_token ON password_reset_tokens(token);
CREATE INDEX idx_prt_expires ON password_reset_tokens(expires_at);

CREATE TABLE username_recovery_requests (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_urr_email ON username_recovery_requests(email);
CREATE INDEX idx_urr_ip ON username_recovery_requests(ip_address);
CREATE INDEX idx_urr_created ON username_recovery_requests(created_at);

CREATE TABLE user_agreements (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    agreement_version VARCHAR(50) NOT NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    CONSTRAINT fk_user_agreements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE UNIQUE INDEX unique_user_agreement ON user_agreements(user_id, agreement_version);
CREATE INDEX idx_user_agreements_user_id ON user_agreements(user_id);
CREATE INDEX idx_user_agreements_version ON user_agreements(agreement_version);

CREATE TABLE oidc_user_links (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    oidc_subject VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, provider_id),
    UNIQUE (oidc_subject, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX idx_oidc_subject ON oidc_user_links(oidc_subject);

CREATE TABLE saml_user_links (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    saml_subject VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, provider_id),
    UNIQUE (saml_subject, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_saml_provider_id ON saml_user_links(provider_id);
CREATE INDEX idx_saml_subject ON saml_user_links(saml_subject);


CREATE TABLE user_groups (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    perm_templ INTEGER NOT NULL REFERENCES perm_templ(id),
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_user_groups_perm_templ ON user_groups(perm_templ);
CREATE INDEX idx_user_groups_created_by ON user_groups(created_by);
CREATE INDEX idx_user_groups_name ON user_groups(name);

CREATE OR REPLACE FUNCTION update_user_groups_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_user_groups_updated_at
    BEFORE UPDATE ON user_groups
    FOR EACH ROW
    EXECUTE FUNCTION update_user_groups_updated_at();

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (1, 'Administrators', 'Full administrative access to all system functions.', 6, NULL),
    (2, 'Zone Managers', 'Full zone management including creation, editing, and deletion.', 7, NULL),
    (3, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 8, NULL),
    (4, 'Viewers', 'Read-only access to zones with search capability.', 9, NULL),
    (5, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 10, NULL);

SELECT setval('user_groups_id_seq', 5);

CREATE TABLE user_group_members (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES user_groups(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (group_id, user_id)
);

CREATE INDEX idx_user_group_members_user ON user_group_members(user_id);
CREATE INDEX idx_user_group_members_group ON user_group_members(group_id);

CREATE TABLE zones_groups (
    id SERIAL PRIMARY KEY,
    domain_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL REFERENCES user_groups(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (domain_id, group_id)
);

CREATE INDEX idx_zones_groups_domain ON zones_groups(domain_id);
CREATE INDEX idx_zones_groups_group ON zones_groups(group_id);
