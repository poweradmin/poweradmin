-- Add API key management permission
INSERT OR IGNORE INTO perm_items (name, descr) VALUES
('api_manage_keys', 'User is allowed to create and manage API keys.');

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
INSERT OR IGNORE INTO perm_items (name, descr) VALUES
    ('zone_delete_own', 'User is allowed to delete zones they own.');
INSERT OR IGNORE INTO perm_items (name, descr) VALUES
    ('zone_delete_others', 'User is allowed to delete zones owned by others.');

-- Grant delete permissions to users with existing edit permissions
-- This ensures backward compatibility - users who could edit can now delete
-- Users with edit_own get delete_own
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT DISTINCT templ_id, (SELECT id FROM perm_items WHERE name = 'zone_delete_own')
FROM perm_templ_items
WHERE perm_id = 44; -- zone_content_edit_own

-- Users with edit_others get delete_others
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT DISTINCT templ_id, (SELECT id FROM perm_items WHERE name = 'zone_delete_others')
FROM perm_templ_items
WHERE perm_id = 47; -- zone_content_edit_others

-- Note: Users with 'user_is_ueberuser' (id 53) automatically have all permissions

-- Add standard permission templates for common use cases
-- These templates provide out-of-the-box permission profiles for typical DNS hosting scenarios
-- If a template with the same name already exists, this will be skipped (WHERE NOT EXISTS)

-- Zone Manager: Full self-service zone management
INSERT INTO perm_templ (name, descr)
SELECT 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Zone Manager');

-- Editor: Basic record editing without SOA/NS access
INSERT INTO perm_templ (name, descr)
SELECT 'Editor', 'Edit own zone records but cannot modify SOA and NS records.'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Editor');

-- Viewer: View-only access for auditing
INSERT INTO perm_templ (name, descr)
SELECT 'Viewer', 'Read-only access to own zones with search capability.'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Viewer');

-- Guest: Placeholder for temporary/pending users
INSERT INTO perm_templ (name, descr)
SELECT 'Guest', 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Guest');

-- Assign permissions to Zone Manager template
-- Only insert if the template exists and permission doesn't already exist
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Zone Manager'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Editor template
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Editor'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewer template
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Viewer'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Guest template intentionally has no permissions assigned

