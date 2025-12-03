-- PostgreSQL Test Data: Users and Permission Templates
-- Purpose: Create comprehensive test users with different permission levels for development
-- Database: Poweradmin (PostgreSQL)
--
-- This script creates:
-- - 4 permission templates (Administrator already exists as template #1)
-- - 5 test users with different permission levels
-- - 4 test domains with various ownership patterns including multi-owner zones
--
-- Usage: psql -U pdns -d pdns -f test-users-permissions-pgsql.sql

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================
-- Templates #1-5 are preconfigured in the base schema (no additional templates needed)

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "poweradmin123" (bcrypt hashed)
-- Use: password_hash('poweradmin123', PASSWORD_BCRYPT, ['cost' => 12])

-- Use NOT EXISTS to skip users if they already exist
INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'admin', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'System Administrator', 'admin@example.com', 'Full system administrator with überuser access', 1, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'admin');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'manager', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Zone Manager', 'manager@example.com', 'Can manage own zones and templates', 2, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'manager');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'client', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Client User', 'client@example.com', 'Limited editing rights - cannot edit SOA/NS records', 3, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'client');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'viewer', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Read Only User', 'viewer@example.com', 'Read-only access to zones', 4, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'viewer');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'noperm', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'No Permission User', 'noperm@example.com', 'Active user with no permissions', 5, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'noperm');

INSERT INTO "users" ("username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap", "auth_method")
SELECT 'inactive', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Inactive User', 'inactive@example.com', 'Inactive account - cannot login', 5, 0, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM "users" WHERE "username" = 'inactive');

-- Update sequence to avoid conflicts
SELECT setval('users_id_seq', (SELECT MAX(id) FROM users));

-- =============================================================================
-- TEST DOMAINS
-- =============================================================================

-- Create test domains in PowerDNS (skip if they already exist)
INSERT INTO "domains" ("name", "type") VALUES
('admin-zone.example.com', 'MASTER'),
('manager-zone.example.com', 'MASTER'),
('client-zone.example.com', 'MASTER'),
('shared-zone.example.com', 'MASTER'),
('xn--verstt-eua3l.info', 'MASTER'),
('xn--80aejmjbdxvpe2k.net', 'MASTER'),
('xn--ob0bz7i69i99fm8qgkfwlc.com', 'MASTER'),
('xn--chtnbin-rwa9e0573b.vn', 'MASTER'),
('test858.example.com', 'MASTER'),
('168.192.in-addr.arpa', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- Add SOA records for each domain (required for PowerDNS)
-- Use NOT EXISTS to prevent duplicate records
INSERT INTO "records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT
    d."id",
    d."name",
    'SOA',
    'ns1.example.com. hostmaster.example.com. ' || CAST(EXTRACT(EPOCH FROM NOW()) AS INTEGER) || ' 10800 3600 604800 86400',
    86400,
    0
FROM "domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM "records" r WHERE r."domain_id" = d."id" AND r."type" = 'SOA'
  );

-- Add NS records for each domain
INSERT INTO "records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns1.example.com.', 86400, 0
FROM "domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM "records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns1.example.com.'
  );

INSERT INTO "records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns2.example.com.', 86400, 0
FROM "domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com',
                   'xn--verstt-eua3l.info', 'xn--80aejmjbdxvpe2k.net', 'xn--ob0bz7i69i99fm8qgkfwlc.com', 'xn--chtnbin-rwa9e0573b.vn',
                   'test858.example.com', '168.192.in-addr.arpa')
  AND NOT EXISTS (
    SELECT 1 FROM "records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns2.example.com.'
  );

-- Add some sample A records
INSERT INTO "records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", 'www.' || d."name", 'A', '192.0.2.1', 3600, 0
FROM "domains" d
WHERE d."name" IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM "records" r WHERE r."domain_id" = d."id" AND r."name" = 'www.' || d."name" AND r."type" = 'A'
  );

-- Update sequence to avoid conflicts
SELECT setval('domains_id_seq', (SELECT MAX(id) FROM domains));
SELECT setval('records_id_seq', (SELECT MAX(id) FROM records));

-- =============================================================================
-- ZONE OWNERSHIP (Poweradmin)
-- =============================================================================

-- Admin owns admin-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = 'admin-zone.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Manager owns manager-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = 'manager-zone.example.com' AND u."username" = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Client owns client-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = 'client-zone.example.com' AND u."username" = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Shared zone with MULTIPLE OWNERS (manager and client both own it)
-- This tests the multi-owner functionality
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = 'shared-zone.example.com'
  AND u."username" IN ('manager', 'client')
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Admin owns test858.example.com (for issue #858 comment testing)
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = 'test858.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Admin owns 168.192.in-addr.arpa reverse zone (for A/PTR sync testing)
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM "domains" d
CROSS JOIN "users" u
WHERE d."name" = '168.192.in-addr.arpa' AND u."username" = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id"
  );

-- Update sequence to avoid conflicts
SELECT setval('zones_id_seq', (SELECT MAX(id) FROM zones));

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
    u."username" as "owner",
    COUNT(*) OVER (PARTITION BY d."id") as "owner_count"
FROM "domains" d
JOIN "zones" z ON d."id" = z."domain_id"
JOIN "users" u ON z."owner" = u."id"
WHERE d."name" LIKE '%.example.com'
ORDER BY d."name", u."username";

-- =============================================================================
-- SUMMARY
-- =============================================================================
--
-- Test Users Created:
-- -------------------
-- Username  | Password       | Template        | Active | Description
-- ----------|----------------|-----------------|--------|---------------------------
-- admin     | poweradmin123  | Administrator   | Yes    | Full system access (überuser)
-- manager   | poweradmin123  | Zone Manager    | Yes    | Can manage own zones (11 perms)
-- client    | poweradmin123  | Client Editor   | Yes    | Limited editing, no SOA/NS (4 perms)
-- viewer    | poweradmin123  | Read Only       | Yes    | View-only access (2 perms)
-- noperm    | poweradmin123  | No Access       | Yes    | Can login but has no permissions (0 perms)
-- inactive  | poweradmin123  | No Access       | No     | Cannot login - inactive account
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
-- =============================================================================
