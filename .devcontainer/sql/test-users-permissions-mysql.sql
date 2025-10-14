-- MySQL Test Data: Users and Permission Templates (Combined for DevContainer)
-- Purpose: Create comprehensive test users with different permission levels for development
-- Databases: Poweradmin tables in 'poweradmin' database, PowerDNS tables in 'pdns' database
--
-- This script creates:
-- - 4 permission templates in poweradmin database
-- - 5 test users in poweradmin database
-- - 4 test domains in pdns database
-- - Zone ownership records in poweradmin database
--
-- Usage: docker exec -i mariadb mysql -u pdns -ppoweradmin < test-users-permissions-mysql-combined.sql

-- =============================================================================
-- POWERADMIN DATABASE - PERMISSION TEMPLATES
-- =============================================================================

USE poweradmin;

-- Template #2: Zone Manager - Can fully manage own zones
INSERT IGNORE INTO `perm_templ` (`id`, `name`, `descr`) VALUES
(2, 'Zone Manager', 'Full management of own zones including creation, editing, deletion, and templates');

-- Template #3: Client Editor - Limited editing (no SOA/NS changes)
INSERT IGNORE INTO `perm_templ` (`id`, `name`, `descr`) VALUES
(3, 'Client Editor', 'Can edit own zone records but cannot modify SOA and NS records');

-- Template #4: Read Only - View access only
INSERT IGNORE INTO `perm_templ` (`id`, `name`, `descr`) VALUES
(4, 'Read Only', 'Read-only access to own zones with search capability');

-- Template #5: No Access - For testing inactive/blocked users
INSERT IGNORE INTO `perm_templ` (`id`, `name`, `descr`) VALUES
(5, 'No Access', 'No permissions - for testing inactive or blocked accounts');

-- =============================================================================
-- POWERADMIN DATABASE - PERMISSION TEMPLATE ITEMS
-- =============================================================================

-- Zone Manager Permissions (Template #2)
INSERT IGNORE INTO `perm_templ_items` (`templ_id`, `perm_id`) VALUES
(2, 41),  -- zone_master_add
(2, 42),  -- zone_slave_add
(2, 43),  -- zone_content_view_own
(2, 44),  -- zone_content_edit_own
(2, 45),  -- zone_meta_edit_own
(2, 49),  -- search
(2, 56),  -- user_edit_own
(2, 63),  -- zone_templ_add
(2, 64),  -- zone_templ_edit
(2, 65),  -- api_manage_keys
(2, 67);  -- zone_delete_own

-- Client Editor Permissions (Template #3)
INSERT IGNORE INTO `perm_templ_items` (`templ_id`, `perm_id`) VALUES
(3, 43),  -- zone_content_view_own
(3, 49),  -- search
(3, 56),  -- user_edit_own
(3, 62);  -- zone_content_edit_own_as_client (no SOA/NS editing)

-- Read Only Permissions (Template #4)
INSERT IGNORE INTO `perm_templ_items` (`templ_id`, `perm_id`) VALUES
(4, 43),  -- zone_content_view_own
(4, 49);  -- search

-- No Access Template (Template #5) - No permissions assigned

-- =============================================================================
-- POWERADMIN DATABASE - TEST USERS
-- =============================================================================
-- Password for all users: "poweradmin123" (bcrypt hashed)

-- Use NOT EXISTS to skip users if they already exist
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'admin', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'System Administrator', 'admin@example.com', 'Full system administrator with überuser access', 1, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'manager', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Zone Manager', 'manager@example.com', 'Can manage own zones and templates', 2, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'manager');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'client', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Client User', 'client@example.com', 'Limited editing rights - cannot edit SOA/NS records', 3, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'client');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'viewer', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Read Only User', 'viewer@example.com', 'Read-only access to zones', 4, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'viewer');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'noperm', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'No Permission User', 'noperm@example.com', 'Active user with no permissions', 5, 1, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'noperm');

INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'inactive', '$2y$12$rwnIW4KUbgxh4GC9f8.WKeqcy1p6zBHaHy.SRNmiNcjMwMXIjy/Vi', 'Inactive User', 'inactive@example.com', 'Inactive account - cannot login', 5, 0, 0, 'sql'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'inactive');

-- =============================================================================
-- PDNS DATABASE - TEST DOMAINS
-- =============================================================================

USE pdns;

-- Create test domains in PowerDNS (ignore if they already exist)
INSERT IGNORE INTO `domains` (`name`, `type`) VALUES
('admin-zone.example.com', 'MASTER'),
('manager-zone.example.com', 'MASTER'),
('client-zone.example.com', 'MASTER'),
('shared-zone.example.com', 'MASTER');

-- Add SOA records for each domain (required for PowerDNS)
-- Use NOT EXISTS to prevent duplicate records
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT
    d.`id`,
    d.`name`,
    'SOA',
    CONCAT('ns1.example.com. hostmaster.example.com. ', UNIX_TIMESTAMP(), ' 10800 3600 604800 86400'),
    86400,
    0
FROM `domains` d
WHERE d.`name` IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'SOA'
  );

-- Add NS records for each domain
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`, d.`name`, 'NS', 'ns1.example.com.', 86400, 0
FROM `domains` d
WHERE d.`name` IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'NS' AND r.`content` = 'ns1.example.com.'
  );

INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`, d.`name`, 'NS', 'ns2.example.com.', 86400, 0
FROM `domains` d
WHERE d.`name` IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`type` = 'NS' AND r.`content` = 'ns2.example.com.'
  );

