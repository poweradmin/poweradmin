-- PostgreSQL Test Data: Users, Permission Templates, Zones
-- Purpose: Create comprehensive test data for development and testing
-- Database: pdns (single database containing both PowerDNS and Poweradmin tables)
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles
-- - 4 LDAP test users
-- - Multiple test domains (master, slave, native, reverse, IDN)
-- - Zone ownership records
-- - Zone templates for quick zone creation
--
-- Usage: docker exec -i postgres psql -U pdns -d pdns < test-users-permissions-pgsql.sql

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================

-- Ensure Administrator template (ID 1) has überuser permission
-- This grants full system access
-- First ensure only überuser permission exists for Administrator template
DELETE FROM perm_templ_items WHERE templ_id = 1 AND perm_id != 53;
INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 53)
ON CONFLICT DO NOTHING;

-- Template 2: Zone Manager - Full management of own zones
INSERT INTO perm_templ (id, name, descr)
VALUES (2, 'Zone Manager', 'Full zone management rights for own zones, can add master/slave zones')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

-- Template 3: Client Editor - Limited editing (no SOA/NS)
INSERT INTO perm_templ (id, name, descr)
VALUES (3, 'Client Editor', 'Limited editing rights - can edit records but not SOA/NS')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

-- Template 4: Read Only - View access only
INSERT INTO perm_templ (id, name, descr)
VALUES (4, 'Read Only', 'Read-only access to view zones and records')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

-- Template 5: No Access - No permissions at all
INSERT INTO perm_templ (id, name, descr)
VALUES (5, 'No Access', 'No permissions - for testing permission denied scenarios')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

-- =============================================================================
-- PERMISSION TEMPLATE ITEMS
-- =============================================================================

-- Zone Manager (Template 2) permissions:
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 41 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 41);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 42 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 42);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 43 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 43);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 44 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 44);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 45 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 45);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 49 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 49);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 2, 56 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 2 AND perm_id = 56);

-- Client Editor (Template 3) permissions:
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 43 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 43);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 62 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 62);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 49 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 49);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 56 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 56);

-- Read Only (Template 4) permissions:
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 4, 43 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 4 AND perm_id = 43);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 4, 49 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 4 AND perm_id = 49);

-- No Access (Template 5) - no permissions added

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "Poweradmin123" (bcrypt hashed)

