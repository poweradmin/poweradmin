-- MySQL Test Data: Users, Permission Templates, Zones for 4.x Branch
-- Purpose: Create comprehensive test data for development and testing
-- Databases: poweradmin (Poweradmin tables), pdns (PowerDNS tables)
--
-- This script creates:
-- - 5 permission templates with various permission levels
-- - 6 test users with different roles
-- - 4 LDAP test users
-- - Multiple test domains (master, slave, native, reverse, IDN)
-- - Zone ownership records
-- - Zone templates for quick zone creation
--
-- Usage: docker exec -i mariadb mysql -u pdns -ppoweradmin < test-users-permissions-mysql.sql

-- =============================================================================
-- POWERADMIN DATABASE - PERMISSION TEMPLATES
-- =============================================================================

USE poweradmin;

-- Ensure Administrator template (ID 1) has überuser permission
-- This grants full system access
-- Using INSERT IGNORE to handle cases where permission already exists
INSERT IGNORE INTO `perm_templ_items` (`templ_id`, `perm_id`) VALUES (1, 53);

-- Also ensure the permission exists even if template was created without it
-- This handles the case where Administrator template exists but lacks the permission
DELETE FROM `perm_templ_items` WHERE `templ_id` = 1 AND `perm_id` != 53;
INSERT IGNORE INTO `perm_templ_items` (`templ_id`, `perm_id`) VALUES (1, 53);

-- Template 2: Zone Manager - Full management of own zones
INSERT INTO `perm_templ` (`id`, `name`, `descr`)
SELECT 2, 'Zone Manager', 'Full zone management rights for own zones, can add master/slave zones'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `id` = 2);

-- Template 3: Client Editor - Limited editing (no SOA/NS)
INSERT INTO `perm_templ` (`id`, `name`, `descr`)
SELECT 3, 'Client Editor', 'Limited editing rights - can edit records but not SOA/NS'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `id` = 3);

-- Template 4: Read Only - View access only
INSERT INTO `perm_templ` (`id`, `name`, `descr`)
SELECT 4, 'Read Only', 'Read-only access to view zones and records'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `id` = 4);

-- Template 5: No Access - No permissions at all
INSERT INTO `perm_templ` (`id`, `name`, `descr`)
SELECT 5, 'No Access', 'No permissions - for testing permission denied scenarios'
WHERE NOT EXISTS (SELECT 1 FROM `perm_templ` WHERE `id` = 5);

-- =============================================================================
-- PERMISSION TEMPLATE ITEMS
-- =============================================================================

-- Zone Manager (Template 2) permissions:
-- zone_master_add(41), zone_slave_add(42), zone_content_view_own(43),
-- zone_content_edit_own(44), zone_meta_edit_own(45), search(49), user_edit_own(56)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 41 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 41);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 42 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 42);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 43 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 43);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 44 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 44);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 45 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 45);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 49 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 49);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 2, 56 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 2 AND `perm_id` = 56);

-- Client Editor (Template 3) permissions:
-- zone_content_view_own(43), zone_content_edit_own_as_client(62), search(49), user_edit_own(56)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 3, 43 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 3 AND `perm_id` = 43);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 3, 62 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 3 AND `perm_id` = 62);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 3, 49 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 3 AND `perm_id` = 49);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 3, 56 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 3 AND `perm_id` = 56);

-- Read Only (Template 4) permissions:
-- zone_content_view_own(43), search(49)
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 4, 43 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 4 AND `perm_id` = 43);
INSERT INTO `perm_templ_items` (`templ_id`, `perm_id`)
SELECT 4, 49 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `perm_templ_items` WHERE `templ_id` = 4 AND `perm_id` = 49);

-- No Access (Template 5) - no permissions added

-- =============================================================================
-- TEST USERS
-- =============================================================================
-- Password for all users: "Poweradmin123" (bcrypt hashed)
-- Hash generated with: password_hash('Poweradmin123', PASSWORD_BCRYPT, ['cost' => 12])

