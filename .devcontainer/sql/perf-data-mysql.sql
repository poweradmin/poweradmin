-- MySQL/MariaDB Perf Data: large zone counts, a large zone, many users and groups
-- Purpose: Volume data for timing zone lists, search, the edit page and pickers
-- Requires: the regular test data (users admin and manager, perm template 'Zone Managers')
--
-- This script creates (sizes are substituted by import-test-data.sh --perf):
-- - __PERF_OWNED__ zones oNNNNNN.perf.test with __PERF_RECORDS__ filler records each, for
--   user perf_owner: odd zones owned directly, even zones only through 'Perf group 0001'
-- - big.perf.test with __PERF_BIG__ filler records, owned by perf_owner
-- - __PERF_OTHER__ zones xNNNNNN.perf.test (SOA and NS only), spread over the perf users
-- - __PERF_USERS__ users perf_user_NNNN and __PERF_GROUPS__ groups 'Perf group NNNN'
-- import-test-data.sh runs perf-data-clean-mysql.sql first, so it re-runs with other sizes.
-- Perf users share the password of the manager test user (Poweradmin123).
--
-- Uses the MariaDB Sequence engine (seq_1_to_N); MySQL proper has no seq tables.

USE pdns;

-- =============================================================================
-- POWERADMIN DATABASE - USERS AND GROUPS
-- =============================================================================

INSERT INTO poweradmin.`users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT 'perf_owner', m.`password`, 'Perf Owner', 'perf_owner@example.com', 'Owns the perf zones', m.`perm_templ`, 1, 0
FROM poweradmin.`users` m
WHERE m.`username` = 'manager';

INSERT INTO poweradmin.`users` (`username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`)
SELECT CONCAT('perf_user_', LPAD(s.`seq`, 4, '0')), m.`password`, CONCAT('Perf User ', LPAD(s.`seq`, 4, '0')),
       CONCAT('perf_user_', LPAD(s.`seq`, 4, '0'), '@example.com'), '', m.`perm_templ`, 1, 0
FROM seq_1_to_1000000 s
CROSS JOIN poweradmin.`users` m
WHERE s.`seq` <= __PERF_USERS__ AND m.`username` = 'manager';

-- Group 0001 always exists: it carries the group-owned half of the perf_owner zones
INSERT INTO poweradmin.`user_groups` (`name`, `description`, `perm_templ`, `created_by`)
SELECT CONCAT('Perf group ', LPAD(s.`seq`, 4, '0')), 'Perf data', t.`id`, a.`id`
FROM seq_1_to_1000000 s
CROSS JOIN poweradmin.`perm_templ` t
CROSS JOIN poweradmin.`users` a
WHERE s.`seq` <= GREATEST(__PERF_GROUPS__, 1) AND t.`name` = 'Zone Managers' AND a.`username` = 'admin';

INSERT INTO poweradmin.`user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM poweradmin.`user_groups` g
CROSS JOIN poweradmin.`users` u
WHERE g.`name` = 'Perf group 0001' AND u.`username` = 'perf_owner';

INSERT INTO poweradmin.`user_group_members` (`group_id`, `user_id`)
SELECT g.`id`, u.`id`
FROM seq_1_to_1000000 s
JOIN poweradmin.`users` u ON u.`username` = CONCAT('perf_user_', LPAD(s.`seq`, 4, '0'))
JOIN poweradmin.`user_groups` g ON g.`name` = CONCAT('Perf group ', LPAD((s.`seq` - 1) % GREATEST(__PERF_GROUPS__, 1) + 1, 4, '0'))
WHERE s.`seq` <= __PERF_USERS__;

-- =============================================================================
-- PDNS DATABASE - DOMAINS
-- =============================================================================

INSERT INTO pdns.`domains` (`name`, `type`)
SELECT CONCAT('o', LPAD(s.`seq`, 6, '0'), '.perf.test'), 'MASTER'
FROM seq_1_to_1000000 s
WHERE s.`seq` <= __PERF_OWNED__;

INSERT INTO pdns.`domains` (`name`, `type`)
SELECT CONCAT('x', LPAD(s.`seq`, 6, '0'), '.perf.test'), 'MASTER'
FROM seq_1_to_1000000 s
WHERE s.`seq` <= __PERF_OTHER__;

INSERT INTO pdns.`domains` (`name`, `type`)
SELECT 'big.perf.test', 'MASTER' FROM DUAL WHERE __PERF_BIG__ > 0;

-- =============================================================================
-- PDNS DATABASE - RECORDS
-- =============================================================================