-- Add some sample A records
INSERT INTO `records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`, CONCAT('www.', d.`name`), 'A', '192.0.2.1', 3600, 0
FROM `domains` d
WHERE d.`name` IN ('admin-zone.example.com', 'manager-zone.example.com', 'client-zone.example.com', 'shared-zone.example.com')
  AND NOT EXISTS (
    SELECT 1 FROM `records` r WHERE r.`domain_id` = d.`id` AND r.`name` = CONCAT('www.', d.`name`) AND r.`type` = 'A'
  );

-- =============================================================================
-- POWERADMIN DATABASE - ZONE OWNERSHIP
-- =============================================================================

USE poweradmin;

-- Admin owns admin-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 0
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` u
WHERE d.`name` = 'admin-zone.example.com' AND u.`username` = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Manager owns manager-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 0
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` u
WHERE d.`name` = 'manager-zone.example.com' AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Client owns client-zone.example.com
INSERT INTO `zones` (`domain_id`, `owner`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 0
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` u
WHERE d.`name` = 'client-zone.example.com' AND u.`username` = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- Shared zone with MULTIPLE OWNERS (manager and client both own it)
INSERT INTO `zones` (`domain_id`, `owner`, `zone_templ_id`)
SELECT d.`id`, u.`id`, 0
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` u
WHERE d.`name` = 'shared-zone.example.com'
  AND u.`username` IN ('manager', 'client')
  AND NOT EXISTS (
    SELECT 1 FROM `zones` z WHERE z.`domain_id` = d.`id` AND z.`owner` = u.`id`
  );

-- =============================================================================
-- VERIFICATION QUERIES
-- =============================================================================

-- Verify permission templates
SELECT
    pt.`id`,
    pt.`name`,
    COUNT(pti.`id`) as `permission_count`
FROM poweradmin.`perm_templ` pt
LEFT JOIN poweradmin.`perm_templ_items` pti ON pt.`id` = pti.`templ_id`
GROUP BY pt.`id`, pt.`name`
ORDER BY pt.`id`;

-- Verify users and their templates
SELECT
    u.`username`,
    u.`fullname`,
    u.`email`,
    u.`active`,
    pt.`name` as `permission_template`
FROM poweradmin.`users` u
LEFT JOIN poweradmin.`perm_templ` pt ON u.`perm_templ` = pt.`id`
WHERE u.`username` IN ('admin', 'manager', 'client', 'viewer', 'noperm', 'inactive')
ORDER BY u.`id`;

-- Verify zones and ownership (including multi-owner zones)
SELECT
    d.`name` as `domain`,
    d.`type`,
    u.`username` as `owner`,
    COUNT(*) OVER (PARTITION BY d.`id`) as `owner_count`
FROM pdns.`domains` d
JOIN poweradmin.`zones` z ON d.`id` = z.`domain_id`
JOIN poweradmin.`users` u ON z.`owner` = u.`id`
WHERE d.`name` LIKE '%.example.com'
ORDER BY d.`name`, u.`username`;

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
