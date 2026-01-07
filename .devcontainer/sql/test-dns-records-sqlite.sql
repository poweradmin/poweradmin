-- SQLite Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: Uses existing test zones from test-users-permissions-sqlite.sql
--
-- Usage: docker exec -i sqlite sqlite3 /data/pdns.db < test-dns-records-sqlite.sql

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com (domain_id=2)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    -- A records
    (2, 'manager-zone.example.com', 'A', '192.0.2.1', 3600, 0, 0),
    (2, 'www.manager-zone.example.com', 'A', '192.0.2.1', 3600, 0, 0),
    (2, 'mail.manager-zone.example.com', 'A', '192.0.2.10', 3600, 0, 0),
    (2, 'mail2.manager-zone.example.com', 'A', '192.0.2.11', 3600, 0, 0),
    (2, 'ftp.manager-zone.example.com', 'A', '192.0.2.20', 3600, 0, 0),
    (2, 'blog.manager-zone.example.com', 'A', '192.0.2.30', 3600, 0, 0),
    (2, 'shop.manager-zone.example.com', 'A', '192.0.2.40', 3600, 0, 0),
    (2, 'api.manager-zone.example.com', 'A', '192.0.2.50', 3600, 0, 0),

    -- AAAA records
    (2, 'manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0),
    (2, 'www.manager-zone.example.com', 'AAAA', '2001:db8::1', 3600, 0, 0),
    (2, 'mail.manager-zone.example.com', 'AAAA', '2001:db8::10', 3600, 0, 0),
    (2, 'ipv6only.manager-zone.example.com', 'AAAA', '2001:db8::100', 3600, 0, 0),

    -- MX records
    (2, 'manager-zone.example.com', 'MX', 'mail.manager-zone.example.com', 3600, 10, 0),
    (2, 'manager-zone.example.com', 'MX', 'mail2.manager-zone.example.com', 3600, 20, 0),
    (2, 'manager-zone.example.com', 'MX', 'backup-mx.example.net.', 3600, 30, 0),

    -- TXT records
    (2, 'manager-zone.example.com', 'TXT', '"v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all"', 3600, 0, 0),
    (2, '_dmarc.manager-zone.example.com', 'TXT', '"v=DMARC1; p=quarantine; sp=reject; rua=mailto:dmarc@example.com"', 3600, 0, 0),
    (2, 'default._domainkey.manager-zone.example.com', 'TXT', '"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3..."', 3600, 0, 0),
    (2, '_github-challenge.manager-zone.example.com', 'TXT', '"a1b2c3d4e5f6g7h8i9j0"', 3600, 0, 0),

    -- CNAME records
    (2, 'cdn.manager-zone.example.com', 'CNAME', 'cdn.cloudflare.net.', 3600, 0, 0),
    (2, 'docs.manager-zone.example.com', 'CNAME', 'documentation.manager-zone.example.com', 3600, 0, 0),
    (2, 'webmail.manager-zone.example.com', 'CNAME', 'mail.manager-zone.example.com', 3600, 0, 0),
    (2, 'status.manager-zone.example.com', 'CNAME', 'status.example-provider.com.', 3600, 0, 0),

    -- SRV records
    (2, '_xmpp-server._tcp.manager-zone.example.com', 'SRV', '0 5269 xmpp.manager-zone.example.com', 3600, 5, 0),
    (2, '_xmpp-client._tcp.manager-zone.example.com', 'SRV', '0 5222 xmpp.manager-zone.example.com', 3600, 5, 0),
    (2, '_sip._tcp.manager-zone.example.com', 'SRV', '0 5060 sip.manager-zone.example.com', 3600, 10, 0),
    (2, '_ldap._tcp.manager-zone.example.com', 'SRV', '0 389 ldap.manager-zone.example.com', 3600, 10, 0),

    -- CAA records
    (2, 'manager-zone.example.com', 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0),
    (2, 'manager-zone.example.com', 'CAA', '0 issuewild "letsencrypt.org"', 3600, 0, 0),
    (2, 'manager-zone.example.com', 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0),

    -- SSHFP records
    (2, 'ssh.manager-zone.example.com', 'SSHFP', '1 1 123456789abcdef67890123456789abcdef012345', 3600, 0, 0),
    (2, 'ssh.manager-zone.example.com', 'SSHFP', '4 2 123456789abcdef67890123456789abcdef67890123456789abcdef67890ab', 3600, 0, 0),

    -- TLSA records
    (2, '_443._tcp.www.manager-zone.example.com', 'TLSA', '3 1 1 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 3600, 0, 0),

    -- NAPTR records
    (2, 'manager-zone.example.com', 'NAPTR', '100 10 "S" "SIP+D2U" "" _sip._udp.example.com.', 3600, 0, 0),
    (2, 'manager-zone.example.com', 'NAPTR', '100 20 "S" "SIP+D2T" "" _sip._tcp.example.com.', 3600, 0, 0),

    -- Disabled records
    (2, 'test-disabled.manager-zone.example.com', 'A', '192.0.2.99', 3600, 0, 1),
    (2, 'old-server.manager-zone.example.com', 'A', '192.0.2.98', 3600, 0, 1);

-- =============================================================================
-- RECORDS FOR client-zone.example.com (domain_id=3)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (3, 'client-zone.example.com', 'A', '192.0.2.100', 3600, 0, 0),
    (3, 'www.client-zone.example.com', 'A', '192.0.2.100', 3600, 0, 0),
    (3, 'mail.client-zone.example.com', 'A', '192.0.2.110', 3600, 0, 0),
    (3, 'client-zone.example.com', 'MX', 'mail.client-zone.example.com', 3600, 10, 0),
    (3, 'client-zone.example.com', 'TXT', '"v=spf1 mx -all"', 3600, 0, 0);

-- =============================================================================
-- RECORDS FOR admin-zone.example.com (domain_id=1)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (1, 'admin-zone.example.com', 'A', '192.0.2.200', 3600, 0, 0),
    (1, 'www.admin-zone.example.com', 'A', '192.0.2.200', 3600, 0, 0),
    (1, 'ns1.admin-zone.example.com', 'A', '192.0.2.201', 3600, 0, 0),
    (1, 'ns2.admin-zone.example.com', 'A', '192.0.2.202', 3600, 0, 0),
    (1, 'mail.admin-zone.example.com', 'A', '192.0.2.210', 3600, 0, 0),
    (1, 'admin-zone.example.com', 'AAAA', '2001:db8:200::1', 3600, 0, 0),
    (1, 'www.admin-zone.example.com', 'AAAA', '2001:db8:200::1', 3600, 0, 0),
    (1, 'admin-zone.example.com', 'MX', 'mail.admin-zone.example.com', 3600, 10, 0),
    (1, 'admin-zone.example.com', 'TXT', '"v=spf1 mx ip4:192.0.2.0/24 -all"', 3600, 0, 0);

-- =============================================================================
-- PTR RECORDS FOR REVERSE ZONES
-- =============================================================================

-- IPv4 reverse zone: 2.0.192.in-addr.arpa (domain_id=8)
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (8, '1.2.0.192.in-addr.arpa', 'PTR', 'manager-zone.example.com.', 3600, 0, 0),
    (8, '10.2.0.192.in-addr.arpa', 'PTR', 'mail.manager-zone.example.com.', 3600, 0, 0),
    (8, '11.2.0.192.in-addr.arpa', 'PTR', 'mail2.manager-zone.example.com.', 3600, 0, 0),
    (8, '100.2.0.192.in-addr.arpa', 'PTR', 'client-zone.example.com.', 3600, 0, 0),
    (8, '200.2.0.192.in-addr.arpa', 'PTR', 'admin-zone.example.com.', 3600, 0, 0);

-- IPv6 reverse zone: 8.b.d.0.1.0.0.2.ip6.arpa (domain_id=9)
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (9, '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', 'PTR', 'manager-zone.example.com.', 3600, 0, 0);

-- =============================================================================
-- RECORDS FOR shared-zone.example.com (domain_id=4)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (4, 'shared-zone.example.com', 'A', '192.0.2.50', 3600, 0, 0),
    (4, 'www.shared-zone.example.com', 'A', '192.0.2.50', 3600, 0, 0),
    (4, 'manager.shared-zone.example.com', 'A', '192.0.2.51', 3600, 0, 0),
    (4, 'client.shared-zone.example.com', 'A', '192.0.2.52', 3600, 0, 0),
    (4, 'mail.shared-zone.example.com', 'A', '192.0.2.55', 3600, 0, 0),
    (4, 'shared-zone.example.com', 'MX', 'mail.shared-zone.example.com', 3600, 10, 0);

-- =============================================================================
-- RECORDS FOR viewer-zone.example.com (domain_id=5)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (5, 'viewer-zone.example.com', 'A', '192.0.2.60', 3600, 0, 0),
    (5, 'www.viewer-zone.example.com', 'A', '192.0.2.60', 3600, 0, 0);

-- =============================================================================
-- RECORDS FOR native-zone.example.org (domain_id=6)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (6, 'native-zone.example.org', 'A', '192.0.2.70', 3600, 0, 0),
    (6, 'www.native-zone.example.org', 'A', '192.0.2.70', 3600, 0, 0),
    (6, 'api.native-zone.example.org', 'A', '192.0.2.71', 3600, 0, 0),
    (6, 'native-zone.example.org', 'AAAA', '2001:db8:70::1', 3600, 0, 0),
    (6, 'mail.native-zone.example.org', 'A', '192.0.2.75', 3600, 0, 0),
    (6, 'native-zone.example.org', 'MX', 'mail.native-zone.example.org', 3600, 10, 0);

-- =============================================================================
-- RECORDS FOR xn--verstt-eua3l.info (IDN) (domain_id=10)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled) VALUES
    (10, 'xn--verstt-eua3l.info', 'A', '192.0.2.150', 3600, 0, 0),
    (10, 'www.xn--verstt-eua3l.info', 'A', '192.0.2.150', 3600, 0, 0),
    (10, 'xn--verstt-eua3l.info', 'AAAA', '2001:db8:150::1', 3600, 0, 0),
    (10, 'mail.xn--verstt-eua3l.info', 'A', '192.0.2.151', 3600, 0, 0),
    (10, 'xn--verstt-eua3l.info', 'MX', 'mail.xn--verstt-eua3l.info', 3600, 10, 0);
