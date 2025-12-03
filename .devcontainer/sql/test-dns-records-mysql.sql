-- MySQL Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-mysql-combined.sql
--
-- This script adds comprehensive DNS records to existing test zones:
-- - Various record types (A, AAAA, MX, TXT, CNAME, SRV, CAA)
-- - Long content records (SPF, DMARC, DKIM) for UI column width testing
-- - Multiple records per type for bulk operation testing
-- - Disabled records for status testing
--
-- Total records added: ~26 per zone
--
-- Usage: docker exec -i mariadb mysql -u pdns -ppoweradmin pdns < test-dns-records-mysql.sql

USE pdns;

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

-- Get domain ID for manager-zone.example.com
SET @zone_name = 'manager-zone.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

-- Only proceed if the zone exists
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    -- A records (7 total)
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.1' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.10', 3600, 0, 0
    UNION ALL SELECT CONCAT('ftp.', @zone_name), 'A', '192.0.2.20', 3600, 0, 0
    UNION ALL SELECT CONCAT('blog.', @zone_name), 'A', '192.0.2.30', 3600, 0, 0
    UNION ALL SELECT CONCAT('shop.', @zone_name), 'A', '192.0.2.40', 3600, 0, 0
    UNION ALL SELECT CONCAT('api.', @zone_name), 'A', '192.0.2.50', 3600, 0, 0

    -- AAAA records (3 total)
    UNION ALL SELECT @zone_name, 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('www.', @zone_name), 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'AAAA', '2001:db8::10', 3600, 0, 0

    -- MX records (2 total)
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 3600, 10, 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail2.', @zone_name), 3600, 20, 0

    -- TXT records (3 total) - Long content for UI testing
    UNION ALL SELECT @zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, 0
    UNION ALL SELECT CONCAT('_dmarc.', @zone_name), 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, 0
    UNION ALL SELECT CONCAT('default._domainkey.', @zone_name), 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, 0

    -- CNAME records (3 total)
    UNION ALL SELECT CONCAT('cdn.', @zone_name), 'CNAME', 'cdn.provider.example.net', 3600, 0, 0
    UNION ALL SELECT CONCAT('docs.', @zone_name), 'CNAME', CONCAT('documentation.', @zone_name), 3600, 0, 0
    UNION ALL SELECT CONCAT('webmail.', @zone_name), 'CNAME', CONCAT('mail.', @zone_name), 3600, 0, 0

    -- SRV records (2 total)
    UNION ALL SELECT CONCAT('_xmpp-server._tcp.', @zone_name), 'SRV', CONCAT('0 5269 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_sip._tcp.', @zone_name), 'SRV', CONCAT('0 5060 sip.', @zone_name), 3600, 10, 0

    -- CAA records (2 total)
    UNION ALL SELECT @zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0

    -- Disabled record for testing (1 total)
    UNION ALL SELECT CONCAT('test-disabled.', @zone_name), 'A', '192.0.2.99', 3600, 0, 1
) AS records_data
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    -- Prevent duplicate records if script is run multiple times
    -- Check for a specific sentinel record that only exists if this script has run
    SELECT 1 FROM records r
    WHERE r.domain_id = @zone_id
      AND r.type = 'A'
      AND r.name = CONCAT('test-disabled.', @zone_name)
    LIMIT 1
  );

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

-- Get domain ID for client-zone.example.com
SET @zone_name = 'client-zone.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

