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

-- Add SAML user links table for SAML authentication
CREATE TABLE IF NOT EXISTS saml_user_links (
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

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_saml_provider_id ON saml_user_links(provider_id);
CREATE INDEX IF NOT EXISTS idx_saml_subject ON saml_user_links(saml_subject);

-- Add performance indexes to existing tables
-- Issue: Missing indexes on foreign key columns caused slow queries with large datasets

CREATE INDEX IF NOT EXISTS idx_log_zones_zone_id ON log_zones(zone_id);
CREATE INDEX IF NOT EXISTS idx_users_perm_templ ON users(perm_templ);
CREATE INDEX IF NOT EXISTS idx_perm_templ_items_templ_id ON perm_templ_items(templ_id);
CREATE INDEX IF NOT EXISTS idx_perm_templ_items_perm_id ON perm_templ_items(perm_id);
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_domain_id ON records_zone_templ(domain_id);
CREATE INDEX IF NOT EXISTS idx_records_zone_templ_zone_templ_id ON records_zone_templ(zone_templ_id);
CREATE INDEX IF NOT EXISTS idx_zones_zone_templ_id ON zones(zone_templ_id);
CREATE INDEX IF NOT EXISTS idx_zone_templ_owner ON zone_templ(owner);
CREATE INDEX IF NOT EXISTS idx_zone_templ_created_by ON zone_templ(created_by);
CREATE INDEX IF NOT EXISTS idx_zone_templ_records_zone_templ_id ON zone_templ_records(zone_templ_id);

-- Add username_recovery_requests table for username recovery functionality
-- This table tracks username recovery requests for rate limiting and security
CREATE TABLE IF NOT EXISTS username_recovery_requests (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_urr_email ON username_recovery_requests(email);
CREATE INDEX IF NOT EXISTS idx_urr_ip ON username_recovery_requests(ip_address);
CREATE INDEX IF NOT EXISTS idx_urr_created ON username_recovery_requests(created_at);
