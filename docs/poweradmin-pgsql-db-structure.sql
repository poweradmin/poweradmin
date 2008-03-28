CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  username varchar(16) NOT NULL,
  password varchar(34) NOT NULL,
  fullname varchar(255) NOT NULL,
  email varchar(255) NOT NULL,
  description text NOT NULL,
  perm_templ integer default 0,
  active smallint default 0
);

INSERT INTO users VALUES (1,'admin','21232f297a57a5a743894a0e4a801fc3','Administrator','admin@example.net','Administrator with full rights.',1,1);

CREATE TABLE perm_items (
  id SERIAL PRIMARY KEY,
  name varchar(64) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_items VALUES (41,'zone_master_add','User is allowed to add new master zones.'),(42,'zone_slave_add','User is allowed to add new slave zones.'),(43,'zone_content_view_own','User is allowed to see the content and meta data of zones he owns.'),(44,'zone_content_edit_own','User is allowed to edit the content of zones he owns.'),(45,'zone_meta_edit_own','User is allowed to edit the meta data of zones he owns.'),(46,'zone_content_view_others','User is allowed to see the content and meta data of zones he does not own.'),(47,'zone_content_edit_others','User is allowed to edit the content of zones he does not own.'),(48,'zone_meta_edit_others','User is allowed to edit the meta data of zones he does not own.'),(49,'search','User is allowed to perform searches.'),(50,'supermaster_view','User is allowed to view supermasters.'),(51,'supermaster_add','User is allowed to add new supermasters.'),(52,'supermaster_edit','User is allowed to edit supermasters.'),(53,'user_is_ueberuser','User has full access. God-like. Redeemer.'),(54,'user_view_others','User is allowed to see other users and their details.'),(55,'user_add_new','User is allowed to add new users.'),(56,'user_edit_own','User is allowed to edit their own details.'),(57,'user_edit_others','User is allowed to edit other users.'),(58,'user_passwd_edit_others','User is allowed to edit the password of other users.'),(59,'user_edit_templ_perm','User is allowed to change the permission template that is assigned to a user.'),(60,'templ_perm_add','User is allowed to add new permission templates.'),(61,'templ_perm_edit','User is allowed to edit existing permission templates.');

CREATE TABLE perm_templ (
  id SERIAL PRIMARY KEY,
  name varchar(128) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_templ VALUES (1,'Administrator','Administrator template with full rights.');

CREATE TABLE perm_templ_items (
  id SERIAL PRIMARY KEY,
  templ_id integer NOT NULL,
  perm_id integer NOT NULL
);

INSERT INTO perm_templ_items VALUES (249,1,53);

CREATE TABLE zones (
  id SERIAL PRIMARY KEY,
  domain_id integer default 0,
  owner integer default 0,
  comment text
);
