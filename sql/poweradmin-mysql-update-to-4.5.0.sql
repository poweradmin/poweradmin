-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE TABLE IF NOT EXISTS `log_record_changes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zone_id` int(11) DEFAULT NULL,
    `record_id` text DEFAULT NULL,
    `action` varchar(32) NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `username` varchar(64) NOT NULL,
    `before_state` text DEFAULT NULL,
    `after_state` text DEFAULT NULL,
    `client_ip` varchar(64) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_log_record_changes_created_at` (`created_at`),
    KEY `idx_log_record_changes_zone_id` (`zone_id`),
    KEY `idx_log_record_changes_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mapping table for API-backed zones whose record IDs are encoded strings
-- (RecordIdentifier base64url) and don't fit in records_zone_templ.record_id
-- (INT). Populated only when applying templates against PowerDNS via the API.
CREATE TABLE IF NOT EXISTS `records_zone_templ_api` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `domain_id` int(11) NOT NULL,
    `record_id` varchar(255) NOT NULL,
    `zone_templ_id` int(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_records_zone_templ_api_domain_id` (`domain_id`),
    KEY `idx_records_zone_templ_api_zone_templ_id` (`zone_templ_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
