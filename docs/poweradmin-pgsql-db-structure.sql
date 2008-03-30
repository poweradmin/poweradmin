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

INSERT INTO users (username, password, fullname, email, description, perm_templ, active) VALUES ('admin','21232f297a57a5a743894a0e4a801fc3','Administrator','admin@example.net','Administrator with full rights.',1,1);

CREATE TABLE perm_items (
  id SERIAL PRIMARY KEY,
  name varchar(64) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_items (name, descr) VALUES ('user_is_ueberuser','User has full access. God-like. Redeemer.'),('zone_master_add','User is allowed to add new master zones.'),('zone_slave_add','User is allowed to add new slave zones.'),('zone_content_view_own','User is allowed to see the content and meta data of zones he owns.'),('zone_content_edit_own','User is allowed to edit the content of zones he owns.'),('zone_meta_edit_own','User is allowed to edit the meta data of zones he owns.'),('zone_content_view_others','User is allowed to see the content and meta data of zones he does not own.'),('zone_content_edit_others','User is allowed to edit the content of zones he does not own.'),('zone_meta_edit_others','User is allowed to edit the meta data of zones he does not own.'),('search','User is allowed to perform searches.'),('supermaster_view','User is allowed to view supermasters.'),('supermaster_add','User is allowed to add new supermasters.'),('supermaster_edit','User is allowed to edit supermasters.'),('user_view_others','User is allowed to see other users and their details.'),('user_add_new','User is allowed to add new users.'),('user_edit_own','User is allowed to edit their own details.'),('user_edit_others','User is allowed to edit other users.'),('user_passwd_edit_others','User is allowed to edit the password of other users.'),('user_edit_templ_perm','User is allowed to change the permission template that is assigned to a user.'),('templ_perm_add','User is allowed to add new permission templates.'),('templ_perm_edit','User is allowed to edit existing permission templates.');

CREATE TABLE perm_templ (
  id SERIAL PRIMARY KEY,
  name varchar(128) NOT NULL,
  descr text NOT NULL
);

INSERT INTO perm_templ (name, descr) VALUES ('Administrator','Administrator template with full rights.');

CREATE TABLE perm_templ_items (
  id SERIAL PRIMARY KEY,
  templ_id integer NOT NULL,
  perm_id integer NOT NULL
);

INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1,1);

CREATE TABLE zones (
  id SERIAL PRIMARY KEY,
  domain_id integer default 0,
  owner integer default 0,
  comment text
);
