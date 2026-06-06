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