INSERT INTO pdns.`records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`, d.`name`, 'SOA', 'ns1.perf.test. hostmaster.perf.test. 2026102701 10800 3600 604800 86400', 86400, 0
FROM pdns.`domains` d
WHERE d.`name` LIKE '%.perf.test';

INSERT INTO pdns.`records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`, d.`name`, 'NS', n.`ns`, 86400, 0
FROM pdns.`domains` d
CROSS JOIN (SELECT 'ns1.perf.test' AS `ns` UNION ALL SELECT 'ns2.perf.test') n
WHERE d.`name` LIKE '%.perf.test';

-- Filler cycles A, AAAA, TXT, CNAME and MX on unique names hN.<zone>
INSERT INTO pdns.`records` (`domain_id`, `name`, `type`, `content`, `ttl`, `prio`)
SELECT d.`id`,
       CONCAT('h', s.`seq`, '.', d.`name`),
       CASE s.`seq` % 5 WHEN 1 THEN 'A' WHEN 2 THEN 'AAAA' WHEN 3 THEN 'TXT' WHEN 4 THEN 'CNAME' ELSE 'MX' END,
       CASE s.`seq` % 5
           WHEN 1 THEN CONCAT('10.', (s.`seq` DIV 65536) % 256, '.', (s.`seq` DIV 256) % 256, '.', s.`seq` % 256)
           WHEN 2 THEN CONCAT('2001:db8::', LOWER(HEX(s.`seq` DIV 65536)), ':', LOWER(HEX(s.`seq` % 65536)))
           WHEN 3 THEN CONCAT('"perf record ', s.`seq`, '"')
           WHEN 4 THEN CONCAT('h1.', d.`name`)
           ELSE CONCAT('mail.', d.`name`)
       END,
       3600,
       CASE WHEN s.`seq` % 5 = 0 THEN 10 ELSE 0 END
FROM pdns.`domains` d
JOIN seq_1_to_1000000 s ON s.`seq` <= CASE WHEN d.`name` = 'big.perf.test' THEN __PERF_BIG__ ELSE __PERF_RECORDS__ END
WHERE d.`name` LIKE 'o%.perf.test' OR d.`name` = 'big.perf.test';

-- =============================================================================
-- POWERADMIN DATABASE - ZONE OWNERSHIP
-- =============================================================================

-- Odd perf_owner zones and the big zone are owned directly
INSERT INTO poweradmin.`zones` (`domain_id`, `owner`, `zone_templ_id`, `zone_name`, `zone_type`)
SELECT d.`id`, u.`id`, 0, d.`name`, 'MASTER'
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` u
WHERE u.`username` = 'perf_owner'
  AND (d.`name` = 'big.perf.test' OR (d.`name` LIKE 'o%.perf.test' AND CAST(SUBSTRING(d.`name`, 2, 6) AS UNSIGNED) % 2 = 1));

-- Even perf_owner zones have no direct owner, only the group
INSERT INTO poweradmin.`zones` (`domain_id`, `owner`, `zone_templ_id`, `zone_name`, `zone_type`)
SELECT d.`id`, NULL, 0, d.`name`, 'MASTER'
FROM pdns.`domains` d
WHERE d.`name` LIKE 'o%.perf.test' AND CAST(SUBSTRING(d.`name`, 2, 6) AS UNSIGNED) % 2 = 0;

INSERT INTO poweradmin.`zones_groups` (`domain_id`, `group_id`)
SELECT d.`id`, g.`id`
FROM pdns.`domains` d
CROSS JOIN poweradmin.`user_groups` g
WHERE g.`name` = 'Perf group 0001'
  AND d.`name` LIKE 'o%.perf.test' AND CAST(SUBSTRING(d.`name`, 2, 6) AS UNSIGNED) % 2 = 0;

-- Other zones round-robin over the perf users (admin when there are none)
INSERT INTO poweradmin.`zones` (`domain_id`, `owner`, `zone_templ_id`, `zone_name`, `zone_type`)
SELECT d.`id`, COALESCE(u.`id`, a.`id`), 0, d.`name`, 'MASTER'
FROM pdns.`domains` d
CROSS JOIN poweradmin.`users` a
LEFT JOIN poweradmin.`users` u
    ON u.`username` = CONCAT('perf_user_', LPAD((CAST(SUBSTRING(d.`name`, 2, 6) AS UNSIGNED) - 1) % GREATEST(__PERF_USERS__, 1) + 1, 4, '0'))
WHERE a.`username` = 'admin' AND d.`name` LIKE 'x%.perf.test';
