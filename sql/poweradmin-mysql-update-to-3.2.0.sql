CREATE TABLE `log_users`
(
    `id`         int(11) NOT NULL AUTO_INCREMENT,
    `event`      varchar(2048) NOT NULL,
    `created_at` timestamp     NOT NULL DEFAULT current_timestamp(),
    `priority`   int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `log_zones`
(
    `id`         int(11) NOT NULL AUTO_INCREMENT,
    `event`      varchar(2048) NOT NULL,
    `created_at` timestamp     NOT NULL DEFAULT current_timestamp(),
    `priority`   int(11) NOT NULL,
    `zone_id`    int(11) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