-- Only proceed if the zone exists
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    -- A records (7 total)
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.1' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.10', 3600, 0, 0
    UNION ALL SELECT CONCAT('ftp.', @zone_name), 'A', '192.0.2.20', 3600, 0, 0
    UNION ALL SELECT CONCAT('blog.', @zone_name), 'A', '192.0.2.30', 3600, 0, 0
    UNION ALL SELECT CONCAT('shop.', @zone_name), 'A', '192.0.2.40', 3600, 0, 0
    UNION ALL SELECT CONCAT('api.', @zone_name), 'A', '192.0.2.50', 3600, 0, 0

    -- AAAA records (3 total)
    UNION ALL SELECT @zone_name, 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('www.', @zone_name), 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'AAAA', '2001:db8::10', 3600, 0, 0

    -- MX records (2 total)
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 3600, 10, 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail2.', @zone_name), 3600, 20, 0

    -- TXT records (3 total) - Long content for UI testing
    UNION ALL SELECT @zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, 0
    UNION ALL SELECT CONCAT('_dmarc.', @zone_name), 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, 0
    UNION ALL SELECT CONCAT('default._domainkey.', @zone_name), 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, 0

    -- CNAME records (3 total)
    UNION ALL SELECT CONCAT('cdn.', @zone_name), 'CNAME', 'cdn.provider.example.net', 3600, 0, 0
    UNION ALL SELECT CONCAT('docs.', @zone_name), 'CNAME', CONCAT('documentation.', @zone_name), 3600, 0, 0
    UNION ALL SELECT CONCAT('webmail.', @zone_name), 'CNAME', CONCAT('mail.', @zone_name), 3600, 0, 0

    -- SRV records (2 total)
    UNION ALL SELECT CONCAT('_xmpp-server._tcp.', @zone_name), 'SRV', CONCAT('0 5269 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_sip._tcp.', @zone_name), 'SRV', CONCAT('0 5060 sip.', @zone_name), 3600, 10, 0

    -- CAA records (2 total)
    UNION ALL SELECT @zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0

    -- Disabled record for testing (1 total)
    UNION ALL SELECT CONCAT('test-disabled.', @zone_name), 'A', '192.0.2.99', 3600, 0, 1
) AS records_data
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    -- Prevent duplicate records if script is run multiple times
    -- Check for a specific sentinel record that only exists if this script has run
    SELECT 1 FROM records r
    WHERE r.domain_id = @zone_id
      AND r.type = 'A'
      AND r.name = CONCAT('test-disabled.', @zone_name)
    LIMIT 1
  );

-- =============================================================================
-- TEST858 ZONE - FOR ISSUE #858 COMMENT TESTING
-- =============================================================================
-- This zone tests per-record comment storage (CAA records with different comments)
-- and A/PTR comment sync functionality

SET @zone_name = 'test858.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);
SET @reverse_zone_id = (SELECT id FROM domains WHERE name = '168.192.in-addr.arpa' LIMIT 1);

-- Only proceed if the zones exist
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    -- A records for forward zone
    SELECT 'www.test858.example.com' as name_col, 'A' as type_col, '192.168.1.10' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT 'mail.test858.example.com', 'A', '192.168.1.20', 3600, 0, 0
    UNION ALL SELECT 'server1.test858.example.com', 'A', '192.168.1.100', 3600, 0, 0

    -- CAA records (3 total) - For testing issue #858 individual comments
    UNION ALL SELECT 'test858.example.com', 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT 'test858.example.com', 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT 'test858.example.com', 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0
) AS records_data
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r
    WHERE r.domain_id = @zone_id
      AND r.type = 'CAA'
      AND r.content LIKE '%issue "letsencrypt%'
    LIMIT 1
  );

-- PTR records for reverse zone
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @reverse_zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    SELECT '10.1.168.192.in-addr.arpa' as name_col, 'PTR' as type_col, 'www.test858.example.com' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT '20.1.168.192.in-addr.arpa', 'PTR', 'mail.test858.example.com', 3600, 0, 0
    UNION ALL SELECT '100.1.168.192.in-addr.arpa', 'PTR', 'server1.test858.example.com', 3600, 0, 0
) AS records_data
WHERE @reverse_zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r
    WHERE r.domain_id = @reverse_zone_id
      AND r.type = 'PTR'
      AND r.name = '10.1.168.192.in-addr.arpa'
    LIMIT 1
  );

-- =============================================================================
-- COMMENTS FOR TEST858 ZONE (Issue #858 - per-record comments)
-- =============================================================================

-- Insert comments for CAA records (each with different comment to test #858)
INSERT INTO comments (domain_id, name, type, modified_at, comment)
SELECT @zone_id, 'test858.example.com', 'CAA', UNIX_TIMESTAMP(), 'Allow LetsEncrypt to issue regular certs'
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = @zone_id AND type = 'CAA' AND comment LIKE '%regular certs%');

INSERT INTO comments (domain_id, name, type, modified_at, comment)
SELECT @zone_id, 'test858.example.com', 'CAA', UNIX_TIMESTAMP(), 'Allow LetsEncrypt wildcard certs'
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = @zone_id AND type = 'CAA' AND comment LIKE '%wildcard%');

INSERT INTO comments (domain_id, name, type, modified_at, comment)
SELECT @zone_id, 'test858.example.com', 'CAA', UNIX_TIMESTAMP(), 'Security contact for certificate issues'
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = @zone_id AND type = 'CAA' AND comment LIKE '%Security contact%');

-- Insert comment for A record (for sync testing)
INSERT INTO comments (domain_id, name, type, modified_at, comment)
SELECT @zone_id, 'www.test858.example.com', 'A', UNIX_TIMESTAMP(), 'Web server - should sync with PTR'
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = @zone_id AND name = 'www.test858.example.com' AND type = 'A');

