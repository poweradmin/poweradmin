-- Add API key management permission
INSERT INTO perm_items (name, descr)
SELECT 'api_manage_keys', 'User is allowed to create and manage API keys.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'api_manage_keys');

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

-- Add zone deletion permissions (Issue #97)
-- Separate permissions for zone deletion to allow fine-grained control
-- Previously, zone deletion was tied to zone_content_edit_* permissions
INSERT INTO perm_items (name, descr)
SELECT 'zone_delete_own', 'User is allowed to delete zones they own.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_delete_own');

INSERT INTO perm_items (name, descr)
SELECT 'zone_delete_others', 'User is allowed to delete zones owned by others.'
WHERE NOT EXISTS (SELECT 1 FROM perm_items WHERE name = 'zone_delete_others');

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
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Zone Manager');

-- Editor: Basic record editing without SOA/NS access
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Editor', 'Edit own zone records but cannot modify SOA and NS records.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Editor');

-- Viewer: View-only access for auditing
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Viewer', 'Read-only access to own zones with search capability.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Viewer');

-- Guest: Placeholder for temporary/pending users
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Guest', 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Guest');

-- Assign permissions to Zone Manager template
-- Only insert if the template exists and permission doesn't already exist
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Zone Manager'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Editor template
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Editor'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewer template
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Viewer'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Guest template intentionally has no permissions assigned

-- ============================================================================
-- Group-Based Permissions (Issue #480)
-- ============================================================================

-- Table: user_groups
-- Description: Stores user groups with permission templates
CREATE TABLE IF NOT EXISTS "user_groups" (
    "id" SERIAL PRIMARY KEY,
    "name" VARCHAR(255) NOT NULL UNIQUE,
    "description" TEXT,
    "perm_templ" INTEGER NOT NULL REFERENCES "perm_templ"("id"),
    "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS "idx_user_groups_perm_templ" ON "user_groups"("perm_templ");
CREATE INDEX IF NOT EXISTS "idx_user_groups_created_by" ON "user_groups"("created_by");
CREATE INDEX IF NOT EXISTS "idx_user_groups_name" ON "user_groups"("name");

-- Trigger for updated_at column
CREATE OR REPLACE FUNCTION update_user_groups_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_user_groups_updated_at
    BEFORE UPDATE ON "user_groups"
    FOR EACH ROW
    EXECUTE FUNCTION update_user_groups_updated_at();

-- Table: user_group_members
-- Description: Junction table for user-group membership (many-to-many)
CREATE TABLE IF NOT EXISTS "user_group_members" (
    "id" SERIAL PRIMARY KEY,
    "group_id" INTEGER NOT NULL REFERENCES "user_groups"("id") ON DELETE CASCADE,
    "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE ("group_id", "user_id")
);

CREATE INDEX IF NOT EXISTS "idx_user_group_members_user" ON "user_group_members"("user_id");
CREATE INDEX IF NOT EXISTS "idx_user_group_members_group" ON "user_group_members"("group_id");

-- Modify zones table to allow nullable owner for group-only ownership
-- Description: Allow zones to be owned only by groups without requiring a user owner
ALTER TABLE "zones" ALTER COLUMN "owner" DROP NOT NULL;
ALTER TABLE "zones" ALTER COLUMN "owner" SET DEFAULT NULL;

-- Table: zones_groups
-- Description: Junction table for zone-group ownership (many-to-many)
CREATE TABLE IF NOT EXISTS "zones_groups" (
    "id" SERIAL PRIMARY KEY,
    "domain_id" INTEGER NOT NULL,
    "group_id" INTEGER NOT NULL REFERENCES "user_groups"("id") ON DELETE CASCADE,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE ("domain_id", "group_id")
);

CREATE INDEX IF NOT EXISTS "idx_zones_groups_domain" ON "zones_groups"("domain_id");
CREATE INDEX IF NOT EXISTS "idx_zones_groups_group" ON "zones_groups"("group_id");

-- Table: log_groups
-- Description: Audit log for group operations (create, update, delete, member/zone changes)
CREATE TABLE IF NOT EXISTS "log_groups" (
    "id" SERIAL PRIMARY KEY,
    "event" VARCHAR(2048) NOT NULL,
    "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "priority" INTEGER NOT NULL,
    "group_id" INTEGER DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS "idx_log_groups_group_id" ON "log_groups"("group_id");

-- ============================================================================
-- Permission Template Types (distinguish user vs group templates)
-- ============================================================================

-- Add template_type column to perm_templ table
ALTER TABLE "public"."perm_templ" ADD COLUMN "template_type" character varying(10) DEFAULT 'user' NOT NULL;

-- Add CHECK constraint for template_type values
ALTER TABLE "public"."perm_templ" ADD CONSTRAINT "perm_templ_template_type_check" CHECK (template_type IN ('user', 'group'));

-- Set default template type for all existing templates
-- All existing templates default to 'user' type
-- Administrators can change this later if templates are used for groups
UPDATE "perm_templ" SET "template_type" = 'user' WHERE "template_type" = 'user';

-- ============================================================================
-- Group-type Permission Templates
-- ============================================================================
-- Create permission templates with template_type='group' for use with user groups
-- These have the same permissions as user templates but are specifically for groups

INSERT INTO "perm_templ" ("name", "descr", "template_type")
SELECT 'Administrators', 'Full administrative access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Administrators' AND "template_type" = 'group');

INSERT INTO "perm_templ" ("name", "descr", "template_type")
SELECT 'Zone Managers', 'Full zone management for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Zone Managers' AND "template_type" = 'group');

INSERT INTO "perm_templ" ("name", "descr", "template_type")
SELECT 'Editors', 'Edit zone records (no SOA/NS) for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Editors' AND "template_type" = 'group');

