-- MySQL Test Data: User Groups and Group-Zone Assignments (4.2.0)
-- Purpose: Populate group memberships and zone-group assignments for testing
-- Requires: test-users-permissions-mysql-combined.sql and test-extra-data-mysql.sql to be run first
--
-- This script creates:
-- - 6 group memberships (including cross-group)
-- - 5 zone-group assignments (including multi-group and group-only ownership)
-- - Sample group audit log entries
--
-- Usage: docker exec -i mariadb mysql -u pdns -ppoweradmin < test-groups-mysql.sql

-- =============================================================================
-- POWERADMIN DATABASE - GROUP MEMBERSHIPS
-- =============================================================================

USE poweradmin;

-- Admin -> Administrators group
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Administrators' AND u.`username` = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- Manager -> Zone Managers group
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Zone Managers' AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- Manager -> Editors group (cross-group membership testing)
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Editors' AND u.`username` = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- Client -> Editors group
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Editors' AND u.`username` = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- Viewer -> Viewers group
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Viewers' AND u.`username` = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- Noperm -> Guests group
INSERT INTO `user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM `user_groups` g
CROSS JOIN `users` u
WHERE g.`name` = 'Guests' AND u.`username` = 'noperm'
  AND NOT EXISTS (
    SELECT 1 FROM `user_group_members` ugm WHERE ugm.`group_id` = g.`id` AND ugm.`user_id` = u.`id`
  );

-- =============================================================================
-- POWERADMIN DATABASE - ZONE-GROUP ASSIGNMENTS
-- =============================================================================

-- manager-zone.example.com -> Zone Managers group
INSERT INTO `zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN `user_groups` g
WHERE d.`name` = 'manager-zone.example.com' AND g.`name` = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM `zones_groups` zg WHERE zg.`domain_id` = d.`id` AND zg.`group_id` = g.`id`
  );

-- client-zone.example.com -> Editors group
INSERT INTO `zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN `user_groups` g
WHERE d.`name` = 'client-zone.example.com' AND g.`name` = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM `zones_groups` zg WHERE zg.`domain_id` = d.`id` AND zg.`group_id` = g.`id`
  );

-- shared-zone.example.com -> Zone Managers group (multi-group zone)
INSERT INTO `zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN `user_groups` g
WHERE d.`name` = 'shared-zone.example.com' AND g.`name` = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM `zones_groups` zg WHERE zg.`domain_id` = d.`id` AND zg.`group_id` = g.`id`
  );

-- shared-zone.example.com -> Editors group (multi-group zone)
INSERT INTO `zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN `user_groups` g
WHERE d.`name` = 'shared-zone.example.com' AND g.`name` = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM `zones_groups` zg WHERE zg.`domain_id` = d.`id` AND zg.`group_id` = g.`id`
  );

-- group-only-zone.example.com -> Zone Managers group (no direct owner)
INSERT INTO `zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN `user_groups` g
WHERE d.`name` = 'group-only-zone.example.com' AND g.`name` = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM `zones_groups` zg WHERE zg.`domain_id` = d.`id` AND zg.`group_id` = g.`id`
  );

-- =============================================================================
-- POWERADMIN DATABASE - GROUP AUDIT LOG ENTRIES
-- =============================================================================

INSERT INTO `log_groups` (`event`, `priority`, `group_id`)
SELECT 'Group "Zone Managers" created by admin', 1, g.`id`
FROM `user_groups` g
WHERE g.`name` = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM `log_groups` lg WHERE lg.`group_id` = g.`id` AND lg.`event` LIKE '%created%'
  );

INSERT INTO `log_groups` (`event`, `priority`, `group_id`)
SELECT 'User "manager" added to group "Zone Managers"', 1, g.`id`
FROM `user_groups` g
WHERE g.`name` = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM `log_groups` lg WHERE lg.`group_id` = g.`id` AND lg.`event` LIKE '%manager%added%'
  );

INSERT INTO `log_groups` (`event`, `priority`, `group_id`)
SELECT 'Zone "shared-zone.example.com" assigned to group "Editors"', 1, g.`id`
FROM `user_groups` g
WHERE g.`name` = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM `log_groups` lg WHERE lg.`group_id` = g.`id` AND lg.`event` LIKE '%assigned%'
  );

INSERT INTO `log_groups` (`event`, `priority`, `group_id`)
SELECT 'User "manager" added to group "Editors" (cross-group membership)', 1, g.`id`
FROM `user_groups` g
WHERE g.`name` = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM `log_groups` lg WHERE lg.`group_id` = g.`id` AND lg.`event` LIKE '%manager%added%'
  );

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Verify group memberships
SELECT g.`name` AS `group_name`, u.`username`
FROM `user_group_members` ugm
JOIN `user_groups` g ON ugm.`group_id` = g.`id`
JOIN `users` u ON ugm.`user_id` = u.`id`
ORDER BY g.`name`, u.`username`;

-- Verify zone-group assignments
SELECT d.`name` AS `domain`, g.`name` AS `group_name`
FROM `zones_groups` zg
JOIN pdns.`domains` d ON zg.`domain_id` = d.`id`
JOIN `user_groups` g ON zg.`group_id` = g.`id`
ORDER BY d.`name`, g.`name`;

-- Verify group audit logs
SELECT lg.`event`, g.`name` AS `group_name`, lg.`created_at`
FROM `log_groups` lg
JOIN `user_groups` g ON lg.`group_id` = g.`id`
ORDER BY lg.`id`;

-- =============================================================================
-- SUMMARY
-- =============================================================================
--
-- Group Memberships:
-- ------------------
-- Group            | User(s)
-- -----------------|------------------
-- Administrators   | admin
-- Zone Managers    | manager
-- Editors          | manager (cross-group), client
-- Viewers          | viewer
-- Guests           | noperm
--
-- Zone-Group Assignments:
-- -----------------------
-- Zone                         | Group(s)
-- -----------------------------|------------------
-- manager-zone.example.com     | Zone Managers
-- client-zone.example.com      | Editors
-- shared-zone.example.com      | Zone Managers, Editors (multi-group)
-- group-only-zone.example.com  | Zone Managers (no direct owner)
--
-- Group Audit Logs: 4 entries
--
-- =============================================================================
