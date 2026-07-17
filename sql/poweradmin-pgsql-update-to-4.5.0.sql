-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE SEQUENCE IF NOT EXISTS log_record_changes_id_seq1 INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."log_record_changes" (
    "id" integer DEFAULT nextval('log_record_changes_id_seq1') NOT NULL,
    "zone_id" integer,
    "record_id" text,
    "action" character varying(32) NOT NULL,
    "user_id" integer,
    "username" character varying(64) NOT NULL,
    "before_state" text,
    "after_state" text,
    "client_ip" character varying(64),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT "log_record_changes_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX IF NOT EXISTS "idx_log_record_changes_created_at" ON "public"."log_record_changes" USING btree ("created_at");
CREATE INDEX IF NOT EXISTS "idx_log_record_changes_zone_id" ON "public"."log_record_changes" USING btree ("zone_id");
CREATE INDEX IF NOT EXISTS "idx_log_record_changes_action" ON "public"."log_record_changes" USING btree ("action");

-- Mapping table for API-backed zones whose record IDs are encoded strings
-- (RecordIdentifier base64url) and don't fit in records_zone_templ.record_id
-- (integer). Populated only when applying templates against PowerDNS via the API.
CREATE SEQUENCE IF NOT EXISTS records_zone_templ_api_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."records_zone_templ_api" (
    "id" integer DEFAULT nextval('records_zone_templ_api_id_seq') NOT NULL,
    "domain_id" integer NOT NULL,
    "record_id" character varying(255) NOT NULL,
    "zone_templ_id" integer NOT NULL,
    CONSTRAINT "records_zone_templ_api_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX IF NOT EXISTS "idx_records_zone_templ_api_domain_id" ON "public"."records_zone_templ_api" USING btree ("domain_id");
CREATE INDEX IF NOT EXISTS "idx_records_zone_templ_api_zone_templ_id" ON "public"."records_zone_templ_api" USING btree ("zone_templ_id");

-- Per-record-type default TTLs managed via the admin UI (closes #1032).
-- When a record is created without an explicit TTL, the server first looks
-- up the type in this table; if no row exists the legacy fallback chain
-- (dns.ttl_reverse for PTR, then dns.ttl) applies.
CREATE TABLE IF NOT EXISTS "public"."record_type_defaults" (
    "record_type" character varying(20) NOT NULL,
    "ttl" integer NOT NULL,
    "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "record_type_defaults_pkey" PRIMARY KEY ("record_type")
);

-- Generic admin-managed settings, layered above config/settings.php.
-- AppSettingsService reads this table first and falls back to ConfigurationManager.
-- No setting is migrated automatically; this is plumbing for future features.
CREATE TABLE IF NOT EXISTS "public"."app_settings" (
    "setting_key" character varying(128) NOT NULL,
    "setting_value" text NOT NULL,
    "value_type" character varying(16) NOT NULL DEFAULT 'string',
    "created_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "app_settings_pkey" PRIMARY KEY ("setting_key")
);

-- Align zones.zone_templ_id with the MySQL/SQLite schemas: NOT NULL DEFAULT 0,
-- matching the "no template" sentinel used by every active write path.
UPDATE "zones" SET "zone_templ_id" = 0 WHERE "zone_templ_id" IS NULL;
ALTER TABLE "zones" ALTER COLUMN "zone_templ_id" SET DEFAULT 0;
ALTER TABLE "zones" ALTER COLUMN "zone_templ_id" SET NOT NULL;

-- Register zone_dnssec_manage_own so admins can grant DNSSEC key management
-- separately from general zone editing. Existing templates are NOT auto-granted
-- the new permission; admins must opt in via the permission template editor.
INSERT INTO perm_items (name, descr)
SELECT 'zone_dnssec_manage_own', 'User is allowed to manage DNSSEC keys for zones he owns.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_dnssec_manage_own');

-- Register dedicated log-view permissions so admins can grant access to the
-- activity logs without granting ueberuser. Zone logs split into own/others
-- like zone_content_view; user and group logs are global. Existing templates
-- are NOT auto-granted these; admins opt in via the permission template editor.
INSERT INTO perm_items (name, descr)
SELECT 'zone_logs_view_own', 'User is allowed to view activity logs for zones he owns.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_logs_view_own');

INSERT INTO perm_items (name, descr)
SELECT 'zone_logs_view_others', 'User is allowed to view activity logs for zones he does not own.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_logs_view_others');

INSERT INTO perm_items (name, descr)
SELECT 'user_logs_view', 'User is allowed to view the user activity logs.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'user_logs_view');

INSERT INTO perm_items (name, descr)
SELECT 'group_logs_view', 'User is allowed to view the group activity logs.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'group_logs_view');

-- Register zone_content_edit_ns_subzone so client-level editors can manage
-- delegation NS records below the zone apex. Existing templates are NOT
-- auto-granted the new permission; admins opt in via the permission template editor.
INSERT INTO perm_items (name, descr)
SELECT 'zone_content_edit_ns_subzone', 'User is allowed to edit NS records below the zone apex, but not SOA and apex NS records.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_content_edit_ns_subzone');

