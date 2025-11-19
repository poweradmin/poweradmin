#!/bin/bash
# Import test permission scenario data into MariaDB
# This script handles the split between pdns and poweradmin databases

set -e

echo "=== Importing test permission scenario ==="

# Part 1: Create domains in pdns database
echo "Step 1: Creating domains in pdns database..."
docker exec -i mariadb mysql -updns -ppoweradmin pdns <<'EOSQL'
-- Clean up existing test domains
DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name IN ('example.com', 'example.org'));
DELETE FROM domains WHERE name IN ('example.com', 'example.org');

-- Create domain: example.com
INSERT INTO domains (name, master, last_check, type, notified_serial, account)
VALUES ('example.com', NULL, NULL, 'MASTER', NULL, NULL);
SET @example_com_domain_id = LAST_INSERT_ID();

-- Create SOA record for example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth)
VALUES (@example_com_domain_id, 'example.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024111601 10800 3600 604800 3600', 3600, 0, 0, NULL, 1);

-- Create NS records for example.com
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth)
VALUES
    (@example_com_domain_id, 'example.com', 'NS', 'ns1.example.com', 3600, 0, 0, NULL, 1),
    (@example_com_domain_id, 'example.com', 'NS', 'ns2.example.com', 3600, 0, 0, NULL, 1);

-- Create domain: example.org
INSERT INTO domains (name, master, last_check, type, notified_serial, account)
VALUES ('example.org', NULL, NULL, 'MASTER', NULL, NULL);
SET @example_org_domain_id = LAST_INSERT_ID();

-- Create SOA record for example.org
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth)
VALUES (@example_org_domain_id, 'example.org', 'SOA', 'ns1.example.org hostmaster.example.org 2024111601 10800 3600 604800 3600', 3600, 0, 0, NULL, 1);

-- Create NS records for example.org
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth)
VALUES
    (@example_org_domain_id, 'example.org', 'NS', 'ns1.example.org', 3600, 0, 0, NULL, 1),
    (@example_org_domain_id, 'example.org', 'NS', 'ns2.example.org', 3600, 0, 0, NULL, 1);

-- Show created domains
SELECT id, name, type FROM domains WHERE name IN ('example.com', 'example.org');
EOSQL

# Part 2: Create poweradmin metadata
echo "Step 2: Creating permission templates, groups, users, and zone ownership..."
docker exec -i mariadb mysql -updns -ppoweradmin poweradmin <<'EOSQL'
-- Clean up existing poweradmin metadata (order matters due to foreign keys)
DELETE FROM user_group_members WHERE user_id IN (SELECT id FROM users WHERE username IN ('alice', 'bob', 'malory'));
DELETE FROM users WHERE username IN ('alice', 'bob', 'malory');
DELETE FROM user_groups WHERE name IN ('ro-users', 'rw-users');
DELETE FROM perm_templ_items WHERE templ_id IN (SELECT id FROM perm_templ WHERE name IN ('Read write user', 'Read write group', 'Read only group'));
DELETE FROM perm_templ WHERE name IN ('Read write user', 'Read write group', 'Read only group');

-- Get domain IDs from pdns database
SET @example_com_domain_id = (SELECT id FROM pdns.domains WHERE name = 'example.com');
SET @example_org_domain_id = (SELECT id FROM pdns.domains WHERE name = 'example.org');

-- Clean up zones and zone group associations
DELETE FROM zones_groups WHERE domain_id IN (@example_com_domain_id, @example_org_domain_id);
DELETE FROM zones WHERE domain_id IN (@example_com_domain_id, @example_org_domain_id);

-- ============================================================================
-- Create Permission Templates
-- ============================================================================

-- Create user permission template: "Read write user"
INSERT INTO perm_templ (name, descr) VALUES ('Read write user', 'Template for users with read-write access to their own zones');
SET @rw_user_template_id = LAST_INSERT_ID();

INSERT INTO perm_templ_items (templ_id, perm_id) VALUES
    (@rw_user_template_id, (SELECT id FROM perm_items WHERE name = 'zone_content_edit_own')),
    (@rw_user_template_id, (SELECT id FROM perm_items WHERE name = 'zone_content_view_own')),
    (@rw_user_template_id, (SELECT id FROM perm_items WHERE name = 'zone_meta_edit_own'));

-- Create group permission template: "Read write group"
INSERT INTO perm_templ (name, descr) VALUES ('Read write group', 'Template for groups with read-write access to owned zones');
SET @rw_group_template_id = LAST_INSERT_ID();

INSERT INTO perm_templ_items (templ_id, perm_id) VALUES
    (@rw_group_template_id, (SELECT id FROM perm_items WHERE name = 'zone_content_edit_own')),
    (@rw_group_template_id, (SELECT id FROM perm_items WHERE name = 'zone_content_view_own')),
    (@rw_group_template_id, (SELECT id FROM perm_items WHERE name = 'zone_meta_edit_own'));

-- Create group permission template: "Read only group"
INSERT INTO perm_templ (name, descr) VALUES ('Read only group', 'Template for groups with read-only access to owned zones');
SET @ro_group_template_id = LAST_INSERT_ID();

INSERT INTO perm_templ_items (templ_id, perm_id) VALUES
    (@ro_group_template_id, (SELECT id FROM perm_items WHERE name = 'zone_content_view_own'));

-- ============================================================================
-- Create Groups
-- ============================================================================

