CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    ip_address VARCHAR(45) NOT NULL,
    timestamp INTEGER NOT NULL,
    successful BOOLEAN NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_login_attempts_user_id ON login_attempts(user_id);
CREATE INDEX idx_login_attempts_ip_address ON login_attempts(ip_address);
CREATE INDEX idx_login_attempts_timestamp ON login_attempts(timestamp);

CREATE TABLE migrations (
    version INTEGER PRIMARY KEY,
    migration_name VARCHAR(100) NULL,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    breakpoint BOOLEAN NOT NULL DEFAULT 0
);

CREATE TABLE api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    secret_key VARCHAR(255) NOT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    disabled BOOLEAN NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_api_keys_secret_key ON api_keys(secret_key);
CREATE INDEX idx_api_keys_created_by ON api_keys(created_by);
CREATE INDEX idx_api_keys_disabled ON api_keys(disabled);

CREATE TABLE user_mfa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    secret VARCHAR(255) NULL,
    recovery_codes TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'app',
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    verification_data TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_mfa_user_id ON user_mfa(user_id);
CREATE INDEX idx_user_mfa_enabled ON user_mfa(enabled);

-- Add zone template permissions
INSERT INTO perm_items (id, name, descr) VALUES
(63, 'zone_templ_add', 'User is allowed to add new zone templates.'),
(64, 'zone_templ_edit', 'User is allowed to edit existing zone templates.');

-- Add created_by column to zone_templ table
ALTER TABLE zone_templ ADD COLUMN created_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
UPDATE zone_templ SET created_by = owner WHERE owner != 0;

-- Add user_preferences table
CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_preferences_user_key ON user_preferences(user_id, preference_key);
CREATE INDEX idx_user_preferences_user_id ON user_preferences(user_id);

-- Add zone_template_sync table
CREATE TABLE zone_template_sync (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    zone_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL,
    last_synced TIMESTAMP NULL,
    template_last_modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    needs_sync INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_templ_id) REFERENCES zone_templ(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_zone_template_unique ON zone_template_sync(zone_id, zone_templ_id);
CREATE INDEX idx_zone_templ_id ON zone_template_sync(zone_templ_id);
CREATE INDEX idx_needs_sync ON zone_template_sync(needs_sync);

-- Initialize sync records for existing zone-template relationships
INSERT OR IGNORE INTO zone_template_sync (zone_id, zone_templ_id, needs_sync, last_synced)
SELECT z.id, z.zone_templ_id, 0, datetime('now')
FROM zones z
WHERE z.zone_templ_id > 0;

-- Add password_reset_tokens table for password reset functionality
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used INTEGER NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_prt_expires ON password_reset_tokens(expires_at);

-- Add user_agreements table for user agreement functionality
CREATE TABLE IF NOT EXISTS user_agreements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    agreement_version VARCHAR(50) NOT NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS unique_user_agreement ON user_agreements(user_id, agreement_version);
CREATE INDEX IF NOT EXISTS idx_user_agreements_user_id ON user_agreements(user_id);
CREATE INDEX IF NOT EXISTS idx_user_agreements_version ON user_agreements(agreement_version);
