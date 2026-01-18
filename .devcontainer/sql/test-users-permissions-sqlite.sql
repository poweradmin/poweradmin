-- SQLite Test Data: Users, Permission Templates, Zones for 4.x Branch
-- Purpose: Create comprehensive test data for development and testing
-- Database: pdns.db (single database containing both PowerDNS and Poweradmin tables)
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles
-- - Multiple test domains (master, slave, native, reverse, IDN)
-- - Zone ownership records
-- - Zone templates for quick zone creation
--
-- Usage: docker exec -i sqlite sqlite3 /data/pdns.db < test-users-permissions-sqlite.sql

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================

INSERT OR REPLACE INTO perm_templ (id, name, descr) VALUES
    (2, 'Zone Manager', 'Full zone management rights for own zones, can add master/slave zones'),
    (3, 'Client Editor', 'Limited editing rights - can edit records but not SOA/NS'),
    (4, 'Read Only', 'Read-only access to view zones and records'),
    (5, 'No Access', 'No permissions - for testing permission denied scenarios');

-- =============================================================================
-- PERMISSION TEMPLATE ITEMS
-- =============================================================================

-- Ensure Administrator template (ID 1) has überuser permission
-- This grants full system access
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53);

-- Zone Manager (Template 2) permissions
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 41);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 42);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 43);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 44);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 45);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 49);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (2, 56);

-- Client Editor (Template 3) permissions
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (3, 43);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (3, 62);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (3, 49);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (3, 56);

-- Read Only (Template 4) permissions
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (4, 43);
INSERT OR IGNORE INTO perm_templ_items (templ_id, perm_id) VALUES (4, 49);

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "Poweradmin123" (bcrypt hashed)

INSERT OR REPLACE INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES
    (1, 'admin', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'System Administrator', 'admin@example.com', 'Full system administrator with full access', 1, 1, 0),
    (2, 'manager', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Zone Manager', 'manager@example.com', 'Zone manager with full zone management rights', 2, 1, 0),
    (3, 'client', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Client User', 'client@example.com', 'Client editor with limited editing rights', 3, 1, 0),
    (4, 'viewer', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Read Only User', 'viewer@example.com', 'Read-only access for viewing zones', 4, 1, 0),
    (5, 'noperm', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'No Permissions User', 'noperm@example.com', 'User with no permissions for testing access denied', 5, 1, 0),
    (6, 'inactive', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Inactive User', 'inactive@example.com', 'Inactive user account for testing disabled login', 5, 0, 0);

-- LDAP users
INSERT OR REPLACE INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES
    (7, 'ldap-admin', '', 'LDAP Administrator', 'ldap-admin@poweradmin.org', 'LDAP user with Administrator permissions', 1, 1, 1),
    (8, 'ldap-manager', '', 'LDAP Zone Manager', 'ldap-manager@poweradmin.org', 'LDAP user with Zone Manager permissions', 2, 1, 1),
    (9, 'ldap-client', '', 'LDAP Client Editor', 'ldap-client@poweradmin.org', 'LDAP user with Client Editor permissions', 3, 1, 1),
    (10, 'ldap-viewer', '', 'LDAP Read Only', 'ldap-viewer@poweradmin.org', 'LDAP user with Read Only permissions', 4, 1, 1);

-- =============================================================================
-- API KEYS (for automated API testing)
-- =============================================================================
-- API key for testing: test-api-key-for-automated-testing-12345
-- This key is linked to the admin user for full API access

INSERT OR IGNORE INTO api_keys (name, secret_key, created_by, disabled, expires_at)
SELECT 'API Test Key', 'test-api-key-for-automated-testing-12345', 1, 0, NULL
WHERE NOT EXISTS (SELECT 1 FROM api_keys WHERE secret_key = 'test-api-key-for-automated-testing-12345');

-- =============================================================================
-- TEST DOMAINS (PowerDNS tables)
-- =============================================================================

INSERT OR REPLACE INTO domains (id, name, type, notified_serial) VALUES
    (1, 'admin-zone.example.com', 'MASTER', 2024010101),
    (2, 'manager-zone.example.com', 'MASTER', 2024010101),
    (3, 'client-zone.example.com', 'MASTER', 2024010101),
    (4, 'shared-zone.example.com', 'MASTER', 2024010101),
    (5, 'viewer-zone.example.com', 'MASTER', 2024010101),
    (6, 'native-zone.example.org', 'NATIVE', 2024010101),
    (8, '2.0.192.in-addr.arpa', 'MASTER', 2024010101),
    (9, '8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER', 2024010101),
    (10, 'xn--verstt-eua3l.info', 'MASTER', 2024010101);

INSERT OR REPLACE INTO domains (id, name, type, master) VALUES
    (7, 'slave-zone.example.net', 'SLAVE', '192.0.2.1');

-- =============================================================================
-- ZONE OWNERSHIP (Poweradmin zones table)
-- =============================================================================

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 1, 1, 'Admin test zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 1 AND owner = 1);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 2, 2, 'Manager test zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 2 AND owner = 2);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 3, 3, 'Client test zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 3 AND owner = 3);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 4, 2, 'Shared zone (manager)', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 4 AND owner = 2);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 4, 3, 'Shared zone (client)', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 4 AND owner = 3);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 5, 4, 'Viewer test zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 5 AND owner = 4);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 6, 2, 'Native zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 6 AND owner = 2);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 7, 1, 'Slave zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 7 AND owner = 1);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 8, 1, 'Reverse IPv4 zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 8 AND owner = 1);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 9, 1, 'Reverse IPv6 zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 9 AND owner = 1);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT 10, 2, 'IDN zone', 0
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE domain_id = 10 AND owner = 2);

