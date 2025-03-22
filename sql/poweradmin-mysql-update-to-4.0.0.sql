CREATE TABLE `login_attempts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NULL,
    `ip_address` varchar(45) NOT NULL,
    `timestamp` int(11) NOT NULL,
    `successful` tinyint(1) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_timestamp` (`timestamp`),
    CONSTRAINT `fk_login_attempts_users`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
