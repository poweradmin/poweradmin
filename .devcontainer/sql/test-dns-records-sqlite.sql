-- SQLite Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-sqlite.sql
--
-- This script adds comprehensive DNS records to existing test zones:
-- - Various record types (A, AAAA, MX, TXT, CNAME, SRV, CAA)
-- - Long content records (SPF, DMARC, DKIM) for UI column width testing
-- - Multiple records per type for bulk operation testing
-- - Disabled records for status testing
--
-- Total records added: ~26 per zone
--
-- Important: This script attaches the PowerDNS database at /data/db/powerdns.db
-- since the domains and records tables are in a separate database file.
--
-- Usage: docker exec -i sqlite sqlite3 /data/poweradmin.db < test-dns-records-sqlite.sql

-- Attach PowerDNS database
ATTACH DATABASE '/data/db/powerdns.db' AS pdns;

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

-- Insert records for manager-zone.example.com if they don't already exist
INSERT INTO pdns.records (domain_id, name, type, content, ttl, prio, disabled)
SELECT
    (SELECT id FROM pdns.domains WHERE name = 'manager-zone.example.com' LIMIT 1),
    name_col,
    type_col,
    content_col,
    ttl_col,
    prio_col,
    disabled_col
FROM (
    -- A records (7 total)
    SELECT 'manager-zone.example.com' as name_col, 'A' as type_col, '192.0.2.1' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT 'www.manager-zone.example.com', 'A', '192.0.2.1', 3600, 0, 0
    UNION ALL SELECT 'mail.manager-zone.example.com', 'A', '192.0.2.10', 3600, 0, 0
    UNION ALL SELECT 'ftp.manager-zone.example.com', 'A', '192.0.2.20', 3600, 0, 0
    UNION ALL SELECT 'blog.manager-zone.example.com', 'A', '192.0.2.30', 3600, 0, 0
    UNION ALL SELECT 'shop.manager-zone.example.com', 'A', '192.0.2.40', 3600, 0, 0
    UNION ALL SELECT 'api.manager-zone.example.com', 'A', '192.0.2.50', 3600, 0, 0

    -- AAAA records (3 total)
    UNION ALL SELECT 'manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT 'www.manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT 'mail.manager-zone.example.com', 'AAAA', '2001:db8::10', 3600, 0, 0

    -- MX records (2 total)
    UNION ALL SELECT 'manager-zone.example.com', 'MX', 'mail.manager-zone.example.com', 3600, 10, 0
    UNION ALL SELECT 'manager-zone.example.com', 'MX', 'mail2.manager-zone.example.com', 3600, 20, 0

    -- TXT records (3 total) - Long content for UI testing
    UNION ALL SELECT 'manager-zone.example.com', 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, 0
    UNION ALL SELECT '_dmarc.manager-zone.example.com', 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, 0
    UNION ALL SELECT 'default._domainkey.manager-zone.example.com', 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, 0

    -- CNAME records (3 total)
    UNION ALL SELECT 'cdn.manager-zone.example.com', 'CNAME', 'cdn.provider.example.net', 3600, 0, 0
    UNION ALL SELECT 'docs.manager-zone.example.com', 'CNAME', 'documentation.manager-zone.example.com', 3600, 0, 0
    UNION ALL SELECT 'webmail.manager-zone.example.com', 'CNAME', 'mail.manager-zone.example.com', 3600, 0, 0

    -- SRV records (2 total)
    UNION ALL SELECT '_xmpp-server._tcp.manager-zone.example.com', 'SRV', '0 5269 xmpp.manager-zone.example.com', 3600, 5, 0
    UNION ALL SELECT '_sip._tcp.manager-zone.example.com', 'SRV', '0 5060 sip.manager-zone.example.com', 3600, 10, 0

    -- CAA records (2 total)
    UNION ALL SELECT 'manager-zone.example.com', 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT 'manager-zone.example.com', 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0

    -- Disabled record for testing (1 total)
    UNION ALL SELECT 'test-disabled.manager-zone.example.com', 'A', '192.0.2.99', 3600, 0, 1
) AS records_data
WHERE (SELECT id FROM pdns.domains WHERE name = 'manager-zone.example.com' LIMIT 1) IS NOT NULL
  AND NOT EXISTS (
    -- Prevent duplicate records if script is run multiple times
    -- Check for a specific sentinel record that only exists if this script has run
    SELECT 1 FROM pdns.records r
    WHERE r.domain_id = (SELECT id FROM pdns.domains WHERE name = 'manager-zone.example.com' LIMIT 1)
      AND r.type = 'A'
      AND r.name = 'test-disabled.manager-zone.example.com'
    LIMIT 1
  );

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

