-- SQLite Test Data: Comprehensive DNS Records for UI Testing
-- Purpose: Create diverse DNS record types for testing zone management UI
-- Database: PowerDNS SQLite database
--
-- Usage: sqlite3 /path/to/powerdns.db < test-dns-records-sqlite.sql

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR manager-zone.example.com
-- =============================================================================

-- A records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'A', '192.0.2.1', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.1', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail.' || d.name, 'A', '192.0.2.10', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail2.' || d.name, 'A', '192.0.2.11', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'ftp.' || d.name, 'A', '192.0.2.20', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'blog.' || d.name, 'A', '192.0.2.30', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'shop.' || d.name, 'A', '192.0.2.40', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'api.' || d.name, 'A', '192.0.2.50', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- AAAA records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'AAAA', '2001:db8::1', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'AAAA', '2001:db8::1', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail.' || d.name, 'AAAA', '2001:db8::10', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'ipv6only.' || d.name, 'AAAA', '2001:db8::100', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- MX records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'mail.' || d.name, 3600, 10, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'mail2.' || d.name, 3600, 20, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'backup-mx.example.net.', 3600, 30, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- TXT records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'TXT', '"v=spf1 ip4:192.0.2.0/24 ip6:2001:db8::/32 include:_spf.google.com ~all"', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '_dmarc.' || d.name, 'TXT', '"v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com; fo=1"', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'default._domainkey.' || d.name, 'TXT', '"v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC..."', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'TXT', '"google-site-verification=1234567890abcdefghijklmnop"', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- CNAME records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'cdn.' || d.name, 'CNAME', 'cdn.cloudflare.net.', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'docs.' || d.name, 'CNAME', 'documentation.' || d.name, 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'webmail.' || d.name, 'CNAME', 'mail.' || d.name, 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- SRV records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '_xmpp-server._tcp.' || d.name, 'SRV', '0 5269 xmpp.' || d.name, 3600, 5, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '_sip._tcp.' || d.name, 'SRV', '0 5060 sip.' || d.name, 3600, 10, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- CAA records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'CAA', '0 iodef "mailto:security@example.com"', 3600, 0, 0
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- Disabled records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'test-disabled.' || d.name, 'A', '192.0.2.99', 3600, 0, 1
FROM domains d WHERE d.name = 'manager-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'old-server.' || d.name, 'A', '192.0.2.98', 3600, 0, 1
FROM domains d WHERE d.name = 'manager-zone.example.com';

-- =============================================================================
-- COMPREHENSIVE DNS RECORDS FOR client-zone.example.com
-- =============================================================================

-- A records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'A', '192.0.2.101', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.101', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail.' || d.name, 'A', '192.0.2.110', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail2.' || d.name, 'A', '192.0.2.111', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'ftp.' || d.name, 'A', '192.0.2.120', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'blog.' || d.name, 'A', '192.0.2.130', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'shop.' || d.name, 'A', '192.0.2.140', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'api.' || d.name, 'A', '192.0.2.150', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- AAAA records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'AAAA', '2001:db8:1::1', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'AAAA', '2001:db8:1::1', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail.' || d.name, 'AAAA', '2001:db8:1::10', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- MX records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'mail.' || d.name, 3600, 10, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'mail2.' || d.name, 3600, 20, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- TXT records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'TXT', '"v=spf1 ip4:192.0.2.0/24 ip6:2001:db8:1::/48 ~all"', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, '_dmarc.' || d.name, 'TXT', '"v=DMARC1; p=reject; rua=mailto:dmarc@example.com"', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- CNAME records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'cdn.' || d.name, 'CNAME', 'd1234567890.cloudfront.net.', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'webmail.' || d.name, 'CNAME', 'mail.' || d.name, 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- CAA records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'CAA', '0 issue "letsencrypt.org"', 3600, 0, 0
FROM domains d WHERE d.name = 'client-zone.example.com';

-- Disabled records
INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'test-disabled.' || d.name, 'A', '192.0.2.199', 3600, 0, 1
FROM domains d WHERE d.name = 'client-zone.example.com';

-- =============================================================================
-- REVERSE ZONE RECORDS (PTR)
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '1.' || d.name, 'PTR', 'server1.example.com.', 3600, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '10.' || d.name, 'PTR', 'mail.manager-zone.example.com.', 3600, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '11.' || d.name, 'PTR', 'mail2.manager-zone.example.com.', 3600, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '101.' || d.name, 'PTR', 'server2.example.com.', 3600, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, '110.' || d.name, 'PTR', 'mail.client-zone.example.com.', 3600, 0
FROM domains d WHERE d.name = '2.0.192.in-addr.arpa';

-- =============================================================================
-- SHARED ZONE RECORDS
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'A', '192.0.2.200', 3600, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.200', 3600, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'api.' || d.name, 'A', '192.0.2.201', 3600, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, 'mail.' || d.name, 'A', '192.0.2.210', 3600, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'MX', 'mail.' || d.name, 3600, 10, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio, disabled)
SELECT d.id, d.name, 'TXT', '"v=spf1 mx ~all"', 3600, 0, 0
FROM domains d WHERE d.name = 'shared-zone.example.com';

-- =============================================================================
-- IDN ZONE RECORDS
-- =============================================================================

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'A', '192.0.2.220', 3600, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, 'www.' || d.name, 'A', '192.0.2.220', 3600, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'MX', 'mail.' || d.name, 3600, 10
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';

INSERT OR IGNORE INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, 'mail.' || d.name, 'A', '192.0.2.221', 3600, 0
FROM domains d WHERE d.name = 'xn--verstt-eua3l.info';

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
