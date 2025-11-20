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

-- Add zone deletion permissions (Issue #97)
-- Separate permissions for zone deletion to allow fine-grained control
-- Previously, zone deletion was tied to zone_content_edit_* permissions
INSERT INTO perm_items (id, name, descr) VALUES
    (67, 'zone_delete_own', 'User is allowed to delete zones they own.'),
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

-- Add standard permission templates for common use cases
-- These templates provide out-of-the-box permission profiles for typical DNS hosting scenarios
-- If a template with the same name already exists, this will be skipped (WHERE NOT EXISTS)

-- Zone Manager: Full self-service zone management
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Zone Manager');

-- DNS Editor: Basic record editing without SOA/NS access
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'DNS Editor', 'Edit own zone records but cannot modify SOA and NS records.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'DNS Editor');

-- Read Only: View-only access for auditing
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'Read Only', 'Read-only access to own zones with search capability.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'Read Only');

-- No Access: Placeholder for inactive/suspended accounts
INSERT INTO "perm_templ" ("name", "descr")
SELECT 'No Access', 'Template with no permissions assigned. Suitable for inactive accounts or users pending permission assignment.'
WHERE NOT EXISTS (SELECT 1 FROM "perm_templ" WHERE "name" = 'No Access');

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

-- Assign permissions to DNS Editor template
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'DNS Editor'
AND pi.name IN ('zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- Assign permissions to Read Only template
INSERT INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT pt.id, pi.id
FROM "perm_templ" pt
CROSS JOIN "perm_items" pi
WHERE pt.name = 'Read Only'
AND pi.name IN ('zone_content_view_own', 'search')
AND NOT EXISTS (
    SELECT 1 FROM "perm_templ_items" pti
    WHERE pti.templ_id = pt.id AND pti.perm_id = pi.id
);

-- No Access template intentionally has no permissions assigned

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
    "domain_id" INTEGER NOT NULL REFERENCES "domains"("id") ON DELETE CASCADE,
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
