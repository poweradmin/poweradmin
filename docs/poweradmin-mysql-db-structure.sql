CREATE TABLE `users` (
  `id` int(11) NOT NULL auto_increment,
  `username` varchar(16) NOT NULL default '',
  `password` varchar(34) NOT NULL default '',
  `fullname` varchar(255) NOT NULL default '',
  `email` varchar(255) NOT NULL default '',
  `description` text NOT NULL,
  `perm_templ` tinyint(11) NOT NULL default '0',
  `active` tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

LOCK TABLES `users` WRITE;
INSERT INTO `users` VALUES (1,'admin','21232f297a57a5a743894a0e4a801fc3','Administrator','admin@example.net','Administrator with full rights.',1,1);
UNLOCK TABLES;

CREATE TABLE `perm_items` (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(64) NOT NULL,
  `descr` text NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

LOCK TABLES `perm_items` WRITE;
INSERT INTO `perm_items` VALUES (41,'zone_master_add','User is allowed to add new master zones.'),(42,'zone_slave_add','User is allowed to add new slave zones.'),(43,'zone_content_view_own','User is allowed to see the content and meta data of zones he owns.'),(44,'zone_content_edit_own','User is allowed to edit the content of zones he owns.'),(45,'zone_meta_edit_own','User is allowed to edit the meta data of zones he owns.'),(46,'zone_content_view_others','User is allowed to see the content and meta data of zones he does not own.'),(47,'zone_content_edit_others','User is allowed to edit the content of zones he does not own.'),(48,'zone_meta_edit_others','User is allowed to edit the meta data of zones he does not own.'),(49,'search','User is allowed to perform searches.'),(50,'supermaster_view','User is allowed to view supermasters.'),(51,'supermaster_add','User is allowed to add new supermasters.'),(52,'supermaster_edit','User is allowed to edit supermasters.'),(53,'user_is_ueberuser','User has full access. God-like. Redeemer.'),(54,'user_view_others','User is allowed to see other users and their details.'),(55,'user_add_new','User is allowed to add new users.'),(56,'user_edit_own','User is allowed to edit their own details.'),(57,'user_edit_others','User is allowed to edit other users.'),(58,'user_passwd_edit_others','User is allowed to edit the password of other users.'),(59,'user_edit_templ_perm','User is allowed to change the permission template that is assigned to a user.'),(60,'templ_perm_add','User is allowed to add new permission templates.'),(61,'templ_perm_edit','User is allowed to edit existing permission templates.');
UNLOCK TABLES;

CREATE TABLE `perm_templ` (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(128) NOT NULL,
  `descr` text NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

LOCK TABLES `perm_templ` WRITE;
INSERT INTO `perm_templ` VALUES (1,'Administrator','Administrator template with full rights.');
UNLOCK TABLES;

CREATE TABLE `perm_templ_items` (
  `id` int(11) NOT NULL auto_increment,
  `templ_id` int(11) NOT NULL,
  `perm_id` int(11) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

LOCK TABLES `perm_templ_items` WRITE;
INSERT INTO `perm_templ_items` VALUES (1,1,53);
UNLOCK TABLES;

CREATE TABLE `zones` (
  `id` int(11) NOT NULL auto_increment,
  `domain_id` int(11) NOT NULL default '0',
  `owner` int(11) NOT NULL default '0',
  `comment` text,
  PRIMARY KEY  (`id`),
  KEY `owner` (`owner`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE zone_templ (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(128) NOT NULL,
  `descr` text NOT NULL,
  `owner` int(11) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE zone_templ_records (
  `id` int(11) NOT NULL auto_increment,
  `zone_templ_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(6) NOT NULL,
  `content` varchar(255) NOT NULL,
  `ttl` int(11) NOT NULL,
  `prio` int(11) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
