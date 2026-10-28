-- Poweradmin schema update to 4.6.0
-- Add the zone_change_requests table and the change approval permissions for
-- the opt-in change approval workflow (approval.enabled). Requests carry a JSON
-- action list that is replayed against the zone once a reviewer approves it.

CREATE TABLE IF NOT EXISTS `zone_change_requests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zone_id` int(11) NOT NULL,
    `zone_name` varchar(255) NOT NULL,
    `kind` varchar(16) NOT NULL,
    `status` varchar(16) NOT NULL,
    `requester_id` int(11) DEFAULT NULL,
    `requester_name` varchar(64) NOT NULL,
    `request_comment` text DEFAULT NULL,
    `base_serial` varchar(32) DEFAULT NULL,
    `payload` text NOT NULL,
    `reviewer_id` int(11) DEFAULT NULL,
    `reviewer_name` varchar(64) DEFAULT NULL,
    `review_comment` text DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `reviewed_at` timestamp NULL DEFAULT NULL,
    `applied_at` timestamp NULL DEFAULT NULL,
    `error` text DEFAULT NULL,
    `snapshot` text DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_zone_change_requests_zone_id` (`zone_id`),
    KEY `idx_zone_change_requests_status` (`status`),
    KEY `idx_zone_change_requests_requester_id` (`requester_id`),
    KEY `idx_zone_change_requests_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Change approval permissions. No template is granted them automatically;
-- admins opt in through the permission template editor.
INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_change_request_own', 'User is allowed to request changes to zones they own'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_change_request_own');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_change_request_others', 'User is allowed to request changes to any zone'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_change_request_others');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_change_approve_own', 'User is allowed to review change requests for zones they own'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_change_approve_own');

INSERT INTO `perm_items` (`name`, `descr`)
SELECT 'zone_change_approve_others', 'User is allowed to review change requests for any zone'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `perm_items` WHERE `name` = 'zone_change_approve_others');

-- Databases upgraded from 4.0.0-4.0.4 straight to 4.1.0 or later never ran the 4.0.5
-- update (records_zone_templ primary key, #906), and no update added the zones indexes.
SET @has_rzt_id = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'records_zone_templ' AND column_name = 'id'
);
SET @repair = IF(@has_rzt_id = 0,
    'ALTER TABLE `records_zone_templ` ADD COLUMN `id` int(11) NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (`id`)',
    'SELECT ''records_zone_templ.id already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

-- Databases from the 3.9.7 update or the installer already index these columns as zones_*_idx
SET @has_zones_domain_id_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'zones' AND index_name IN ('idx_zones_domain_id', 'zones_domain_id_idx')
);
SET @repair = IF(@has_zones_domain_id_index = 0,
    'CREATE INDEX `idx_zones_domain_id` ON `zones` (`domain_id`)',
    'SELECT ''zones.domain_id index already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

SET @has_zones_owner_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'zones' AND index_name IN ('idx_zones_owner', 'zones_owner_idx')
);
SET @repair = IF(@has_zones_owner_index = 0,
    'CREATE INDEX `idx_zones_owner` ON `zones` (`owner`)',
    'SELECT ''zones.owner index already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

-- Zone count limit per user and group (#72): NULL uses the dns.default_max_zones_* setting
SET @has_users_max_zones = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'max_zones'
);
SET @repair = IF(@has_users_max_zones = 0,
    'ALTER TABLE `users` ADD COLUMN `max_zones` int(11) DEFAULT NULL',
    'SELECT ''users.max_zones already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

SET @has_groups_max_zones = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_groups' AND column_name = 'max_zones'
);
SET @repair = IF(@has_groups_max_zones = 0,
    'ALTER TABLE `user_groups` ADD COLUMN `max_zones` INT DEFAULT NULL',
    'SELECT ''user_groups.max_zones already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

-- An encoded API record id holds the zone name twice plus the content, so an SOA
-- in a long zone outgrew varchar(255) and zone creation from a template failed
ALTER TABLE `records_zone_templ_api` MODIFY `record_id` text NOT NULL;

-- Usernames are unique (a rename could collide with another account). Older data may
-- already hold a name twice; then the index is skipped with a notice. Find those rows with
--   SELECT username, COUNT(*) FROM users GROUP BY username HAVING COUNT(*) > 1;
-- rename or remove the extra accounts and run this block again.
SET @has_users_username_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_username'
);
SET @duplicate_usernames = (
    SELECT COUNT(*) FROM (SELECT `username` FROM `users` GROUP BY `username` HAVING COUNT(*) > 1) AS dup
);
SET @repair = IF(@has_users_username_index > 0,
    'SELECT ''idx_users_username already present'' AS notice',
    IF(@duplicate_usernames > 0,
        'SELECT ''idx_users_username NOT created: users holds duplicate usernames, resolve them and re-run'' AS notice',
        'CREATE UNIQUE INDEX `idx_users_username` ON `users` (`username`)'));
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

-- The log pages sort and filter by created_at; installer databases name these *_created_at_idx
SET @has_log_users_created_at_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'log_users' AND index_name IN ('idx_log_users_created_at', 'log_users_created_at_idx')
);
SET @repair = IF(@has_log_users_created_at_index = 0,
    'CREATE INDEX `idx_log_users_created_at` ON `log_users` (`created_at`)',
    'SELECT ''log_users.created_at index already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;
SET @has_log_zones_created_at_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'log_zones' AND index_name IN ('idx_log_zones_created_at', 'log_zones_created_at_idx')
);
SET @repair = IF(@has_log_zones_created_at_index = 0,
    'CREATE INDEX `idx_log_zones_created_at` ON `log_zones` (`created_at`)',
    'SELECT ''log_zones.created_at index already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;
SET @has_log_groups_created_at_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'log_groups' AND index_name IN ('idx_log_groups_created_at', 'log_groups_created_at_idx')
);
SET @repair = IF(@has_log_groups_created_at_index = 0,
    'CREATE INDEX `idx_log_groups_created_at` ON `log_groups` (`created_at`)',
    'SELECT ''log_groups.created_at index already present'' AS notice');
