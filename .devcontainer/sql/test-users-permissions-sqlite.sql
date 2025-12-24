-- SQLite Test Data: Users, Permission Templates, Zones for 3.x Branch
-- Purpose: Create comprehensive test data for development and testing
-- Database: Poweradmin SQLite database
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles
-- - Multiple test domains (master, slave, native, reverse, IDN)
-- - Zone ownership records
-- - Zone templates for quick zone creation
--
-- Usage: sqlite3 /path/to/poweradmin.db < test-users-permissions-sqlite.sql

-- =============================================================================
-- PERMISSION TEMPLATES
-- =============================================================================

-- Template 2: Zone Manager
INSERT OR IGNORE INTO perm_templ (id, name, descr) VALUES
(2, 'Zone Manager', 'Full zone management rights for own zones, can add master/slave zones');

-- Template 3: Client Editor
INSERT OR IGNORE INTO perm_templ (id, name, descr) VALUES
(3, 'Client Editor', 'Limited editing rights - can edit records but not SOA/NS');

-- Template 4: Read Only
INSERT OR IGNORE INTO perm_templ (id, name, descr) VALUES
(4, 'Read Only', 'Read-only access to view zones and records');

-- Template 5: No Access
INSERT OR IGNORE INTO perm_templ (id, name, descr) VALUES
(5, 'No Access', 'No permissions - for testing permission denied scenarios');

-- =============================================================================
-- PERMISSION TEMPLATE ITEMS
-- =============================================================================

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
-- Password for all users: "poweradmin123" (bcrypt hashed)

-- Update admin if exists, otherwise insert
INSERT OR REPLACE INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT
    COALESCE((SELECT id FROM users WHERE username = 'admin'), 1),
    'admin',
    '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi',
    'System Administrator',
    'admin@example.com',
    'Full system administrator with full access',
    1, 1, 0;

-- Manager user
INSERT OR IGNORE INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('manager', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Zone Manager', 'manager@example.com', 'Can manage own zones and add new zones', 2, 1, 0);

-- Client user
INSERT OR IGNORE INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('client', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Client User', 'client@example.com', 'Limited editing rights - cannot edit SOA/NS records', 3, 1, 0);

-- Viewer user
INSERT OR IGNORE INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('viewer', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Read Only User', 'viewer@example.com', 'Read-only access to zones', 4, 1, 0);

-- Noperm user
INSERT OR IGNORE INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('noperm', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'No Permission User', 'noperm@example.com', 'Active user with no permissions', 5, 1, 0);

-- Inactive user
INSERT OR IGNORE INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('inactive', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Inactive User', 'inactive@example.com', 'Inactive account - cannot login', 5, 0, 0);

-- =============================================================================
-- TEST DOMAINS
-- =============================================================================

-- Master zones (forward)
INSERT OR IGNORE INTO domains (name, type) VALUES ('admin-zone.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('manager-zone.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('client-zone.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('shared-zone.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('viewer-zone.example.com', 'MASTER');

-- Native zones
INSERT OR IGNORE INTO domains (name, type) VALUES ('native-zone.example.org', 'NATIVE');
INSERT OR IGNORE INTO domains (name, type) VALUES ('secondary-native.example.org', 'NATIVE');

-- Slave zones
INSERT OR IGNORE INTO domains (name, type, master) VALUES ('slave-zone.example.net', 'SLAVE', '192.0.2.1');
INSERT OR IGNORE INTO domains (name, type, master) VALUES ('external-slave.example.net', 'SLAVE', '192.0.2.2');

-- IPv4 reverse zones
INSERT OR IGNORE INTO domains (name, type) VALUES ('2.0.192.in-addr.arpa', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('168.192.in-addr.arpa', 'MASTER');

-- IPv6 reverse zone
INSERT OR IGNORE INTO domains (name, type) VALUES ('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER');

-- IDN zones
INSERT OR IGNORE INTO domains (name, type) VALUES ('xn--verstt-eua3l.info', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('xn--80aejmjbdxvpe2k.net', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('xn--fiq228c.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('xn--wgbh1c.example.com', 'MASTER');

-- Long domain names
INSERT OR IGNORE INTO domains (name, type) VALUES ('very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'MASTER');
INSERT OR IGNORE INTO domains (name, type) VALUES ('another.very.deeply.nested.subdomain.structure.example.com', 'MASTER');

-- =============================================================================
-- SOA AND NS RECORDS
-- =============================================================================

-- Add SOA records for master/native zones
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT
    d.id,
    d.name,
    'SOA',
    'ns1.example.com. hostmaster.example.com. ' || strftime('%s', 'now') || ' 10800 3600 604800 86400',
    86400,
    0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'SOA');

-- Add NS records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns1.example.com.', 86400, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns1.example.com.');

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns2.example.com.', 86400, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns2.example.com.');

-- Add basic A records for forward zones
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.1', 3600, 0
FROM domains d
WHERE d.type IN ('MASTER', 'NATIVE')
  AND d.name NOT LIKE '%.arpa'
  AND d.name NOT LIKE 'xn--%'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = 'www.' || d.name AND r.type = 'A');

-- =============================================================================
-- ZONE OWNERSHIP
-- =============================================================================

-- Admin owns admin-zone.example.com
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'admin-zone.example.com' AND u.username = 'admin';

-- Manager owns manager-zone.example.com
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'manager-zone.example.com' AND u.username = 'manager';

-- Client owns client-zone.example.com
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'client-zone.example.com' AND u.username = 'client';

-- Shared zone - manager
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'manager';

-- Shared zone - client
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'shared-zone.example.com' AND u.username = 'client';

-- Viewer owns viewer-zone.example.com
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = 'viewer-zone.example.com' AND u.username = 'viewer';

-- Manager owns native zones
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name IN ('native-zone.example.org', 'secondary-native.example.org') AND u.username = 'manager';

-- Admin owns slave zones
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name IN ('slave-zone.example.net', 'external-slave.example.net') AND u.username = 'admin';

-- Admin owns reverse zones
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name LIKE '%.arpa' AND u.username = 'admin';

-- Manager owns IDN zones
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name LIKE 'xn--%' AND u.username = 'manager';

-- Client owns long domain name zones
INSERT OR IGNORE INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE (d.name LIKE 'very-long-%' OR d.name LIKE 'another.very%') AND u.username = 'client';

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

-- Basic Zone Template
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Basic Web Zone', 'Basic zone template with www, mail, and common records', u.id
FROM users u WHERE u.username = 'admin';

-- Full Zone Template
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Full Zone Template', 'Comprehensive template with SPF, DMARC, and common services', u.id
FROM users u WHERE u.username = 'admin';

-- Manager's custom template
INSERT OR IGNORE INTO zone_templ (name, descr, owner)
SELECT 'Manager Custom Template', 'Custom template owned by manager user', u.id
FROM users u WHERE u.username = 'manager';

-- Template records for Basic Web Zone
INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';

INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';

INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';

INSERT OR IGNORE INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt WHERE zt.name = 'Basic Web Zone';

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
    GROUP_CONCAT(u.username, ', ') as owners
FROM domains d
LEFT JOIN zones z ON d.id = z.domain_id
LEFT JOIN users u ON z.owner = u.id
GROUP BY d.id, d.name, d.type
ORDER BY d.name;
