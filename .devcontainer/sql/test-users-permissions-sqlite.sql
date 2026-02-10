-- SQLite Test Data: Users, Permission Templates, Zones
-- Purpose: Create comprehensive test data for development and testing
-- Database: pdns.db (single database containing both PowerDNS and Poweradmin tables)
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles (+ 4 LDAP users)
-- - Multiple test domains (master, slave, native, reverse, IDN, long names)
-- - Zone ownership records (using name-based lookups)
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
-- First ensure only überuser permission exists for Administrator template
DELETE FROM perm_templ_items WHERE templ_id = 1 AND perm_id != 53;
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
-- Creates comprehensive set of test domains:
-- - MASTER zones: standard DNS zones
-- - NATIVE zones: for native replication
-- - SLAVE zones: secondary zones
-- - Reverse zones: IPv4 and IPv6 PTR records
-- - IDN zone: internationalized domain name
-- - Long domain names: for UI width testing

-- MASTER zones
INSERT OR IGNORE INTO domains (name, type, notified_serial) VALUES
    ('admin-zone.example.com', 'MASTER', 2024010101),
    ('manager-zone.example.com', 'MASTER', 2024010101),
    ('client-zone.example.com', 'MASTER', 2024010101),
    ('shared-zone.example.com', 'MASTER', 2024010101),
    ('viewer-zone.example.com', 'MASTER', 2024010101);

-- NATIVE zones
INSERT OR IGNORE INTO domains (name, type, notified_serial) VALUES
    ('native-zone.example.org', 'NATIVE', 2024010101),
    ('secondary-native.example.org', 'NATIVE', 2024010101);

-- SLAVE zones
INSERT OR IGNORE INTO domains (name, type, master) VALUES
    ('slave-zone.example.net', 'SLAVE', '192.0.2.1'),
    ('external-slave.example.net', 'SLAVE', '198.51.100.1');

-- Reverse zones
INSERT OR IGNORE INTO domains (name, type, notified_serial) VALUES
    ('2.0.192.in-addr.arpa', 'MASTER', 2024010101),
    ('168.192.in-addr.arpa', 'MASTER', 2024010101),
    ('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER', 2024010101);

-- IDN zone (verstäubt.info in Punycode)
INSERT OR IGNORE INTO domains (name, type, notified_serial) VALUES
    ('xn--verstt-eua3l.info', 'MASTER', 2024010101);

