-- Fix password_reset_tokens used field default value if it exists but lacks proper default
-- SQLite doesn't support ALTER COLUMN, so we check if the table exists and recreate if needed
-- This handles cases where the 4.0.0 migration created the table but the used field doesn't have DEFAULT 0

-- Create a new table with correct schema if password_reset_tokens exists
CREATE TABLE IF NOT EXISTS password_reset_tokens_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used INTEGER NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL
);

-- Copy data from old table if it exists
INSERT OR IGNORE INTO password_reset_tokens_new (id, email, token, expires_at, created_at, used, ip_address)
SELECT id, email, token, expires_at, created_at, COALESCE(used, 0), ip_address 
FROM password_reset_tokens 
WHERE EXISTS (SELECT name FROM sqlite_master WHERE type='table' AND name='password_reset_tokens');

-- Drop old table and rename new one if old table exists
DROP TABLE IF EXISTS password_reset_tokens;
ALTER TABLE password_reset_tokens_new RENAME TO password_reset_tokens;

-- Recreate indexes
CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_prt_expires ON password_reset_tokens(expires_at);

-- Add API key management permission
INSERT INTO perm_items (id, name, descr) VALUES
(65, 'api_manage_keys', 'User is allowed to create and manage API keys.');

-- Add authentication method column to users table
-- SQLite doesn't support ALTER COLUMN with constraints, so we need to recreate the table
CREATE TABLE users_new (
    id INTEGER PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    password VARCHAR(128) NOT NULL,
    fullname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    description VARCHAR(1024) NOT NULL,
    perm_templ INTEGER NOT NULL,
    active INTEGER NOT NULL,
    use_ldap INTEGER NOT NULL,
    auth_method VARCHAR(20) NOT NULL DEFAULT 'sql'
);

-- Copy existing data
INSERT INTO users_new (id, username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method)
SELECT id, username, password, fullname, email, description, perm_templ, active, use_ldap,
       CASE WHEN use_ldap = 1 THEN 'ldap' ELSE 'sql' END as auth_method
FROM users;

-- Drop old table and rename new one
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;

-- Add OIDC user links table for OpenID Connect authentication
CREATE TABLE IF NOT EXISTS oidc_user_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_oidc_subject ON oidc_user_links(oidc_subject);

-- Add SAML user links table for SAML authentication
CREATE TABLE IF NOT EXISTS saml_user_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_saml_provider_id ON saml_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_saml_subject ON saml_user_links(saml_subject);
