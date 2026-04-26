-- Poweradmin schema update to 4.5.0
-- Add log_record_changes table to capture structured before/after snapshots
-- of record/zone mutations, enabling the diff-style change-log UI and email
-- digest reports. The existing log_zones activity feed is unchanged.

CREATE TABLE IF NOT EXISTS `log_record_changes` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `zone_id` int(11) DEFAULT NULL,
    `record_id` int(11) DEFAULT NULL,
    `action` varchar(32) NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `username` varchar(64) NOT NULL,
    `before_state` varchar(8192) DEFAULT NULL,
    `after_state` varchar(8192) DEFAULT NULL,
    `client_ip` varchar(64) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_log_record_changes_created_at` (`created_at`),
    KEY `idx_log_record_changes_zone_id` (`zone_id`),
    KEY `idx_log_record_changes_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
