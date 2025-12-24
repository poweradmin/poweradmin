-- PostgreSQL Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: pdns
--
-- Usage: docker exec -i postgres psql -U pdns -d pdns < test-dns-records-pgsql.sql

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_name TEXT := 'manager-zone.example.com';
    zone_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name;

    IF zone_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM records WHERE domain_id = zone_id AND type = 'A' AND name = 'test-disabled.' || zone_name
    ) THEN
        -- A records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'A', '192.0.2.1', 3600, 0, false),
        (zone_id, 'www.' || zone_name, 'A', '192.0.2.1', 3600, 0, false),
        (zone_id, 'mail.' || zone_name, 'A', '192.0.2.10', 3600, 0, false),
        (zone_id, 'mail2.' || zone_name, 'A', '192.0.2.11', 3600, 0, false),
        (zone_id, 'ftp.' || zone_name, 'A', '192.0.2.20', 3600, 0, false),
        (zone_id, 'blog.' || zone_name, 'A', '192.0.2.30', 3600, 0, false),
        (zone_id, 'shop.' || zone_name, 'A', '192.0.2.40', 3600, 0, false),
        (zone_id, 'api.' || zone_name, 'A', '192.0.2.50', 3600, 0, false);

        -- AAAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
        (zone_id, 'www.' || zone_name, 'AAAA', '2001:db8::1', 3600, 0, false),
        (zone_id, 'mail.' || zone_name, 'AAAA', '2001:db8::10', 3600, 0, false),
        (zone_id, 'ipv6only.' || zone_name, 'AAAA', '2001:db8::100', 3600, 0, false);

        -- MX records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10, false),
        (zone_id, zone_name, 'MX', 'mail2.' || zone_name, 3600, 20, false),
        (zone_id, zone_name, 'MX', 'backup-mx.example.net.', 3600, 30, false);

        -- TXT records (long content for UI testing)
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com include:spf.protection.outlook.com ~all', 3600, 0, false),
        (zone_id, '_dmarc.' || zone_name, 'TXT', 'v=DMARC1; p=quarantine; sp=reject; rua=mailto:dmarc-reports@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1; adkim=s; aspf=s', 3600, 0, false),
        (zone_id, 'default._domainkey.' || zone_name, 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz6WIl0d8AJ7gMXBN0123456789abcdef', 3600, 0, false),
        (zone_id, '_github-challenge.' || zone_name, 'TXT', 'a1b2c3d4e5f6g7h8i9j0', 3600, 0, false),
        (zone_id, zone_name, 'TXT', 'google-site-verification=1234567890abcdefghijklmnop', 3600, 0, false);

        -- CNAME records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, 'cdn.' || zone_name, 'CNAME', 'cdn.cloudflare.net.', 3600, 0, false),
        (zone_id, 'docs.' || zone_name, 'CNAME', 'documentation.' || zone_name, 3600, 0, false),
        (zone_id, 'webmail.' || zone_name, 'CNAME', 'mail.' || zone_name, 3600, 0, false),
        (zone_id, 'status.' || zone_name, 'CNAME', 'status.example-provider.com.', 3600, 0, false);

        -- SRV records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, '_xmpp-server._tcp.' || zone_name, 'SRV', '0 5269 xmpp.' || zone_name, 3600, 5, false),
        (zone_id, '_xmpp-client._tcp.' || zone_name, 'SRV', '0 5222 xmpp.' || zone_name, 3600, 5, false),
        (zone_id, '_sip._tcp.' || zone_name, 'SRV', '0 5060 sip.' || zone_name, 3600, 10, false),
        (zone_id, '_ldap._tcp.' || zone_name, 'SRV', '0 389 ldap.' || zone_name, 3600, 10, false);

        -- CAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
        (zone_id, zone_name, 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, false),
        (zone_id, zone_name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, false);

        -- SSHFP records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, 'ssh.' || zone_name, 'SSHFP', '1 1 123456789abcdef67890123456789abcdef012345', 3600, 0, false),
        (zone_id, 'ssh.' || zone_name, 'SSHFP', '4 2 123456789abcdef67890123456789abcdef67890123456789abcdef67890ab', 3600, 0, false);

        -- TLSA record
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, '_443._tcp.www.' || zone_name, 'TLSA', '3 1 1 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 3600, 0, false);

        -- NAPTR records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'NAPTR', '100 10 "S" "SIP+D2U" "" _sip._udp.example.com.', 3600, 0, false),
        (zone_id, zone_name, 'NAPTR', '100 20 "S" "SIP+D2T" "" _sip._tcp.example.com.', 3600, 0, false);

        -- Disabled records for testing
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, 'test-disabled.' || zone_name, 'A', '192.0.2.99', 3600, 0, true),
        (zone_id, 'old-server.' || zone_name, 'A', '192.0.2.98', 3600, 0, true);
    END IF;
