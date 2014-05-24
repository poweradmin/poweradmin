<?php

$def_tables = array(
    array(
        'table_name' => 'perm_items',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array(
                'type' => 'integer',
                'notnull' => 1,
                'unsigned' => 0,
                'autoincrement' => 1,
                'name' => 'id',
                'table' => 'perm_items',
                'flags' => 'primary_keynot_null'
            ),
            'name' => array(
                'type' => 'text',
                'notnull' => 1,
                'length' => 64,
                'fixed' => 0,
                'default' => 0,
                'name' => 'name',
                'table' => 'perm_items',
                'flags' => 'not_null'
            ),
            'descr' => array(
                'type' => 'text',
                'length' => 1024,
                'notnull' => 1,
                'fixed' => 0,
                'default' => 0,
                'name' => 'descr',
                'table' => 'perm_items',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'perm_templ',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array(
                'type' => 'integer',
                'notnull' => 1,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'name' => 'id',
                'table' => 'perm_templ',
                'flags' => 'primary_keynot_null'
            ),
            'name' => array(
                'type' => 'text',
                'notnull' => 1,
                'length' => 128,
                'fixed' => 0,
                'default' => 0,
                'name' => 'name',
                'table' => 'perm_templ',
                'flags' => 'not_null'
            ),
            'descr' => array(
                'notnull' => 1,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'length' => 1024,
                'name' => 'descr',
                'table' => 'perm_templ',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'perm_templ_items',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array(
                'notnull' => 1,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'type' => 'integer',
                'name' => 'id',
                'table' => 'perm_templ_items',
                'flags' => 'primary_keynot_null'
            ),
            'templ_id' => array(
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'templ_id',
                'table' => 'perm_templ_items',
                'flags' => 'not_null'
            ),
            'perm_id' => array(
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'perm_id',
                'table' => 'perm_templ_items',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'users',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array
                (
                'notnull' => 1,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'type' => 'integer',
                'name' => 'id',
                'table' => 'users',
                'flags' => 'primary_keynot_null'
            ),
            'username' => array
                (
                'notnull' => 1,
                'length' => 64,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'username',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'password' => array
                (
                'notnull' => 1,
                'length' => 128,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'password',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'fullname' => array
                (
                'notnull' => 1,
                'length' => 255,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'fullname',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'email' => array
                (
                'notnull' => 1,
                'length' => 255,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'email',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'description' => array
                (
                'notnull' => 1,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'length' => 1024,
                'name' => 'description',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'perm_templ' => array
                (
                'notnull' => 1,
                'length' => 1,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'perm_templ',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'active' => array
                (
                'notnull' => 1,
                'length' => 1,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'active',
                'table' => 'users',
                'flags' => 'not_null'
            ),
            'use_ldap' => array
                (
                'notnull' => 1,
                'length' => 1,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'use_ldap',
                'table' => 'users',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'zones',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array
                (
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'type' => 'integer',
                'name' => 'id',
                'table' => 'zones',
                'flags' => 'primary_keynot_null'
            ),
            'domain_id' => array
                (
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'domain_id',
                'table' => 'zones',
                'flags' => 'not_null'
            ),
            'owner' => array
                (
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'owner',
                'table' => 'zones',
                'flags' => 'not_null'
            ),
            'comment' => array
                (
                'notnull' => 0,
                'length' => 1024,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'comment',
                'table' => 'zones',
                'flags' => ''
            ),
            'zone_templ_id' => array
                (
                'notnull' => 1,
                'length' => 4,
                'unsigned' => 0,
                'type' => 'integer',
                'name' => 'zone_templ_id',
                'table' => 'zones',
                'flags' => ''
            ),
        )
    ),
    array(
        'table_name' => 'zone_templ',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'type' => 'integer',
                'name' => 'id',
                'table' => 'zone_templ',
                'flags' => 'primary_keynot_null'
            ),
            'name' => array
                (
                'notnull' => 1,
                'length' => 128,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'name',
                'table' => 'zone_templ',
                'flags' => 'not_null'
            ),
            'descr' => array
                (
                'notnull' => 1,
                'length' => 1024,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'descr',
                'table' => 'zone_templ',
                'flags' => 'not_null'
            ),
            'owner' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'owner',
                'table' => 'zone_templ',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'zone_templ_records',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'unsigned' => 0,
                'default' => 0,
                'autoincrement' => 1,
                'type' => 'integer',
                'name' => 'id',
                'table' => 'zone_templ_records',
                'flags' => 'primary_keynot_null'
            ),
            'zone_templ_id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'zone_templ_id',
                'table' => 'zone_templ_records',
                'flags' => 'not_null'
            ),
            'name' => array
                (
                'notnull' => 1,
                'length' => 255,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'name',
                'table' => 'zone_templ_records',
                'flags' => ''
            ),
            'type' => array
                (
                'notnull' => 1,
                'length' => 6,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'type',
                'table' => 'zone_templ_records',
                'flags' => ''
            ),
            'content' => array
                (
                'notnull' => 1,
                'length' => 255,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'content',
                'table' => 'zone_templ_records',
                'flags' => ''
            ),
            'ttl' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'ttl',
                'table' => 'zone_templ_records',
                'flags' => ''
            ),
            'prio' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'prio',
                'table' => 'zone_templ_records',
                'flags' => ''
            )
        )
    ),
    array(
        'table_name' => 'records_zone_templ',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'domain_id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'domain_id',
                'table' => 'records_zone_templ',
                'flags' => 'not_null'
            ),
            'record_id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'record_id',
                'table' => 'records_zone_templ',
                'flags' => 'not_null'
            ),
            'zone_templ_id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'zone_templ_id',
                'table' => 'records_zone_templ',
                'flags' => 'not_null'
            )
        )
    ),
    array(
        'table_name' => 'migrations',
        'options' => array('type' => 'innodb'),
        'fields' => array(
            'domain_id' => array
                (
                'notnull' => 1,
                'length' => 255,
                'fixed' => 0,
                'default' => 0,
                'type' => 'text',
                'name' => 'version',
                'table' => 'migrations',
                'flags' => 'not_null'
            ),
            'record_id' => array
                (
                'notnull' => 1,
                'length' => 11,
                'fixed' => 0,
                'default' => 0,
                'type' => 'integer',
                'name' => 'apply_time',
                'table' => 'migrations',
                'flags' => 'not_null'
            )
        )
    )
);

