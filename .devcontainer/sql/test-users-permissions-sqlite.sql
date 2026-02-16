-- SQLite Test Data: Users and Permission Templates
-- Purpose: Create comprehensive test users with different permission levels for development
-- Database: Poweradmin (SQLite)
--
-- This script creates:
-- - 4 permission templates (Administrator already exists as template #1)
-- - 5 test users with different permission levels
-- - 4 test domains with various ownership patterns including multi-owner zones
--
-- Usage: sqlite3 /data/pdns.db < test-users-permissions-sqlite.sql
--
-- For devcontainer: All tables (both Poweradmin and PowerDNS) are in /data/pdns.db.
-- The script attaches the same database as 'pdns' alias for consistency with
-- MySQL/PostgreSQL scripts that use separate databases.

-- =============================================================================
-- ATTACH DATABASE ALIAS
-- =============================================================================
-- Create 'pdns' alias for consistent table references across database types
ATTACH DATABASE '/data/pdns.db' AS pdns;

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================
-- Recreate templates 1-5 in case they were modified/deleted by previous E2E tests

INSERT OR REPLACE INTO "perm_templ" ("id", "name", "descr") VALUES
    (1, 'Administrator', 'Administrator template with full rights.');
INSERT OR REPLACE INTO "perm_templ" ("id", "name", "descr") VALUES
    (2, 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates.');
INSERT OR REPLACE INTO "perm_templ" ("id", "name", "descr") VALUES
    (3, 'DNS Editor', 'Edit own zone records but cannot modify SOA and NS records.');
INSERT OR REPLACE INTO "perm_templ" ("id", "name", "descr") VALUES
    (4, 'Read Only', 'Read-only access to own zones with search capability.');
INSERT OR REPLACE INTO "perm_templ" ("id", "name", "descr") VALUES
    (5, 'No Access', 'Template with no permissions assigned. Suitable for inactive accounts or users pending permission assignment.');

-- Recreate Administrator permissions (template 1)
INSERT OR IGNORE INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT 1, "id" FROM "perm_items" WHERE "name" = 'user_is_ueberuser';

-- Recreate Zone Manager permissions (template 2)
INSERT OR IGNORE INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT 2, "id" FROM "perm_items" WHERE "name" IN (
    'zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
    'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
    'api_manage_keys', 'zone_delete_own'
);

-- Recreate DNS Editor permissions (template 3)
INSERT OR IGNORE INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT 3, "id" FROM "perm_items" WHERE "name" IN (
    'zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client'
);

-- Recreate Read Only permissions (template 4)
INSERT OR IGNORE INTO "perm_templ_items" ("templ_id", "perm_id")
SELECT 4, "id" FROM "perm_items" WHERE "name" IN (
    'zone_content_view_own', 'search'
);

-- Template 5 (No Access) has no permissions

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "Poweradmin123" (bcrypt hashed)
-- Use: password_hash('Poweradmin123', PASSWORD_BCRYPT, ['cost' => 12])

-- Use NOT EXISTS to skip users if they already exist
INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'admin', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'System Administrator', 'admin@example.com', 'Full system administrator with überuser access', 1, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'admin');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'manager', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'Zone Manager', 'manager@example.com', 'Can manage own zones and templates', 2, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'manager');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'client', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'Client User', 'client@example.com', 'Limited editing rights - cannot edit SOA/NS records', 3, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'client');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'viewer', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'Read Only User', 'viewer@example.com', 'Read-only access to zones', 4, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'viewer');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'noperm', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'No Permission User', 'noperm@example.com', 'Active user with no permissions', 5, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'noperm');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'inactive', '$2y$10$39tapIc.ibhXb8xHHfAPrOf.RQZHXhYsQNiVdqY0POC4GD6HNg43u', 'Inactive User', 'inactive@example.com', 'Inactive account - cannot login', 5, 0, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'inactive');

-- =============================================================================
-- API KEY FOR AUTOMATED TESTING
-- =============================================================================
-- API key linked to admin user for API test suite (tests/api/)

INSERT INTO "api_keys" ("name", "secret_key", "created_by", "disabled")
SELECT 'Automated Testing Key', 'test-api-key-for-automated-testing-12345', u."id", 0
FROM "users" u
WHERE u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "api_keys" WHERE "secret_key" = 'test-api-key-for-automated-testing-12345');

-- =============================================================================
-- TEST DOMAINS
-- =============================================================================

-- Create test domains in PowerDNS (using pdns alias, skip if they already exist)
INSERT OR IGNORE INTO pdns."domains" ("name", "type") VALUES
('admin-zone.example.com', 'MASTER'),
('manager-zone.example.com', 'MASTER'),
('client-zone.example.com', 'MASTER'),
('shared-zone.example.com', 'MASTER'),
('xn--verstt-eua3l.info', 'MASTER'),
('xn--mnchen-3ya.de', 'MASTER'),
('xn--80aejmjbdxvpe2k.net', 'MASTER'),
('xn--ob0bz7i69i99fm8qgkfwlc.com', 'MASTER'),
('xn--chtnbin-rwa9e0573b.vn', 'MASTER'),
('test858.example.com', 'MASTER'),
('168.192.in-addr.arpa', 'MASTER');

-- Add SOA records for each domain (required for PowerDNS)
-- Use NOT EXISTS to prevent duplicate records
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT
    d."id",
    d."name",
    'SOA',
    'ns1.example.com. hostmaster.example.com. ' || CAST(strftime('%s', 'now') AS INTEGER) || ' 10800 3600 604800 86400',
    86400,
    0
FROM pdns."domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--mnchen-3ya.de', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'SOA'
  );

