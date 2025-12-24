-- MySQL Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-mysql-combined.sql
--
-- This script adds comprehensive DNS records to existing test zones:
-- - Various record types (A, AAAA, MX, TXT, CNAME, SRV, CAA, PTR, NAPTR, SSHFP, TLSA)
-- - Long content records (SPF, DMARC, DKIM) for UI column width testing
-- - Multiple records per type for bulk operation testing
-- - Disabled records for status testing
--
-- Usage: docker exec -i mariadb mysql -u pdns -ppoweradmin pdns < test-dns-records-mysql.sql

USE pdns;

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

SET @zone_name = 'manager-zone.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

-- Only proceed if zone exists and hasn't been populated
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    -- A records (8 total)
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.1' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.10', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail2.', @zone_name), 'A', '192.0.2.11', 3600, 0, 0
    UNION ALL SELECT CONCAT('ftp.', @zone_name), 'A', '192.0.2.20', 3600, 0, 0
    UNION ALL SELECT CONCAT('blog.', @zone_name), 'A', '192.0.2.30', 3600, 0, 0
    UNION ALL SELECT CONCAT('shop.', @zone_name), 'A', '192.0.2.40', 3600, 0, 0
    UNION ALL SELECT CONCAT('api.', @zone_name), 'A', '192.0.2.50', 3600, 0, 0

    -- AAAA records (4 total)
    UNION ALL SELECT @zone_name, 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('www.', @zone_name), 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'AAAA', '2001:db8::10', 3600, 0, 0
    UNION ALL SELECT CONCAT('ipv6only.', @zone_name), 'AAAA', '2001:db8::100', 3600, 0, 0

    -- MX records (3 total)
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 3600, 10, 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail2.', @zone_name), 3600, 20, 0
    UNION ALL SELECT @zone_name, 'MX', 'backup-mx.example.net.', 3600, 30, 0

    -- TXT records (5 total) - Long content for UI testing
    UNION ALL SELECT @zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com include:spf.protection.outlook.com ~all', 3600, 0, 0
    UNION ALL SELECT CONCAT('_dmarc.', @zone_name), 'TXT', 'v=DMARC1; p=quarantine; sp=reject; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1; adkim=s; aspf=s', 3600, 0, 0
    UNION ALL SELECT CONCAT('default._domainkey.', @zone_name), 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7gMXBN0123456789abcdef', 3600, 0, 0
    UNION ALL SELECT CONCAT('_github-challenge.', @zone_name), 'TXT', 'a1b2c3d4e5f6g7h8i9j0', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'TXT', 'google-site-verification=1234567890abcdefghijklmnop', 3600, 0, 0

    -- CNAME records (4 total)
    UNION ALL SELECT CONCAT('cdn.', @zone_name), 'CNAME', 'cdn.cloudflare.net.', 3600, 0, 0
    UNION ALL SELECT CONCAT('docs.', @zone_name), 'CNAME', CONCAT('documentation.', @zone_name), 3600, 0, 0
    UNION ALL SELECT CONCAT('webmail.', @zone_name), 'CNAME', CONCAT('mail.', @zone_name), 3600, 0, 0
    UNION ALL SELECT CONCAT('status.', @zone_name), 'CNAME', 'status.example-provider.com.', 3600, 0, 0

    -- SRV records (4 total)
    UNION ALL SELECT CONCAT('_xmpp-server._tcp.', @zone_name), 'SRV', CONCAT('0 5269 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_xmpp-client._tcp.', @zone_name), 'SRV', CONCAT('0 5222 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_sip._tcp.', @zone_name), 'SRV', CONCAT('0 5060 sip.', @zone_name), 3600, 10, 0
    UNION ALL SELECT CONCAT('_ldap._tcp.', @zone_name), 'SRV', CONCAT('0 389 ldap.', @zone_name), 3600, 10, 0

    -- CAA records (3 total)
    UNION ALL SELECT @zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0

    -- SSHFP records (2 total)
    UNION ALL SELECT CONCAT('ssh.', @zone_name), 'SSHFP', '1 1 123456789abcdef67890123456789abcdef012345', 3600, 0, 0
    UNION ALL SELECT CONCAT('ssh.', @zone_name), 'SSHFP', '4 2 123456789abcdef67890123456789abcdef67890123456789abcdef67890ab', 3600, 0, 0

    -- TLSA records (1 total)
    UNION ALL SELECT CONCAT('_443._tcp.www.', @zone_name), 'TLSA', '3 1 1 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 3600, 0, 0

    -- NAPTR records (2 total)
    UNION ALL SELECT @zone_name, 'NAPTR', '100 10 "S" "SIP+D2U" "" _sip._udp.example.com.', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'NAPTR', '100 20 "S" "SIP+D2T" "" _sip._tcp.example.com.', 3600, 0, 0

    -- Disabled records for testing (2 total)
    UNION ALL SELECT CONCAT('test-disabled.', @zone_name), 'A', '192.0.2.99', 3600, 0, 1
    UNION ALL SELECT CONCAT('old-server.', @zone_name), 'A', '192.0.2.98', 3600, 0, 1
) AS records_data
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r
    WHERE r.domain_id = @zone_id AND r.type = 'A' AND r.name = CONCAT('test-disabled.', @zone_name)
    LIMIT 1
  );

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

