-- SQLite Perf Data: large zone counts, a large zone, many users and groups
-- Purpose: Volume data for timing zone lists, search, the edit page and pickers
-- Requires: the regular test data (users admin and manager, perm template 'Zone Managers')
--
-- This script creates (sizes are substituted by import-test-data.sh --perf):
-- - __PERF_OWNED__ zones oNNNNNN.perf.test with __PERF_RECORDS__ filler records each, for
--   user perf_owner: odd zones owned directly, even zones only through 'Perf group 0001'
-- - big.perf.test with __PERF_BIG__ filler records, owned by perf_owner
-- - __PERF_OTHER__ zones xNNNNNN.perf.test (SOA and NS only), spread over the perf users
-- - __PERF_USERS__ users perf_user_NNNN and __PERF_GROUPS__ groups 'Perf group NNNN'
-- import-test-data.sh runs perf-data-clean-sqlite.sql first, so it re-runs with other sizes.
-- Perf users share the password of the manager test user (Poweradmin123).
--
-- Poweradmin and PowerDNS tables share one file here, so no table is qualified.

BEGIN;

-- An indexed number table: joining a recursive CTE by range scans it once per zone
CREATE TEMP TABLE perf_seq (n INTEGER PRIMARY KEY);
INSERT INTO perf_seq (n)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq
    WHERE n < MAX(__PERF_OWNED__, __PERF_RECORDS__, __PERF_BIG__, __PERF_OTHER__, __PERF_USERS__, __PERF_GROUPS__, 1))
SELECT n FROM seq;

-- =============================================================================
-- USERS AND GROUPS
-- =============================================================================

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'perf_owner', m.password, 'Perf Owner', 'perf_owner@example.com', 'Owns the perf zones', m.perm_templ, 1, 0
FROM users m
WHERE m.username = 'manager';

INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap)
SELECT 'perf_user_' || printf('%04d', s.n), m.password, 'Perf User ' || printf('%04d', s.n),
       'perf_user_' || printf('%04d', s.n) || '@example.com', '', m.perm_templ, 1, 0
FROM perf_seq s
CROSS JOIN users m
WHERE s.n <= __PERF_USERS__ AND m.username = 'manager';

-- Group 0001 always exists: it carries the group-owned half of the perf_owner zones
INSERT INTO user_groups (name, description, perm_templ, created_by)
SELECT 'Perf group ' || printf('%04d', s.n), 'Perf data', t.id, a.id
FROM perf_seq s
CROSS JOIN perm_templ t
CROSS JOIN users a
WHERE s.n <= MAX(__PERF_GROUPS__, 1) AND t.name = 'Zone Managers' AND a.username = 'admin';

INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM user_groups g
CROSS JOIN users u
WHERE g.name = 'Perf group 0001' AND u.username = 'perf_owner';

INSERT INTO user_group_members (group_id, user_id)
SELECT g.id, u.id
FROM perf_seq s
JOIN users u ON u.username = 'perf_user_' || printf('%04d', s.n)
JOIN user_groups g ON g.name = 'Perf group ' || printf('%04d', (s.n - 1) % MAX(__PERF_GROUPS__, 1) + 1)
WHERE s.n <= __PERF_USERS__;

-- =============================================================================
-- DOMAINS
-- =============================================================================

INSERT INTO domains (name, type)
SELECT 'o' || printf('%06d', n) || '.perf.test', 'MASTER' FROM perf_seq WHERE n <= __PERF_OWNED__;

INSERT INTO domains (name, type)
SELECT 'x' || printf('%06d', n) || '.perf.test', 'MASTER' FROM perf_seq WHERE n <= __PERF_OTHER__;

INSERT INTO domains (name, type)
SELECT 'big.perf.test', 'MASTER' WHERE __PERF_BIG__ > 0;

-- =============================================================================
-- RECORDS
-- =============================================================================

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'SOA', 'ns1.perf.test. hostmaster.perf.test. 2026102701 10800 3600 604800 86400', 86400, 0
FROM domains d
WHERE d.name LIKE '%.perf.test';

INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id, d.name, 'NS', n.ns, 86400, 0
FROM domains d
CROSS JOIN (SELECT 'ns1.perf.test' AS ns UNION ALL SELECT 'ns2.perf.test') n
WHERE d.name LIKE '%.perf.test';

-- Filler cycles A, AAAA, TXT, CNAME and MX on unique names hN.<zone>
INSERT INTO records (domain_id, name, type, content, ttl, prio)
SELECT d.id,
       'h' || s.n || '.' || d.name,
       CASE s.n % 5 WHEN 1 THEN 'A' WHEN 2 THEN 'AAAA' WHEN 3 THEN 'TXT' WHEN 4 THEN 'CNAME' ELSE 'MX' END,
       CASE s.n % 5
           WHEN 1 THEN '10.' || ((s.n / 65536) % 256) || '.' || ((s.n / 256) % 256) || '.' || (s.n % 256)
           WHEN 2 THEN '2001:db8::' || printf('%x:%x', s.n / 65536, s.n % 65536)
           WHEN 3 THEN '"perf record ' || s.n || '"'
           WHEN 4 THEN 'h1.' || d.name
           ELSE 'mail.' || d.name
       END,
       3600,
       CASE WHEN s.n % 5 = 0 THEN 10 ELSE 0 END
FROM domains d
JOIN perf_seq s ON s.n <= CASE WHEN d.name = 'big.perf.test' THEN __PERF_BIG__ ELSE __PERF_RECORDS__ END
WHERE d.name LIKE 'o%.perf.test' OR d.name = 'big.perf.test';

-- =============================================================================
-- ZONE OWNERSHIP
-- =============================================================================

-- Odd perf_owner zones and the big zone are owned directly
INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type)
SELECT d.id, u.id, 0, d.name, 'MASTER'
FROM domains d
CROSS JOIN users u
WHERE u.username = 'perf_owner'
  AND (d.name = 'big.perf.test' OR (d.name LIKE 'o%.perf.test' AND CAST(substr(d.name, 2, 6) AS INTEGER) % 2 = 1));

-- Even perf_owner zones have no direct owner, only the group
INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type)
SELECT d.id, NULL, 0, d.name, 'MASTER'
FROM domains d
WHERE d.name LIKE 'o%.perf.test' AND CAST(substr(d.name, 2, 6) AS INTEGER) % 2 = 0;

INSERT INTO zones_groups (domain_id, group_id)
SELECT d.id, g.id
FROM domains d
CROSS JOIN user_groups g
WHERE g.name = 'Perf group 0001'
  AND d.name LIKE 'o%.perf.test' AND CAST(substr(d.name, 2, 6) AS INTEGER) % 2 = 0;

-- Other zones round-robin over the perf users (admin when there are none)
INSERT INTO zones (domain_id, owner, zone_templ_id, zone_name, zone_type)
SELECT d.id, COALESCE(u.id, a.id), 0, d.name, 'MASTER'
FROM domains d
CROSS JOIN users a
LEFT JOIN users u
    ON u.username = 'perf_user_' || printf('%04d', (CAST(substr(d.name, 2, 6) AS INTEGER) - 1) % MAX(__PERF_USERS__, 1) + 1)
WHERE a.username = 'admin' AND d.name LIKE 'x%.perf.test';

COMMIT;

ANALYZE;