INSERT INTO user_groups (name, description, perm_templ, created_by)
VALUES ('ro-users', 'Read-only users group', @ro_group_template_id, 1);
SET @ro_users_group_id = LAST_INSERT_ID();

INSERT INTO user_groups (name, description, perm_templ, created_by)
VALUES ('rw-users', 'Read-write users group', @rw_group_template_id, 1);
SET @rw_users_group_id = LAST_INSERT_ID();

-- ============================================================================
-- Create Users (password: test123)
-- ============================================================================

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('alice', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Alice Smith', 'alice@example.com', 'Test user Alice', @rw_user_template_id, 1, 0);
SET @alice_user_id = LAST_INSERT_ID();

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('bob', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bob Johnson', 'bob@example.com', 'Test user Bob', @rw_user_template_id, 1, 0);
SET @bob_user_id = LAST_INSERT_ID();

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
VALUES ('malory', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Malory Williams', 'malory@example.com', 'Test user Malory', @rw_user_template_id, 1, 0);
SET @malory_user_id = LAST_INSERT_ID();

-- ============================================================================
-- Add Users to Groups
-- ============================================================================

INSERT INTO user_group_members (user_id, group_id) VALUES
    (@alice_user_id, @ro_users_group_id),
    (@bob_user_id, @ro_users_group_id),
    (@malory_user_id, @ro_users_group_id),
    (@alice_user_id, @rw_users_group_id),
    (@bob_user_id, @rw_users_group_id);

-- ============================================================================
-- Create Zones
-- ============================================================================

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
VALUES (@example_com_domain_id, @malory_user_id, 'Test zone - owned by Malory', 0);

INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
VALUES (@example_org_domain_id, NULL, 'Test zone - group owned only', 0);

-- ============================================================================
-- Set Zone Group Ownership
-- ============================================================================

INSERT INTO zones_groups (domain_id, group_id) VALUES
    (@example_com_domain_id, @rw_users_group_id),
    (@example_com_domain_id, @ro_users_group_id),
    (@example_org_domain_id, @ro_users_group_id),
    (@example_org_domain_id, @rw_users_group_id);

-- ============================================================================
-- VERIFICATION
-- ============================================================================

SELECT '==== PERMISSION TEMPLATES ====' as info;
SELECT id, name, descr FROM perm_templ WHERE name IN ('Read write user', 'Read write group', 'Read only group');

SELECT '==== GROUPS ====' as info;
SELECT g.id, g.name, g.description, pt.name as template_name
FROM user_groups g
LEFT JOIN perm_templ pt ON g.perm_templ = pt.id
WHERE g.name IN ('ro-users', 'rw-users');

SELECT '==== USERS ====' as info;
SELECT u.id, u.username, u.fullname, pt.name as template_name
FROM users u
LEFT JOIN perm_templ pt ON u.perm_templ = pt.id
WHERE u.username IN ('alice', 'bob', 'malory');

SELECT '==== USER-GROUP MEMBERSHIPS ====' as info;
SELECT u.username, g.name as group_name
FROM user_group_members ugm
JOIN users u ON ugm.user_id = u.id
JOIN user_groups g ON ugm.group_id = g.id
WHERE u.username IN ('alice', 'bob', 'malory')
ORDER BY u.username, g.name;

SELECT '==== ZONES ====' as info;
SELECT z.id, d.name as domain_name, u.username as owner, z.comment
FROM zones z
JOIN pdns.domains d ON z.domain_id = d.id
LEFT JOIN users u ON z.owner = u.id
WHERE d.name IN ('example.com', 'example.org');

SELECT '==== ZONE GROUP OWNERSHIP ====' as info;
SELECT d.name as domain_name, g.name as group_name, pt.name as group_template
FROM zones_groups zg
JOIN pdns.domains d ON zg.domain_id = d.id
JOIN user_groups g ON zg.group_id = g.id
LEFT JOIN perm_templ pt ON g.perm_templ = pt.id
WHERE d.name IN ('example.com', 'example.org')
ORDER BY d.name, g.name;

SELECT '==== EXPECTED PERMISSIONS ====' as info;
SELECT 'example.com' as domain, 'Alice' as user, 'CAN EDIT (via rw-users)' as permission
UNION ALL SELECT 'example.com', 'Bob', 'CAN EDIT (via rw-users)'
UNION ALL SELECT 'example.com', 'Malory', 'CAN EDIT (owner + rw template)'
UNION ALL SELECT 'example.org', 'Alice', 'CAN EDIT (via rw-users)'
UNION ALL SELECT 'example.org', 'Bob', 'CAN EDIT (via rw-users)'
UNION ALL SELECT 'example.org', 'Malory', 'READ ONLY (via ro-users only)';
EOSQL

echo ""
echo "=== Import completed successfully! ==="
echo ""
echo "Test users created (password: test123):"
echo "  - alice  (member of: ro-users, rw-users)"
echo "  - bob    (member of: ro-users, rw-users)"
echo "  - malory (member of: ro-users)"
echo ""
echo "Domains created:"
echo "  - example.com (owned by: rw-users group, ro-users group, malory user)"
echo "  - example.org (owned by: ro-users group, rw-users group)"
echo ""
echo "You can now test the permission system:"
echo "  - Access http://localhost:3000 (nginx)"
echo "  - Login with any of the test users"
echo ""