-- Long domain names for UI width testing
INSERT OR IGNORE INTO domains (name, type, notified_serial) VALUES
    ('very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'MASTER', 2024010101),
    ('another.very.deeply.nested.subdomain.structure.example.com', 'MASTER', 2024010101);

-- =============================================================================
-- ZONE OWNERSHIP (Poweradmin zones table)
-- =============================================================================
-- Uses name-based lookups for safe re-imports

-- admin-zone.example.com -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Admin test zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'admin-zone.example.com' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- manager-zone.example.com -> manager
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Manager test zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'manager-zone.example.com' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- client-zone.example.com -> client
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Client test zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'client-zone.example.com' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- shared-zone.example.com -> manager (multi-owner)
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Shared zone (manager)', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- shared-zone.example.com -> client (multi-owner)
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Shared zone (client)', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- viewer-zone.example.com -> viewer
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Viewer test zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'viewer-zone.example.com' AND u.username = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- native-zone.example.org -> manager
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Native zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'native-zone.example.org' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- secondary-native.example.org -> manager
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Secondary native zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'secondary-native.example.org' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- slave-zone.example.net -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Slave zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'slave-zone.example.net' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- external-slave.example.net -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'External slave zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'external-slave.example.net' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- 2.0.192.in-addr.arpa -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Reverse IPv4 zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = '2.0.192.in-addr.arpa' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- 168.192.in-addr.arpa -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Additional reverse IPv4 zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = '168.192.in-addr.arpa' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- 8.b.d.0.1.0.0.2.ip6.arpa -> admin
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Reverse IPv6 zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- xn--verstt-eua3l.info -> manager
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'IDN zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'xn--verstt-eua3l.info' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- very-long-subdomain-name-for-testing-ui-column-widths.example.com -> client
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Long domain name zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'very-long-subdomain-name-for-testing-ui-column-widths.example.com' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- another.very.deeply.nested.subdomain.structure.example.com -> client
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Deeply nested domain zone', 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'another.very.deeply.nested.subdomain.structure.example.com' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- =============================================================================
-- BASIC SOA AND NS RECORDS FOR EACH ZONE
-- =============================================================================

-- admin-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'admin-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'admin-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'admin-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'admin-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'admin-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'admin-zone.example.com';

-- manager-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'manager-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'manager-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'manager-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- client-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'client-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'client-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'client-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- shared-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'shared-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'shared-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'shared-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

-- viewer-zone.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'viewer-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'viewer-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'viewer-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'viewer-zone.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'viewer-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'viewer-zone.example.com';

-- native-zone.example.org
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'native-zone.example.org', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'native-zone.example.org';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'native-zone.example.org', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'native-zone.example.org';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'native-zone.example.org', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'native-zone.example.org';

-- secondary-native.example.org
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'secondary-native.example.org', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'secondary-native.example.org';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'secondary-native.example.org', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'secondary-native.example.org';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'secondary-native.example.org', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'secondary-native.example.org';

-- 2.0.192.in-addr.arpa
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '2.0.192.in-addr.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '2.0.192.in-addr.arpa', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '2.0.192.in-addr.arpa', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

-- 168.192.in-addr.arpa
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '168.192.in-addr.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = '168.192.in-addr.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '168.192.in-addr.arpa', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '168.192.in-addr.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '168.192.in-addr.arpa', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '168.192.in-addr.arpa';

-- 8.b.d.0.1.0.0.2.ip6.arpa
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '8.b.d.0.1.0.0.2.ip6.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa';

-- xn--verstt-eua3l.info
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'xn--verstt-eua3l.info', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'xn--verstt-eua3l.info', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'xn--verstt-eua3l.info', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';

-- very-long-subdomain-name-for-testing-ui-column-widths.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'very-long-subdomain-name-for-testing-ui-column-widths.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'very-long-subdomain-name-for-testing-ui-column-widths.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'very-long-subdomain-name-for-testing-ui-column-widths.example.com';

-- another.very.deeply.nested.subdomain.structure.example.com
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'another.very.deeply.nested.subdomain.structure.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, 0
FROM domains d WHERE d.name = 'another.very.deeply.nested.subdomain.structure.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'another.very.deeply.nested.subdomain.structure.example.com', 'NS', 'ns1.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'another.very.deeply.nested.subdomain.structure.example.com';
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'another.very.deeply.nested.subdomain.structure.example.com', 'NS', 'ns2.example.com', 86400, 0, 0
FROM domains d WHERE d.name = 'another.very.deeply.nested.subdomain.structure.example.com';

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================
-- Enhanced templates matching 3.x branch

-- Basic Web Zone template (admin owned)
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Basic Web Zone', 'Basic zone with A records and mail exchanger', u.id
FROM users u WHERE u.username = 'admin';

-- Full Zone Template (admin owned)
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Full Zone Template', 'Comprehensive template with SPF, DMARC, DKIM, CNAME', u.id
FROM users u WHERE u.username = 'admin';

-- Manager Custom Template (manager owned)
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Manager Custom Template', 'Custom template owned by manager user', u.id
FROM users u WHERE u.username = 'manager';

-- Zone template records for Basic Web Zone
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';

-- Zone template records for Full Zone Template
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'CNAME', '[ZONE]', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'TXT', '"v=spf1 mx a -all"', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '_dmarc.[ZONE]', 'TXT', '"v=DMARC1; p=quarantine; rua=mailto:dmarc@[ZONE]"', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template';

-- Zone template records for Manager Custom Template
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template';
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '10.0.0.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template';
