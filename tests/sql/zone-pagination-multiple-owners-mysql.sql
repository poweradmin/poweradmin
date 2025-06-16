-- MySQL Test Data for Zone Pagination Issue
-- Purpose: Reproduce pagination bug with domains having multiple owners
-- Issue: When domains have multiple owners, JOINs create duplicate rows that break pagination
-- Expected result: 96 domains total, but incorrect pagination due to ~104 rows from duplicates

-- Create test users with all required fields
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES 
('testuser1', '$2y$12$dummy.hash.for.testing', 'Test User 1', 'user1@example.com', 'Test user for pagination testing', 1, 1, 0),
('testuser2', '$2y$12$dummy.hash.for.testing', 'Test User 2', 'user2@example.com', 'Test user for pagination testing', 1, 1, 0),
('testuser3', '$2y$12$dummy.hash.for.testing', 'Test User 3', 'user3@example.com', 'Test user for pagination testing', 1, 1, 0);

-- Get user IDs using MySQL variables
SET @user1 = (SELECT id FROM users WHERE username = 'testuser1');
SET @user2 = (SELECT id FROM users WHERE username = 'testuser2');
SET @user3 = (SELECT id FROM users WHERE username = 'testuser3');

-- Create 96 domains starting with 'm' (for MySQL test)
INSERT INTO domains (name, type) VALUES
('m-test01.com', 'MASTER'),
('m-test02.com', 'MASTER'),
('m-test03.com', 'MASTER'),
('m-test04.com', 'MASTER'),
('m-test05.com', 'MASTER'),
('m-test06.com', 'MASTER'),
('m-test07.com', 'MASTER'),
('m-test08.com', 'MASTER'),
('m-test09.com', 'MASTER'),
('m-test10.com', 'MASTER'),
('m-test11.com', 'MASTER'),
('m-test12.com', 'MASTER'),
('m-test13.com', 'MASTER'),
('m-test14.com', 'MASTER'),
('m-test15.com', 'MASTER'),
('m-test16.com', 'MASTER'),
('m-test17.com', 'MASTER'),
('m-test18.com', 'MASTER'),
('m-test19.com', 'MASTER'),
('m-test20.com', 'MASTER'),
('m-test21.com', 'MASTER'),
('m-test22.com', 'MASTER'),
('m-test23.com', 'MASTER'),
('m-test24.com', 'MASTER'),
('m-test25.com', 'MASTER'),
('m-test26.com', 'MASTER'),
('m-test27.com', 'MASTER'),
('m-test28.com', 'MASTER'),
('m-test29.com', 'MASTER'),
('m-test30.com', 'MASTER'),
('m-test31.com', 'MASTER'),
('m-test32.com', 'MASTER'),
('m-test33.com', 'MASTER'),
('m-test34.com', 'MASTER'),
('m-test35.com', 'MASTER'),
('m-test36.com', 'MASTER'),
('m-test37.com', 'MASTER'),
('m-test38.com', 'MASTER'),
('m-test39.com', 'MASTER'),
('m-test40.com', 'MASTER'),
('m-test41.com', 'MASTER'),
('m-test42.com', 'MASTER'),
('m-test43.com', 'MASTER'),
('m-test44.com', 'MASTER'),
('m-test45.com', 'MASTER'),
('m-test46.com', 'MASTER'),
('m-test47.com', 'MASTER'),
('m-test48.com', 'MASTER'),
('m-test49.com', 'MASTER'),
('m-test50.com', 'MASTER'),
('m-test51.com', 'MASTER'),
('m-test52.com', 'MASTER'),
('m-test53.com', 'MASTER'),
('m-test54.com', 'MASTER'),
('m-test55.com', 'MASTER'),
('m-test56.com', 'MASTER'),
('m-test57.com', 'MASTER'),
('m-test58.com', 'MASTER'),
('m-test59.com', 'MASTER'),
('m-test60.com', 'MASTER'),
('m-test61.com', 'MASTER'),
('m-test62.com', 'MASTER'),
('m-test63.com', 'MASTER'),
('m-test64.com', 'MASTER'),
('m-test65.com', 'MASTER'),
('m-test66.com', 'MASTER'),
('m-test67.com', 'MASTER'),
('m-test68.com', 'MASTER'),
('m-test69.com', 'MASTER'),
('m-test70.com', 'MASTER'),
('m-test71.com', 'MASTER'),
('m-test72.com', 'MASTER'),
('m-test73.com', 'MASTER'),
('m-test74.com', 'MASTER'),
('m-test75.com', 'MASTER'),
('m-test76.com', 'MASTER'),
('m-test77.com', 'MASTER'),
('m-test78.com', 'MASTER'),
('m-test79.com', 'MASTER'),
('m-test80.com', 'MASTER'),
('m-test81.com', 'MASTER'),
('m-test82.com', 'MASTER'),
('m-test83.com', 'MASTER'),
('m-test84.com', 'MASTER'),
('m-test85.com', 'MASTER'),
('m-test86.com', 'MASTER'),
('m-test87.com', 'MASTER'),
('m-test88.com', 'MASTER'),
('m-test89.com', 'MASTER'),
('m-test90.com', 'MASTER'),
('m-test91.com', 'MASTER'),
('m-test92.com', 'MASTER'),
('m-test93.com', 'MASTER'),
('m-test94.com', 'MASTER'),
('m-test95.com', 'MASTER'),
('m-test96.com', 'MASTER');

-- Add SOA records for each domain (required for PowerDNS)
INSERT INTO records (domain_id, name, type, content, ttl)
SELECT id, name, 'SOA', 'ns1.example.com. admin.example.com. 2024010101 10800 3600 604800 86400', 86400
FROM domains WHERE name LIKE 'm-test%.com';

-- Assign single owner to first 80 domains
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT id, @user1, 0
FROM domains 
WHERE name LIKE 'm-test%.com' 
AND CAST(SUBSTRING(name, 7, 2) AS UNSIGNED) <= 80;

-- Add second owner to 8 domains (creates 8 duplicate rows in query results)
-- This causes pagination to break: 96 domains + 8 duplicates = 104 total rows
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT id, @user2, 0
FROM domains 
WHERE name IN ('m-test10.com', 'm-test20.com', 'm-test30.com', 'm-test40.com', 
               'm-test50.com', 'm-test60.com', 'm-test70.com', 'm-test80.com');

-- Assign remaining domains (81-96) to user3
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT id, @user3, 0
FROM domains 
WHERE name LIKE 'm-test%.com' 
AND CAST(SUBSTRING(name, 7, 2) AS UNSIGNED) > 80;

-- Verify the setup - should show unique_domains = 96, total_rows = 104
SELECT COUNT(DISTINCT d.id) as unique_domains, COUNT(*) as total_rows
FROM domains d
JOIN zones z ON d.id = z.domain_id
WHERE d.name LIKE 'm-test%.com';

-- Test Instructions:
-- 1. Execute this SQL in your MySQL database
-- 2. Set $iface_rowamount = 50 in config/settings.php
-- 3. Navigate to index.php?page=list_zones&letter=m
-- 4. Without fix: See ~47 domains on page 1, ~45 on page 2, missing domains
-- 5. With fix: See exactly 50 domains on page 1, 46 on page 2
-- 6. Check that all domains with multiple owners display correctly

-- Cleanup (uncomment to remove test data):
-- DELETE FROM zones WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE 'm-test%.com');
-- DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE 'm-test%.com');
-- DELETE FROM domains WHERE name LIKE 'm-test%.com';
-- DELETE FROM users WHERE username IN ('testuser1', 'testuser2', 'testuser3');