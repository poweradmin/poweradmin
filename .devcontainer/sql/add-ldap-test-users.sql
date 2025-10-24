-- Add LDAP Test Users to Poweradmin
-- Purpose: Create test users for LDAP authentication and session cache testing
--
-- Prerequisites:
-- 1. LDAP server must be running with test users
-- 2. LDAP users should exist in LDAP directory (created via ldap-test-user.ldif):
--    - testuser (password: testpass123) - Administrator permissions
--    - testuser2 (password: testpass456) - Zone Manager permissions
--
-- Usage:
--   docker exec -i mariadb mysql -u root -puberuser poweradmin < .devcontainer/sql/add-ldap-test-users.sql
--   OR
--   docker exec -i mariadb mysql -u pdns -ppoweradmin poweradmin < .devcontainer/sql/add-ldap-test-users.sql
--
-- Part of: LDAP testing infrastructure (.devcontainer/ldap/setup-ldap-test.sh)

USE poweradmin;

-- Add testuser - LDAP user with Administrator permissions
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'testuser', '', 'Test User (LDAP)', 'testuser@poweradmin.org', 'LDAP test user for session cache testing - Administrator', 1, 1, 1, 'ldap'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'testuser');

-- Add testuser2 - LDAP user with Zone Manager permissions (different from testuser)
INSERT INTO `users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`, `auth_method`)
SELECT 'testuser2', '', 'Test User 2 (LDAP)', 'testuser2@poweradmin.org', 'LDAP test user 2 for account switching tests - Zone Manager', 2, 1, 1, 'ldap'
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'testuser2');

-- Verification: Show created LDAP users
SELECT
    id,
    username,
    fullname,
    email,
    perm_templ,
    active,
    use_ldap,
    auth_method
FROM users
WHERE username IN ('testuser', 'testuser2')
ORDER BY username;

-- Show summary
SELECT
    COUNT(*) as ldap_users_count,
    SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count
FROM users
WHERE use_ldap = 1;