END $$;

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_name TEXT := 'client-zone.example.com';
    zone_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name;

    IF zone_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM records WHERE domain_id = zone_id AND type = 'A' AND name = 'test-disabled.' || zone_name
    ) THEN
        -- A records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'A', '192.0.2.101', 3600, 0, false),
        (zone_id, 'www.' || zone_name, 'A', '192.0.2.101', 3600, 0, false),
        (zone_id, 'mail.' || zone_name, 'A', '192.0.2.110', 3600, 0, false),
        (zone_id, 'mail2.' || zone_name, 'A', '192.0.2.111', 3600, 0, false),
        (zone_id, 'ftp.' || zone_name, 'A', '192.0.2.120', 3600, 0, false),
        (zone_id, 'blog.' || zone_name, 'A', '192.0.2.130', 3600, 0, false),
        (zone_id, 'shop.' || zone_name, 'A', '192.0.2.140', 3600, 0, false),
        (zone_id, 'api.' || zone_name, 'A', '192.0.2.150', 3600, 0, false);

        -- AAAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'AAAA', '2001:db8:1::1', 3600, 0, false),
        (zone_id, 'www.' || zone_name, 'AAAA', '2001:db8:1::1', 3600, 0, false),
        (zone_id, 'mail.' || zone_name, 'AAAA', '2001:db8:1::10', 3600, 0, false),
        (zone_id, 'ipv6only.' || zone_name, 'AAAA', '2001:db8:1::100', 3600, 0, false);

        -- MX records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10, false),
        (zone_id, zone_name, 'MX', 'mail2.' || zone_name, 3600, 20, false),
        (zone_id, zone_name, 'MX', 'backup-mx.example.net.', 3600, 30, false);

        -- TXT records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'TXT', 'v=spf1 ip4:192.0.2.0/24 ip6:2001:db8:1::/48 include:_spf.google.com ~all', 3600, 0, false),
        (zone_id, '_dmarc.' || zone_name, 'TXT', 'v=DMARC1; p=reject; rua=mailto:dmarc@example.com; ruf=mailto:dmarc-forensics@example.com; fo=1', 3600, 0, false),
        (zone_id, 'default._domainkey.' || zone_name, 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3QEKyU1fSma0axspqYK5iAj+54lsAg4qRRCnpKK68hawSI8zvKBSjzQAHNxfh3UDPz', 3600, 0, false),
        (zone_id, zone_name, 'TXT', 'google-site-verification=abcdefghijklmnopqrstuvwxyz', 3600, 0, false),
        (zone_id, zone_name, 'TXT', 'ms=ms12345678', 3600, 0, false);

        -- CNAME records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, 'cdn.' || zone_name, 'CNAME', 'd1234567890.cloudfront.net.', 3600, 0, false),
        (zone_id, 'docs.' || zone_name, 'CNAME', 'client-docs.readthedocs.io.', 3600, 0, false),
        (zone_id, 'webmail.' || zone_name, 'CNAME', 'mail.' || zone_name, 3600, 0, false),
        (zone_id, 'autodiscover.' || zone_name, 'CNAME', 'autodiscover.outlook.com.', 3600, 0, false);

        -- SRV records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, '_xmpp-server._tcp.' || zone_name, 'SRV', '0 5269 xmpp.' || zone_name, 3600, 5, false),
        (zone_id, '_xmpp-client._tcp.' || zone_name, 'SRV', '0 5222 xmpp.' || zone_name, 3600, 5, false),
        (zone_id, '_sip._tcp.' || zone_name, 'SRV', '0 5060 sip.' || zone_name, 3600, 10, false),
        (zone_id, '_imaps._tcp.' || zone_name, 'SRV', '0 993 mail.' || zone_name, 3600, 10, false);

        -- CAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'CAA', '0 issue "digicert.com"', 3600, 0, false),
        (zone_id, zone_name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
        (zone_id, zone_name, 'CAA', '0 iodef "mailto:admin@example.com"', 3600, 0, false);

        -- Disabled records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, 'test-disabled.' || zone_name, 'A', '192.0.2.199', 3600, 0, true),
        (zone_id, 'old-api.' || zone_name, 'A', '192.0.2.198', 3600, 0, true);
    END IF;
