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
WHERE d.name IN ('manager-zone.example.com', 'client-zone.example.com')
GROUP BY d.name
ORDER BY d.name;
