-- Adminer 4.8.1 MySQL 5.5.5-10.9.3-MariaDB-1:10.9.3+maria~ubu2204 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

CREATE TABLE `log_users` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `event` varchar(2048) NOT NULL,
                             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                             `priority` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `log_zones` (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `event` varchar(2048) NOT NULL,
                             `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                             `priority` int(11) NOT NULL,
                             `zone_id` int(11) DEFAULT NULL,
                             PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `migrations` (
                              `version` varchar(255) NOT NULL,
                              `apply_time` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `perm_items` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(64) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_items` (`id`, `name`, `descr`) VALUES
                                                     (41,	'zone_master_add',	'User is allowed to add new master zones.'),
                                                     (42,	'zone_slave_add',	'User is allowed to add new slave zones.'),
                                                     (43,	'zone_content_view_own',	'User is allowed to see the content and meta data of zones he owns.'),
                                                     (44,	'zone_content_edit_own',	'User is allowed to edit the content of zones he owns.'),
                                                     (45,	'zone_meta_edit_own',	'User is allowed to edit the meta data of zones he owns.'),
                                                     (46,	'zone_content_view_others',	'User is allowed to see the content and meta data of zones he does not own.'),
                                                     (47,	'zone_content_edit_others',	'User is allowed to edit the content of zones he does not own.'),
                                                     (48,	'zone_meta_edit_others',	'User is allowed to edit the meta data of zones he does not own.'),
                                                     (49,	'search',	'User is allowed to perform searches.'),
                                                     (50,	'supermaster_view',	'User is allowed to view supermasters.'),
                                                     (51,	'supermaster_add',	'User is allowed to add new supermasters.'),
                                                     (52,	'supermaster_edit',	'User is allowed to edit supermasters.'),
                                                     (53,	'user_is_ueberuser',	'User has full access. God-like. Redeemer.'),
                                                     (54,	'user_view_others',	'User is allowed to see other users and their details.'),
                                                     (55,	'user_add_new',	'User is allowed to add new users.'),
                                                     (56,	'user_edit_own',	'User is allowed to edit their own details.'),
                                                     (57,	'user_edit_others',	'User is allowed to edit other users.'),
                                                     (58,	'user_passwd_edit_others',	'User is allowed to edit the password of other users.'),
                                                     (59,	'user_edit_templ_perm',	'User is allowed to change the permission template that is assigned to a user.'),
                                                     (60,	'templ_perm_add',	'User is allowed to add new permission templates.'),
                                                     (61,	'templ_perm_edit',	'User is allowed to edit existing permission templates.'),
                                                     (62,	'zone_content_edit_own_as_client',	'User is allowed to edit record, but not SOA and NS.');

CREATE TABLE `perm_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ` (`id`, `name`, `descr`) VALUES
    (1,	'Administrator',	'Administrator template with full rights.');

CREATE TABLE `perm_templ_items` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `templ_id` int(11) NOT NULL,
                                    `perm_id` int(11) NOT NULL,
                                    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `perm_templ_items` (`id`, `templ_id`, `perm_id`) VALUES
    (1,	1,	53);

CREATE TABLE `records_zone_templ` (
                                      `domain_id` int(11) NOT NULL,
                                      `record_id` int(11) NOT NULL,
                                      `zone_templ_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `users` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `username` varchar(64) NOT NULL,
                         `password` varchar(128) NOT NULL,
                         `fullname` varchar(255) NOT NULL,
                         `email` varchar(255) NOT NULL,
                         `description` varchar(1024) NOT NULL,
                         `perm_templ` int(11) NOT NULL,
                         `active` int(1) NOT NULL,
                         `use_ldap` int(1) NOT NULL,
                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `username`, `password`, `fullname`, `email`, `description`, `perm_templ`, `active`, `use_ldap`) VALUES
    (1,	'admin',	'$2y$12$10ei/WGJPcUY9Ea8/eVage9zBbxr0xxW82qJF/cfSyev/jX84WHQe',	'Administrator',	'admin@example.net',	'Administrator with full rights.',	1,	1,	0);

CREATE TABLE `zones` (
                         `id` int(11) NOT NULL AUTO_INCREMENT,
                         `domain_id` int(11) NOT NULL,
                         `owner` int(11) NOT NULL,
                         `comment` varchar(1024) DEFAULT NULL,
                         `zone_templ_id` int(11) NOT NULL,
                         PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `zone_templ` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(128) NOT NULL,
                              `descr` varchar(1024) NOT NULL,
                              `owner` int(11) NOT NULL,
                              PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `zone_templ_records` (
                                      `id` int(11) NOT NULL AUTO_INCREMENT,
                                      `zone_templ_id` int(11) NOT NULL,
                                      `name` varchar(255) NOT NULL,
                                      `type` varchar(6) NOT NULL,
                                      `content` varchar(2048) NOT NULL,
                                      `ttl` int(11) NOT NULL,
                                      `prio` int(11) NOT NULL,
                                      PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- 2022-09-29 19:08:10