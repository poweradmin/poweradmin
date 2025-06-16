-- Create test users with all required fields
INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES
                                                                                                       ('testuser1', '$2y$12$dummy.hash.for.testing', 'Test User 1', 'user1@example.com', 'Test user for pagination testing', 1, 1, 0),
                                                                                                       ('testuser2', '$2y$12$dummy.hash.for.testing', 'Test User 2', 'user2@example.com', 'Test user for pagination testing', 1, 1, 0),
                                                                                                       ('testuser3', '$2y$12$dummy.hash.for.testing', 'Test User 3', 'user3@example.com', 'Test user for pagination testing', 1, 1, 0);

-- Get user IDs for PostgreSQL (using different syntax)
-- Note: PostgreSQL doesn't support variables like MySQL, so we'll use CTEs

-- Create 96 domains starting with 'p' (for PostgreSQL test)
INSERT INTO domains (name, type) VALUES
                                     ('p-test01.com', 'MASTER'),
                                     ('p-test02.com', 'MASTER'),
                                     ('p-test03.com', 'MASTER'),
                                     ('p-test04.com', 'MASTER'),
                                     ('p-test05.com', 'MASTER'),
                                     ('p-test06.com', 'MASTER'),
                                     ('p-test07.com', 'MASTER'),
                                     ('p-test08.com', 'MASTER'),
                                     ('p-test09.com', 'MASTER'),
                                     ('p-test10.com', 'MASTER'),
                                     ('p-test11.com', 'MASTER'),
                                     ('p-test12.com', 'MASTER'),
                                     ('p-test13.com', 'MASTER'),
                                     ('p-test14.com', 'MASTER'),
                                     ('p-test15.com', 'MASTER'),
                                     ('p-test16.com', 'MASTER'),
                                     ('p-test17.com', 'MASTER'),
                                     ('p-test18.com', 'MASTER'),
                                     ('p-test19.com', 'MASTER'),
                                     ('p-test20.com', 'MASTER'),
                                     ('p-test21.com', 'MASTER'),
                                     ('p-test22.com', 'MASTER'),
                                     ('p-test23.com', 'MASTER'),
                                     ('p-test24.com', 'MASTER'),
                                     ('p-test25.com', 'MASTER'),
                                     ('p-test26.com', 'MASTER'),
                                     ('p-test27.com', 'MASTER'),
                                     ('p-test28.com', 'MASTER'),
                                     ('p-test29.com', 'MASTER'),
                                     ('p-test30.com', 'MASTER'),
                                     ('p-test31.com', 'MASTER'),
                                     ('p-test32.com', 'MASTER'),
                                     ('p-test33.com', 'MASTER'),
                                     ('p-test34.com', 'MASTER'),
                                     ('p-test35.com', 'MASTER'),
                                     ('p-test36.com', 'MASTER'),
                                     ('p-test37.com', 'MASTER'),
                                     ('p-test38.com', 'MASTER'),
                                     ('p-test39.com', 'MASTER'),
                                     ('p-test40.com', 'MASTER'),
                                     ('p-test41.com', 'MASTER'),
                                     ('p-test42.com', 'MASTER'),
                                     ('p-test43.com', 'MASTER'),
                                     ('p-test44.com', 'MASTER'),
                                     ('p-test45.com', 'MASTER'),
                                     ('p-test46.com', 'MASTER'),
                                     ('p-test47.com', 'MASTER'),
                                     ('p-test48.com', 'MASTER'),
                                     ('p-test49.com', 'MASTER'),
                                     ('p-test50.com', 'MASTER'),
                                     ('p-test51.com', 'MASTER'),
                                     ('p-test52.com', 'MASTER'),
                                     ('p-test53.com', 'MASTER'),
                                     ('p-test54.com', 'MASTER'),
                                     ('p-test55.com', 'MASTER'),
                                     ('p-test56.com', 'MASTER'),
                                     ('p-test57.com', 'MASTER'),
                                     ('p-test58.com', 'MASTER'),
                                     ('p-test59.com', 'MASTER'),
                                     ('p-test60.com', 'MASTER'),
                                     ('p-test61.com', 'MASTER'),
                                     ('p-test62.com', 'MASTER'),
                                     ('p-test63.com', 'MASTER'),
                                     ('p-test64.com', 'MASTER'),
                                     ('p-test65.com', 'MASTER'),
                                     ('p-test66.com', 'MASTER'),
                                     ('p-test67.com', 'MASTER'),
                                     ('p-test68.com', 'MASTER'),
                                     ('p-test69.com', 'MASTER'),
                                     ('p-test70.com', 'MASTER'),
                                     ('p-test71.com', 'MASTER'),
                                     ('p-test72.com', 'MASTER'),
                                     ('p-test73.com', 'MASTER'),
                                     ('p-test74.com', 'MASTER'),
                                     ('p-test75.com', 'MASTER'),
                                     ('p-test76.com', 'MASTER'),
                                     ('p-test77.com', 'MASTER'),
                                     ('p-test78.com', 'MASTER'),
                                     ('p-test79.com', 'MASTER'),
                                     ('p-test80.com', 'MASTER'),
                                     ('p-test81.com', 'MASTER'),
                                     ('p-test82.com', 'MASTER'),
                                     ('p-test83.com', 'MASTER'),
                                     ('p-test84.com', 'MASTER'),
                                     ('p-test85.com', 'MASTER'),
                                     ('p-test86.com', 'MASTER'),
                                     ('p-test87.com', 'MASTER'),
                                     ('p-test88.com', 'MASTER'),
                                     ('p-test89.com', 'MASTER'),
                                     ('p-test90.com', 'MASTER'),
                                     ('p-test91.com', 'MASTER'),
                                     ('p-test92.com', 'MASTER'),
                                     ('p-test93.com', 'MASTER'),
                                     ('p-test94.com', 'MASTER'),
                                     ('p-test95.com', 'MASTER'),
                                     ('p-test96.com', 'MASTER');

