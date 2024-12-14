-- Adminer 4.8.1 SQLite 3 3.38.5 dump

CREATE TABLE log_users (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL);


CREATE TABLE log_zones (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL, zone_id integer);


CREATE TABLE migrations (version VARCHAR(255) NOT NULL, apply_time integer NOT NULL);


CREATE TABLE perm_items (id integer PRIMARY KEY, name VARCHAR(64) NOT NULL, descr VARCHAR(1024) NOT NULL);

INSERT INTO "perm_items" ("id", "name", "descr") VALUES (41,	'zone_master_add',	'User is allowed to add new master zones.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (42,	'zone_slave_add',	'User is allowed to add new slave zones.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (43,	'zone_content_view_own',	'User is allowed to see the content and meta data of zones he owns.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (44,	'zone_content_edit_own',	'User is allowed to edit the content of zones he owns.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (45,	'zone_meta_edit_own',	'User is allowed to edit the meta data of zones he owns.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (46,	'zone_content_view_others',	'User is allowed to see the content and meta data of zones he does not own.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (47,	'zone_content_edit_others',	'User is allowed to edit the content of zones he does not own.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (48,	'zone_meta_edit_others',	'User is allowed to edit the meta data of zones he does not own.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (49,	'search',	'User is allowed to perform searches.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (50,	'supermaster_view',	'User is allowed to view supermasters.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (51,	'supermaster_add',	'User is allowed to add new supermasters.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (52,	'supermaster_edit',	'User is allowed to edit supermasters.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (53,	'user_is_ueberuser',	'User has full access. God-like. Redeemer.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (54,	'user_view_others',	'User is allowed to see other users and their details.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (55,	'user_add_new',	'User is allowed to add new users.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (56,	'user_edit_own',	'User is allowed to edit their own details.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (57,	'user_edit_others',	'User is allowed to edit other users.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (58,	'user_passwd_edit_others',	'User is allowed to edit the password of other users.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (59,	'user_edit_templ_perm',	'User is allowed to change the permission template that is assigned to a user.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (60,	'templ_perm_add',	'User is allowed to add new permission templates.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (61,	'templ_perm_edit',	'User is allowed to edit existing permission templates.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (62,	'zone_content_edit_own_as_client',	'User is allowed to edit record, but not SOA and NS.');

CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL);

INSERT INTO "perm_templ" ("id", "name", "descr") VALUES (1,	'Administrator',	'Administrator template with full rights.');

CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL);

INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (1,	1,	53);

CREATE TABLE records_zone_templ (domain_id integer NOT NULL, record_id integer NOT NULL, zone_templ_id integer NOT NULL);


CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL, fullname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, description VARCHAR(1024) NOT NULL, perm_templ integer NOT NULL, active integer(1) NOT NULL, use_ldap integer(1) NOT NULL);

INSERT INTO "users" ("id", "username", "password", "fullname", "email", "description", "perm_templ", "active", "use_ldap") VALUES (1,	'admin',	'$2y$12$10ei/WGJPcUY9Ea8/eVage9zBbxr0xxW82qJF/cfSyev/jX84WHQe',	'Administrator',	'admin@example.net',	'Administrator with full rights.',	1,	1,	0);

CREATE TABLE zone_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, owner integer NOT NULL);


CREATE TABLE zone_templ_records (id integer PRIMARY KEY, zone_templ_id integer NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(6) NOT NULL, content VARCHAR(2048) NOT NULL, ttl integer NOT NULL, prio integer NOT NULL);


CREATE TABLE zones (id integer PRIMARY KEY, domain_id integer NOT NULL, owner integer NOT NULL, comment VARCHAR(1024), zone_templ_id integer NOT NULL);


--