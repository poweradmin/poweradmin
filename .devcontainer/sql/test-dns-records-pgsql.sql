-- PostgreSQL Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-pgsql.sql
--
-- Usage: docker exec -i postgres psql -U pdns -d pdns < test-dns-records-pgsql.sql

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'manager-zone.example.com' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'manager-zone.example.com') THEN
        -- A records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'manager-zone.example.com', 'A', '192.0.2.1', 3600, 0, false),
            (zone_id_var, 'www.manager-zone.example.com', 'A', '192.0.2.1', 3600, 0, false),
            (zone_id_var, 'mail.manager-zone.example.com', 'A', '192.0.2.10', 3600, 0, false),
            (zone_id_var, 'mail2.manager-zone.example.com', 'A', '192.0.2.11', 3600, 0, false),
            (zone_id_var, 'ftp.manager-zone.example.com', 'A', '192.0.2.20', 3600, 0, false),
            (zone_id_var, 'blog.manager-zone.example.com', 'A', '192.0.2.30', 3600, 0, false),
            (zone_id_var, 'shop.manager-zone.example.com', 'A', '192.0.2.40', 3600, 0, false),
            (zone_id_var, 'api.manager-zone.example.com', 'A', '192.0.2.50', 3600, 0, false);

        -- AAAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id_var, 'www.manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, false),
            (zone_id_var, 'mail.manager-zone.example.com', 'AAAA', '2001:db8::10', 3600, 0, false),
            (zone_id_var, 'ipv6only.manager-zone.example.com', 'AAAA', '2001:db8::100', 3600, 0, false);

        -- MX records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'manager-zone.example.com', 'MX', 'mail.manager-zone.example.com', 3600, 10, false),
            (zone_id_var, 'manager-zone.example.com', 'MX', 'mail2.manager-zone.example.com', 3600, 20, false),
            (zone_id_var, 'manager-zone.example.com', 'MX', 'backup-mx.example.net.', 3600, 30, false);

        -- TXT records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'manager-zone.example.com', 'TXT', '"v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all"', 3600, 0, false),
            (zone_id_var, '_dmarc.manager-zone.example.com', 'TXT', '"v=DMARC1; p=quarantine; sp=reject; rua=mailto:dmarc@example.com"', 3600, 0, false),
            (zone_id_var, 'default._domainkey.manager-zone.example.com', 'TXT', '"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3..."', 3600, 0, false);

        -- CNAME records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'cdn.manager-zone.example.com', 'CNAME', 'cdn.cloudflare.net.', 3600, 0, false),
            (zone_id_var, 'docs.manager-zone.example.com', 'CNAME', 'documentation.manager-zone.example.com', 3600, 0, false),
            (zone_id_var, 'webmail.manager-zone.example.com', 'CNAME', 'mail.manager-zone.example.com', 3600, 0, false);

        -- SRV records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, '_xmpp-server._tcp.manager-zone.example.com', 'SRV', '0 5269 xmpp.manager-zone.example.com', 3600, 5, false),
            (zone_id_var, '_xmpp-client._tcp.manager-zone.example.com', 'SRV', '0 5222 xmpp.manager-zone.example.com', 3600, 5, false),
            (zone_id_var, '_sip._tcp.manager-zone.example.com', 'SRV', '0 5060 sip.manager-zone.example.com', 3600, 10, false);

        -- CAA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'manager-zone.example.com', 'CAA', '0 issue "letsencrypt.org"', 3600, 0, false),
            (zone_id_var, 'manager-zone.example.com', 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, false),
            (zone_id_var, 'manager-zone.example.com', 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, false);

        -- SSHFP records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'ssh.manager-zone.example.com', 'SSHFP', '1 1 123456789abcdef67890123456789abcdef012345', 3600, 0, false);

        -- TLSA records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, '_443._tcp.www.manager-zone.example.com', 'TLSA', '3 1 1 0123456789abcdef0123456789abcdef', 3600, 0, false);

        -- Disabled records
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'test-disabled.manager-zone.example.com', 'A', '192.0.2.99', 3600, 0, true),
            (zone_id_var, 'old-server.manager-zone.example.com', 'A', '192.0.2.98', 3600, 0, true);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR client-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'client-zone.example.com' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'client-zone.example.com') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'client-zone.example.com', 'A', '192.0.2.100', 3600, 0, false),
            (zone_id_var, 'www.client-zone.example.com', 'A', '192.0.2.100', 3600, 0, false),
            (zone_id_var, 'mail.client-zone.example.com', 'A', '192.0.2.110', 3600, 0, false),
            (zone_id_var, 'client-zone.example.com', 'MX', 'mail.client-zone.example.com', 3600, 10, false),
            (zone_id_var, 'client-zone.example.com', 'TXT', '"v=spf1 mx -all"', 3600, 0, false);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR admin-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'admin-zone.example.com' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'admin-zone.example.com') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'admin-zone.example.com', 'A', '192.0.2.200', 3600, 0, false),
            (zone_id_var, 'www.admin-zone.example.com', 'A', '192.0.2.200', 3600, 0, false),
            (zone_id_var, 'ns1.admin-zone.example.com', 'A', '192.0.2.201', 3600, 0, false),
            (zone_id_var, 'ns2.admin-zone.example.com', 'A', '192.0.2.202', 3600, 0, false),
            (zone_id_var, 'mail.admin-zone.example.com', 'A', '192.0.2.210', 3600, 0, false),
            (zone_id_var, 'admin-zone.example.com', 'AAAA', '2001:db8:200::1', 3600, 0, false),
            (zone_id_var, 'admin-zone.example.com', 'MX', 'mail.admin-zone.example.com', 3600, 10, false),
            (zone_id_var, 'admin-zone.example.com', 'TXT', '"v=spf1 mx ip4:192.0.2.0/24 -all"', 3600, 0, false);
    END IF;
