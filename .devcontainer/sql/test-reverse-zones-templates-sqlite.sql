-- SQLite Test Data: Reverse Zones and Zone Templates
-- Purpose: Add reverse zones and zone templates for comprehensive E2E testing
-- Requires: test-users-permissions-sqlite.sql to be run first
--
-- This script creates:
-- - IPv4 reverse zones (192.0.2.0/24)
-- - IPv6 reverse zones (2001:db8::/32)
-- - Zone templates for testing template functionality
-- - PTR records for reverse zones
--
-- Usage: docker exec -i sqlite sqlite3 /data/pdns.db < test-reverse-zones-templates-sqlite.sql

-- =============================================================================
-- REVERSE ZONES IN PDNS DATABASE
-- =============================================================================

-- Create IPv4 reverse zone (192.0.2.0/24 - TEST-NET-1)
INSERT OR IGNORE INTO domains (name, type) VALUES ('2.0.192.in-addr.arpa', 'MASTER');

-- Create IPv6 reverse zone (2001:db8::/32)
INSERT OR IGNORE INTO domains (name, type) VALUES ('8.b.d.0.1.0.0.2.ip6.arpa', 'MASTER');

-- Add SOA records for reverse zones
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'SOA',
       'ns1.example.com. hostmaster.example.com. ' || strftime('%s', 'now') || ' 10800 3600 604800 86400',
       86400, 0
FROM domains d
WHERE d.name IN ('2.0.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'SOA');

-- Add NS records for reverse zones
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns1.example.com.', 86400, 0
FROM domains d
WHERE d.name IN ('2.0.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns1.example.com.');

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', 'ns2.example.com.', 86400, 0
FROM domains d
WHERE d.name IN ('2.0.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa')
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'NS' AND r.content = 'ns2.example.com.');

-- Add PTR records for IPv4 reverse zone
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '1.' || d.name, 'PTR', 'www.manager-zone.example.com.', 3600, 0
FROM domains d
WHERE d.name = '2.0.192.in-addr.arpa'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = '1.' || d.name AND r.type = 'PTR');

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '10.' || d.name, 'PTR', 'mail.manager-zone.example.com.', 3600, 0
FROM domains d
WHERE d.name = '2.0.192.in-addr.arpa'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = '10.' || d.name AND r.type = 'PTR');

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '20.' || d.name, 'PTR', 'ftp.manager-zone.example.com.', 3600, 0
FROM domains d
WHERE d.name = '2.0.192.in-addr.arpa'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.name = '20.' || d.name AND r.type = 'PTR');

-- Add PTR record for IPv6 reverse zone
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.' || d.name, 'PTR', 'www.manager-zone.example.com.', 3600, 0
FROM domains d
WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa'
  AND NOT EXISTS (SELECT 1 FROM records r WHERE r.domain_id = d.id AND r.type = 'PTR' AND r.content = 'www.manager-zone.example.com.');

-- =============================================================================
-- ZONE OWNERSHIP FOR REVERSE ZONES
-- =============================================================================

-- Admin owns IPv4 reverse zone
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = '2.0.192.in-addr.arpa' AND u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Manager also owns IPv4 reverse zone
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = '2.0.192.in-addr.arpa' AND u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- Admin owns IPv6 reverse zone
INSERT INTO zones (domain_id, owner, zone_templ_id)
SELECT d.id, u.id, 0
FROM domains d, users u
WHERE d.name = '8.b.d.0.1.0.0.2.ip6.arpa' AND u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.domain_id = d.id AND z.owner = u.id);

-- =============================================================================
-- ZONE TEMPLATES
-- =============================================================================

-- Create zone templates for testing
INSERT INTO zone_templ (name, descr, owner)
SELECT 'Standard Web Zone', 'Template with standard web records (www, mail, ftp)', u.id
FROM users u
WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Standard Web Zone');

INSERT INTO zone_templ (name, descr, owner)
SELECT 'Mail Server Zone', 'Template with mail server records (MX, SPF, DKIM)', u.id
FROM users u
WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Mail Server Zone');

INSERT INTO zone_templ (name, descr, owner)
SELECT 'Minimal Zone', 'Basic zone with only NS records', u.id
FROM users u
WHERE u.username = 'admin'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Minimal Zone');

INSERT INTO zone_templ (name, descr, owner)
SELECT 'Manager Template', 'Template owned by manager user', u.id
FROM users u
WHERE u.username = 'manager'
  AND NOT EXISTS (SELECT 1 FROM zone_templ WHERE name = 'Manager Template');

-- Add template records for Standard Web Zone
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt
WHERE zt.name = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = '[ZONE]' AND ztr.type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'www.[ZONE]', 'A', '192.0.2.1', 3600, 0
FROM zone_templ zt
WHERE zt.name = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = 'www.[ZONE]' AND ztr.type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt
WHERE zt.name = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = 'mail.[ZONE]' AND ztr.type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt
WHERE zt.name = 'Standard Web Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = '[ZONE]' AND ztr.type = 'MX');

-- Add template records for Mail Server Zone
INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail.[ZONE]', 3600, 10
FROM zone_templ zt
WHERE zt.name = 'Mail Server Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = '[ZONE]' AND ztr.type = 'MX' AND ztr.prio = 10);

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'MX', 'mail2.[ZONE]', 3600, 20
FROM zone_templ zt
WHERE zt.name = 'Mail Server Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = '[ZONE]' AND ztr.type = 'MX' AND ztr.prio = 20);

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, 'mail.[ZONE]', 'A', '192.0.2.10', 3600, 0
FROM zone_templ zt
WHERE zt.name = 'Mail Server Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = 'mail.[ZONE]' AND ztr.type = 'A');

INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio)
SELECT zt.id, '[ZONE]', 'TXT', 'v=spf1 mx -all', 3600, 0
FROM zone_templ zt
WHERE zt.name = 'Mail Server Zone'
  AND NOT EXISTS (SELECT 1 FROM zone_templ_records ztr WHERE ztr.zone_templ_id = zt.id AND ztr.name = '[ZONE]' AND ztr.type = 'TXT');

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Verify reverse zones
SELECT d.name, d.type, COUNT(r.id) as record_count
FROM domains d
LEFT JOIN records r ON d.id = r.domain_id
WHERE d.name LIKE '%.arpa'
GROUP BY d.id, d.name, d.type;

-- Verify zone templates
SELECT zt.name, zt.descr, u.username as owner, COUNT(ztr.id) as record_count
FROM zone_templ zt
JOIN users u ON zt.owner = u.id
LEFT JOIN zone_templ_records ztr ON zt.id = ztr.zone_templ_id
GROUP BY zt.id, zt.name, zt.descr, u.username;
