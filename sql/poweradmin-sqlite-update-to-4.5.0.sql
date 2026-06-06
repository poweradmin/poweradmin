-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE TABLE IF NOT EXISTS log_record_changes (
    id integer PRIMARY KEY,
    zone_id integer,
    record_id TEXT,
    action VARCHAR(32) NOT NULL,
    user_id integer,
    username VARCHAR(64) NOT NULL,
    before_state TEXT,
    after_state TEXT,
    client_ip VARCHAR(64),
    created_at timestamp DEFAULT current_timestamp NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_log_record_changes_created_at ON log_record_changes(created_at);
CREATE INDEX IF NOT EXISTS idx_log_record_changes_zone_id ON log_record_changes(zone_id);
CREATE INDEX IF NOT EXISTS idx_log_record_changes_action ON log_record_changes(action);

-- Mapping table for API-backed zones whose record IDs are encoded strings
-- (RecordIdentifier base64url) and don't fit in records_zone_templ.record_id
-- (integer). Populated only when applying templates against PowerDNS via the API.
CREATE TABLE IF NOT EXISTS records_zone_templ_api (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id integer NOT NULL,
    record_id text NOT NULL,
    zone_templ_id integer NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_records_zone_templ_api_domain_id ON records_zone_templ_api(domain_id);
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_api_zone_templ_id ON records_zone_templ_api(zone_templ_id);

-- Per-record-type default TTLs managed via the admin UI (closes #1032).
-- When a record is created without an explicit TTL, the server first looks
-- up the type in this table; if no row exists the legacy fallback chain
-- (dns.ttl_reverse for PTR, then dns.ttl) applies.
CREATE TABLE IF NOT EXISTS record_type_defaults (
    record_type text NOT NULL PRIMARY KEY,
    ttl integer NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Generic admin-managed settings, layered above config/settings.php.
-- AppSettingsService reads this table first and falls back to ConfigurationManager.
-- No setting is migrated automatically; this is plumbing for future features.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key text NOT NULL PRIMARY KEY,
    setting_value text NOT NULL,
    value_type text NOT NULL DEFAULT 'string',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add DEFAULT 0 to zones.zone_templ_id so INSERTs that omit the column land
-- on the "no template" sentinel used by every active write path. SQLite has
-- no ALTER COLUMN ... SET DEFAULT, so the table is rebuilt; foreign_keys are
-- toggled off to let DROP TABLE succeed when log_zones / zone_template_sync
-- still reference it, then re-enabled after the swap.
PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;
CREATE TABLE zones_new (
    id integer PRIMARY KEY,
    domain_id integer NULL DEFAULT NULL,
    owner integer NULL DEFAULT NULL,
    comment VARCHAR(1024),
    zone_templ_id integer NOT NULL DEFAULT 0,
    zone_name VARCHAR(255) DEFAULT NULL,
    zone_type VARCHAR(8) DEFAULT NULL,
    zone_master VARCHAR(255) DEFAULT NULL
);
INSERT INTO zones_new (id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type, zone_master)
    SELECT id, domain_id, owner, comment, zone_templ_id, zone_name, zone_type, zone_master FROM zones;
DROP TABLE zones;
ALTER TABLE zones_new RENAME TO zones;
CREATE INDEX IF NOT EXISTS idx_zones_domain_id ON zones(domain_id);
CREATE INDEX IF NOT EXISTS idx_zones_owner ON zones(owner);
CREATE INDEX IF NOT EXISTS idx_zones_zone_templ_id ON zones(zone_templ_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_zones_zone_name ON zones(zone_name);
COMMIT;
PRAGMA foreign_keys = ON;

-- Register zone_dnssec_manage_own so admins can grant DNSSEC key management
-- separately from general zone editing. Existing templates are NOT auto-granted
-- the new permission; admins must opt in via the permission template editor.
-- perm_items.name has no unique constraint, so guard against duplicates by hand.
INSERT INTO perm_items (name, descr)
SELECT 'zone_dnssec_manage_own', 'User is allowed to manage DNSSEC keys for zones he owns.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_dnssec_manage_own');

-- password_reset_tokens.token VARCHAR is advisory in SQLite; existing rows
-- accept the wider sha256$<64hex> value without ALTER. The structure file is
-- updated to keep declarations consistent across databases.

-- Tag each login_attempts row with the authentication stage that produced it
-- (password / mfa). Lets MfaVerifyController throttle the second factor without
-- letting a fresh first-factor success clear the MFA failure counter.
ALTER TABLE login_attempts ADD COLUMN attempt_type VARCHAR(16) NOT NULL DEFAULT 'password';
CREATE INDEX IF NOT EXISTS idx_login_attempts_attempt_type ON login_attempts(attempt_type);

-- Granular API key permissions (closes #795). New columns and the api_key_zones
-- table are optional: existing keys get is_readonly=0, allowed_operations=NULL and
-- no zone rows, which means "unrestricted" - identical to the previous behavior.
ALTER TABLE api_keys ADD COLUMN is_readonly BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE api_keys ADD COLUMN allowed_operations VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS api_key_zones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    api_key_id INTEGER NOT NULL,
    zone_id INTEGER NOT NULL,
    UNIQUE (api_key_id, zone_id),
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_api_key_zones_api_key_id ON api_key_zones(api_key_id);
CREATE INDEX IF NOT EXISTS idx_api_key_zones_zone_id ON api_key_zones(zone_id);
