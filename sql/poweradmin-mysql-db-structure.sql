-- PowerAdmin
-- MySQL Database Structure
--

CREATE TABLE users (
  id          INTEGER      NOT NULL AUTO_INCREMENT,
  username    VARCHAR(64)  NOT NULL,
  `password`  VARCHAR(128) NOT NULL,
  fullname    VARCHAR(255) NOT NULL,
  email       VARCHAR(255) NOT NULL,
  description TEXT         NOT NULL,
  perm_templ  TINYINT      NOT NULL,
  active      TINYINT      NOT NULL,
  use_ldap    TINYINT      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

START TRANSACTION;
    INSERT INTO users ( id, username, `password`, fullname, email
                      , description, perm_templ, active, use_ldap )
    VALUES ( 1, 'admin', '21232f297a57a5a743894a0e4a801fc3', 'Administrator'
           , 'admin@example.net', 'Administrator with full rights.', 1, 1, 0 );
COMMIT;

CREATE TABLE perm_items (
  id INTEGER       NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  descr TEXT       NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

START TRANSACTION;
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 41, 'zone_master_add', 'User is allowed to add new master zones.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 42, 'zone_slave_add', 'User is allowed to add new slave zones.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 43, 'zone_content_view_own', 'User is allowed to see the content and meta data of zones he owns.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 44, 'zone_content_edit_own', 'User is allowed to edit the content of zones he owns.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 45, 'zone_meta_edit_own', 'User is allowed to edit the meta data of zones he owns.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 46, 'zone_content_view_others', 'User is allowed to see the content and meta data of zones he does not own.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 47, 'zone_content_edit_others', 'User is allowed to edit the content of zones he does not own.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 48, 'zone_meta_edit_others', 'User is allowed to edit the meta data of zones he does not own.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 49, 'search', 'User is allowed to perform searches.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 50, 'supermaster_view', 'User is allowed to view supermasters.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 51, 'supermaster_add', 'User is allowed to add new supermasters.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 52, 'supermaster_edit', 'User is allowed to edit supermasters.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 53, 'user_is_ueberuser', 'User has full access. God-like. Redeemer.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 54, 'user_view_others', 'User is allowed to see other users and their details.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 55, 'user_add_new', 'User is allowed to add new users.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 56, 'user_edit_own', 'User is allowed to edit their own details.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 57, 'user_edit_others', 'User is allowed to edit other users.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 58, 'user_passwd_edit_others', 'User is allowed to edit the password of other users.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 59, 'user_edit_templ_perm', 'User is allowed to change the permission template that is assigned to a user.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 60, 'templ_perm_add', 'User is allowed to add new permission templates.' );
    INSERT INTO perm_items ( id, name, descr ) VALUES ( 61, 'templ_perm_edit', 'User is allowed to edit existing permission templates.' );
COMMIT;

CREATE TABLE perm_templ (
  id    INTEGER      NOT NULL AUTO_INCREMENT,
  name  VARCHAR(128) NOT NULL,
  descr TEXT         NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

START TRANSACTION;
    INSERT INTO perm_templ ( id, name, descr )
        VALUES ( 1, 'Administrator'
               , 'Administrator template with full rights.' );
COMMIT;

CREATE TABLE perm_templ_items (
  id INTEGER       NOT NULL AUTO_INCREMENT,
  templ_id INTEGER NOT NULL,
  perm_id INTEGER  NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

START TRANSACTION;
    INSERT INTO perm_templ_items ( id, templ_id, perm_id )
    VALUES ( 1, 1, 53 );
COMMIT;

CREATE TABLE zones (
  id            INTEGER NOT NULL AUTO_INCREMENT,
  domain_id     INTEGER NOT NULL,
  owner         INTEGER NOT NULL,
  `comment`     TEXT,
  zone_templ_id INTEGER NOT NULL,
  PRIMARY KEY (id),
  KEY owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE zone_templ (
  id    INTEGER      NOT NULL AUTO_INCREMENT,
  name  VARCHAR(128) NOT NULL,
  descr TEXT         NOT NULL,
  owner INTEGER      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE zone_templ_records (
  id            INTEGER      NOT NULL AUTO_INCREMENT,
  zone_templ_id INTEGER      NOT NULL,
  name          VARCHAR(255) NOT NULL,
  `type`        VARCHAR(6)   NOT NULL,
  content       VARCHAR(255) NOT NULL,
  ttl           INTEGER      NOT NULL,
  prio          INTEGER      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE records_zone_templ (
    domain_id INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE migrations (
    version VARCHAR(255) NOT NULL,
    apply_time INTEGER NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
