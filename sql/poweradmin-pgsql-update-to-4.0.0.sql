CREATE TABLE login_attempts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NULL,
    ip_address VARCHAR(45) NOT NULL,
    "timestamp" INTEGER NOT NULL,
    successful BOOLEAN NOT NULL,
    CONSTRAINT fk_login_attempts_users
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_login_attempts_user_id ON login_attempts(user_id);
CREATE INDEX idx_login_attempts_ip_address ON login_attempts(ip_address);
CREATE INDEX idx_login_attempts_timestamp ON login_attempts("timestamp");

CREATE TABLE migrations (
    version BIGINT PRIMARY KEY,
    migration_name VARCHAR(100) NULL,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    breakpoint BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE api_keys (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    secret_key VARCHAR(255) NOT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    disabled BOOLEAN NOT NULL DEFAULT FALSE,
    expires_at TIMESTAMP NULL,
    CONSTRAINT fk_api_keys_users FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_api_keys_secret_key ON api_keys(secret_key);
CREATE INDEX idx_api_keys_created_by ON api_keys(created_by);
CREATE INDEX idx_api_keys_disabled ON api_keys(disabled);

CREATE TABLE user_mfa (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    secret VARCHAR(255) NULL,
    recovery_codes TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'app',
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    verification_data TEXT NULL,
    CONSTRAINT fk_user_mfa_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_mfa_user_id ON user_mfa(user_id);
CREATE INDEX idx_user_mfa_enabled ON user_mfa(enabled);

-- Add zone template permissions
INSERT INTO perm_items (id, name, descr) VALUES
(63, 'zone_templ_add', 'User is allowed to add new zone templates.'),
(64, 'zone_templ_edit', 'User is allowed to edit existing zone templates.');

-- Add created_by column to zone_templ table
ALTER TABLE zone_templ ADD COLUMN created_by INTEGER;
UPDATE zone_templ SET created_by = owner WHERE owner != 0;
ALTER TABLE zone_templ ADD CONSTRAINT fk_zone_templ_users FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- Add user_preferences table
CREATE TABLE user_preferences (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NULL,
    CONSTRAINT fk_user_preferences_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_preferences_user_key ON user_preferences(user_id, preference_key);
CREATE INDEX idx_user_preferences_user_id ON user_preferences(user_id);
