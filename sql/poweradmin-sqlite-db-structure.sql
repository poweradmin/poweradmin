BEGIN TRANSACTION;

CREATE TABLE users (
  id INTEGER PRIMARY KEY NOT NULL,
  username varchar(64) NOT NULL DEFAULT '',
  password varchar(128) NOT NULL DEFAULT '',
  fullname varchar(255) NOT NULL DEFAULT '',
  email varchar(255) NOT NULL DEFAULT '',
  description text NOT NULL,
  perm_templ tinyint(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 0,
  use_ldap tinyint(1) NOT NULL DEFAULT 0
);

INSERT INTO users VALUES (1,'admin','21232f297a57a5a743894a0e4a801fc3','Administrator','admin@example.net','Administrator with full rights.',1,1,0);

CREATE TABLE perm_items (
  id INTEGER PRIMARY KEY NOT NULL,
  name varchar(64) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_items VALUES (41,'zone_master_add','User is allowed to add new master zones.');
INSERT INTO perm_items VALUES (42,'zone_slave_add','User is allowed to add new slave zones.');
INSERT INTO perm_items VALUES (43,'zone_content_view_own','User is allowed to see the content and meta data of zones he owns.');
INSERT INTO perm_items VALUES (44,'zone_content_edit_own','User is allowed to edit the content of zones he owns.');
INSERT INTO perm_items VALUES (45,'zone_meta_edit_own','User is allowed to edit the meta data of zones he owns.');
INSERT INTO perm_items VALUES (46,'zone_content_view_others','User is allowed to see the content and meta data of zones he does not own.');
INSERT INTO perm_items VALUES (47,'zone_content_edit_others','User is allowed to edit the content of zones he does not own.');
INSERT INTO perm_items VALUES (48,'zone_meta_edit_others','User is allowed to edit the meta data of zones he does not own.');
INSERT INTO perm_items VALUES (49,'search','User is allowed to perform searches.');
INSERT INTO perm_items VALUES (50,'supermaster_view','User is allowed to view supermasters.');
INSERT INTO perm_items VALUES (51,'supermaster_add','User is allowed to add new supermasters.');
INSERT INTO perm_items VALUES (52,'supermaster_edit','User is allowed to edit supermasters.');
INSERT INTO perm_items VALUES (53,'user_is_ueberuser','User has full access. God-like. Redeemer.');
INSERT INTO perm_items VALUES (54,'user_view_others','User is allowed to see other users and their details.');
INSERT INTO perm_items VALUES (55,'user_add_new','User is allowed to add new users.');
INSERT INTO perm_items VALUES (56,'user_edit_own','User is allowed to edit their own details.');
INSERT INTO perm_items VALUES (57,'user_edit_others','User is allowed to edit other users.');
INSERT INTO perm_items VALUES (58,'user_passwd_edit_others','User is allowed to edit the password of other users.');
INSERT INTO perm_items VALUES (59,'user_edit_templ_perm','User is allowed to change the permission template that is assigned to a user.');
INSERT INTO perm_items VALUES (60,'templ_perm_add','User is allowed to add new permission templates.');
INSERT INTO perm_items VALUES (61,'templ_perm_edit','User is allowed to edit existing permission templates.');

CREATE TABLE perm_templ (
  id INTEGER PRIMARY KEY NOT NULL,
  name varchar(128) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_templ VALUES (1,'Administrator','Administrator template with full rights.');

CREATE TABLE perm_templ_items (
  id INTEGER PRIMARY KEY NOT NULL,
  templ_id int(11) NOT NULL,
  perm_id int(11) NOT NULL
);

INSERT INTO perm_templ_items VALUES (1,1,53);

CREATE TABLE zones (
  id INTEGER PRIMARY KEY NOT NULL,
  domain_id int(11) NOT NULL DEFAULT 0,
  owner int(11) NOT NULL DEFAULT 0,
  comment text,
  zone_templ_id INT(11) NOT NULL
);

CREATE INDEX owner ON zones (owner);

CREATE TABLE zone_templ (
  id INTEGER PRIMARY KEY NOT NULL,
  name varchar(128) NOT NULL,
  descr text NOT NULL,
  owner int(11) NOT NULL
);

CREATE TABLE zone_templ_records (
  id INTEGER PRIMARY KEY NOT NULL,
  zone_templ_id int(11) NOT NULL,
  name varchar(255) NOT NULL,
  type varchar(6) NOT NULL,
  content varchar(255) NOT NULL,
  ttl int(11) NOT NULL,
  prio int(11) NOT NULL
);

CREATE TABLE records_zone_templ (
    domain_id int(11) NOT NULL,
    record_id int(11) NOT NULL,
    zone_templ_id int(11) NOT NULL
);

CREATE TABLE migrations (
    version varchar(255) NOT NULL,
    apply_time int(11) NOT NULL
);

COMMIT;