SET @zone_name = 'client-zone.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, disabled_col
FROM (
    -- A records (8 total)
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.101' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.101', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.110', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail2.', @zone_name), 'A', '192.0.2.111', 3600, 0, 0
    UNION ALL SELECT CONCAT('ftp.', @zone_name), 'A', '192.0.2.120', 3600, 0, 0
    UNION ALL SELECT CONCAT('blog.', @zone_name), 'A', '192.0.2.130', 3600, 0, 0
    UNION ALL SELECT CONCAT('shop.', @zone_name), 'A', '192.0.2.140', 3600, 0, 0
    UNION ALL SELECT CONCAT('api.', @zone_name), 'A', '192.0.2.150', 3600, 0, 0

    -- AAAA records (4 total)
    UNION ALL SELECT @zone_name, 'AAAA', '2001:db8:1::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('www.', @zone_name), 'AAAA', '2001:db8:1::1', 3600, 0, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'AAAA', '2001:db8:1::10', 3600, 0, 0
    UNION ALL SELECT CONCAT('ipv6only.', @zone_name), 'AAAA', '2001:db8:1::100', 3600, 0, 0

    -- MX records (3 total)
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 3600, 10, 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail2.', @zone_name), 3600, 20, 0
    UNION ALL SELECT @zone_name, 'MX', 'backup-mx.example.net.', 3600, 30, 0

    -- TXT records (5 total)
    UNION ALL SELECT @zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8:1::/48 include:_spf.google.com ~all', 3600, 0, 0
    UNION ALL SELECT CONCAT('_dmarc.', @zone_name), 'TXT', 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, 0
    UNION ALL SELECT CONCAT('default._domainkey.', @zone_name), 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'TXT', 'google-site-verification=abcdefghijklmnopqrstuvwxyz', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'TXT', 'ms=ms12345678', 3600, 0, 0

    -- CNAME records (4 total)
    UNION ALL SELECT CONCAT('cdn.', @zone_name), 'CNAME', 'd1234567890.cloudfront.net.', 3600, 0, 0
    UNION ALL SELECT CONCAT('docs.', @zone_name), 'CNAME', 'client-docs.readthedocs.io.', 3600, 0, 0
    UNION ALL SELECT CONCAT('webmail.', @zone_name), 'CNAME', CONCAT('mail.', @zone_name), 3600, 0, 0
    UNION ALL SELECT CONCAT('autodiscover.', @zone_name), 'CNAME', 'autodiscover.outlook.com.', 3600, 0, 0

    -- SRV records (4 total)
    UNION ALL SELECT CONCAT('_xmpp-server._tcp.', @zone_name), 'SRV', CONCAT('0 5269 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_xmpp-client._tcp.', @zone_name), 'SRV', CONCAT('0 5222 xmpp.', @zone_name), 3600, 5, 0
    UNION ALL SELECT CONCAT('_sip._tcp.', @zone_name), 'SRV', CONCAT('0 5060 sip.', @zone_name), 3600, 10, 0
    UNION ALL SELECT CONCAT('_imaps._tcp.', @zone_name), 'SRV', CONCAT('0 993 mail.', @zone_name), 3600, 10, 0

    -- CAA records (3 total)
    UNION ALL SELECT @zone_name, 'CAA', '0 issue "digicert.com"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT @zone_name, 'CAA', '0 iodef "mailto:admin@example.com"', 3600, 0, 0

    -- Disabled records for testing (2 total)
    UNION ALL SELECT CONCAT('test-disabled.', @zone_name), 'A', '192.0.2.199', 3600, 0, 1
    UNION ALL SELECT CONCAT('old-api.', @zone_name), 'A', '192.0.2.198', 3600, 0, 1
) AS records_data
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r
    WHERE r.domain_id = @zone_id AND r.type = 'A' AND r.name = CONCAT('test-disabled.', @zone_name)
    LIMIT 1
  );

