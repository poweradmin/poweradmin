-- PostgreSQL Test Data: User Groups and Group-Zone Assignments (4.2.0)
-- Purpose: Populate group memberships and zone-group assignments for testing
-- Requires: test-users-permissions-pgsql.sql and test-extra-data-pgsql.sql to be run first
--
-- This script creates:
-- - 6 group memberships (including cross-group)
-- - 5 zone-group assignments (including multi-group and group-only ownership)
-- - Sample group audit log entries
--
-- Usage: docker exec -i -e PGPASSWORD=poweradmin postgres psql -U pdns -d pdns < test-groups-pgsql.sql

-- =============================================================================
-- GROUP PERMISSION TEMPLATES
-- =============================================================================

-- Restore group permission templates (6-10) deleted by --clean
INSERT INTO perm_templ (id, name, descr, template_type) VALUES
    (6, 'Administrators', 'Full administrative access for group members.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;

INSERT INTO perm_templ (id, name, descr, template_type) VALUES
    (7, 'Zone Managers', 'Full zone management for group members.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;

INSERT INTO perm_templ (id, name, descr, template_type) VALUES
    (8, 'Editors', 'Edit zone records (no SOA/NS) for group members.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;

INSERT INTO perm_templ (id, name, descr, template_type) VALUES
    (9, 'Viewers', 'Read-only zone access for group members.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;

INSERT INTO perm_templ (id, name, descr, template_type) VALUES
    (10, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 'group')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, descr = EXCLUDED.descr, template_type = EXCLUDED.template_type;

-- Restore group permission template items
INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 6, id FROM perm_items WHERE name = 'user_is_ueberuser'
  AND NOT EXISTS (SELECT 1 FROM perm_templ_items pti WHERE pti.templ_id = 6 AND pti.perm_id = perm_items.id);

INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 7, id FROM perm_items WHERE name IN (
    'zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
    'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
    'zone_delete_own', 'api_manage_keys'
) AND NOT EXISTS (SELECT 1 FROM perm_templ_items pti WHERE pti.templ_id = 7 AND pti.perm_id = perm_items.id);

INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 8, id FROM perm_items WHERE name IN (
    'zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client'
) AND NOT EXISTS (SELECT 1 FROM perm_templ_items pti WHERE pti.templ_id = 8 AND pti.perm_id = perm_items.id);

INSERT INTO perm_templ_items (templ_id, perm_id)
SELECT 9, id FROM perm_items WHERE name IN (
    'zone_content_view_own', 'search'
) AND NOT EXISTS (SELECT 1 FROM perm_templ_items pti WHERE pti.templ_id = 9 AND pti.perm_id = perm_items.id);

-- Template 10 (Guests) has no permissions

SELECT setval('perm_templ_id_seq', GREATEST((SELECT MAX(id) FROM perm_templ), 10));
SELECT setval('perm_templ_items_id_seq', COALESCE((SELECT MAX(id) FROM perm_templ_items), 1));

-- =============================================================================
-- DEFAULT USER GROUPS
-- =============================================================================

-- Restore default user groups deleted by --clean
INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (1, 'Administrators', 'Full administrative access to all system functions.', 6, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (2, 'Zone Managers', 'Full zone management including creation, editing, and deletion.', 7, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (3, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 8, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (4, 'Viewers', 'Read-only access to zones with search capability.', 9, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (5, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 10, NULL)
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

-- =============================================================================
-- GROUP MEMBERSHIPS
-- =============================================================================

-- Admin -> Administrators group
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Administrators' AND u.username = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- Manager -> Zone Managers group
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Zone Managers' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- Manager -> Editors group (cross-group membership testing)
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Editors' AND u.username = 'manager'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- Client -> Editors group
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Editors' AND u.username = 'client'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- Viewer -> Viewers group
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Viewers' AND u.username = 'viewer'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- Noperm -> Guests group
INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g, users u
WHERE g.name = 'Guests' AND u.username = 'noperm'
  AND NOT EXISTS (
    SELECT 1 FROM user_group_members ugm WHERE ugm.group_id = g.id AND ugm.user_id = u.id
  );

-- =============================================================================
-- ZONE-GROUP ASSIGNMENTS
-- =============================================================================

-- manager-zone.example.com -> Zone Managers group
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'manager-zone.example.com' AND g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- client-zone.example.com -> Editors group
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'client-zone.example.com' AND g.name = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- shared-zone.example.com -> Zone Managers group (multi-group zone)
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'shared-zone.example.com' AND g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- shared-zone.example.com -> Editors group (multi-group zone)
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'shared-zone.example.com' AND g.name = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- group-only-zone.example.com -> Zone Managers group (no direct owner)
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'group-only-zone.example.com' AND g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- group-orphan-zone.example.com -> Zone Managers group (no zones row at all, #1329)
INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d, user_groups g
WHERE d.name = 'group-orphan-zone.example.com' AND g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM zones_groups zg WHERE zg.domain_id = d.id AND zg.group_id = g.id
  );

-- =============================================================================
-- GROUP AUDIT LOG ENTRIES
-- =============================================================================

-- Entries use the structured "operation:key:value" format the app writes today,
-- which the log view renders as colored operation badges. The first row is kept in
-- the old free-text format on purpose, to exercise the "legacy" fallback badge that
-- still displays audit rows recorded before structured logging existed.

INSERT INTO log_groups (event, priority, group_id)
SELECT 'Group "Zone Managers" created by admin', 1, g.id
FROM user_groups g
WHERE g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM log_groups lg WHERE lg.group_id = g.id AND lg.event LIKE '%created by admin%'
  );

INSERT INTO log_groups (event, priority, group_id)
SELECT 'client_ip:127.0.0.1 user:admin operation:add_members group:Zone_Managers group_id:' || g.id || ' count:1 members:manager', 1, g.id
FROM user_groups g
WHERE g.name = 'Zone Managers'
  AND NOT EXISTS (
    SELECT 1 FROM log_groups lg WHERE lg.group_id = g.id AND (lg.event LIKE '%operation:add_members%' OR lg.event LIKE '%added to group%')
  );

INSERT INTO log_groups (event, priority, group_id)
SELECT 'client_ip:127.0.0.1 user:admin operation:add_zones group:Editors group_id:' || g.id || ' count:1 zones:shared-zone.example.com', 1, g.id
FROM user_groups g
WHERE g.name = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM log_groups lg WHERE lg.group_id = g.id AND (lg.event LIKE '%operation:add_zones%' OR lg.event LIKE '%assigned to group%')
  );

INSERT INTO log_groups (event, priority, group_id)
SELECT 'client_ip:127.0.0.1 user:admin operation:add_members group:Editors group_id:' || g.id || ' count:1 members:manager', 1, g.id
FROM user_groups g
WHERE g.name = 'Editors'
  AND NOT EXISTS (
    SELECT 1 FROM log_groups lg WHERE lg.group_id = g.id AND (lg.event LIKE '%operation:add_members%' OR lg.event LIKE '%added to group%')
  );

-- Update sequences
SELECT setval('user_group_members_id_seq', COALESCE((SELECT MAX(id) FROM user_group_members), 1));
SELECT setval('zones_groups_id_seq', COALESCE((SELECT MAX(id) FROM zones_groups), 1));
SELECT setval('log_groups_id_seq', COALESCE((SELECT MAX(id) FROM log_groups), 1));

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Verify group memberships
SELECT g.name AS group_name, u.username
FROM user_group_members ugm
JOIN user_groups g ON ugm.group_id = g.id
JOIN users u ON ugm.user_id = u.id
ORDER BY g.name, u.username;

-- Verify zone-group assignments
SELECT d.name AS domain, g.name AS group_name
FROM zones_groups zg
JOIN domains d ON zg.domain_id = d.id
JOIN user_groups g ON zg.group_id = g.id
ORDER BY d.name, g.name;

-- Verify group audit logs
SELECT lg.event, g.name AS group_name, lg.created_at
FROM log_groups lg
JOIN user_groups g ON lg.group_id = g.id
ORDER BY lg.id;