-- =============================================================================
-- BASIC SOA AND NS RECORDS FOR EACH ZONE
-- =============================================================================

-- admin-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (1, 'admin-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (1, 'admin-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0),
    (1, 'admin-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0);

-- manager-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (2, 'manager-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (2, 'manager-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0),
    (2, 'manager-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0);

-- client-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (3, 'client-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (3, 'client-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0),
    (3, 'client-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0);

-- shared-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (4, 'shared-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (4, 'shared-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0),
    (4, 'shared-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0);

-- viewer-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (5, 'viewer-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (5, 'viewer-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0),
    (5, 'viewer-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0);

-- native-zone.example.org
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (6, 'native-zone.example.org', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (6, 'native-zone.example.org', 'NS', 'ns1.example.com', 86400, 0, 0),
    (6, 'native-zone.example.org', 'NS', 'ns2.example.com', 86400, 0, 0);

-- 2.0.192.in-addr.arpa
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (8, '2.0.192.in-addr.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (8, '2.0.192.in-addr.arpa', 'NS', 'ns1.example.com', 86400, 0, 0),
    (8, '2.0.192.in-addr.arpa', 'NS', 'ns2.example.com', 86400, 0, 0);

-- 8.b.d.0.1.0.0.2.ip6.arpa
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (9, '8.b.d.0.1.0.0.2.ip6.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (9, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns1.example.com', 86400, 0, 0),
    (9, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns2.example.com', 86400, 0, 0);

-- xn--verstt-eua3l.info
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (10, 'xn--verstt-eua3l.info', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0),
    (10, 'xn--verstt-eua3l.info', 'NS', 'ns1.example.com', 86400, 0, 0),
    (10, 'xn--verstt-eua3l.info', 'NS', 'ns2.example.com', 86400, 0, 0);

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

INSERT OR REPLACE INTO zone_templ (id, name, descr, owner) VALUES
    (1, 'Basic Zone', 'Basic zone template with standard SOA and NS records', 1),
    (2, 'Web Hosting', 'Zone template for web hosting with A, MX, and TXT records', 2);

-- Zone template records
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES
    (1, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0),
    (1, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0),
    (1, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0),
    (2, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0),
    (2, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0),
    (2, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0),
    (2, '[ZONE]', 'A', '192.0.2.1', 3600, 0),
    (2, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0),
    (2, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10),
    (2, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0);
