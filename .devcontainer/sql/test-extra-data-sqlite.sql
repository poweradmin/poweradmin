-- SQLite Test Data: Extra Comprehensive Data
-- Purpose: Add additional test data for SLAVE/NATIVE zones, supermasters, expired API keys,
--          zone template sync, and login attempts
-- Requires: test-users-permissions-sqlite.sql and test-reverse-zones-templates-sqlite.sql first
--
-- This script creates:
-- - 4 new zones (group-only, viewer, slave, native)
-- - 3 supermaster records (IPv4 + IPv6)
-- - 1 expired API key
-- - 2 zone template sync entries
-- - 5 login attempt records
--
-- Usage: docker exec -i sqlite sqlite3 /data/pdns.db < test-extra-data-sqlite.sql

-- =============================================================================
-- ATTACH DATABASE ALIAS
-- =============================================================================
ATTACH DATABASE '/data/pdns.db' AS pdns;

-- =============================================================================
-- ADDITIONAL ZONES
-- =============================================================================

-- Group-only zone (no direct owner, managed via groups)
INSERT OR IGNORE INTO pdns."domains" ("name", "type") VALUES
('group-only-zone.example.com', 'MASTER');

-- Viewer zone (viewer currently has no zones to view)
INSERT OR IGNORE INTO pdns."domains" ("name", "type") VALUES
('viewer-zone.example.com', 'MASTER');

-- Slave zone (tests SLAVE zone type)
INSERT OR IGNORE INTO pdns."domains" ("name", "type", "master") VALUES
('slave-zone.example.com', 'SLAVE', '10.0.0.1');

-- Native zone (tests NATIVE zone type)
INSERT OR IGNORE INTO pdns."domains" ("name", "type") VALUES
('native-zone.example.com', 'NATIVE');

-- Add SOA records for MASTER and NATIVE zones (not SLAVE)
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'SOA',
       'ns1.example.com. hostmaster.example.com. ' || CAST(strftime('%s', 'now') AS INTEGER) || ' 10800 3600 604800 86400',
       86400, 0
FROM pdns."domains" d
WHERE d."name" IN ('group-only-zone.example.com', 'viewer-zone.example.com', 'native-zone.example.com')
  AND NOT EXISTS (SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'SOA');

-- Add NS records for MASTER and NATIVE zones
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns1.example.com.', 86400, 0
FROM pdns."domains" d
WHERE d."name" IN ('group-only-zone.example.com', 'viewer-zone.example.com', 'native-zone.example.com')
  AND NOT EXISTS (SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns1.example.com.');

INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", d."name", 'NS', 'ns2.example.com.', 86400, 0
FROM pdns."domains" d
WHERE d."name" IN ('group-only-zone.example.com', 'viewer-zone.example.com', 'native-zone.example.com')
  AND NOT EXISTS (SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."type" = 'NS' AND r."content" = 'ns2.example.com.');

-- Add sample A record for viewer zone
INSERT INTO pdns."records" ("domain_id", "name", "type", "content", "ttl", "prio")
SELECT d."id", 'www.' || d."name", 'A', '192.0.2.100', 3600, 0
FROM pdns."domains" d
WHERE d."name" = 'viewer-zone.example.com'
  AND NOT EXISTS (SELECT 1 FROM pdns."records" r WHERE r."domain_id" = d."id" AND r."name" = 'www.' || d."name" AND r."type" = 'A');

-- =============================================================================
-- SUPERMASTERS
-- =============================================================================

-- IPv4 supermaster
INSERT OR IGNORE INTO pdns."supermasters" ("ip", "nameserver", "account") VALUES
('10.0.0.1', 'ns1.supermaster.example.com', 'admin');

-- Second IPv4 supermaster
INSERT OR IGNORE INTO pdns."supermasters" ("ip", "nameserver", "account") VALUES
('10.0.0.2', 'ns2.supermaster.example.com', 'admin');

-- IPv6 supermaster
INSERT OR IGNORE INTO pdns."supermasters" ("ip", "nameserver", "account") VALUES
('2001:db8::1', 'ns3.supermaster.example.com', 'admin');

-- =============================================================================
-- ZONE OWNERSHIP
-- =============================================================================

-- Viewer owns viewer-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d, "users" u
WHERE d."name" = 'viewer-zone.example.com' AND u."username" = 'viewer'
  AND NOT EXISTS (SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id");

-- Admin owns slave-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d, "users" u
WHERE d."name" = 'slave-zone.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id");

-- Admin owns native-zone.example.com
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", u."id", 0
FROM pdns."domains" d, "users" u
WHERE d."name" = 'native-zone.example.com' AND u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id" AND z."owner" = u."id");

-- group-only-zone.example.com has NO direct owner (only group ownership)
INSERT INTO "zones" ("domain_id", "owner", "zone_templ_id")
SELECT d."id", NULL, 0
FROM pdns."domains" d
WHERE d."name" = 'group-only-zone.example.com'
  AND NOT EXISTS (SELECT 1 FROM "zones" z WHERE z."domain_id" = d."id");

-- =============================================================================
-- EXPIRED API KEY
-- =============================================================================

INSERT INTO "api_keys" ("name", "secret_key", "created_by", "disabled", "expires_at")
SELECT 'Expired Testing Key', 'test-api-key-expired-for-testing-99999', u."id", 0, '2024-01-01 00:00:00'
FROM "users" u
WHERE u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "api_keys" WHERE "secret_key" = 'test-api-key-expired-for-testing-99999');

-- =============================================================================
-- ZONE TEMPLATE SYNC ENTRIES
-- =============================================================================

-- Link Standard Web Zone template to admin-zone (synced)
INSERT INTO "zone_template_sync" ("zone_id", "zone_templ_id", "last_synced", "needs_sync")
SELECT z."id", zt."id", datetime('now'), 0
FROM "zones" z
JOIN pdns."domains" d ON z."domain_id" = d."id"
CROSS JOIN "zone_templ" zt
WHERE d."name" = 'admin-zone.example.com' AND zt."name" = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM "zone_template_sync" zts WHERE zts."zone_id" = z."id" AND zts."zone_templ_id" = zt."id")
LIMIT 1;

