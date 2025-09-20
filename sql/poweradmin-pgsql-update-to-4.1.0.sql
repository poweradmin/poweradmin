-- Fix password_reset_tokens used field default value if it exists but lacks proper default
-- This handles cases where the 4.0.0 migration created the table but the used field doesn't have DEFAULT FALSE
ALTER TABLE password_reset_tokens ALTER COLUMN used SET DEFAULT FALSE;

-- Add API key management permission
INSERT INTO perm_items (id, name, descr) VALUES
(65, 'api_manage_keys', 'User is allowed to create and manage API keys.');

-- Add authentication method column to users table
ALTER TABLE users ADD COLUMN auth_method VARCHAR(20) NOT NULL DEFAULT 'sql';

-- Update existing LDAP users to use 'ldap' auth method
UPDATE users SET auth_method = 'ldap' WHERE use_ldap = 1;

-- Add OIDC user links table for OpenID Connect authentication
CREATE TABLE IF NOT EXISTS oidc_user_links (
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

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_oidc_subject ON oidc_user_links(oidc_subject);