-- Admin user - ensure exists with correct settings (may exist from base schema)
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'admin', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'System Administrator', 'admin@example.com', 'Full system administrator with full access', 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');

-- Update admin password if user exists but has different password
UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'System Administrator',
    `email` = 'admin@example.com'
WHERE `username` = 'admin';

-- Manager user - Zone Manager template
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'manager', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Zone Manager', 'manager@example.com', 'Zone manager with full zone management rights', 2, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'manager');

UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'Zone Manager',
    `email` = 'manager@example.com'
WHERE `username` = 'manager';

-- Client user - Client Editor template
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'client', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Client User', 'client@example.com', 'Client editor with limited editing rights', 3, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'client');

UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'Client User',
    `email` = 'client@example.com'
WHERE `username` = 'client';

-- Viewer user - Read Only template
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'viewer', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Read Only User', 'viewer@example.com', 'Read-only access for viewing zones', 4, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'viewer');

UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'Read Only User',
    `email` = 'viewer@example.com'
WHERE `username` = 'viewer';

-- No permissions user - No Access template
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'noperm', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'No Permissions User', 'noperm@example.com', 'User with no permissions for testing access denied', 5, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'noperm');

UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'No Permissions User',
    `email` = 'noperm@example.com'
WHERE `username` = 'noperm';

-- Inactive user - Cannot login
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'inactive', '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy', 'Inactive User', 'inactive@example.com', 'Inactive user account for testing disabled login', 5, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'inactive');

UPDATE `users` SET
    `password` = '$2y$12$ePrwYICR/IF/tgZv5vwlK.BJygebrdvGkoYc9jyLExCPOzD1Vj0Zy',
    `fullname` = 'Inactive User',
    `email` = 'inactive@example.com'
WHERE `username` = 'inactive';

-- =============================================================================
-- LDAP TEST USERS
-- =============================================================================
-- These users authenticate via LDAP (password stored in LDAP, not database)
-- LDAP Password for all users: testpass123
-- LDAP users must exist in LDAP directory (created via ldap-test-users.ldif)

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'ldap-admin', '', 'LDAP Administrator', 'ldap-admin@poweradmin.org', 'LDAP user with Administrator permissions', 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'ldap-admin');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'ldap-manager', '', 'LDAP Zone Manager', 'ldap-manager@poweradmin.org', 'LDAP user with Zone Manager permissions', 2, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'ldap-manager');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'ldap-client', '', 'LDAP Client Editor', 'ldap-client@poweradmin.org', 'LDAP user with Client Editor permissions', 3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'ldap-client');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'ldap-viewer', '', 'LDAP Read Only', 'ldap-viewer@poweradmin.org', 'LDAP user with Read Only permissions', 4, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'ldap-viewer');

-- =============================================================================
-- API KEYS (for automated API testing)
-- =============================================================================
-- API key for testing: test-api-key-for-automated-testing-12345
-- This key is linked to the admin user for full API access

INSERT INTO `api_keys` (`name`, `secret_key`, `created_by`, `disabled`, `expires_at`)
SELECT 'API Test Key', 'test-api-key-for-automated-testing-12345',
       (SELECT `id` FROM `users` WHERE `username` = 'admin' LIMIT 1), 0, NULL
WHERE NOT EXISTS (SELECT 1 FROM `api_keys` WHERE `secret_key` = 'test-api-key-for-automated-testing-12345');

-- =============================================================================
-- PDNS DATABASE - TEST DOMAINS (PowerDNS tables)
-- =============================================================================

USE pdns;

-- Master zones (forward)
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('admin-zone.example.com', 'MASTER', 2024010101),
('manager-zone.example.com', 'MASTER', 2024010101),
('client-zone.example.com', 'MASTER', 2024010101),
('shared-zone.example.com', 'MASTER', 2024010101),
('viewer-zone.example.com', 'MASTER', 2024010101);

-- Native zones (for testing different zone types)
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('native-zone.example.org', 'NATIVE', 2024010101),
('secondary-native.example.org', 'NATIVE', 2024010101);

