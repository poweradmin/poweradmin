-- Adminer 4.8.1 SQLite 3 3.38.5 dump

CREATE TABLE log_users (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL);


CREATE TABLE log_zones (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL, zone_id integer);

CREATE INDEX idx_log_zones_zone_id ON log_zones(zone_id);


CREATE TABLE log_groups (id integer PRIMARY KEY, event VARCHAR(2048) NOT NULL, created_at timestamp DEFAULT current_timestamp, priority integer NOT NULL, group_id integer);

CREATE INDEX idx_log_groups_group_id ON log_groups(group_id);


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
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (65,	'api_manage_keys',	'User is allowed to create and manage API keys.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (67,	'zone_delete_own',	'User is allowed to delete zones they own.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (68,	'zone_delete_others',	'User is allowed to delete zones owned by others.');
INSERT INTO "perm_items" ("id", "name", "descr") VALUES (69,	'user_enforce_mfa',	'User is required to use multi-factor authentication.');

CREATE TABLE perm_templ (id integer PRIMARY KEY, name VARCHAR(128) NOT NULL, descr VARCHAR(1024) NOT NULL, template_type VARCHAR(10) NOT NULL DEFAULT 'user', CHECK(template_type IN ('user', 'group')));

INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (1,	'Administrator',	'Administrator template with full rights.',	'user');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (2,	'Zone Manager',	'Full management of own zones including creation, editing, deletion, and templates.',	'user');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (3,	'Editor',	'Edit own zone records but cannot modify SOA and NS records.',	'user');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (4,	'Viewer',	'Read-only access to own zones with search capability.',	'user');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (5,	'Guest',	'Temporary access with no permissions. Suitable for users awaiting approval or limited access.',	'user');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (6,	'Administrators',	'Full administrative access for group members.',	'group');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (7,	'Zone Managers',	'Full zone management for group members.',	'group');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (8,	'Editors',	'Edit zone records (no SOA/NS) for group members.',	'group');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (9,	'Viewers',	'Read-only zone access for group members.',	'group');
INSERT INTO "perm_templ" ("id", "name", "descr", "template_type") VALUES (10,	'Guests',	'Temporary group with no permissions. Suitable for users awaiting approval.',	'group');

CREATE TABLE perm_templ_items (id integer PRIMARY KEY, templ_id integer NOT NULL, perm_id integer NOT NULL);

CREATE INDEX idx_perm_templ_items_templ_id ON perm_templ_items(templ_id);
CREATE INDEX idx_perm_templ_items_perm_id ON perm_templ_items(perm_id);

INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (1,	1,	53);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (2,	2,	41);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (3,	2,	42);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (4,	2,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (5,	2,	44);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (6,	2,	45);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (7,	2,	49);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (8,	2,	56);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (9,	2,	63);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (10,	2,	64);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (11,	2,	65);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (12,	2,	67);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (13,	3,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (14,	3,	49);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (15,	3,	56);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (16,	3,	62);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (17,	4,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (18,	4,	49);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (19,	6,	53);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (20,	7,	41);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (21,	7,	42);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (22,	7,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (23,	7,	44);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (24,	7,	45);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (25,	7,	49);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (26,	7,	56);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (27,	7,	63);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (28,	7,	64);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (29,	7,	65);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (30,	7,	67);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (31,	8,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (32,	8,	49);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (33,	8,	56);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (34,	8,	62);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (35,	9,	43);
INSERT INTO "perm_templ_items" ("id", "templ_id", "perm_id") VALUES (36,	9,	49);

CREATE TABLE records_zone_templ (domain_id integer NOT NULL, record_id integer NOT NULL, zone_templ_id integer NOT NULL);

CREATE INDEX idx_records_zone_templ_domain_id ON records_zone_templ(domain_id);
CREATE INDEX idx_records_zone_templ_zone_templ_id ON records_zone_templ(zone_templ_id);


CREATE TABLE users (id integer PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL, fullname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, description VARCHAR(1024) NOT NULL, perm_templ integer NOT NULL, active integer(1) NOT NULL, use_ldap integer(1) NOT NULL, auth_method VARCHAR(20) NOT NULL DEFAULT 'sql');

CREATE INDEX idx_users_perm_templ ON users(perm_templ);

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

CREATE INDEX idx_zone_templ_owner ON zone_templ(owner);
CREATE INDEX idx_zone_templ_created_by ON zone_templ(created_by);


CREATE TABLE zone_templ_records (id integer PRIMARY KEY, zone_templ_id integer NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(6) NOT NULL, content VARCHAR(2048) NOT NULL, ttl integer NOT NULL, prio integer NOT NULL);

CREATE INDEX idx_zone_templ_records_zone_templ_id ON zone_templ_records(zone_templ_id);


CREATE TABLE zones (id integer PRIMARY KEY, domain_id integer NOT NULL, owner integer NULL DEFAULT NULL, comment VARCHAR(1024), zone_templ_id integer NOT NULL);

CREATE INDEX idx_zones_domain_id ON zones(domain_id);
CREATE INDEX idx_zones_owner ON zones(owner);
CREATE INDEX idx_zones_zone_templ_id ON zones(zone_templ_id);

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

CREATE TABLE username_recovery_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_urr_email ON username_recovery_requests(email);
CREATE INDEX idx_urr_ip ON username_recovery_requests(ip_address);
CREATE INDEX idx_urr_created ON username_recovery_requests(created_at);

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


CREATE TABLE user_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    perm_templ INTEGER NOT NULL,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (perm_templ) REFERENCES perm_templ(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_user_groups_perm_templ ON user_groups(perm_templ);
CREATE INDEX idx_user_groups_created_by ON user_groups(created_by);
CREATE INDEX idx_user_groups_name ON user_groups(name);

CREATE TRIGGER trigger_user_groups_updated_at
    AFTER UPDATE ON user_groups
    FOR EACH ROW
BEGIN
    UPDATE user_groups SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

INSERT INTO user_groups (id, name, description, perm_templ, created_by) VALUES
    (1, 'Administrators', 'Full administrative access to all system functions.', 6, NULL),
    (2, 'Zone Managers', 'Full zone management including creation, editing, and deletion.', 7, NULL),
    (3, 'Editors', 'Edit zone records but cannot modify SOA and NS records.', 8, NULL),
    (4, 'Viewers', 'Read-only access to zones with search capability.', 9, NULL),
    (5, 'Guests', 'Temporary group with no permissions. Suitable for users awaiting approval.', 10, NULL);

CREATE TABLE user_group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_user_group_members_user ON user_group_members(user_id);
CREATE INDEX idx_user_group_members_group ON user_group_members(group_id);

CREATE TABLE zones_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (domain_id, group_id),
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
);

CREATE INDEX idx_zones_groups_domain ON zones_groups(domain_id);
CREATE INDEX idx_zones_groups_group ON zones_groups(group_id);

CREATE TABLE record_comment_links (
    record_id INTEGER NOT NULL,
    comment_id INTEGER NOT NULL,
    PRIMARY KEY (record_id),
    UNIQUE (comment_id)
);

CREATE INDEX idx_record_comment_links_comment ON record_comment_links(comment_id);