INSERT INTO "perm_templ" ("name", "descr", "template_type")
SELECT 'Viewers', 'Read-only zone access for group members.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Viewers' AND "template_type" = 'group');

INSERT INTO "perm_templ" ("name", "descr", "template_type")
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Guests' AND "template_type" = 'group');

-- Guests group template intentionally has no permissions assigned

-- Assign permissions to Administrators group template (same as Administrator user template)
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND pi.name = 'user_is_ueberuser'
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Zone Managers group template (same as Zone Manager user template)
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND pi.name IN ('zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                'api_manage_keys', 'zone_delete_own')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Editors group template (same as DNS Editor user template)
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Viewers group template (same as Read Only user template)
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- ============================================================================
-- Default User Groups
-- ============================================================================
-- Create default user groups that map to group-type permission templates
-- These groups can be used for LDAP group mapping in the future

INSERT INTO "user_groups" ("name", "description", "perm_templ", "created_by")
SELECT 'Administrators', 'Full administrative access to all system functions.', pt.id, NULL
FROM "perm_templ" pt WHERE pt.name = 'Administrators' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM "user_groups" WHERE "name" = 'Administrators');

INSERT INTO "user_groups" ("name", "description", "perm_templ", "created_by")
SELECT 'Zone Managers', 'Full zone management including creation, editing, and deletion.', pt.id, NULL
FROM "perm_templ" pt WHERE pt.name = 'Zone Managers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM "user_groups" WHERE "name" = 'Zone Managers');

INSERT INTO "user_groups" ("name", "description", "perm_templ", "created_by")
SELECT 'Editors', 'Edit zone records but cannot modify SOA and NS records.', pt.id, NULL
FROM "perm_templ" pt WHERE pt.name = 'Editors' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM "user_groups" WHERE "name" = 'Editors');

INSERT INTO "user_groups" ("name", "description", "perm_templ", "created_by")
SELECT 'Viewers', 'Read-only access to zones with search capability.', pt.id, NULL
FROM "perm_templ" pt WHERE pt.name = 'Viewers' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM "user_groups" WHERE "name" = 'Viewers');

INSERT INTO "user_groups" ("name", "description", "perm_templ", "created_by")
SELECT 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', pt.id, NULL
FROM "perm_templ" pt WHERE pt.name = 'Guests' AND pt.template_type = 'group'
AND NOT EXISTS (SELECT 1 FROM "user_groups" WHERE "name" = 'Guests');
