-- ============================================================================
-- Poweradmin 4.2.0 Migration (SQLite)
-- ============================================================================

-- Rename default permission templates for consistency
-- DNS Editor -> Editor, Read Only -> Viewer, No Access -> Guest
UPDATE perm_templ SET name = 'Editor', descr = 'Edit own zone records but cannot modify SOA and NS records.'
WHERE name = 'DNS Editor';

UPDATE perm_templ SET name = 'Viewer', descr = 'Read-only access to own zones with search capability.'
WHERE name = 'Read Only';

UPDATE perm_templ SET name = 'Guest', descr = 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.'
WHERE name = 'No Access';

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

-- Add MFA enforcement permission
-- This permission allows enforcing MFA for users/groups when mfa.enforced is enabled
INSERT OR IGNORE INTO perm_items (name, descr) VALUES
    ('user_enforce_mfa', 'User is required to use multi-factor authentication.');

-- ============================================================================
-- Per-Record Comments Support (Issue #858)
-- ============================================================================
-- Create linking table to associate individual records with comments
-- This allows per-record comments instead of per-RRset comments
-- The PowerDNS comments table stores comments by (domain_id, name, type),
-- this linking table maps individual record IDs to specific comment IDs

CREATE TABLE IF NOT EXISTS record_comment_links (
    record_id INTEGER NOT NULL,
    comment_id INTEGER NOT NULL,
    PRIMARY KEY (record_id),
    UNIQUE (comment_id)
);

CREATE INDEX IF NOT EXISTS idx_record_comment_links_comment ON record_comment_links(comment_id);
