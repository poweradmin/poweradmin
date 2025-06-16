-- SQLite Test Data for Zone Pagination Issue
-- Purpose: Reproduce pagination bug with domains having multiple owners
-- Issue: When domains have multiple owners, JOINs create duplicate rows that break pagination
-- Expected result: 96 domains total, but incorrect pagination due to ~104 rows from duplicates

-- Create test users with all required fields
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES 
('testuser1', '$2y$12$dummy.hash.for.testing', 'Test User 1', 'user1@example.com', 'Test user for pagination testing', 1, 1, 0),
('testuser2', '$2y$12$dummy.hash.for.testing', 'Test User 2', 'user2@example.com', 'Test user for pagination testing', 1, 1, 0),
('testuser3', '$2y$12$dummy.hash.for.testing', 'Test User 3', 'user3@example.com', 'Test user for pagination testing', 1, 1, 0);

-- Create 96 domains starting with 's' (for SQLite test)
INSERT INTO domains (name, type) VALUES
('s-test01.com', 'MASTER'),
('s-test02.com', 'MASTER'),
('s-test03.com', 'MASTER'),
('s-test04.com', 'MASTER'),
('s-test05.com', 'MASTER'),
('s-test06.com', 'MASTER'),
('s-test07.com', 'MASTER'),
('s-test08.com', 'MASTER'),
('s-test09.com', 'MASTER'),
('s-test10.com', 'MASTER'),
('s-test11.com', 'MASTER'),
('s-test12.com', 'MASTER'),
('s-test13.com', 'MASTER'),
('s-test14.com', 'MASTER'),
('s-test15.com', 'MASTER'),
('s-test16.com', 'MASTER'),
('s-test17.com', 'MASTER'),
('s-test18.com', 'MASTER'),
('s-test19.com', 'MASTER'),
('s-test20.com', 'MASTER'),
('s-test21.com', 'MASTER'),
('s-test22.com', 'MASTER'),
('s-test23.com', 'MASTER'),
('s-test24.com', 'MASTER'),
('s-test25.com', 'MASTER'),
('s-test26.com', 'MASTER'),
('s-test27.com', 'MASTER'),
('s-test28.com', 'MASTER'),
('s-test29.com', 'MASTER'),
('s-test30.com', 'MASTER'),
('s-test31.com', 'MASTER'),
('s-test32.com', 'MASTER'),
('s-test33.com', 'MASTER'),
('s-test34.com', 'MASTER'),
('s-test35.com', 'MASTER'),
('s-test36.com', 'MASTER'),
('s-test37.com', 'MASTER'),
('s-test38.com', 'MASTER'),
('s-test39.com', 'MASTER'),
('s-test40.com', 'MASTER'),
('s-test41.com', 'MASTER'),
('s-test42.com', 'MASTER'),
('s-test43.com', 'MASTER'),
('s-test44.com', 'MASTER'),
('s-test45.com', 'MASTER'),
('s-test46.com', 'MASTER'),
('s-test47.com', 'MASTER'),
('s-test48.com', 'MASTER'),
('s-test49.com', 'MASTER'),
('s-test50.com', 'MASTER'),
('s-test51.com', 'MASTER'),
('s-test52.com', 'MASTER'),
('s-test53.com', 'MASTER'),
('s-test54.com', 'MASTER'),
('s-test55.com', 'MASTER'),
('s-test56.com', 'MASTER'),
('s-test57.com', 'MASTER'),
('s-test58.com', 'MASTER'),
('s-test59.com', 'MASTER'),
('s-test60.com', 'MASTER'),
('s-test61.com', 'MASTER'),
('s-test62.com', 'MASTER'),
('s-test63.com', 'MASTER'),
('s-test64.com', 'MASTER'),
('s-test65.com', 'MASTER'),
('s-test66.com', 'MASTER'),
('s-test67.com', 'MASTER'),
('s-test68.com', 'MASTER'),
('s-test69.com', 'MASTER'),
('s-test70.com', 'MASTER'),
('s-test71.com', 'MASTER'),
('s-test72.com', 'MASTER'),
('s-test73.com', 'MASTER'),
('s-test74.com', 'MASTER'),
('s-test75.com', 'MASTER'),
('s-test76.com', 'MASTER'),
('s-test77.com', 'MASTER'),
('s-test78.com', 'MASTER'),
('s-test79.com', 'MASTER'),
('s-test80.com', 'MASTER'),
('s-test81.com', 'MASTER'),
('s-test82.com', 'MASTER'),
('s-test83.com', 'MASTER'),
('s-test84.com', 'MASTER'),
('s-test85.com', 'MASTER'),
('s-test86.com', 'MASTER'),
('s-test87.com', 'MASTER'),
('s-test88.com', 'MASTER'),
('s-test89.com', 'MASTER'),
('s-test90.com', 'MASTER'),
('s-test91.com', 'MASTER'),
('s-test92.com', 'MASTER'),
('s-test93.com', 'MASTER'),
('s-test94.com', 'MASTER'),
('s-test95.com', 'MASTER'),
('s-test96.com', 'MASTER');