END $$;

-- =============================================================================
-- PTR RECORDS FOR REVERSE ZONES
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = '2.0.192.in-addr.arpa' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'PTR') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, '1.2.0.192.in-addr.arpa', 'PTR', 'manager-zone.example.com.', 3600, 0, false),
            (zone_id_var, '10.2.0.192.in-addr.arpa', 'PTR', 'mail.manager-zone.example.com.', 3600, 0, false),
            (zone_id_var, '100.2.0.192.in-addr.arpa', 'PTR', 'client-zone.example.com.', 3600, 0, false),
            (zone_id_var, '200.2.0.192.in-addr.arpa', 'PTR', 'admin-zone.example.com.', 3600, 0, false);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR shared-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'shared-zone.example.com' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'shared-zone.example.com') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'shared-zone.example.com', 'A', '192.0.2.50', 3600, 0, false),
            (zone_id_var, 'www.shared-zone.example.com', 'A', '192.0.2.50', 3600, 0, false),
            (zone_id_var, 'manager.shared-zone.example.com', 'A', '192.0.2.51', 3600, 0, false),
            (zone_id_var, 'client.shared-zone.example.com', 'A', '192.0.2.52', 3600, 0, false),
            (zone_id_var, 'mail.shared-zone.example.com', 'A', '192.0.2.55', 3600, 0, false),
            (zone_id_var, 'shared-zone.example.com', 'MX', 'mail.shared-zone.example.com', 3600, 10, false);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR viewer-zone.example.com
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'viewer-zone.example.com' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'viewer-zone.example.com') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'viewer-zone.example.com', 'A', '192.0.2.60', 3600, 0, false),
            (zone_id_var, 'www.viewer-zone.example.com', 'A', '192.0.2.60', 3600, 0, false);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR native-zone.example.org
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'native-zone.example.org' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'native-zone.example.org') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'native-zone.example.org', 'A', '192.0.2.70', 3600, 0, false),
            (zone_id_var, 'www.native-zone.example.org', 'A', '192.0.2.70', 3600, 0, false),
            (zone_id_var, 'api.native-zone.example.org', 'A', '192.0.2.71', 3600, 0, false),
            (zone_id_var, 'native-zone.example.org', 'AAAA', '2001:db8:70::1', 3600, 0, false),
            (zone_id_var, 'mail.native-zone.example.org', 'A', '192.0.2.75', 3600, 0, false),
            (zone_id_var, 'native-zone.example.org', 'MX', 'mail.native-zone.example.org', 3600, 10, false);
    END IF;
END $$;

-- =============================================================================
-- RECORDS FOR xn--verstt-eua3l.info (IDN)
-- =============================================================================

DO $$
DECLARE
    zone_id_var INTEGER;
BEGIN
    SELECT id INTO zone_id_var FROM domains WHERE name = 'xn--verstt-eua3l.info' LIMIT 1;

    IF zone_id_var IS NOT NULL AND NOT EXISTS (SELECT 1 FROM records WHERE domain_id = zone_id_var AND type = 'A' AND name = 'xn--verstt-eua3l.info') THEN
        INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
            (zone_id_var, 'xn--verstt-eua3l.info', 'A', '192.0.2.150', 3600, 0, false),
            (zone_id_var, 'www.xn--verstt-eua3l.info', 'A', '192.0.2.150', 3600, 0, false),
            (zone_id_var, 'xn--verstt-eua3l.info', 'AAAA', '2001:db8:150::1', 3600, 0, false),
            (zone_id_var, 'mail.xn--verstt-eua3l.info', 'A', '192.0.2.151', 3600, 0, false),
            (zone_id_var, 'xn--verstt-eua3l.info', 'MX', 'mail.xn--verstt-eua3l.info', 3600, 10, false);
    END IF;
END $$;

-- Update sequences
SELECT setval('records_id_seq', COALESCE((SELECT MAX(id) FROM records), 1));
