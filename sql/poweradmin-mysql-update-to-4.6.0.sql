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
    PRIMARY KEY (`id`),
    KEY `idx_zone_change_requests_zone_id` (`zone_id`),
    KEY `idx_zone_change_requests_status` (`status`),
    KEY `idx_zone_change_requests_requester_id` (`requester_id`),
    KEY `idx_zone_change_requests_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