-- Add SOA records for each domain (required for PowerDNS)
INSERT INTO records (domain_id, name, type, content, ttl)
SELECT id, name, 'SOA', 'ns1.example.com. admin.example.com. 2024010101 10800 3600 604800 86400', 86400
FROM domains WHERE name LIKE 's-test%.com';

-- Assign single owner to first 80 domains (using testuser1)
-- SQLite doesn't support variables, so we use subqueries
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, (SELECT id FROM users WHERE username = 'testuser1' LIMIT 1) u
WHERE d.name LIKE 's-test%.com' 
AND CAST(SUBSTR(d.name, 7, 2) AS INTEGER) <= 80;

-- Add second owner to 8 domains (creates 8 duplicate rows in query results)
-- This causes pagination to break: 96 domains + 8 duplicates = 104 total rows
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, (SELECT id FROM users WHERE username = 'testuser2' LIMIT 1) u
WHERE d.name IN ('s-test10.com', 's-test20.com', 's-test30.com', 's-test40.com', 
                 's-test50.com', 's-test60.com', 's-test70.com', 's-test80.com');

-- Assign remaining domains (81-96) to testuser3
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, (SELECT id FROM users WHERE username = 'testuser3' LIMIT 1) u
WHERE d.name LIKE 's-test%.com' 
AND CAST(SUBSTR(d.name, 7, 2) AS INTEGER) > 80;

-- Verify the setup - should show unique_domains = 96, total_rows = 104
SELECT COUNT(DISTINCT d.id) as unique_domains, COUNT(*) as total_rows
FROM domains d
JOIN zones z ON d.id = z.domain_id
WHERE d.name LIKE 's-test%.com';

-- Additional verification: Show domains with multiple owners
SELECT d.name, COUNT(z.owner) as owner_count, GROUP_CONCAT(u.username) as owners
FROM domains d
JOIN zones z ON d.id = z.domain_id
JOIN users u ON z.owner = u.id
WHERE d.name LIKE 's-test%.com'
GROUP BY d.id, d.name
HAVING COUNT(z.owner) > 1
ORDER BY d.name;

-- Test Instructions:
-- 1. Execute this SQL in your SQLite database
-- 2. Set $iface_rowamount = 50 in config/settings.php
-- 3. Navigate to index.php?page=list_zones&letter=s
-- 4. Without fix: See ~47 domains on page 1, ~45 on page 2, missing domains
-- 5. With fix: See exactly 50 domains on page 1, 46 on page 2
-- 6. Check that all domains with multiple owners display correctly
-- 7. SQLite uses different natural sorting than MySQL/PostgreSQL

-- SQLite-specific notes:
-- - Uses SUBSTR() instead of SUBSTRING()
-- - Uses CAST(... AS INTEGER) for type conversion
-- - No variables, uses subqueries instead
-- - GROUP_CONCAT() for concatenating multiple values

-- Cleanup (uncomment to remove test data):
-- DELETE FROM zones WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE 's-test%.com');
-- DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE 's-test%.com');
-- DELETE FROM domains WHERE name LIKE 's-test%.com';
-- DELETE FROM users WHERE username IN ('testuser1', 'testuser2', 'testuser3');