-- Widen password_reset_tokens.token so the new sha256$<64hex> storage format fits.
-- Plaintext rows issued before this upgrade naturally expire within the
-- token_lifetime (1 hour default) and remain unreadable to the new validator;
-- affected users simply request a new reset link.
ALTER TABLE "password_reset_tokens" ALTER COLUMN "token" TYPE VARCHAR(128);

-- Tag each login_attempts row with the authentication stage that produced it
-- (password / mfa). Lets MfaVerifyController throttle the second factor without
-- letting a fresh first-factor success clear the MFA failure counter.
ALTER TABLE "login_attempts" ADD COLUMN "attempt_type" character varying(16) NOT NULL DEFAULT 'password';
CREATE INDEX IF NOT EXISTS "idx_login_attempts_attempt_type" ON "public"."login_attempts" USING btree ("attempt_type");

-- Granular API key permissions (closes #795). New columns and the api_key_zones
-- table are optional: existing keys get is_readonly=false, allowed_operations=NULL
-- and no zone rows, which means "unrestricted" - identical to the previous behavior.
ALTER TABLE "api_keys" ADD COLUMN "is_readonly" boolean DEFAULT false NOT NULL;
ALTER TABLE "api_keys" ADD COLUMN "allowed_operations" character varying(255);

CREATE SEQUENCE IF NOT EXISTS api_key_zones_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."api_key_zones" (
    "id" integer DEFAULT nextval('api_key_zones_id_seq') NOT NULL,
    "api_key_id" integer NOT NULL,
    "zone_id" integer NOT NULL,
    CONSTRAINT "api_key_zones_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "idx_api_key_zones_unique" UNIQUE ("api_key_id", "zone_id"),
    CONSTRAINT "fk_api_key_zones_api_key" FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) WITH (oids = false);

CREATE INDEX IF NOT EXISTS "idx_api_key_zones_api_key_id" ON "public"."api_key_zones" USING btree ("api_key_id");
CREATE INDEX IF NOT EXISTS "idx_api_key_zones_zone_id" ON "public"."api_key_zones" USING btree ("zone_id");

-- Register dedicated view permissions for zone metadata and zone ownership,
-- split out of zone_content_view (closes #1354). Unlike opt-in permissions,
-- templates holding zone_content_view_* ARE auto-granted the matching new
-- permissions below: metadata and ownership were visible to content viewers
-- before this release, so upgrades keep what users could already see. Admins
-- revoke the new permissions per template to hide those sections.
INSERT INTO "perm_items" ("name", "descr")
SELECT 'zone_metadata_view_own', 'User is allowed to see the meta data of zones he owns.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_items" WHERE "name" = 'zone_metadata_view_own');

INSERT INTO "perm_items" ("name", "descr")
SELECT 'zone_metadata_view_others', 'User is allowed to see the meta data of zones he does not own.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_items" WHERE "name" = 'zone_metadata_view_others');

INSERT INTO "perm_items" ("name", "descr")
SELECT 'zone_ownership_view_own', 'User is allowed to see the owners of zones he owns.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_items" WHERE "name" = 'zone_ownership_view_own');

INSERT INTO "perm_items" ("name", "descr")
SELECT 'zone_ownership_view_others', 'User is allowed to see the owners of zones he does not own.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_items" WHERE "name" = 'zone_ownership_view_others');

-- Databases seeded with explicit ids may have a stale sequence; realign it
-- before the grant inserts below draw new ids from it.
SELECT setval('perm_templ_items_id_seq', (SELECT COALESCE(MAX(id), 1) FROM perm_templ_items));

INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pti."templ_id", np."id"
FROM "perm_templ_items" pti
JOIN "perm_items" cp ON cp."id" = pti."perm_id" AND cp."name" = 'zone_content_view_own'
JOIN "perm_items" np ON np."name" = 'zone_metadata_view_own'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ_items" x WHERE x."templ_id" = pti."templ_id" AND x."perm_id" = np."id");

INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pti."templ_id", np."id"
FROM "perm_templ_items" pti
JOIN "perm_items" cp ON cp."id" = pti."perm_id" AND cp."name" = 'zone_content_view_own'
JOIN "perm_items" np ON np."name" = 'zone_ownership_view_own'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ_items" x WHERE x."templ_id" = pti."templ_id" AND x."perm_id" = np."id");

INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pti."templ_id", np."id"
FROM "perm_templ_items" pti
JOIN "perm_items" cp ON cp."id" = pti."perm_id" AND cp."name" = 'zone_content_view_others'
JOIN "perm_items" np ON np."name" = 'zone_metadata_view_others'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ_items" x WHERE x."templ_id" = pti."templ_id" AND x."perm_id" = np."id");

INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pti."templ_id", np."id"
FROM "perm_templ_items" pti
JOIN "perm_items" cp ON cp."id" = pti."perm_id" AND cp."name" = 'zone_content_view_others'
JOIN "perm_items" np ON np."name" = 'zone_ownership_view_others'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ_items" x WHERE x."templ_id" = pti."templ_id" AND x."perm_id" = np."id");

-- Content view no longer covers metadata; keep the catalog text accurate.
UPDATE "perm_items" SET "descr" = 'User is allowed to see the content of zones he owns.'
WHERE "name" = 'zone_content_view_own';

UPDATE "perm_items" SET "descr" = 'User is allowed to see the content of zones he does not own.'
WHERE "name" = 'zone_content_view_others';