-- Slave zones (requires master IP - using example IP)
INSERT IGNORE INTO `domains` (`name`, `type`, `master`) VALUES
('slave-zone.example.net', 'SLAVE', '192.0.2.1'),
('external-slave.example.net', 'SLAVE', '192.0.2.2');

-- IPv4 reverse zones
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('2.0.192.in-addr.arpa', 'MASTER', 2024010101),
('168.192.in-addr.arpa', 'MASTER', 2024010101);

-- IPv6 reverse zone
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER', 2024010101);

-- IDN (Internationalized Domain Names) - Punycode encoded
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('xn--verstt-eua3l.info', 'MASTER', 2024010101);              -- översätt.info (Swedish: translate)

-- Long domain names for UI testing
INSERT IGNORE INTO `domains` (`name`, `type`, `notified_serial`) VALUES
('very-long-subdomain-name-for-testing-ui-column-widths.example.com', 'MASTER', 2024010101),
('another.very.deeply.nested.subdomain.structure.example.com', 'MASTER', 2024010101);

-- =============================================================================
-- PDNS DATABASE - SOA AND NS RECORDS
-- =============================================================================

-- Add SOA records for master/native zones
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`, `disabled`)
SELECT
    d.`id`,
    d.`name`,
    'SOA',
    CONCAT('ns1.example.com. hostmaster.example.com. ', DATE_FORMAT(NOW(), '%Y%m%d'), '01 10800 3600 604800 86400'),
    86400,
    0,
    0
FROM `domains` d
WHERE d.`type` IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'SOA'
  );

-- Add NS records
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`, `disabled`)
SELECT d.`id`, d.`name`, 'NS', 'ns1.example.com.', 86400, 0, 0
FROM `domains` d
WHERE d.`type` IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'NS' AND r.`content` = 'ns1.example.com.'
  );

INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`, `disabled`)
SELECT d.`id`, d.`name`, 'NS', 'ns2.example.com.', 86400, 0, 0
FROM `domains` d
WHERE d.`type` IN ('MASTER', 'NATIVE')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'NS' AND r.`content` = 'ns2.example.com.'
  );

-- Add basic A records for forward zones (excluding reverse and IDN zones)
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`, `disabled`)
SELECT d.`id`, CONCAT('www.', d.`name`), 'A', '192.0.2.1', 3600, 0, 0
FROM `domains` d
WHERE d.`type` IN ('MASTER', 'NATIVE')
  AND d.`name` NOT LIKE '%.arpa'
  AND d.`name` NOT LIKE 'xn--%'
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`name` = CONCAT('www.', d.`name`) AND r.`type` = 'A'
  );

-- =============================================================================
-- ZONE OWNERSHIP (poweradmin database, references pdns.domains)
-- Uses name-based lookups instead of hardcoded IDs for safety
-- =============================================================================

USE poweradmin;

-- Admin owns admin-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Admin test zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'admin-zone.example.com' AND u.`username` = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Manager owns manager-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Manager test zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'manager-zone.example.com' AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Client owns client-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Client test zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'client-zone.example.com' AND u.`username` = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Shared zone with MULTIPLE OWNERS (manager and client both own it)
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Shared zone (manager)', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'shared-zone.example.com' AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Shared zone (client)', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'shared-zone.example.com' AND u.`username` = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Viewer owns viewer-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Viewer test zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'viewer-zone.example.com' AND u.`username` = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Manager owns native zones
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Native zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` IN ('native-zone.example.org', 'secondary-native.example.org')
  AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Admin owns slave zones
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Slave zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` IN ('slave-zone.example.net', 'external-slave.example.net')
  AND u.`username` = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Admin owns reverse zones
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Reverse zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` LIKE '%.arpa'
  AND u.`username` = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Manager owns IDN zone
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'IDN zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE d.`name` = 'xn--verstt-eua3l.info'
  AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Client owns long domain name zones (for UI testing)
INSERT INTO `zones` (`domain_id`, `owner`, `comment`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 'Long domain name zone', 0
FROM `pdns`.`domains` d
CROSS JOIN `users` u
WHERE (d.`name` LIKE 'very-long-%' OR d.`name` LIKE 'another.very%')
  AND u.`username` = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

-- Basic Web Zone Template (owned by admin)
INSERT INTO `zone_templ` (`name`, `descr`, `owner`)
SELECT 'Basic Web Zone', 'Basic zone template with www, mail, and common records', u.`id`
FROM `users` u WHERE u.`username` = 'admin'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ` WHERE `name` = 'Basic Web Zone');