-- =============================================================================
-- REVERSE ZONE RECORDS (PTR)
-- =============================================================================

SET @zone_name = '2.0.192.in-addr.arpa';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT @zone_id, name_col, 'PTR', content_col, 3600, 0
FROM (
    SELECT CONCAT('1.', @zone_name) as name_col, 'server1.example.com.' as content_col
    UNION ALL SELECT CONCAT('10.', @zone_name), 'mail.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('11.', @zone_name), 'mail2.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('20.', @zone_name), 'ftp.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('30.', @zone_name), 'blog.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('40.', @zone_name), 'shop.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('50.', @zone_name), 'api.manager-zone.example.com.'
    UNION ALL SELECT CONCAT('101.', @zone_name), 'server2.example.com.'
    UNION ALL SELECT CONCAT('110.', @zone_name), 'mail.client-zone.example.com.'
) AS ptr_records
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = @zone_id AND r.type = 'PTR' LIMIT 1
  );

-- =============================================================================
-- SHARED ZONE RECORDS
-- =============================================================================

SET @zone_name = 'shared-zone.example.com';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT @zone_id, name_col, type_col, content_col, ttl_col, prio_col, 0
FROM (
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.200' as content_col, 3600 as ttl_col, 0 as prio_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.200', 3600, 0
    UNION ALL SELECT CONCAT('api.', @zone_name), 'A', '192.0.2.201', 3600, 0
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.210', 3600, 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 3600, 10
    UNION ALL SELECT @zone_name, 'TXT', 'v=spf1 mx ~all', 3600, 0
    UNION ALL SELECT @zone_name, 'AAAA', '2001:db8:2::1', 3600, 0
) AS shared_records
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = @zone_id AND r.type = 'A' AND r.name = @zone_name LIMIT 1
  );

-- =============================================================================
-- IDN ZONE RECORDS
-- =============================================================================

-- Add basic records to IDN zones
SET @zone_name = 'xn--verstt-eua3l.info';
SET @zone_id = (SELECT id FROM domains WHERE name = @zone_name LIMIT 1);

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT @zone_id, name_col, type_col, content_col, 3600, prio_col
FROM (
    SELECT @zone_name as name_col, 'A' as type_col, '192.0.2.220' as content_col, 0 as prio_col
    UNION ALL SELECT CONCAT('www.', @zone_name), 'A', '192.0.2.220', 0
    UNION ALL SELECT @zone_name, 'MX', CONCAT('mail.', @zone_name), 10
    UNION ALL SELECT CONCAT('mail.', @zone_name), 'A', '192.0.2.221', 0
) AS idn_records
WHERE @zone_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM records r WHERE r.domain_id = @zone_id AND r.type = 'A' AND r.name = @zone_name LIMIT 1
  );

-- =============================================================================
-- VERIFICATION
-- =============================================================================

SELECT
    d.name AS zone_name,
    COUNT(r.id) AS total_records,
    SUM(CASE WHEN r.type = 'SOA' THEN 1 ELSE 0 END) AS soa,
    SUM(CASE WHEN r.type = 'NS' THEN 1 ELSE 0 END) AS ns,
    SUM(CASE WHEN r.type = 'A' THEN 1 ELSE 0 END) AS a,
    SUM(CASE WHEN r.type = 'AAAA' THEN 1 ELSE 0 END) AS aaaa,
    SUM(CASE WHEN r.type = 'MX' THEN 1 ELSE 0 END) AS mx,
    SUM(CASE WHEN r.type = 'TXT' THEN 1 ELSE 0 END) AS txt,
    SUM(CASE WHEN r.type = 'CNAME' THEN 1 ELSE 0 END) AS cname,
    SUM(CASE WHEN r.type = 'SRV' THEN 1 ELSE 0 END) AS srv,
    SUM(CASE WHEN r.type = 'CAA' THEN 1 ELSE 0 END) AS caa,
    SUM(CASE WHEN r.type = 'PTR' THEN 1 ELSE 0 END) AS ptr,
    SUM(CASE WHEN r.disabled = 1 THEN 1 ELSE 0 END) AS disabled
FROM domains d
LEFT JOIN records r ON d.id = r.domain_id
GROUP BY d.name
ORDER BY d.name;