PREPARE repair FROM @repair;
EXECUTE repair;
DEALLOCATE PREPARE repair;

-- Name zones left unnamed by older versions (#1648). The API backend finds zones only by
-- zones.zone_name, so an unnamed zone would come back after a switch as a new, ownerless zone.
-- Same rows and values as the zones_without_names consistency fix:
--  - a zone with zones rows but no named row gets its lowest-id row named after its domain;
--  - a zone with grants or API key restrictions but no zones row gets an ownerless named row.
-- A zone that has a named row is left alone, and so is one whose name another zones row holds.
-- Type and master are left to the API sync. Safe to re-run. Skipped when the domains table is
-- not in @pdns_db (API backend); edit here when pdns_db_name is set, e.g. SET @pdns_db = 'pdns';
SET @pdns_db = DATABASE();

SET @has_domains = (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = @pdns_db AND table_name = 'domains'
);

-- Names are compared in the zones column's charset and collation, so a domains table
-- with another collation cannot raise "Illegal mix of collations" or miss a held name.
SET @zn_charset = (SELECT character_set_name FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'zones' AND column_name = 'zone_name');
SET @zn_collation = (SELECT collation_name FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'zones' AND column_name = 'zone_name');
SET @zn_name = CONCAT('CONVERT(d.name USING ', @zn_charset, ') COLLATE ', @zn_collation);

SET @name_rows = IF(@has_domains > 0, CONCAT(
    'UPDATE `zones` z ',
    'INNER JOIN (SELECT domain_id, MIN(id) AS min_id, COUNT(zone_name) AS named FROM `zones` GROUP BY domain_id) m ',
    '    ON m.domain_id = z.domain_id AND m.min_id = z.id AND m.named = 0 ',
    'INNER JOIN `', @pdns_db, '`.`domains` d ON d.id = z.domain_id ',
    'LEFT JOIN `zones` o ON o.zone_name = ', @zn_name, ' ',
    'SET z.zone_name = ', @zn_name, ' ',
    'WHERE z.zone_name IS NULL AND o.id IS NULL'),
    'SELECT ''PowerDNS domains table not found, zone name backfill skipped'' AS notice');
PREPARE name_rows FROM @name_rows;
EXECUTE name_rows;
DEALLOCATE PREPARE name_rows;

SET @add_rows = IF(@has_domains > 0, CONCAT(
    'INSERT INTO `zones` (`domain_id`, `owner`, `zone_templ_id`, `zone_name`) ',
    'SELECT d.id, NULL, 0, d.name FROM `', @pdns_db, '`.`domains` d ',
    'WHERE NOT EXISTS (SELECT 1 FROM `zones` z WHERE z.domain_id = d.id) ',
    '  AND NOT EXISTS (SELECT 1 FROM `zones` o WHERE o.zone_name = ', @zn_name, ') ',
    '  AND (EXISTS (SELECT 1 FROM `zones_groups` g WHERE g.domain_id = d.id) ',
    '       OR EXISTS (SELECT 1 FROM `api_key_zones` k WHERE k.zone_id = d.id))'),
    'SELECT ''PowerDNS domains table not found, ownerless zone rows skipped'' AS notice');
PREPARE add_rows FROM @add_rows;
EXECUTE add_rows;
DEALLOCATE PREPARE add_rows;
