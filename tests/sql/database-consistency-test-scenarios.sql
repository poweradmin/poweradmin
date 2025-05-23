-- Database Consistency Test Scenarios
-- This file creates test data to verify database consistency checks
-- Run this file to create test scenarios, then verify the consistency checks detect them

-- ==============================================================================
-- Test 1: Zones without owners
-- Creates a domain entry without corresponding ownership in zones table
-- ==============================================================================
INSERT INTO domains (name, type) VALUES ('orphan-zone.com', 'MASTER');
-- Intentionally not creating zones entry to test orphan detection

-- ==============================================================================
-- Test 2: Slave zones without master IPs
-- Creates a slave zone with empty master field
-- ==============================================================================
INSERT INTO domains (name, type, master) VALUES ('slave-no-master.com', 'SLAVE', '');

-- ==============================================================================
-- Test 3: Orphaned records
-- Creates records pointing to non-existent domain
-- ==============================================================================
INSERT INTO records (domain_id, name, type, content, ttl) 
VALUES (99999, 'orphan.example.com', 'A', '192.168.1.1', 300);

-- ==============================================================================
-- Test 4: Duplicate SOA records
-- Creates duplicate SOA record for an existing domain
-- ==============================================================================
-- First, create a test domain with proper SOA
INSERT INTO domains (name, type) VALUES ('duplicate-soa-test.com', 'MASTER');
SET @test_domain_id = LAST_INSERT_ID();
INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (@test_domain_id, 1, 0);
INSERT INTO records (domain_id, name, type, content, ttl) 
VALUES (@test_domain_id, 'duplicate-soa-test.com', 'SOA', 'ns1.example.com hostmaster.example.com 2024010101 3600 1800 604800 86400', 86400);

-- Now create duplicate SOA
INSERT INTO records (domain_id, name, type, content, ttl) 
VALUES (@test_domain_id, 'duplicate-soa-test.com', 'SOA', 'ns2.example.com hostmaster.example.com 2024010102 3600 1800 604800 86400', 86400);

-- ==============================================================================
-- Test 5: Zones without SOA records
-- Creates a zone with only NS records, missing required SOA
-- ==============================================================================
INSERT INTO domains (name, type) VALUES ('no-soa-zone.com', 'MASTER');
SET @no_soa_domain_id = LAST_INSERT_ID();
INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (@no_soa_domain_id, 1, 0);
INSERT INTO records (domain_id, name, type, content, ttl) 
VALUES (@no_soa_domain_id, 'no-soa-zone.com', 'NS', 'ns1.example.com', 86400);

-- ==============================================================================
-- VERIFICATION QUERIES
-- Run these to confirm test data was created successfully
-- ==============================================================================
SELECT 'Test scenarios created. Run these queries to verify:' AS message;

SELECT 'Zones without owners:' AS test_case;
SELECT d.id, d.name, d.type FROM domains d 
LEFT JOIN zones z ON d.id = z.domain_id 
WHERE z.domain_id IS NULL AND d.name IN ('orphan-zone.com');

SELECT 'Slave zones without master IPs:' AS test_case;
SELECT id, name, type, master FROM domains 
WHERE type = 'SLAVE' AND (master = '' OR master IS NULL) AND name = 'slave-no-master.com';

SELECT 'Orphaned records:' AS test_case;
SELECT r.id, r.domain_id, r.name, r.type FROM records r 
LEFT JOIN domains d ON r.domain_id = d.id 
WHERE d.id IS NULL AND r.domain_id = 99999;

SELECT 'Duplicate SOA records:' AS test_case;
SELECT domain_id, COUNT(*) as soa_count FROM records 
WHERE type = 'SOA' AND domain_id = @test_domain_id
GROUP BY domain_id HAVING COUNT(*) > 1;

SELECT 'Zones without SOA records:' AS test_case;
SELECT d.id, d.name FROM domains d 
LEFT JOIN records r ON d.id = r.domain_id AND r.type = 'SOA' 
WHERE r.id IS NULL AND d.type IN ('MASTER', 'NATIVE') AND d.name = 'no-soa-zone.com';