-- Link Standard Web Zone template to manager-zone (needs sync)
INSERT INTO "zone_template_sync" ("zone_id", "zone_templ_id", "last_synced", "needs_sync")
SELECT z."id", zt."id", datetime('now', '-7 days'), 1
FROM "zones" z
JOIN pdns."domains" d ON z."domain_id" = d."id"
CROSS JOIN "zone_templ" zt
WHERE d."name" = 'manager-zone.example.com' AND zt."name" = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM "zone_template_sync" zts WHERE zts."zone_id" = z."id" AND zts."zone_templ_id" = zt."id")
LIMIT 1;

-- =============================================================================
-- LOGIN ATTEMPTS
-- =============================================================================

-- Successful login by admin
INSERT INTO "login_attempts" ("user_id", "ip_address", "timestamp", "successful")
SELECT u."id", '127.0.0.1', CAST(strftime('%s', 'now') AS INTEGER) - 3600, 1
FROM "users" u
WHERE u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "login_attempts" la WHERE la."user_id" = u."id" AND la."ip_address" = '127.0.0.1' AND la."successful" = 1);

-- Successful login by manager
INSERT INTO "login_attempts" ("user_id", "ip_address", "timestamp", "successful")
SELECT u."id", '127.0.0.1', CAST(strftime('%s', 'now') AS INTEGER) - 1800, 1
FROM "users" u
WHERE u."username" = 'manager'
  AND NOT EXISTS (SELECT 1 FROM "login_attempts" la WHERE la."user_id" = u."id" AND la."ip_address" = '127.0.0.1' AND la."successful" = 1);

-- Failed login attempt (unknown user)
INSERT INTO "login_attempts" ("user_id", "ip_address", "timestamp", "successful")
SELECT NULL, '203.0.113.50', CAST(strftime('%s', 'now') AS INTEGER) - 7200, 0
WHERE NOT EXISTS (SELECT 1 FROM "login_attempts" la WHERE la."ip_address" = '203.0.113.50' AND la."successful" = 0);

-- Failed login attempt (brute force from different IP)
INSERT INTO "login_attempts" ("user_id", "ip_address", "timestamp", "successful")
SELECT NULL, '198.51.100.25', CAST(strftime('%s', 'now') AS INTEGER) - 600, 0
WHERE NOT EXISTS (SELECT 1 FROM "login_attempts" la WHERE la."ip_address" = '198.51.100.25' AND la."successful" = 0);

-- Failed login attempt (wrong password for admin)
INSERT INTO "login_attempts" ("user_id", "ip_address", "timestamp", "successful")
SELECT u."id", '192.168.1.100', CAST(strftime('%s', 'now') AS INTEGER) - 300, 0
FROM "users" u
WHERE u."username" = 'admin'
  AND NOT EXISTS (SELECT 1 FROM "login_attempts" la WHERE la."user_id" = u."id" AND la."ip_address" = '192.168.1.100' AND la."successful" = 0);

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Verify new zones
SELECT d."name", d."type", d."master"
FROM pdns."domains" d
WHERE d."name" IN ('group-only-zone.example.com', 'viewer-zone.example.com', 'slave-zone.example.com', 'native-zone.example.com')
ORDER BY d."name";

-- Verify supermasters
SELECT "ip", "nameserver", "account"
FROM pdns."supermasters"
ORDER BY "ip";

-- Verify zone ownership including NULL owner
SELECT d."name", u."username" AS "owner"
FROM "zones" z
JOIN pdns."domains" d ON z."domain_id" = d."id"
LEFT JOIN "users" u ON z."owner" = u."id"
WHERE d."name" IN ('group-only-zone.example.com', 'viewer-zone.example.com', 'slave-zone.example.com', 'native-zone.example.com')
ORDER BY d."name";

-- Verify API keys
SELECT "name", "secret_key", "disabled", "expires_at"
FROM "api_keys"
ORDER BY "name";
