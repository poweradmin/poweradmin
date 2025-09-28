-- Adminer 4.8.1 SQLite 3 3.38.5 dump

CREATE TABLE log_users (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL);


CREATE TABLE log_zones (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL, zone_id integer);




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
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (63,	'zone_templ_add',	'User is allowed to add new zone templates.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (64,	'zone_templ_edit',	'User is allowed to edit existing zone templates.');

CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL);

INSERT INTO "perm_templ" ("id", "name", "descr") VALUES (1,	'Administrator',	'Administrator template with full rights.');

CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL);

INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (1,	1,	53);

CREATE TABLE records_zone_templ (domain_id integer NOT NULL, record_id integer NOT NULL, zone_templ_id integer NOT NULL);


CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL, fullname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, description VARCHAR(1024) NOT NULL, perm_templ integer NOT NULL, active integer(1) NOT NULL, use_ldap integer(1) NOT NULL, auth_method VARCHAR(20) NOT NULL DEFAULT 'sql');

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    ip_address VARCHAR(45) NOT NULL,
    timestamp INTEGER NOT NULL,
    successful BOOLEAN NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE INDEX idx_login_attempts_user_id ON login_attempts(user_id);
CREATE INDEX idx_login_attempts_ip_address ON login_attempts(ip_address);
CREATE INDEX idx_login_attempts_timestamp ON login_attempts(timestamp);

CREATE TABLE zone_templ (
    id integer PRIMARY KEY, 
    name VARCHAR(128) NOT NULL, 
    descr VARCHAR(1024) NOT NULL, 
    owner integer NOT NULL,
    created_by integer,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);


CREATE TABLE zone_templ_records (id integer PRIMARY KEY, zone_templ_id integer NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(6) NOT NULL, content VARCHAR(2048) NOT NULL, ttl integer NOT NULL, prio integer NOT NULL);


CREATE TABLE zones (id integer PRIMARY KEY, domain_id integer NOT NULL, owner integer NOT NULL, comment VARCHAR(1024), zone_templ_id integer NOT NULL);

CREATE TABLE api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    secret_key VARCHAR(255) NOT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    disabled BOOLEAN NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX idx_api_keys_secret_key ON api_keys(secret_key);
CREATE INDEX idx_api_keys_created_by ON api_keys(created_by);
CREATE INDEX idx_api_keys_disabled ON api_keys(disabled);

CREATE TABLE user_mfa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    secret VARCHAR(255) NULL,
    recovery_codes TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'app',
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    verification_data TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_mfa_user_id ON user_mfa(user_id);
CREATE INDEX idx_user_mfa_enabled ON user_mfa(enabled);

CREATE TABLE user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_user_preferences_user_key ON user_preferences(user_id, preference_key);
CREATE INDEX idx_user_preferences_user_id ON user_preferences(user_id);

CREATE TABLE zone_template_sync (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    zone_id INTEGER NOT NULL,
    zone_templ_id INTEGER NOT NULL,
    last_synced TIMESTAMP NULL,
    template_last_modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    needs_sync INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_templ_id) REFERENCES zone_templ(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_zone_template_unique ON zone_template_sync(zone_id, zone_templ_id);
CREATE INDEX idx_zone_templ_id ON zone_template_sync(zone_templ_id);
CREATE INDEX idx_needs_sync ON zone_template_sync(needs_sync);

--
CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used INTEGER NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) DEFAULT NULL
);

CREATE INDEX idx_prt_email ON password_reset_tokens(email);
CREATE UNIQUE INDEX idx_prt_token ON password_reset_tokens(token);
CREATE INDEX idx_prt_expires ON password_reset_tokens(expires_at);

CREATE TABLE user_agreements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    agreement_version VARCHAR(50) NOT NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE UNIQUE INDEX unique_user_agreement ON user_agreements(user_id, agreement_version);
CREATE INDEX idx_user_agreements_user_id ON user_agreements(user_id);
CREATE INDEX idx_user_agreements_version ON user_agreements(agreement_version);

CREATE TABLE oidc_user_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    oidc_subject VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, provider_id),
    UNIQUE (oidc_subject, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_oidc_provider_id ON oidc_user_links(provider_id);
CREATE INDEX idx_oidc_subject ON oidc_user_links(oidc_subject);

CREATE TABLE saml_user_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    saml_subject VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, provider_id),
    UNIQUE (saml_subject, provider_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_saml_provider_id ON saml_user_links(provider_id);
CREATE INDEX idx_saml_subject ON saml_user_links(saml_subject);