-- ============================================================================
-- Group-Based Permissions (Issue #480)
-- ============================================================================

-- Table: user_groups
-- Description: Stores user groups with permission templates
CREATE TABLE IF NOT EXISTS user_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    perm_templ INTEGER NOT NULL,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (perm_templ) REFERENCES perm_templ(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_user_groups_perm_templ ON user_groups(perm_templ);
CREATE INDEX IF NOT EXISTS idx_user_groups_created_by ON user_groups(created_by);
CREATE INDEX IF NOT EXISTS idx_user_groups_name ON user_groups(name);

-- Trigger for updated_at column
CREATE TRIGGER IF NOT EXISTS trigger_user_groups_updated_at
    AFTER UPDATE ON user_groups
    FOR EACH ROW
BEGIN
    UPDATE user_groups SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Table: user_group_members
-- Description: Junction table for user-group membership (many-to-many)
CREATE TABLE IF NOT EXISTS user_group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_group_members_user ON user_group_members(user_id);
CREATE INDEX IF NOT EXISTS idx_user_group_members_group ON user_group_members(group_id);

-- Modify zones table to allow nullable owner for group-only ownership
-- Description: Allow zones to be owned only by groups without requiring a user owner
-- Note: SQLite doesn't support ALTER COLUMN, so we need to recreate the table
BEGIN TRANSACTION;

-- Create new zones table with nullable owner
CREATE TABLE zones_new (
    id INTEGER PRIMARY KEY,
    domain_id INTEGER NOT NULL,
    owner INTEGER NULL DEFAULT NULL,
    comment VARCHAR(1024),
    zone_templ_id INTEGER NOT NULL
);

-- Copy data from old table
INSERT INTO zones_new (id, domain_id, owner, comment, zone_templ_id)
SELECT id, domain_id, owner, comment, zone_templ_id FROM zones;

-- Drop old table
DROP TABLE zones;

-- Rename new table
ALTER TABLE zones_new RENAME TO zones;

-- Recreate indexes
CREATE INDEX idx_zones_domain_id ON zones(domain_id);
CREATE INDEX idx_zones_owner ON zones(owner);
CREATE INDEX idx_zones_zone_templ_id ON zones(zone_templ_id);

COMMIT;

-- Table: zones_groups
-- Description: Junction table for zone-group ownership (many-to-many)
CREATE TABLE IF NOT EXISTS zones_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (domain_id, group_id),
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_zones_groups_domain ON zones_groups(domain_id);
CREATE INDEX IF NOT EXISTS idx_zones_groups_group ON zones_groups(group_id);

-- Table: log_groups
-- Description: Audit log for group operations (create, update, delete, member/zone changes)
CREATE TABLE IF NOT EXISTS log_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event VARCHAR(2048) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    priority INTEGER NOT NULL,
    group_id INTEGER DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_log_groups_group_id ON log_groups(group_id);

-- ============================================================================
-- Permission Template Types (distinguish user vs group templates)
-- ============================================================================

-- SQLite doesn't support ALTER TABLE ADD COLUMN with constraints easily
-- So we need to recreate the table

-- Create new table with template_type column
CREATE TABLE perm_templ_new (
    id integer PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    descr VARCHAR(1024) NOT NULL,
    template_type VARCHAR(10) NOT NULL DEFAULT 'user',
    CHECK(template_type IN ('user', 'group'))
);

-- Copy data from old table
INSERT INTO perm_templ_new (id, name, descr, template_type)
SELECT id, name, descr, 'user' FROM perm_templ;

-- Drop old table
DROP TABLE perm_templ;

-- Rename new table
ALTER TABLE perm_templ_new RENAME TO perm_templ;

-- ============================================================================
-- Group-type Permission Templates
-- ============================================================================
-- Create permission templates with template_type='group' for use with user groups
-- These have the same permissions as user templates but are specifically for groups

INSERT INTO perm_templ (name, descr, template_type)
SELECT 'Administrators', 'Full administrative access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Administrators' AND template_type = 'group');

INSERT INTO perm_templ (name, descr, template_type)
SELECT 'Zone Managers', 'Full zone management for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Zone Managers' AND template_type = 'group');

INSERT INTO perm_templ (name, descr, template_type)
SELECT 'Editors', 'Edit zone records (no SOA/NS) for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Editors' AND template_type = 'group');

INSERT INTO perm_templ (name, descr, template_type)
SELECT 'Viewers', 'Read-only zone access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Viewers' AND template_type = 'group');

INSERT INTO perm_templ (name, descr, template_type)
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE name = 'Guests' AND template_type = 'group');

-- Guests group template intentionally has no permissions assigned

-- Assign permissions to Administrators group template (same as Administrator user template)
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND pi.name = 'user_is_ueberuser'
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Zone Managers group template (same as Zone Manager user template)
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Editors group template (same as DNS Editor user template)
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewers group template (same as Read Only user template)
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT pt.id, pi.id
FROM perm_templ pt
CROSS JOIN perm_items pi
WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM perm_templ_items pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- ============================================================================
-- Default User Groups
-- ============================================================================
-- Create default user groups that map to group-type permission templates
-- These groups can be used for LDAP group mapping in the future

INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Administrators', 'Full administrative access to all system functions.', pt.id, NULL
FROM perm_templ pt WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM user_groups WHERE name = 'Administrators');

INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Zone Managers', 'Full zone management including creation, editing, and deletion.', pt.id, NULL
FROM perm_templ pt WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM user_groups WHERE name = 'Zone Managers');

INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Editors', 'Edit zone records but cannot modify SOA and NS records.', pt.id, NULL
FROM perm_templ pt WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM user_groups WHERE name = 'Editors');

INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Viewers', 'Read-only access to zones with search capability.', pt.id, NULL
FROM perm_templ pt WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM user_groups WHERE name = 'Viewers');

INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', pt.id, NULL
FROM perm_templ pt WHERE pt.name = 'Guests' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM user_groups WHERE name = 'Guests');
