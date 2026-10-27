-- MySQL/MariaDB Perf Data Cleanup: removes everything perf-data-mysql.sql creates
-- Purpose: Runs before each perf seed, and alone (import-test-data.sh --perf-clean)
--          to give the shared devcontainer databases back their regular data
-- Matches by name only: zones *.perf.test, users perf_*, groups 'Perf group *'

USE pdns;

DELETE FROM poweradmin.`zones_groups` WHERE `domain_id` IN (SELECT `id` FROM pdns.`domains` WHERE `name` LIKE '%.perf.test');
DELETE FROM poweradmin.`zones` WHERE `zone_name` LIKE '%.perf.test';
DELETE FROM pdns.`records` WHERE `domain_id` IN (SELECT `id` FROM pdns.`domains` WHERE `name` LIKE '%.perf.test');
DELETE FROM pdns.`comments` WHERE `domain_id` IN (SELECT `id` FROM pdns.`domains` WHERE `name` LIKE '%.perf.test');
DELETE FROM pdns.`domainmetadata` WHERE `domain_id` IN (SELECT `id` FROM pdns.`domains` WHERE `name` LIKE '%.perf.test');
DELETE FROM pdns.`cryptokeys` WHERE `domain_id` IN (SELECT `id` FROM pdns.`domains` WHERE `name` LIKE '%.perf.test');
DELETE FROM pdns.`domains` WHERE `name` LIKE '%.perf.test';
DELETE FROM poweradmin.`user_groups` WHERE `name` LIKE 'Perf group %';
DELETE FROM poweradmin.`api_keys` WHERE `created_by` IN (SELECT `id` FROM poweradmin.`users` WHERE `username` LIKE 'perf\_%');
DELETE FROM poweradmin.`users` WHERE `username` LIKE 'perf\_%';
