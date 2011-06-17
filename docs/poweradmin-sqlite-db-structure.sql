BEGIN TRANSACTION;

CREATE TABLE users (
  id INTEGER PRIMARY KEY NOT NULL,
  username varchar(16) NOT NULL DEFAULT '',
  password varchar(34) NOT NULL DEFAULT '',
  fullname varchar(255) NOT NULL DEFAULT '',
  email varchar(255) NOT NULL DEFAULT '',
  description text NOT NULL,
  perm_templ tinyint(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 0
);

CREATE TABLE perm_items (
  id INTEGER PRIMARY KEY NOT NULL,
  name varchar(64) NOT NULL,
  descr text NOT NULL
);

CREATE TABLE perm_templ (
  id INTEGER PRIMARY KEY NOT NULL,
  name varchar(128) NOT NULL,
  descr text NOT NULL
);

CREATE TABLE perm_templ_items (
  id INTEGER PRIMARY KEY NOT NULL,
  templ_id int(11) NOT NULL,
  perm_id int(11) NOT NULL
);

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

COMMIT;