-- Insert template records for Basic Web Zone
INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = 'www.[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = 'mail.[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM `zone_templ` zt WHERE zt.`name` = 'Basic Web Zone'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'MX');

-- Full Zone Template (more comprehensive, owned by admin)
INSERT INTO `zone_templ` (`name`, `descr`, `owner`)
SELECT 'Full Zone Template', 'Comprehensive template with SPF, DMARC, and common services', u.`id`
FROM `users` u WHERE u.`username` = 'admin'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ` WHERE `name` = 'Full Zone Template');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, 'www.[ZONE]', 'CNAME', '[ZONE]', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = 'www.[ZONE]' AND `type` = 'CNAME');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'TXT', '"v=spf1 mx ~all"', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'TXT');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '_dmarc.[ZONE]', 'TXT', '"v=DMARC1; p=none; rua=mailto:dmarc@[ZONE]"', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '_dmarc.[ZONE]' AND `type` = 'TXT');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = 'mail.[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM `zone_templ` zt WHERE zt.`name` = 'Full Zone Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'MX');

-- Manager's custom template
INSERT INTO `zone_templ` (`name`, `descr`, `owner`)
SELECT 'Manager Custom Template', 'Custom template owned by manager user', u.`id`
FROM `users` u WHERE u.`username` = 'manager'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ` WHERE `name` = 'Manager Custom Template');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, '[ZONE]', 'A', '192.0.2.100', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Manager Custom Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = '[ZONE]' AND `type` = 'A');

INSERT INTO `zone_templ_records` (`zone_templ_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT zt.`id`, 'www.[ZONE]', 'A', '192.0.2.100', 3600, 0
FROM `zone_templ` zt WHERE zt.`name` = 'Manager Custom Template'
  AND NOT EXISTS (SELECT 1 FROM `zone_templ_records` WHERE `zone_templ_id` = zt.`id` AND `name` = 'www.[ZONE]' AND `type` = 'A');

-- =============================================================================
-- VERIFICATION QUERIES (Optional - can be run manually)
-- =============================================================================

-- Verify permission templates
SELECT
    pt.`id`,
    pt.`name`,
    COUNT(pti.`id`) as `permission_count`
FROM `poweradmin`.`perm_templ` pt
LEFT JOIN `poweradmin`.`perm_templ_items` pti ON pt.`id` = pti.`templ_id`
GROUP BY pt.`id`, pt.`name`
ORDER BY pt.`id`;

-- Verify users and their templates
SELECT
    u.`username`,
    u.`fullname`,
    u.`active`,
    u.`use_ldap`,
    pt.`name` as `permission_template`
FROM `poweradmin`.`users` u
LEFT JOIN `poweradmin`.`perm_templ` pt ON u.`perm_templ` = pt.`id`
ORDER BY u.`id`;

-- Verify domains by type
SELECT `type`, COUNT(*) as `count` FROM `pdns`.`domains` GROUP BY `type`;

-- Verify zones and ownership
SELECT
    d.`name` as `domain`,
    d.`type`,
    GROUP_CONCAT(u.`username` ORDER BY u.`username`) as `owners`
FROM `pdns`.`domains` d
LEFT JOIN `poweradmin`.`zones` z ON d.`id` = z.`domain_id`
LEFT JOIN `poweradmin`.`users` u ON z.`owner` = u.`id`
GROUP BY d.`id`, d.`name`, d.`type`
ORDER BY d.`name`;