-- =============================================================================
-- RECORD_COMMENT_LINKS FOR TEST858 (per-record comment linking)
-- =============================================================================

-- Link CAA comments to specific records
INSERT INTO poweradmin.record_comment_links (record_id, comment_id)
SELECT r.id, c.id
FROM records r
JOIN comments c ON c.domain_id = r.domain_id AND c.name = r.name AND c.type = r.type
WHERE r.domain_id = @zone_id
  AND r.type = 'CAA'
  AND r.content LIKE '%issue "letsencrypt%'
  AND c.comment LIKE '%regular certs%'
  AND NOT EXISTS (SELECT 1 FROM poweradmin.record_comment_links rcl WHERE rcl.record_id = r.id);

INSERT INTO poweradmin.record_comment_links (record_id, comment_id)
SELECT r.id, c.id
FROM records r
JOIN comments c ON c.domain_id = r.domain_id AND c.name = r.name AND c.type = r.type
WHERE r.domain_id = @zone_id
  AND r.type = 'CAA'
  AND r.content LIKE '%issuewild%'
  AND c.comment LIKE '%wildcard%'
  AND NOT EXISTS (SELECT 1 FROM poweradmin.record_comment_links rcl WHERE rcl.record_id = r.id);

INSERT INTO poweradmin.record_comment_links (record_id, comment_id)
SELECT r.id, c.id
FROM records r
JOIN comments c ON c.domain_id = r.domain_id AND c.name = r.name AND c.type = r.type
WHERE r.domain_id = @zone_id
  AND r.type = 'CAA'
  AND r.content LIKE '%iodef%'
  AND c.comment LIKE '%Security contact%'
  AND NOT EXISTS (SELECT 1 FROM poweradmin.record_comment_links rcl WHERE rcl.record_id = r.id);

-- Link A record comment
INSERT INTO poweradmin.record_comment_links (record_id, comment_id)
SELECT r.id, c.id
FROM records r
JOIN comments c ON c.domain_id = r.domain_id AND c.name = r.name AND c.type = r.type
WHERE r.domain_id = @zone_id
  AND r.type = 'A'
  AND r.name = 'www.test858.example.com'
  AND c.comment LIKE '%sync with PTR%'
  AND NOT EXISTS (SELECT 1 FROM poweradmin.record_comment_links rcl WHERE rcl.record_id = r.id);

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Show summary of records added
SELECT
    d.name AS zone_name,
    COUNT(r.id) AS total_records,
    SUM(CASE WHEN r.type = 'SOA' THEN 1 ELSE 0 END) AS soa_records,
    SUM(CASE WHEN r.type = 'NS' THEN 1 ELSE 0 END) AS ns_records,
    SUM(CASE WHEN r.type = 'A' THEN 1 ELSE 0 END) AS a_records,
    SUM(CASE WHEN r.type = 'AAAA' THEN 1 ELSE 0 END) AS aaaa_records,
    SUM(CASE WHEN r.type = 'MX' THEN 1 ELSE 0 END) AS mx_records,
    SUM(CASE WHEN r.type = 'TXT' THEN 1 ELSE 0 END) AS txt_records,
    SUM(CASE WHEN r.type = 'CNAME' THEN 1 ELSE 0 END) AS cname_records,
    SUM(CASE WHEN r.type = 'SRV' THEN 1 ELSE 0 END) AS srv_records,
    SUM(CASE WHEN r.type = 'CAA' THEN 1 ELSE 0 END) AS caa_records,
    SUM(CASE WHEN r.disabled = 1 THEN 1 ELSE 0 END) AS disabled_records
FROM domains d
LEFT JOIN records r ON d.id = r.domain_id
WHERE d.name IN ('manager-zone.example.com', 'client-zone.example.com', 'test858.example.com', '168.192.in-addr.arpa')
GROUP BY d.name
ORDER BY d.name;

-- Show test858 comments and links for verification
SELECT
    'ISSUE #858 TEST DATA' as info,
    r.id as record_id,
    r.type,
    LEFT(r.content, 40) as content,
    c.comment
FROM records r
LEFT JOIN poweradmin.record_comment_links rcl ON r.id = rcl.record_id
LEFT JOIN comments c ON rcl.comment_id = c.id
WHERE r.domain_id = (SELECT id FROM domains WHERE name = 'test858.example.com')
  AND r.type IN ('CAA', 'A')
ORDER BY r.type, r.id;
