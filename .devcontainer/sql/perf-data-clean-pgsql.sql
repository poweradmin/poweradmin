-- PostgreSQL Perf Data Cleanup: removes everything perf-data-pgsql.sql creates
-- Purpose: Runs before each perf seed, and alone (import-test-data.sh --perf-clean)
--          to give the shared devcontainer databases back their regular data
-- Matches by name only: zones *.perf.test, users perf_*, groups 'Perf group *'

DELETE FROM zones_groups WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE '%.perf.test');
DELETE FROM zones WHERE zone_name LIKE '%.perf.test';
DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE '%.perf.test');
DELETE FROM comments WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE '%.perf.test');
DELETE FROM domainmetadata WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE '%.perf.test');
DELETE FROM cryptokeys WHERE domain_id IN (SELECT id FROM domains WHERE name LIKE '%.perf.test');
DELETE FROM domains WHERE name LIKE '%.perf.test';
DELETE FROM user_groups WHERE name LIKE 'Perf group %';
DELETE FROM api_keys WHERE created_by IN (SELECT id FROM users WHERE username LIKE 'perf\_%');
DELETE FROM users WHERE username LIKE 'perf\_%';