-- Admin user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (1, 'admin', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'System Administrator', 'admin@example.com', 'Full system administrator with full access', 1, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname,
    email = EXCLUDED.email;

-- Manager user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (2, 'manager', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Zone Manager', 'manager@example.com', 'Zone manager with full zone management rights', 2, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Client user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (3, 'client', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Client User', 'client@example.com', 'Client editor with limited editing rights', 3, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Viewer user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (4, 'viewer', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Read Only User', 'viewer@example.com', 'Read-only access for viewing zones', 4, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- No permissions user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (5, 'noperm', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'No Permissions User', 'noperm@example.com', 'User with no permissions for testing access denied', 5, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Inactive user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (6, 'inactive', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Inactive User', 'inactive@example.com', 'Inactive user account for testing disabled login', 5, 0, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- LDAP users
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (7, 'ldap-admin', '', 'LDAP Administrator', 'ldap-admin@poweradmin.org', 'LDAP user with Administrator permissions', 1, 1, 1)
ON CONFLICT (id) DO UPDATE SET fullname = EXCLUDED.fullname;

INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (8, 'ldap-manager', '', 'LDAP Zone Manager', 'ldap-manager@poweradmin.org', 'LDAP user with Zone Manager permissions', 2, 1, 1)
ON CONFLICT (id) DO UPDATE SET fullname = EXCLUDED.fullname;

INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (9, 'ldap-client', '', 'LDAP Client Editor', 'ldap-client@poweradmin.org', 'LDAP user with Client Editor permissions', 3, 1, 1)
ON CONFLICT (id) DO UPDATE SET fullname = EXCLUDED.fullname;

INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (10, 'ldap-viewer', '', 'LDAP Read Only', 'ldap-viewer@poweradmin.org', 'LDAP user with Read Only permissions', 4, 1, 1)
ON CONFLICT (id) DO UPDATE SET fullname = EXCLUDED.fullname;

-- =============================================================================
-- API KEYS (for automated API testing)
-- =============================================================================

INSERT INTO api_keys (name, secret_key, created_by, disabled, expires_at)
SELECT 'API Test Key', 'test-api-key-for-automated-testing-12345', 1, false, NULL
WHERE NOT EXISTS (SELECT 1 FROM api_keys WHERE secret_key = 'test-api-key-for-automated-testing-12345');

-- =============================================================================
-- TEST DOMAINS (PowerDNS tables)
-- =============================================================================

-- Master zones (forward)
INSERT INTO domains (name, type, notified_serial)
VALUES ('admin-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('manager-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('client-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('shared-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('viewer-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- Native zones
INSERT INTO domains (name, type, notified_serial)
VALUES ('native-zone.example.org', 'NATIVE', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('secondary-native.example.org', 'NATIVE', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- Slave zones
INSERT INTO domains (name, type, master)
VALUES ('slave-zone.example.net', 'SLAVE', '192.0.2.1')
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, master)
VALUES ('external-slave.example.net', 'SLAVE', '192.0.2.2')
ON CONFLICT (name) DO NOTHING;

-- IPv4 reverse zones
INSERT INTO domains (name, type, notified_serial)
VALUES ('2.0.192.in-addr.arpa', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('168.192.in-addr.arpa', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- IPv6 reverse zone
INSERT INTO domains (name, type, notified_serial)
VALUES ('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- IDN zone
INSERT INTO domains (name, type, notified_serial)
VALUES ('xn--verstt-eua3l.info', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- Long domain names for UI testing
INSERT INTO domains (name, type, notified_serial)
VALUES ('very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

INSERT INTO domains (name, type, notified_serial)
VALUES ('another.very.deeply.nested.subdomain.structure.example.com', 'MASTER', 2024010101)
ON CONFLICT (name) DO NOTHING;

-- =============================================================================
-- ZONE OWNERSHIP (Poweradmin zones table)
-- Uses name-based lookups for safety
-- =============================================================================

-- Admin owns admin-zone.example.com
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Admin test zone', 0
FROM domains d, users u
WHERE d.name = 'admin-zone.example.com' AND u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Manager owns manager-zone.example.com
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Manager test zone', 0
FROM domains d, users u
WHERE d.name = 'manager-zone.example.com' AND u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Client owns client-zone.example.com
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Client test zone', 0
FROM domains d, users u
WHERE d.name = 'client-zone.example.com' AND u.username = 'client'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Shared zone (manager)
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Shared zone (manager)', 0
FROM domains d, users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Shared zone (client)
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Shared zone (client)', 0
FROM domains d, users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'client'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Viewer owns viewer-zone.example.com
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Viewer test zone', 0
FROM domains d, users u
WHERE d.name = 'viewer-zone.example.com' AND u.username = 'viewer'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Manager owns native zones
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Native zone', 0
FROM domains d, users u
WHERE d.name IN ('native-zone.example.org', 'secondary-native.example.org') AND u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Admin owns slave zones
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Slave zone', 0
FROM domains d, users u
WHERE d.name IN ('slave-zone.example.net', 'external-slave.example.net') AND u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Admin owns reverse zones
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Reverse zone', 0
FROM domains d, users u
WHERE d.name LIKE '%.arpa' AND u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Manager owns IDN zone
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'IDN zone', 0
FROM domains d, users u
WHERE d.name = 'xn--verstt-eua3l.info' AND u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Client owns long domain name zones
INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
SELECT d.id, u.id, 'Long domain name zone', 0
FROM domains d, users u
WHERE (d.name LIKE 'very-long-%' OR d.name LIKE 'another.very%') AND u.username = 'client'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- =============================================================================
-- SOA AND NS RECORDS FOR ALL MASTER/NATIVE ZONES
-- =============================================================================

-- Add SOA records for master/native zones
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'SOA',
       'ns1.example.com. hostmaster.example.com. ' || to_char(current_date, 'YYYYMMDD') || '01 10800 3600 604800 86400',
       86400, 0, false
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'SOA');

-- Add NS records (ns1)
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'NS', 'ns1.example.com.', 86400, 0, false
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns1.example.com.');

-- Add NS records (ns2)
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'NS', 'ns2.example.com.', 86400, 0, false
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns2.example.com.');

-- Add basic A records for forward zones
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.1', 3600, 0, false
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND d.name NOT LIKE '%.arpa'
  AND d.name NOT LIKE 'xn--%'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = 'www.' || d.name AND r.type = 'A');

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

-- Basic Web Zone Template (owned by admin)
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Basic Web Zone', 'Basic zone template with www, mail, and common records', u.id
FROM users u WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Basic Web Zone');

-- Insert template records for Basic Web Zone
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = 'www.[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = 'mail.[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'MX');

-- Full Zone Template (owned by admin)
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Full Zone Template', 'Comprehensive template with SPF, DMARC, and common services', u.id
FROM users u WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Full Zone Template');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'CNAME', '[ZONE]', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = 'www.[ZONE]' AND type = 'CNAME');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'TXT', '"v=spf1 mx ~all"', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'TXT');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '_dmarc.[ZONE]', 'TXT', '"v=DMARC1; p=none; rua=mailto:dmarc@[ZONE]"', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '_dmarc.[ZONE]' AND type = 'TXT');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = 'mail.[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt WHERE zt.name = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'MX');

-- Manager's custom template
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Manager Custom Template', 'Custom template owned by manager user', u.id
FROM users u WHERE u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Manager Custom Template');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.100', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = '[ZONE]' AND type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'A', '192.0.2.100', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Manager Custom Template'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = zt.id AND name = 'www.[ZONE]' AND type = 'A');

-- =============================================================================
-- SYNC SEQUENCES
-- =============================================================================

SELECT setval('perm_templ_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ), 1));
SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1));
SELECT setval('domains_id_seq', COALESCE((SELECT MAX(id) FROM domains), 1));
SELECT setval('records_id_seq', COALESCE((SELECT MAX(id) FROM records), 1));
SELECT setval('zones_id_seq', COALESCE((SELECT MAX(id) FROM zones), 1));
SELECT setval('zone_templ_id_seq', COALESCE((SELECT MAX(id) FROM zone_templ), 1));
SELECT setval('zone_templ_records_id_seq', COALESCE((SELECT MAX(id) FROM zone_templ_records), 1));
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));
SELECT setval('api_keys_id_seq', COALESCE((SELECT MAX(id) FROM api_keys), 1));
