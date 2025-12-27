-- PostgreSQL Test Data: Users, Permission Templates, Zones for 3.x Branch
-- Purpose: Create comprehensive test data for development and testing
-- Database: pdns (unified database for Poweradmin and PowerDNS tables)
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles
-- - Multiple test domains (master, slave, native, reverse, IDN)
-- - Zone ownership records
-- - Zone templates for quick zone creation
--
-- Usage: docker exec -i postgres psql -U pdns -d pdns < test-users-permissions-pgsql.sql

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================

-- Template 2: Zone Manager - Full management of own zones
INSERT INTO perm_templ (id, name, descr)
SELECT 2, 'Zone Manager', 'Full zone management rights for own zones, can add master/slave zones'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE id = 2);

-- Template 3: Client Editor - Limited editing (no SOA/NS)
INSERT INTO perm_templ (id, name, descr)
SELECT 3, 'Client Editor', 'Limited editing rights - can edit records but not SOA/NS'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE id = 3);

-- Template 4: Read Only - View access only
INSERT INTO perm_templ (id, name, descr)
SELECT 4, 'Read Only', 'Read-only access to view zones and records'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE id = 4);

-- Template 5: No Access - No permissions at all
INSERT INTO perm_templ (id, name, descr)
SELECT 5, 'No Access', 'No permissions - for testing permission denied scenarios'
WHERE NOT EXISTS (SELECT 1 FROM perm_templ WHERE id = 5);

-- Update sequence
SELECT setval('perm_templ_id_seq', (SELECT COALESCE(MAX(id), 1) FROM perm_templ));

-- =============================================================================
-- PERMISSION TEMPLATE ITEMS
-- =============================================================================

-- Zone Manager (Template 2) permissions
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

-- Client Editor (Template 3) permissions
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 43 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 43);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 62 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 62);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 49 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 49);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 3, 56 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 3 AND perm_id = 56);

-- Read Only (Template 4) permissions
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 4, 43 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 4 AND perm_id = 43);
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 4, 49 WHERE NOT EXISTS (SELECT 1 FROM perm_templ_items WHERE templ_id = 4 AND perm_id = 49);

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "poweradmin123" (bcrypt hashed)

-- Admin user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'admin', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'System Administrator', 'admin@example.com', 'Full system administrator with full access', 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- Update admin if exists
UPDATE users SET
    password = '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi',
    fullname = 'System Administrator',
    email = 'admin@example.com'
WHERE username = 'admin';

-- Manager user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'manager', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Zone Manager', 'manager@example.com', 'Can manage own zones and add new zones', 2, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'manager');

-- Client user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'client', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Client User', 'client@example.com', 'Limited editing rights - cannot edit SOA/NS records', 3, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'client');

-- Viewer user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'viewer', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Read Only User', 'viewer@example.com', 'Read-only access to zones', 4, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'viewer');

-- Noperm user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'noperm', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'No Permission User', 'noperm@example.com', 'Active user with no permissions', 5, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'noperm');

-- Inactive user
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'inactive', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Inactive User', 'inactive@example.com', 'Inactive account - cannot login', 5, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'inactive');

-- =============================================================================
-- LDAP TEST USERS
-- =============================================================================
-- These users authenticate via LDAP (password stored in LDAP, not database)
-- LDAP Password for all users: testpass123
-- LDAP users must exist in LDAP directory (created via ldap-test-users.ldif)

-- ldap-admin - LDAP user with Administrator permissions
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'ldap-admin', '', 'LDAP Administrator', 'ldap-admin@poweradmin.org', 'LDAP test user with Administrator permissions', 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ldap-admin');

-- ldap-manager - LDAP user with Zone Manager permissions
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'ldap-manager', '', 'LDAP Zone Manager', 'ldap-manager@poweradmin.org', 'LDAP test user with Zone Manager permissions', 2, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ldap-manager');

-- ldap-client - LDAP user with Client Editor permissions
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'ldap-client', '', 'LDAP Client Editor', 'ldap-client@poweradmin.org', 'LDAP test user with Client Editor permissions', 3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ldap-client');

-- ldap-viewer - LDAP user with Read Only permissions
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'ldap-viewer', '', 'LDAP Read Only', 'ldap-viewer@poweradmin.org', 'LDAP test user with Read Only permissions', 4, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'ldap-viewer');

-- Update sequence
SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));

-- =============================================================================
-- TEST DOMAINS
-- =============================================================================

