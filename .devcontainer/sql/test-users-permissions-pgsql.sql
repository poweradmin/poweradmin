-- PostgreSQL Test Data: Users, Permission Templates, Zones for 4.x Branch
-- Purpose: Create comprehensive test data for development and testing
-- Database: pdns (single database containing both PowerDNS and Poweradmin tables)
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
-- Password for all users: "poweradmin123" (bcrypt hashed)

-- Admin user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (1, 'admin', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'System Administrator', 'admin@example.com', 'Full system administrator with full access', 1, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname,
    email = EXCLUDED.email;

-- Manager user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (2, 'manager', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Zone Manager', 'manager@example.com', 'Zone manager with full zone management rights', 2, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Client user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (3, 'client', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Client User', 'client@example.com', 'Client editor with limited editing rights', 3, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Viewer user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (4, 'viewer', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Read Only User', 'viewer@example.com', 'Read-only access for viewing zones', 4, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- No permissions user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (5, 'noperm', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'No Permissions User', 'noperm@example.com', 'User with no permissions for testing access denied', 5, 1, 0)
ON CONFLICT (id) DO UPDATE SET
    password = EXCLUDED.password,
    fullname = EXCLUDED.fullname;

-- Inactive user
INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES (6, 'inactive', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Inactive User', 'inactive@example.com', 'Inactive user account for testing disabled login', 5, 0, 0)
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
-- TEST DOMAINS (PowerDNS tables)
-- =============================================================================

INSERT INTO domains (id, name, type, notified_serial)
VALUES (1, 'admin-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (2, 'manager-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (3, 'client-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (4, 'shared-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (5, 'viewer-zone.example.com', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (6, 'native-zone.example.org', 'NATIVE', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, master)
VALUES (7, 'slave-zone.example.net', 'SLAVE', '192.0.2.1')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (8, '2.0.192.in-addr.arpa', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (9, '8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO domains (id, name, type, notified_serial)
VALUES (10, 'xn--verstt-eua3l.info', 'MASTER', 2024010101)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name;

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
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 1, 'admin-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 1 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 1, 'admin-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 1 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 1, 'admin-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 1 AND type = 'NS' AND content = 'ns2.example.com');

-- manager-zone.example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 2, 'manager-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 2 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 2, 'manager-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 2 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 2, 'manager-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 2 AND type = 'NS' AND content = 'ns2.example.com');

-- client-zone.example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 3, 'client-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 3 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 3, 'client-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 3 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 3, 'client-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 3 AND type = 'NS' AND content = 'ns2.example.com');

-- shared-zone.example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 4, 'shared-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 4 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 4, 'shared-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 4 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 4, 'shared-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 4 AND type = 'NS' AND content = 'ns2.example.com');

-- viewer-zone.example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 5, 'viewer-zone.example.com', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 5 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 5, 'viewer-zone.example.com', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 5 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 5, 'viewer-zone.example.com', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 5 AND type = 'NS' AND content = 'ns2.example.com');

-- native-zone.example.org
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 6, 'native-zone.example.org', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 6 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 6, 'native-zone.example.org', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 6 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 6, 'native-zone.example.org', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 6 AND type = 'NS' AND content = 'ns2.example.com');

-- Reverse zones
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 8, '2.0.192.in-addr.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 8 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 8, '2.0.192.in-addr.arpa', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 8 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 8, '2.0.192.in-addr.arpa', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 8 AND type = 'NS' AND content = 'ns2.example.com');

INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 9, '8.b.d.0.1.0.0.2.ip6.arpa', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 9 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 9, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 9 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 9, '8.b.d.0.1.0.0.2.ip6.arpa', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 9 AND type = 'NS' AND content = 'ns2.example.com');

-- IDN zone
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 10, 'xn--verstt-eua3l.info', 'SOA', 'ns1.example.com admin.example.com 2024010101 10800 3600 604800 3600', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 10 AND type = 'SOA');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 10, 'xn--verstt-eua3l.info', 'NS', 'ns1.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 10 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT 10, 'xn--verstt-eua3l.info', 'NS', 'ns2.example.com', 86400, 0, false
WHERE NOT EXISTS (SELECT 1 FROM records WHERE domain_id = 10 AND type = 'NS' AND content = 'ns2.example.com');

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

INSERT INTO zone_templ (id, name, descr, owner)
VALUES (1, 'Basic Zone', 'Basic zone template with standard SOA and NS records', 1)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

INSERT INTO zone_templ (id, name, descr, owner)
VALUES (2, 'Web Hosting', 'Zone template for web hosting with A, MX, and TXT records', 2)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr;

-- Zone template records
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 1, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 1 AND type = 'SOA');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 1, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 1 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 1, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 1 AND type = 'NS' AND content = 'ns2.example.com');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, '[ZONE]', 'SOA', 'ns1.example.com admin.example.com 0 10800 3600 604800 3600', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND type = 'SOA');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, '[ZONE]', 'NS', 'ns1.example.com', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND type = 'NS' AND content = 'ns1.example.com');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, '[ZONE]', 'NS', 'ns2.example.com', 86400, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND type = 'NS' AND content = 'ns2.example.com');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, '[ZONE]', 'A', '192.0.2.1', 3600, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND type = 'A');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND name = 'www.[ZONE]');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND type = 'MX');
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT 2, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
WHERE NOT EXISTS (SELECT 1 FROM zone_templ_records WHERE zone_templ_id = 2 AND name = 'mail.[ZONE]');

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
