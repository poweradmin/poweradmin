-- PostgreSQL Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-pgsql.sql
--
-- This script adds comprehensive DNS records to existing test zones:
-- - Various record types (A, AAAA, MX, TXT, CNAME, SRV, CAA)
-- - Long content records (SPF, DMARC, DKIM) for UI column width testing
-- - Multiple records per type for bulk operation testing
-- - Disabled records for status testing
--
-- Total records added: ~26 per zone
--
-- Usage: docker exec -i -e PGPASSWORD=poweradmin postgres psql -U pdns -d pdns < test-dns-records-pgsql.sql

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

-- Get domain ID for manager-zone.example.com
DO $$
DECLARE
    zone_id INTEGER;
    zone_name VARCHAR := 'manager-zone.example.com';
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name LIMIT 1;

    -- Only proceed if the zone exists
    IF zone_id IS NOT NULL THEN
        -- Check if records already exist to prevent duplicates
        IF NOT EXISTS (
            -- Prevent duplicate records if script is run multiple times
            -- Check for a specific sentinel record that only exists if this script has run
            SELECT 1 FROM records r
            WHERE r.domain_id = zone_id
              AND r.type = 'A'
              AND r.name = 'test-disabled.' || zone_name
            LIMIT 1
        ) THEN
            INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            -- A records (7 total)
            (zone_id, zone_name, 'A', '192.0.2.1', 3600, 0, false),
            (zone_id, 'www.' || zone_name, 'A', '192.0.2.1', 3600, 0, false),
            (zone_id, 'mail.' || zone_name, 'A', '192.0.2.10', 3600, 0, false),
            (zone_id, 'ftp.' || zone_name, 'A', '192.0.2.20', 3600, 0, false),
            (zone_id, 'blog.' || zone_name, 'A', '192.0.2.30', 3600, 0, false),
            (zone_id, 'shop.' || zone_name, 'A', '192.0.2.40', 3600, 0, false),
            (zone_id, 'api.' || zone_name, 'A', '192.0.2.50', 3600, 0, false),

            -- AAAA records (3 total)
            (zone_id, zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id, 'www.' || zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id, 'mail.' || zone_name, 'AAAA', '2001:db8::10', 3600, 0, false),

            -- MX records (2 total)
            (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10, false),
            (zone_id, zone_name, 'MX', 'mail2.' || zone_name, 3600, 20, false),

            -- TXT records (3 total) - Long content for UI testing
            (zone_id, zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, false),
            (zone_id, '_dmarc.' || zone_name, 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, false),
            (zone_id, 'default._domainkey.' || zone_name, 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, false),

            -- CNAME records (3 total)
            (zone_id, 'cdn.' || zone_name, 'CNAME', 'cdn.provider.example.net', 3600, 0, false),
            (zone_id, 'docs.' || zone_name, 'CNAME', 'documentation.' || zone_name, 3600, 0, false),
            (zone_id, 'webmail.' || zone_name, 'CNAME', 'mail.' || zone_name, 3600, 0, false),

            -- SRV records (2 total)
            (zone_id, '_xmpp-server._tcp.' || zone_name, 'SRV', '0 5269 xmpp.' || zone_name, 3600, 5, false),
            (zone_id, '_sip._tcp.' || zone_name, 'SRV', '0 5060 sip.' || zone_name, 3600, 10, false),

            -- CAA records (2 total)
            (zone_id, zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
            (zone_id, zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, false),

            -- Disabled record for testing (1 total)
            (zone_id, 'test-disabled.' || zone_name, 'A', '192.0.2.99', 3600, 0, true);
        END IF;
    END IF;
END $$;

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

-- Get domain ID for client-zone.example.com
DO $$
DECLARE
    zone_id INTEGER;
    zone_name VARCHAR := 'client-zone.example.com';
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name LIMIT 1;

    -- Only proceed if the zone exists
    IF zone_id IS NOT NULL THEN
        -- Check if records already exist to prevent duplicates
        IF NOT EXISTS (
            -- Prevent duplicate records if script is run multiple times
            -- Check for a specific sentinel record that only exists if this script has run
            SELECT 1 FROM records r
            WHERE r.domain_id = zone_id
              AND r.type = 'A'
              AND r.name = 'test-disabled.' || zone_name
            LIMIT 1
        ) THEN
            INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            -- A records (7 total)
            (zone_id, zone_name, 'A', '192.0.2.1', 3600, 0, false),
            (zone_id, 'www.' || zone_name, 'A', '192.0.2.1', 3600, 0, false),
            (zone_id, 'mail.' || zone_name, 'A', '192.0.2.10', 3600, 0, false),
            (zone_id, 'ftp.' || zone_name, 'A', '192.0.2.20', 3600, 0, false),
            (zone_id, 'blog.' || zone_name, 'A', '192.0.2.30', 3600, 0, false),
            (zone_id, 'shop.' || zone_name, 'A', '192.0.2.40', 3600, 0, false),
            (zone_id, 'api.' || zone_name, 'A', '192.0.2.50', 3600, 0, false),

            -- AAAA records (3 total)
            (zone_id, zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id, 'www.' || zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id, 'mail.' || zone_name, 'AAAA', '2001:db8::10', 3600, 0, false),

            -- MX records (2 total)
            (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10, false),
            (zone_id, zone_name, 'MX', 'mail2.' || zone_name, 3600, 20, false),

            -- TXT records (3 total) - Long content for UI testing
            (zone_id, zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all', 3600, 0, false),
            (zone_id, '_dmarc.' || zone_name, 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, false),
            (zone_id, 'default._domainkey.' || zone_name, 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7g', 3600, 0, false),

            -- CNAME records (3 total)
            (zone_id, 'cdn.' || zone_name, 'CNAME', 'cdn.provider.example.net', 3600, 0, false),
            (zone_id, 'docs.' || zone_name, 'CNAME', 'documentation.' || zone_name, 3600, 0, false),
            (zone_id, 'webmail.' || zone_name, 'CNAME', 'mail.' || zone_name, 3600, 0, false),

            -- SRV records (2 total)
            (zone_id, '_xmpp-server._tcp.' || zone_name, 'SRV', '0 5269 xmpp.' || zone_name, 3600, 5, false),
            (zone_id, '_sip._tcp.' || zone_name, 'SRV', '0 5060 sip.' || zone_name, 3600, 10, false),

            -- CAA records (2 total)
            (zone_id, zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
            (zone_id, zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, false),

            -- Disabled record for testing (1 total)
            (zone_id, 'test-disabled.' || zone_name, 'A', '192.0.2.99', 3600, 0, true);
        END IF;
    END IF;
END $$;

-- =============================================================================
-- TEST858 ZONE - FOR ISSUE #858 COMMENT TESTING
-- =============================================================================
-- This zone tests per-record comment storage (CAA records with different comments)
-- and A/PTR comment sync functionality

DO $$
DECLARE
    zone_id INTEGER;
    reverse_zone_id INTEGER;
    zone_name VARCHAR := 'test858.example.com';
    caa_record_id_1 INTEGER;
    caa_record_id_2 INTEGER;
    caa_record_id_3 INTEGER;
    a_record_id INTEGER;
    comment_id_1 INTEGER;
    comment_id_2 INTEGER;
    comment_id_3 INTEGER;
    a_comment_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name LIMIT 1;
    SELECT id INTO reverse_zone_id FROM domains WHERE name = '168.192.in-addr.arpa' LIMIT 1;

    -- Only proceed if the zones exist
    IF zone_id IS NOT NULL THEN
        -- Check if records already exist to prevent duplicates
        IF NOT EXISTS (
            SELECT 1 FROM records r
            WHERE r.domain_id = zone_id
              AND r.type = 'CAA'
              AND r.content LIKE '%issue "letsencrypt%'
            LIMIT 1
        ) THEN
            -- A records for forward zone
            INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id, 'www.test858.example.com', 'A', '192.168.1.10', 3600, 0, false),
            (zone_id, 'mail.test858.example.com', 'A', '192.168.1.20', 3600, 0, false),
            (zone_id, 'server1.test858.example.com', 'A', '192.168.1.100', 3600, 0, false),
            -- CAA records (3 total) - For testing issue #858 individual comments
            (zone_id, zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
            (zone_id, zone_name, 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, false),
            (zone_id, zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, false);
        END IF;
    END IF;

    -- PTR records for reverse zone
    IF reverse_zone_id IS NOT NULL THEN
        IF NOT EXISTS (
            SELECT 1 FROM records r
            WHERE r.domain_id = reverse_zone_id
              AND r.type = 'PTR'
              AND r.name = '10.1.168.192.in-addr.arpa'
            LIMIT 1
        ) THEN
            INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (reverse_zone_id, '10.1.168.192.in-addr.arpa', 'PTR', 'www.test858.example.com', 3600, 0, false),
            (reverse_zone_id, '20.1.168.192.in-addr.arpa', 'PTR', 'mail.test858.example.com', 3600, 0, false),
            (reverse_zone_id, '100.1.168.192.in-addr.arpa', 'PTR', 'server1.test858.example.com', 3600, 0, false);
        END IF;
    END IF;

    -- Add comments for CAA records (each with different comment to test #858)
    IF zone_id IS NOT NULL THEN
        IF NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%regular certs%') THEN
            INSERT INTO comments (domain_id, name, type, modified_at, comment) VALUES
            (zone_id, 'test858.example.com', 'CAA', EXTRACT(EPOCH FROM NOW())::INTEGER, 'Allow LetsEncrypt to issue regular certs');
        END IF;

        IF NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%wildcard%') THEN
            INSERT INTO comments (domain_id, name, type, modified_at, comment) VALUES
            (zone_id, 'test858.example.com', 'CAA', EXTRACT(EPOCH FROM NOW())::INTEGER, 'Allow LetsEncrypt wildcard certs');
        END IF;

        IF NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%Security contact%') THEN
            INSERT INTO comments (domain_id, name, type, modified_at, comment) VALUES
            (zone_id, 'test858.example.com', 'CAA', EXTRACT(EPOCH FROM NOW())::INTEGER, 'Security contact for certificate issues');
        END IF;

        -- Insert comment for A record (for sync testing)
        IF NOT EXISTS (SELECT 1 FROM comments WHERE domain_id = zone_id AND name = 'www.test858.example.com' AND type = 'A') THEN
            INSERT INTO comments (domain_id, name, type, modified_at, comment) VALUES
            (zone_id, 'www.test858.example.com', 'A', EXTRACT(EPOCH FROM NOW())::INTEGER, 'Web server - should sync with PTR');
        END IF;

        -- Link CAA comments to specific records via record_comment_links
        -- Get record and comment IDs
        SELECT id INTO caa_record_id_1 FROM records WHERE domain_id = zone_id AND type = 'CAA' AND content LIKE '%issue "letsencrypt%' LIMIT 1;
        SELECT id INTO caa_record_id_2 FROM records WHERE domain_id = zone_id AND type = 'CAA' AND content LIKE '%issuewild%' LIMIT 1;
        SELECT id INTO caa_record_id_3 FROM records WHERE domain_id = zone_id AND type = 'CAA' AND content LIKE '%iodef%' LIMIT 1;
        SELECT id INTO a_record_id FROM records WHERE domain_id = zone_id AND type = 'A' AND name = 'www.test858.example.com' LIMIT 1;

        SELECT id INTO comment_id_1 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%regular certs%' LIMIT 1;
        SELECT id INTO comment_id_2 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%wildcard%' LIMIT 1;
        SELECT id INTO comment_id_3 FROM comments WHERE domain_id = zone_id AND type = 'CAA' AND comment LIKE '%Security contact%' LIMIT 1;
        SELECT id INTO a_comment_id FROM comments WHERE domain_id = zone_id AND name = 'www.test858.example.com' AND type = 'A' LIMIT 1;

        -- Insert record_comment_links
        IF caa_record_id_1 IS NOT NULL AND comment_id_1 IS NOT NULL THEN
            INSERT INTO record_comment_links (record_id, comment_id)
            SELECT caa_record_id_1, comment_id_1
            WHERE NOT EXISTS (SELECT 1 FROM record_comment_links WHERE record_id = caa_record_id_1);
        END IF;

        IF caa_record_id_2 IS NOT NULL AND comment_id_2 IS NOT NULL THEN
            INSERT INTO record_comment_links (record_id, comment_id)
            SELECT caa_record_id_2, comment_id_2
            WHERE NOT EXISTS (SELECT 1 FROM record_comment_links WHERE record_id = caa_record_id_2);
        END IF;

        IF caa_record_id_3 IS NOT NULL AND comment_id_3 IS NOT NULL THEN
            INSERT INTO record_comment_links (record_id, comment_id)
            SELECT caa_record_id_3, comment_id_3
            WHERE NOT EXISTS (SELECT 1 FROM record_comment_links WHERE record_id = caa_record_id_3);
        END IF;

        IF a_record_id IS NOT NULL AND a_comment_id IS NOT NULL THEN
            INSERT INTO record_comment_links (record_id, comment_id)
            SELECT a_record_id, a_comment_id
            WHERE NOT EXISTS (SELECT 1 FROM record_comment_links WHERE record_id = a_record_id);
        END IF;
    END IF;
END $$;

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
    SUM(CASE WHEN r.disabled = true THEN 1 ELSE 0 END) AS disabled_records
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
LEFT JOIN record_comment_links rcl ON r.id = rcl.record_id
LEFT JOIN comments c ON rcl.comment_id = c.id
WHERE r.domain_id = (SELECT id FROM domains WHERE name = 'test858.example.com')
  AND r.type IN ('CAA', 'A')
ORDER BY r.type, r.id;