END $$;

-- =============================================================================
-- REVERSE ZONE RECORDS (PTR)
-- =============================================================================

DO $$
DECLARE
    zone_name TEXT := '2.0.192.in-addr.arpa';
    zone_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name;

    IF zone_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM records WHERE domain_id = zone_id AND type = 'PTR'
    ) THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
        (zone_id, '1.' || zone_name, 'PTR', 'server1.example.com.', 3600, 0),
        (zone_id, '10.' || zone_name, 'PTR', 'mail.manager-zone.example.com.', 3600, 0),
        (zone_id, '11.' || zone_name, 'PTR', 'mail2.manager-zone.example.com.', 3600, 0),
        (zone_id, '20.' || zone_name, 'PTR', 'ftp.manager-zone.example.com.', 3600, 0),
        (zone_id, '30.' || zone_name, 'PTR', 'blog.manager-zone.example.com.', 3600, 0),
        (zone_id, '40.' || zone_name, 'PTR', 'shop.manager-zone.example.com.', 3600, 0),
        (zone_id, '50.' || zone_name, 'PTR', 'api.manager-zone.example.com.', 3600, 0),
        (zone_id, '101.' || zone_name, 'PTR', 'server2.example.com.', 3600, 0),
        (zone_id, '110.' || zone_name, 'PTR', 'mail.client-zone.example.com.', 3600, 0);
    END IF;
END $$;

-- =============================================================================
-- SHARED ZONE RECORDS
-- =============================================================================

DO $$
DECLARE
    zone_name TEXT := 'shared-zone.example.com';
    zone_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name;

    IF zone_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM records WHERE domain_id = zone_id AND type = 'A' AND name = zone_name
    ) THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
        (zone_id, zone_name, 'A', '192.0.2.200', 3600, 0, false),
        (zone_id, 'www.' || zone_name, 'A', '192.0.2.200', 3600, 0, false),
        (zone_id, 'api.' || zone_name, 'A', '192.0.2.201', 3600, 0, false),
        (zone_id, 'mail.' || zone_name, 'A', '192.0.2.210', 3600, 0, false),
        (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10, false),
        (zone_id, zone_name, 'TXT', 'v=spf1 mx ~all', 3600, 0, false),
        (zone_id, zone_name, 'AAAA', '2001:db8:2::1', 3600, 0, false);
    END IF;
END $$;

-- =============================================================================
-- IDN ZONE RECORDS
-- =============================================================================

DO $$
DECLARE
    zone_name TEXT := 'xn--verstt-eua3l.info';
    zone_id INTEGER;
BEGIN
    SELECT id INTO zone_id FROM domains WHERE name = zone_name;

    IF zone_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM records WHERE domain_id = zone_id AND type = 'A' AND name = zone_name
    ) THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES
        (zone_id, zone_name, 'A', '192.0.2.220', 3600, 0),
        (zone_id, 'www.' || zone_name, 'A', '192.0.2.220', 3600, 0),
        (zone_id, zone_name, 'MX', 'mail.' || zone_name, 3600, 10),
        (zone_id, 'mail.' || zone_name, 'A', '192.0.2.221', 3600, 0);
    END IF;
END $$;

-- Update sequence
SELECT setval('records_id_seq', (SELECT COALESCE(MAX(id), 1) FROM records));

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
    SUM(CASE WHEN r.disabled = true THEN 1 ELSE 0 END) AS disabled
FROM domains d
LEFT JOIN records r ON d.id = r.domain_id
GROUP BY d.name
ORDER BY d.name;