-- Insert records for client-zone.example.com if they don't already exist
INSERT INTO pdns.records (domain_id, name, type, content, ttl, prio, disabled)
SELECT
    (SELECT id FROM pdns.domains WHERE name = 'client-zone.example.com' LIMIT 1),
    name_col,
    type_col,
    content_col,
    ttl_col,
    prio_col,
    disabled_col
FROM (
    -- A records (7 total)
    SELECT 'client-zone.example.com' as name_col, 'A' as type_col, '192.0.2.1' as content_col, 3600 as ttl_col, 0 as prio_col, 0 as disabled_col
    UNION ALL SELECT 'www.client-zone.example.com', 'A', '192.0.2.1', 3600, 0, 0
    UNION ALL SELECT 'mail.client-zone.example.com', 'A', '192.0.2.10', 3600, 0, 0
    UNION ALL SELECT 'ftp.client-zone.example.com', 'A', '192.0.2.20', 3600, 0, 0
    UNION ALL SELECT 'blog.client-zone.example.com', 'A', '192.0.2.30', 3600, 0, 0
    UNION ALL SELECT 'shop.client-zone.example.com', 'A', '192.0.2.40', 3600, 0, 0
    UNION ALL SELECT 'api.client-zone.example.com', 'A', '192.0.2.50', 3600, 0, 0

    -- AAAA records (3 total)
    UNION ALL SELECT 'client-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT 'www.client-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0
    UNION ALL SELECT 'mail.client-zone.example.com', 'AAAA', '2001:db8::10', 3600, 0, 0

    -- MX records (2 total)
    UNION ALL SELECT 'client-zone.example.com', 'MX', 'mail.client-zone.example.com', 3600, 10, 0
    UNION ALL SELECT 'client-zone.example.com', 'MX', 'mail2.client-zone.example.com', 3600, 20, 0

    -- TXT records (3 total) - Long content for UI testing
    UNION ALL SELECT 'client-zone.example.com', 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, 0
    UNION ALL SELECT '_dmarc.client-zone.example.com', 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, 0
    UNION ALL SELECT 'default._domainkey.client-zone.example.com', 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, 0

    -- CNAME records (3 total)
    UNION ALL SELECT 'cdn.client-zone.example.com', 'CNAME', 'cdn.provider.example.net', 3600, 0, 0
    UNION ALL SELECT 'docs.client-zone.example.com', 'CNAME', 'documentation.client-zone.example.com', 3600, 0, 0
    UNION ALL SELECT 'webmail.client-zone.example.com', 'CNAME', 'mail.client-zone.example.com', 3600, 0, 0

    -- SRV records (2 total)
    UNION ALL SELECT '_xmpp-server._tcp.client-zone.example.com', 'SRV', '0 5269 xmpp.client-zone.example.com', 3600, 5, 0
    UNION ALL SELECT '_sip._tcp.client-zone.example.com', 'SRV', '0 5060 sip.client-zone.example.com', 3600, 10, 0

    -- CAA records (2 total)
    UNION ALL SELECT 'client-zone.example.com', 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
    UNION ALL SELECT 'client-zone.example.com', 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0

    -- Disabled record for testing (1 total)
    UNION ALL SELECT 'test-disabled.client-zone.example.com', 'A', '192.0.2.99', 3600, 0, 1
) AS records_data
WHERE (SELECT id FROM pdns.domains WHERE name = 'client-zone.example.com' LIMIT 1) IS NOT NULL
  AND NOT EXISTS (
    -- Prevent duplicate records if script is run multiple times
    -- Check for a specific sentinel record that only exists if this script has run
    SELECT 1 FROM pdns.records r
    WHERE r.domain_id = (SELECT id FROM pdns.domains WHERE name = 'client-zone.example.com' LIMIT 1)
      AND r.type = 'A'
      AND r.name = 'test-disabled.client-zone.example.com'
    LIMIT 1
  );

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
FROM pdns.domains d
LEFT JOIN pdns.records r ON d.id = r.domain_id
WHERE d.name IN ('manager-zone.example.com', 'client-zone.example.com')
GROUP BY d.name
ORDER BY d.name;

-- Detach the PowerDNS database
DETACH DATABASE pdns;
