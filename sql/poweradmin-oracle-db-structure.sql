CREATE TABLE users (
  id number(11) NOT NULL,
  username varchar2(64) DEFAULT '' NOT NULL,
  password varchar2(128) DEFAULT '' NOT NULL,
  fullname varchar2(255) DEFAULT '' NOT NULL,
  email varchar2(255) DEFAULT '' NOT NULL,
  description clob NOT NULL,
  perm_templ number(11) DEFAULT '0' NOT NULL,
  active number(1) DEFAULT '0' NOT NULL,
  use_ldap number(1) DEFAULT '0' NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE USERS_ID_SEQUENCE;

INSERT INTO users VALUES (1,'admin','21232f297a57a5a743894a0e4a801fc3','Administrator','admin@example.net','Administrator with full rights.',1,1);
SELECT USERS_ID_SEQUENCE.NEXTVAL FROM dual;

CREATE TABLE perm_items (
  id number(11) NOT NULL,
  name varchar2(64) NOT NULL,
  descr clob NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE PERM_ITEMS_ID_SEQUENCE;

INSERT INTO perm_items VALUES (1, 'user_is_ueberuser','User has full access. God-like. Redeemer.');
INSERT INTO perm_items VALUES (2, 'zone_master_add','User is allowed to add new master zones.');
INSERT INTO perm_items VALUES (3, 'zone_slave_add','User is allowed to add new slave zones.');
INSERT INTO perm_items VALUES (4, 'zone_content_view_own','User is allowed to see the content and meta data of zones he owns.');
INSERT INTO perm_items VALUES (5, 'zone_content_edit_own','User is allowed to edit the content of zones he owns.');
INSERT INTO perm_items VALUES (6, 'zone_meta_edit_own','User is allowed to edit the meta data of zones he owns.');
INSERT INTO perm_items VALUES (7, 'zone_content_view_others','User is allowed to see the content and meta data of zones he does not own.');
INSERT INTO perm_items VALUES (8, 'zone_content_edit_others','User is allowed to edit the content of zones he does not own.');
INSERT INTO perm_items VALUES (9, 'zone_meta_edit_others','User is allowed to edit the meta data of zones he does not own.');
INSERT INTO perm_items VALUES (10, 'search','User is allowed to perform searches.');
INSERT INTO perm_items VALUES (11, 'supermaster_view','User is allowed to view supermasters.');
INSERT INTO perm_items VALUES (12, 'supermaster_add','User is allowed to add new supermasters.');
INSERT INTO perm_items VALUES (13, 'supermaster_edit','User is allowed to edit supermasters.');
INSERT INTO perm_items VALUES (14, 'user_view_others','User is allowed to see other users and their details.');
INSERT INTO perm_items VALUES (15, 'user_add_new','User is allowed to add new users.');
INSERT INTO perm_items VALUES (16, 'user_edit_own','User is allowed to edit their own details.');
INSERT INTO perm_items VALUES (17, 'user_edit_others','User is allowed to edit other users.');
INSERT INTO perm_items VALUES (18, 'user_passwd_edit_others','User is allowed to edit the password of other users.');
INSERT INTO perm_items VALUES (19, 'user_edit_templ_perm','User is allowed to change the permission template that is assigned to a user.');
INSERT INTO perm_items VALUES (20, 'templ_perm_add','User is allowed to add new permission templates.');
INSERT INTO perm_items VALUES (21, 'templ_perm_edit','User is allowed to edit existing permission templates.');

CREATE TABLE perm_templ (
  id number(11) NOT NULL,
  name varchar2(128) NOT NULL,
  descr clob NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE PERM_TEMPL_ID_SEQUENCE;

INSERT INTO perm_templ VALUES (1,'Administrator','Administrator template with full rights.');

CREATE TABLE perm_templ_items (
  id number(11) NOT NULL,
  templ_id number(11) NOT NULL,
  perm_id number(11) NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE PERM_TEMPL_ITEMS_ID_SEQUENCE;

INSERT INTO perm_templ_items VALUES (1,1,1);

CREATE TABLE zones (
  id number(11) NOT NULL,
  domain_id number(11) DEFAULT '0' NOT NULL,
  owner number(11) DEFAULT '0' NOT NULL,
  comment_ clob,
  PRIMARY KEY (id)
);
CREATE SEQUENCE ZONES_ID_SEQUENCE;

CREATE TABLE zone_templ (
  id number(11) NOT NULL,
  name varchar2(128) NOT NULL,
  descr clob NOT NULL,
  owner number(11) NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE ZONE_TEMPL_ID_SEQUENCE;

CREATE TABLE zone_templ_records (
  id number(11) NOT NULL,
  zone_templ_id number(11) NOT NULL,
  name varchar2(255) NOT NULL,
  type varchar2(6) NOT NULL,
  content varchar2(255) NOT NULL,
  ttl number(11) NOT NULL,
  prio number(11) NOT NULL,
  PRIMARY KEY (id)
);
CREATE SEQUENCE ZONE_TEMPL_RECID_SEQUENCE;

CREATE TABLE records_zone_templ (
    domain_id number(11) NOT NULL,
    record_id number(11) NOT NULL,
    zone_templ_id number(11) NOT NULL
);

CREATE TABLE migrations (
    version varchar2(255) NOT NULL,
    apply_time number(11) NOT NULL
);