// Tables from PowerDNS
$grantTables = array('supermasters', 'domains', 'records');
// Include PowerAdmin tables
foreach ($def_tables as $table) {
    $grantTables[] = $table['table_name'];
}

// For PostgreSQL you need to grant access to sequences
$grantSequences = array('domains_id_seq', 'records_id_seq');
foreach ($def_tables as $table) {
    // ignore tables without primary key
    if ($table['table_name'] == 'migrations') { continue; }
    if ($table['table_name'] == 'records_zone_templ') { continue; }
    $grantSequences[] = $table['table_name'] . '_id_seq';
}

$def_permissions = array(
    array(41, 'zone_master_add', 'User is allowed to add new master zones.'),
    array(42, 'zone_slave_add', 'User is allowed to add new slave zones.'),
    array(43, 'zone_content_view_own', 'User is allowed to see the content and meta data of zones he owns.'),
    array(44, 'zone_content_edit_own', 'User is allowed to edit the content of zones he owns.'),
    array(45, 'zone_meta_edit_own', 'User is allowed to edit the meta data of zones he owns.'),
    array(46, 'zone_content_view_others', 'User is allowed to see the content and meta data of zones he does not own.'),
    array(47, 'zone_content_edit_others', 'User is allowed to edit the content of zones he does not own.'),
    array(48, 'zone_meta_edit_others', 'User is allowed to edit the meta data of zones he does not own.'),
    array(49, 'search', 'User is allowed to perform searches.'),
    array(50, 'supermaster_view', 'User is allowed to view supermasters.'),
    array(51, 'supermaster_add', 'User is allowed to add new supermasters.'),
    array(52, 'supermaster_edit', 'User is allowed to edit supermasters.'),
    array(53, 'user_is_ueberuser', 'User has full access. God-like. Redeemer.'),
    array(54, 'user_view_others', 'User is allowed to see other users and their details.'),
    array(55, 'user_add_new', 'User is allowed to add new users.'),
    array(56, 'user_edit_own', 'User is allowed to edit their own details.'),
    array(57, 'user_edit_others', 'User is allowed to edit other users.'),
    array(58, 'user_passwd_edit_others', 'User is allowed to edit the password of other users.'), // not used
    array(59, 'user_edit_templ_perm', 'User is allowed to change the permission template that is assigned to a user.'),
    array(60, 'templ_perm_add', 'User is allowed to add new permission templates.'),
    array(61, 'templ_perm_edit', 'User is allowed to edit existing permission templates.')
);

$def_remaining_queries = array(
    "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('admin'," . $db->quote(md5($pa_pass), 'text') . ",'Administrator','admin@example.net','Administrator with full rights.',1,1,0)",
    "INSERT INTO perm_templ (name, descr) VALUES ('Administrator','Administrator template with full rights.')",
    "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1,53)"
);