-- Add NS records for each domain
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns1.example.com.', 86400, 0
FROM pdns."domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--mnchen-3ya.de', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns1.example.com.'
  );

INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns2.example.com.', 86400, 0
FROM pdns."domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--mnchen-3ya.de', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns2.example.com.'
  );

-- Add some sample A records
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", 'www.' || d."name", 'A', '192.0.2.1', 3600, 0
FROM pdns."domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."name" = 'www.' || d."name" AND r."type" = 'A'
  );

-- =============================================================================
-- ZONE OWNERSHIP (Poweradmin)
-- =============================================================================

-- Admin owns admin-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'admin-zone.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Manager owns manager-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'manager-zone.example.com' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Client owns client-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'client-zone.example.com' AND u."username" = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Shared zone with MULTIPLE OWNERS (manager and client both own it)
-- This tests the multi-owner functionality
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'shared-zone.example.com'
  AND u."username" IN ('manager', 'client')
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Admin owns test858.example.com (for issue #858 comment testing)
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'test858.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Admin owns 168.192.in-addr.arpa reverse zone (for A/PTR sync testing)
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = '168.192.in-addr.arpa' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- =============================================================================
-- IDN ZONES OWNERSHIP
-- =============================================================================

-- Swedish IDN (översätt.info) owned by manager
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--verstt-eua3l.info' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- German IDN (münchen.de) owned by admin
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--mnchen-3ya.de' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Russian IDN (автоэлектрик.net) owned by manager
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--80aejmjbdxvpe2k.net' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Korean IDN (베스트공포닷컴.com) owned by client
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--ob0bz7i69i99fm8qgkfwlc.com' AND u."username" = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Vietnamese IDN (chợtânbiên.vn) owned by viewer
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--chtnbin-rwa9e0573b.vn' AND u."username" = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- =============================================================================
-- IDN ZONES OWNERSHIP
-- =============================================================================

-- Swedish IDN (översätt.info) owned by manager
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--verstt-eua3l.info' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- German IDN (münchen.de) owned by admin
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--mnchen-3ya.de' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Russian IDN (автоэлектрик.net) owned by manager
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--80aejmjbdxvpe2k.net' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Korean IDN (베스트공포닷컴.com) owned by client
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--ob0bz7i69i99fm8qgkfwlc.com' AND u."username" = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Vietnamese IDN (chợtânbiên.vn) owned by viewer
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d
CROSS JOIN "users" u
WHERE d."name" = 'xn--chtnbin-rwa9e0573b.vn' AND u."username" = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================

-- Verify permission templates
SELECT
    pt."id",
    pt."name",
    COUNT(pti."id") as "permission_count"
FROM "perm_templ" pt
LEFT JOIN "perm_templ_items" pti ON pt."id" = pti."templ_id"
GROUP BY pt."id", pt."name"
ORDER BY pt."id";

-- Verify users and their templates
SELECT
    u."username",
    u."fullname",
    u."email",
    u."active",
    pt."name" as "permission_template"
FROM "users" u
LEFT JOIN "perm_templ" pt ON u."perm_templ" = pt."id"
WHERE u."username" IN ('admin', 'manager', 'client', 'viewer', 'noperm', 'inactive')
ORDER BY u."id";

-- Verify zones and ownership (including multi-owner zones)
SELECT
    d."name" as "domain",
    d."type",
    u."username" as "owner"
FROM pdns."domains" d
JOIN "zones" z ON d."id" = z."domain_id"
JOIN "users" u ON z."owner" = u."id"
WHERE d."name" LIKE '%.example.com'
ORDER BY d."name", u."username";

-- Count owners per domain
SELECT
    d."name" as "domain",
    COUNT(DISTINCT z."owner") as "owner_count"
FROM pdns."domains" d
JOIN "zones" z ON d."id" = z."domain_id"
WHERE d."name" LIKE '%.example.com'
GROUP BY d."id", d."name"
ORDER BY d."name";

-- =============================================================================
-- SUMMARY
-- =============================================================================
--
-- Test Users Created:
-- -------------------
-- Username  | Password       | Template        | Active | Description
-- ----------|----------------|-----------------|--------|---------------------------
-- admin     | Poweradmin123  | Administrator   | Yes    | Full system access (überuser)
-- manager   | Poweradmin123  | Zone Manager    | Yes    | Can manage own zones (11 perms)
-- client    | Poweradmin123  | Client Editor   | Yes    | Limited editing, no SOA/NS (4 perms)
-- viewer    | Poweradmin123  | Read Only       | Yes    | View-only access (2 perms)
-- noperm    | Poweradmin123  | No Access       | Yes    | Can login but has no permissions (0 perms)
-- inactive  | Poweradmin123  | No Access       | No     | Cannot login - inactive account
--
-- Test Domains Created:
-- ---------------------
-- Domain                      | Owner(s)
-- ----------------------------|------------------
-- admin-zone.example.com      | admin
-- manager-zone.example.com    | manager
-- client-zone.example.com     | client
-- shared-zone.example.com     | manager, client (multi-owner)
--
-- IDN Zones (Internationalized Domain Names):
-- -------------------------------------------
-- Punycode                    | Display Name           | Owner
-- ----------------------------|------------------------|--------
-- xn--verstt-eua3l.info       | översätt.info          | manager (Swedish)
-- xn--mnchen-3ya.de           | münchen.de             | admin (German)
-- xn--80aejmjbdxvpe2k.net     | автоэлектрик.net       | manager (Russian)
-- xn--ob0bz7i69i99fm8qgkfwlc.com | 베스트공포닷컴.com  | client (Korean)
-- xn--chtnbin-rwa9e0573b.vn   | chợtânbiên.vn          | viewer (Vietnamese)
--
-- =============================================================================