-- Add SOA records for each domain
INSERT INTO records (domain_id, name, type, content, ttl)
SELECT id, name, 'SOA', 'ns1.example.com. admin.example.com. 2024010101 10800 3600 604800 86400', 86400
FROM domains WHERE name LIKE 'p-test%.com';

-- Assign single owner to first 80 domains (using testuser1)
WITH user_id AS (SELECT id FROM users WHERE username = 'testuser1' LIMIT 1)
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, user_id u
WHERE d.name LIKE 'p-test%.com'
  AND CAST(SUBSTRING(d.name, 7, 2) AS INTEGER) <= 80;

-- Add second owner to 13 domains (creates duplicate rows in query results)
WITH user_id AS (SELECT id FROM users WHERE username = 'testuser2' LIMIT 1)
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, user_id u
WHERE d.name IN ('p-test10.com', 'p-test20.com', 'p-test30.com', 'p-test40.com',
                 'p-test50.com', 'p-test60.com', 'p-test70.com', 'p-test80.com',
                 'p-test81.com', 'p-test82.com', 'p-test83.com', 'p-test84.com',
                 'p-test85.com');

-- Assign remaining domains (86-96) to testuser3
WITH user_id AS (SELECT id FROM users WHERE username = 'testuser3' LIMIT 1)
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, user_id u
WHERE d.name LIKE 'p-test%.com'
  AND CAST(SUBSTRING(d.name, 7, 2) AS INTEGER) > 85;

-- Verify the setup
SELECT COUNT(DISTINCT d.id) as unique_domains, COUNT(*) as total_rows
FROM domains d
         JOIN zones z ON d.id = z.domain_id
WHERE d.name LIKE 'p-test%.com';
-- Should show: unique_domains = 96, total_rows = 104
