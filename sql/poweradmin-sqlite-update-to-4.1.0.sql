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
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_urr_email ON username_recovery_requests(email);
CREATE INDEX IF NOT EXISTS idx_urr_ip ON username_recovery_requests(ip_address);
CREATE INDEX IF NOT EXISTS idx_urr_created ON username_recovery_requests(created_at);

-- Add zone deletion permissions (Issue #97)
-- Separate permissions for zone deletion to allow fine-grained control
-- Previously, zone deletion was tied to zone_content_edit_* permissions
INSERT INTO perm_items (id, name, descr) VALUES
    (67, 'zone_delete_own', 'User is allowed to delete zones they own.');
INSERT INTO perm_items (id, name, descr) VALUES
    (68, 'zone_delete_others', 'User is allowed to delete zones owned by others.');

-- Grant delete permissions to users with existing edit permissions
-- This ensures backward compatibility - users who could edit can now delete
-- Users with edit_own get delete_own
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT DISTINCT templ_id, 67
FROM perm_templ_items
WHERE perm_id = 44; -- zone_content_edit_own

-- Users with edit_others get delete_others
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT DISTINCT templ_id, 68
FROM perm_templ_items
WHERE perm_id = 47; -- zone_content_edit_others

-- Note: Users with 'user_is_ueberuser' (id 53) automatically have all permissions