-- Master zones (forward)
INSERT INTO domains (name, type) VALUES
('admin-zone.example.com', 'MASTER'),
('manager-zone.example.com', 'MASTER'),
('client-zone.example.com', 'MASTER'),
('shared-zone.example.com', 'MASTER'),
('viewer-zone.example.com', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- Native zones
INSERT INTO domains (name, type) VALUES
('native-zone.example.org', 'NATIVE'),
('secondary-native.example.org', 'NATIVE')
ON CONFLICT (name) DO NOTHING;

-- Slave zones
INSERT INTO domains (name, type, master) VALUES
('slave-zone.example.net', 'SLAVE', '192.0.2.1'),
('external-slave.example.net', 'SLAVE', '192.0.2.2')
ON CONFLICT (name) DO NOTHING;

-- IPv4 reverse zones
INSERT INTO domains (name, type) VALUES
('2.0.192.in-addr.arpa', 'MASTER'),
('168.192.in-addr.arpa', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- IPv6 reverse zone
INSERT INTO domains (name, type) VALUES
('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- IDN zones
INSERT INTO domains (name, type) VALUES
('xn--verstt-eua3l.info', 'MASTER'),
('xn--80aejmjbdxvpe2k.net', 'MASTER'),
('xn--fiq228c.example.com', 'MASTER'),
('xn--wgbh1c.example.com', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- Long domain names
INSERT INTO domains (name, type) VALUES
('very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'MASTER'),
('another.very.deeply.nested.subdomain.structure.example.com', 'MASTER')
ON CONFLICT (name) DO NOTHING;

-- Update sequence
SELECT setval('domains_id_seq', (SELECT COALESCE(MAX(id), 1) FROM domains));

-- =============================================================================
-- SOA AND NS RECORDS
-- =============================================================================

-- Add SOA records for master/native zones
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT
    d.id,
    d.name,
    'SOA',
    'ns1.example.com. hostmaster.example.com. ' || TO_CHAR(NOW(), 'YYYYMMDD') || '01 10800 3600 604800 86400',
    86400,
    0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'SOA'
  );

-- Add NS records
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns1.example.com.', 86400, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns1.example.com.'
  );

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns2.example.com.', 86400, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns2.example.com.'
  );

-- Add basic A records for forward zones
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.1', 3600, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND d.name NOT LIKE '%.arpa'
  AND d.name NOT LIKE 'xn--%'
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = 'www.' || d.name AND r.type = 'A'
  );

-- Update sequence
SELECT setval('records_id_seq', (SELECT COALESCE(MAX(id), 1) FROM records));

-- =============================================================================
-- ZONE OWNERSHIP
-- =============================================================================

-- Admin owns admin-zone.example.com
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'admin-zone.example.com' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Manager owns manager-zone.example.com
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'manager-zone.example.com' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Client owns client-zone.example.com
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'client-zone.example.com' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Shared zone with MULTIPLE OWNERS
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'shared-zone.example.com'
  AND u.username IN ('manager', 'client')
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Viewer owns viewer-zone.example.com
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name = 'viewer-zone.example.com' AND u.username = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Manager owns native zones
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name IN ('native-zone.example.org', 'secondary-native.example.org')
  AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Admin owns slave zones
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name IN ('slave-zone.example.net', 'external-slave.example.net')
  AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Admin owns reverse zones
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name LIKE '%.arpa'
  AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Manager owns IDN zones
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE d.name LIKE 'xn--%'
  AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Client owns long domain name zones
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d
CROSS JOIN users u
WHERE (d.name LIKE 'very-long-%' OR d.name LIKE 'another.very%')
  AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id
  );

-- Update sequence
SELECT setval('zones_id_seq', (SELECT COALESCE(MAX(id), 1) FROM zones));

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

-- Basic Zone Template
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Basic Web Zone', 'Basic zone template with www, mail, and common records', u.id
FROM users u WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Basic Web Zone');

-- Full Zone Template
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Full Zone Template', 'Comprehensive template with SPF, DMARC, and common services', u.id
FROM users u WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Full Zone Template');

-- Manager's custom template
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Manager Custom Template', 'Custom template owned by manager user', u.id
FROM users u WHERE u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Manager Custom Template');

-- Update sequence
SELECT setval('zone_templ_id_seq', (SELECT COALESCE(MAX(id), 1) FROM zone_templ));

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

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================

-- Verify permission templates
SELECT
    pt.id,
    pt.name,
    COUNT(pti.id) as permission_count
FROM perm_templ pt
LEFT JOIN perm_templ_items pti ON pt.id = pti.templ_id
GROUP BY pt.id, pt.name
ORDER BY pt.id;

-- Verify users
SELECT
    u.username,
    u.fullname,
    u.active,
    pt.name as permission_template
FROM users u
LEFT JOIN perm_templ pt ON u.perm_templ = pt.id
ORDER BY u.id;

-- Verify domains by type
SELECT type, COUNT(*) as count FROM domains GROUP BY type ORDER BY type;

-- Verify zones and ownership
SELECT
    d.name as domain,
    d.type,
    string_agg(u.username, ', ' ORDER BY u.username) as owners
FROM domains d
LEFT JOIN zones z ON d.id = z.domain_id
LEFT JOIN users u ON z.owner = u.id
GROUP BY d.id, d.name, d.type
ORDER BY d.name;
