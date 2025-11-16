<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace PoweradminInstall;

class DatabaseStructureHelper
{
    public static function getDefaultTables(): array
    {
        return array(
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
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'templ_id',
                        'table' => 'perm_templ_items',
                        'flags' => 'not_null'
                    ),
                    'perm_id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'perm_id',
                        'table' => 'perm_templ_items',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'perm_templ_items_templ_id_idx' => array(
                        'fields' => array(
                            'templ_id' => array()
                        )
                    ),
                    'perm_templ_items_perm_id_idx' => array(
                        'fields' => array(
                            'perm_id' => array()
                        )
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
                    ),
                    'auth_method' => array
                    (
                        'notnull' => 1,
                        'length' => 20,
                        'fixed' => 0,
                        'default' => 'sql',
                        'type' => 'text',
                        'name' => 'auth_method',
                        'table' => 'users',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'users_perm_templ_idx' => array(
                        'fields' => array(
                            'perm_templ' => array()
                        )
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
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'domain_id',
                        'table' => 'zones',
                        'flags' => 'not_null'
                    ),
                    'owner' => array
                    (
                        'notnull' => 0,
                        'unsigned' => 0,
                        'default' => null,
                        'type' => 'integer',
                        'name' => 'owner',
                        'table' => 'zones',
                        'flags' => ''
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
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'zone_templ_id',
                        'table' => 'zones',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'zones_domain_id_idx' => array(
                        'fields' => array(
                            'domain_id' => array()
                        )
                    ),
                    'zones_owner_idx' => array(
                        'fields' => array(
                            'owner' => array()
                        )
                    ),
                    'zones_zone_templ_id_idx' => array(
                        'fields' => array(
                            'zone_templ_id' => array()
                        )
                    )
                )
            ),
            array(
                'table_name' => 'zone_templ',
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
                        'fixed' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'owner',
                        'table' => 'zone_templ',
                        'flags' => 'not_null'
                    ),
                    'created_by' => array
                    (
                        'notnull' => 0,
                        'fixed' => 0,
                        'default' => null,
                        'type' => 'integer',
                        'name' => 'created_by',
                        'table' => 'zone_templ',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'zone_templ_owner_idx' => array(
                        'fields' => array(
                            'owner' => array()
                        )
                    ),
                    'zone_templ_created_by_idx' => array(
                        'fields' => array(
                            'created_by' => array()
                        )
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
                        'length' => 2048,
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
                        'fixed' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'prio',
                        'table' => 'zone_templ_records',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'zone_templ_records_zone_templ_id_idx' => array(
                        'fields' => array(
                            'zone_templ_id' => array()
                        )
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
                        'fixed' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'zone_templ_id',
                        'table' => 'records_zone_templ',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'records_zone_templ_domain_id_idx' => array(
                        'fields' => array(
                            'domain_id' => array()
                        )
                    ),
                    'records_zone_templ_zone_templ_id_idx' => array(
                        'fields' => array(
                            'zone_templ_id' => array()
                        )
                    )
                )
            ),
            array(
                'table_name' => 'log_zones',
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
                        'table' => 'log_zones',
                        'flags' => 'primary_keynot_null'
                    ),
                    'event' => array
                    (
                        'notnull' => 1,
                        'length' => 2048,
                        'type' => 'text',
                        'name' => 'event',
                        'table' => 'log_zones',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'notnull' => 0,
                        'default' => 'current_timestamp',
                        'type' => 'timestamp',
                        'name' => 'created_at',
                        'table' => 'log_zones',
                        'flags' => ''
                    ),
                    'priority' => array
                    (
                        'notnull' => 1,
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'priority',
                        'table' => 'log_zones',
                        'flags' => ''
                    ),
                    'zone_id' => array(
                        'notnull' => 0,
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'zone_id',
                        'table' => 'log_zones',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'log_zones_zone_id_idx' => array(
                        'fields' => array(
                            'zone_id' => array()
                        )
                    )
                )
            ),
            array(
                'table_name' => 'log_users',
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
                        'table' => 'log_users',
                        'flags' => 'primary_keynot_null'
                    ),
                    'event' => array
                    (
                        'notnull' => 1,
                        'length' => 2048,
                        'type' => 'text',
                        'name' => 'event',
                        'table' => 'log_users',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'notnull' => 0,
                        'default' => 'current_timestamp',
                        'type' => 'timestamp',
                        'name' => 'created_at',
                        'table' => 'log_users',
                        'flags' => ''
                    ),
                    'priority' => array
                    (
                        'notnull' => 1,
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'priority',
                        'table' => 'log_users',
                        'flags' => ''
                    )
                )
            ),
            array(
                'table_name' => 'login_attempts',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'autoincrement' => 1,
                        'type' => 'integer',
                        'name' => 'id',
                        'table' => 'login_attempts',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'notnull' => 0,
                        'unsigned' => 0,
                        'default' => null,
                        'type' => 'integer',
                        'name' => 'user_id',
                        'table' => 'login_attempts',
                        'flags' => ''
                    ),
                    'ip_address' => array(
                        'notnull' => 1,
                        'length' => 45,
                        'fixed' => 0,
                        'type' => 'text',
                        'name' => 'ip_address',
                        'table' => 'login_attempts',
                        'flags' => 'not_null'
                    ),
                    'timestamp' => array(
                        'notnull' => 1,
                        'type' => 'integer',
                        'name' => 'timestamp',
                        'table' => 'login_attempts',
                        'flags' => 'not_null'
                    ),
                    'successful' => array(
                        'notnull' => 1,
                        'length' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'successful',
                        'table' => 'login_attempts',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'idx_login_attempts_user_id' => array('user_id'),
                    'idx_login_attempts_ip_address' => array('ip_address'),
                    'idx_login_attempts_timestamp' => array('timestamp')
                ),
                'foreign_keys' => array(
                    'fk_login_attempts_users' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'SET NULL'
                    )
                )
            ),
            array(
                'table_name' => 'api_keys',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'autoincrement' => 1,
                        'type' => 'integer',
                        'name' => 'id',
                        'table' => 'api_keys',
                        'flags' => 'primary_keynot_null'
                    ),
                    'name' => array(
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'type' => 'text',
                        'name' => 'name',
                        'table' => 'api_keys',
                        'flags' => 'not_null'
                    ),
                    'secret_key' => array(
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'type' => 'text',
                        'name' => 'secret_key',
                        'table' => 'api_keys',
                        'flags' => 'not_null'
                    ),
                    'created_by' => array(
                        'notnull' => 0,
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'created_by',
                        'table' => 'api_keys',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'type' => 'timestamp',
                        'name' => 'created_at',
                        'table' => 'api_keys',
                        'flags' => 'not_null'
                    ),
                    'last_used_at' => array(
                        'notnull' => 0,
                        'type' => 'timestamp',
                        'name' => 'last_used_at',
                        'table' => 'api_keys',
                        'flags' => ''
                    ),
                    'disabled' => array(
                        'notnull' => 1,
                        'length' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'disabled',
                        'table' => 'api_keys',
                        'flags' => 'not_null'
                    ),
                    'expires_at' => array(
                        'notnull' => 0,
                        'type' => 'timestamp',
                        'name' => 'expires_at',
                        'table' => 'api_keys',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'idx_api_keys_secret_key' => array('secret_key'),
                    'idx_api_keys_created_by' => array('created_by'),
                    'idx_api_keys_disabled' => array('disabled')
                ),
                'foreign_keys' => array(
                    'fk_api_keys_users' => array(
                        'table' => 'users',
                        'fields' => array('created_by' => 'id'),
                        'ondelete' => 'SET NULL'
                    )
                )
            ),
            array(
                'table_name' => 'user_mfa',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'autoincrement' => 1,
                        'type' => 'integer',
                        'name' => 'id',
                        'table' => 'user_mfa',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'user_id',
                        'table' => 'user_mfa',
                        'flags' => 'not_null'
                    ),
                    'enabled' => array(
                        'notnull' => 1,
                        'length' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'enabled',
                        'table' => 'user_mfa',
                        'flags' => 'not_null'
                    ),
                    'secret' => array(
                        'notnull' => 0,
                        'length' => 255,
                        'fixed' => 0,
                        'type' => 'text',
                        'name' => 'secret',
                        'table' => 'user_mfa',
                        'flags' => ''
                    ),
                    'recovery_codes' => array(
                        'notnull' => 0,
                        'type' => 'text',
                        'name' => 'recovery_codes',
                        'table' => 'user_mfa',
                        'flags' => ''
                    ),
                    'verification_data' => array(
                        'notnull' => 0,
                        'type' => 'text',
                        'name' => 'verification_data',
                        'table' => 'user_mfa',
                        'flags' => ''
                    ),
                    'type' => array(
                        'notnull' => 1,
                        'length' => 20,
                        'fixed' => 0,
                        // Removed default to avoid issues with string quoting
                        'type' => 'text',
                        'name' => 'type',
                        'table' => 'user_mfa',
                        'flags' => 'not_null'
                    ),
                    'last_used_at' => array(
                        'notnull' => 0,
                        'type' => 'timestamp',
                        'name' => 'last_used_at',
                        'table' => 'user_mfa',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'type' => 'timestamp',
                        'name' => 'created_at',
                        'table' => 'user_mfa',
                        'flags' => 'not_null'
                    ),
                    'updated_at' => array(
                        'notnull' => 0,
                        'type' => 'timestamp',
                        'name' => 'updated_at',
                        'table' => 'user_mfa',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'idx_user_mfa_user_id' => array('user_id'),
                    'idx_user_mfa_enabled' => array('enabled')
                ),
                'foreign_keys' => array(
                    'fk_user_mfa_users' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'user_preferences',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'autoincrement' => 1,
                        'type' => 'integer',
                        'name' => 'id',
                        'table' => 'user_preferences',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'notnull' => 1,
                        'unsigned' => 0,
                        'type' => 'integer',
                        'name' => 'user_id',
                        'table' => 'user_preferences',
                        'flags' => 'not_null'
                    ),
                    'preference_key' => array(
                        'notnull' => 1,
                        'length' => 100,
                        'fixed' => 0,
                        'type' => 'text',
                        'name' => 'preference_key',
                        'table' => 'user_preferences',
                        'flags' => 'not_null'
                    ),
                    'preference_value' => array(
                        'notnull' => 0,
                        'type' => 'text',
                        'name' => 'preference_value',
                        'table' => 'user_preferences',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'idx_user_preferences_user_key' => array('user_id', 'preference_key'),
                    'idx_user_preferences_user_id' => array('user_id')
                ),
                'foreign_keys' => array(
                    'fk_user_preferences_users' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'zone_template_sync',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'zone_template_sync',
                        'flags' => 'primary_keynot_null'
                    ),
                    'zone_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'length' => 11,
                        'name' => 'zone_id',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    ),
                    'zone_templ_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'length' => 11,
                        'name' => 'zone_templ_id',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    ),
                    'last_synced' => array(
                        'type' => 'timestamp',
                        'notnull' => 0,
                        'name' => 'last_synced',
                        'table' => 'zone_template_sync',
                        'flags' => ''
                    ),
                    'template_last_modified' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'CURRENT_TIMESTAMP',
                        'name' => 'template_last_modified',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    ),
                    'needs_sync' => array(
                        'type' => 'boolean',
                        'notnull' => 1,
                        'default' => 0,
                        'name' => 'needs_sync',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'CURRENT_TIMESTAMP',
                        'name' => 'created_at',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    ),
                    'updated_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'CURRENT_TIMESTAMP',
                        'name' => 'updated_at',
                        'table' => 'zone_template_sync',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'idx_zone_template_unique' => array('zone_id', 'zone_templ_id'),
                    'idx_zone_templ_id' => array('zone_templ_id'),
                    'idx_needs_sync' => array('needs_sync')
                ),
                'foreign_keys' => array(
                    'fk_zone_template_sync_zone' => array(
                        'table' => 'zones',
                        'fields' => array('zone_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    ),
                    'fk_zone_template_sync_templ' => array(
                        'table' => 'zone_templ',
                        'fields' => array('zone_templ_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'user_agreements',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'user_agreements',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'user_id',
                        'table' => 'user_agreements',
                        'flags' => 'not_null'
                    ),
                    'agreement_version' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 50,
                        'fixed' => 0,
                        'name' => 'agreement_version',
                        'table' => 'user_agreements',
                        'flags' => 'not_null'
                    ),
                    'accepted_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'CURRENT_TIMESTAMP',
                        'name' => 'accepted_at',
                        'table' => 'user_agreements',
                        'flags' => 'not_null'
                    ),
                    'ip_address' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'length' => 45,
                        'fixed' => 0,
                        'name' => 'ip_address',
                        'table' => 'user_agreements',
                        'flags' => ''
                    ),
                    'user_agent' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'name' => 'user_agent',
                        'table' => 'user_agreements',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'unique_user_agreement' => array('user_id', 'agreement_version'),
                    'idx_user_agreements_user_id' => array('user_id'),
                    'idx_user_agreements_version' => array('agreement_version')
                ),
                'foreign_keys' => array(
                    'fk_user_agreements_user' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'password_reset_tokens',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'password_reset_tokens',
                        'flags' => 'primary_keynot_null'
                    ),
                    'email' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'email',
                        'table' => 'password_reset_tokens',
                        'flags' => 'not_null'
                    ),
                    'token' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 64,
                        'fixed' => 0,
                        'name' => 'token',
                        'table' => 'password_reset_tokens',
                        'flags' => 'not_null'
                    ),
                    'expires_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'name' => 'expires_at',
                        'table' => 'password_reset_tokens',
                        'flags' => 'not_null'
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'password_reset_tokens',
                        'flags' => 'not_null'
                    ),
                    'used' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'length' => 1,
                        'unsigned' => 0,
                        'default' => 0,
                        'name' => 'used',
                        'table' => 'password_reset_tokens',
                        'flags' => 'not_null'
                    ),
                    'ip_address' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'length' => 45,
                        'fixed' => 0,
                        'name' => 'ip_address',
                        'table' => 'password_reset_tokens',
                        'flags' => ''
                    )
                ),
                'indexes' => array(
                    'idx_password_reset_tokens_token' => array('token'),
                    'idx_password_reset_tokens_email' => array('email'),
                    'idx_password_reset_tokens_expires' => array('expires_at')
                )
            ),
            array(
                'table_name' => 'username_recovery_requests',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'username_recovery_requests',
                        'flags' => 'primary_keynot_null'
                    ),
                    'email' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'email',
                        'table' => 'username_recovery_requests',
                        'flags' => 'not_null'
                    ),
                    'ip_address' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 45,
                        'fixed' => 0,
                        'name' => 'ip_address',
                        'table' => 'username_recovery_requests',
                        'flags' => 'not_null'
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'username_recovery_requests',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'idx_username_recovery_requests_email' => array('email'),
                    'idx_username_recovery_requests_ip' => array('ip_address'),
                    'idx_username_recovery_requests_created' => array('created_at')
                )
            ),
            array(
                'table_name' => 'oidc_user_links',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'oidc_user_links',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'user_id',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    ),
                    'provider_id' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 50,
                        'fixed' => 0,
                        'name' => 'provider_id',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    ),
                    'oidc_subject' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'oidc_subject',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    ),
                    'username' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'username',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    ),
                    'email' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'email',
                        'table' => 'oidc_user_links',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    ),
                    'updated_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'updated_at',
                        'table' => 'oidc_user_links',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'unique_user_provider' => array('user_id', 'provider_id'),
                    'unique_subject_provider' => array('oidc_subject', 'provider_id'),
                    'idx_oidc_provider_id' => array('provider_id'),
                    'idx_oidc_subject' => array('oidc_subject')
                ),
                'foreign_keys' => array(
                    'fk_oidc_user_links_users' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'saml_user_links',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'saml_user_links',
                        'flags' => 'primary_keynot_null'
                    ),
                    'user_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'user_id',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    ),
                    'provider_id' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 50,
                        'fixed' => 0,
                        'name' => 'provider_id',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    ),
                    'saml_subject' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'saml_subject',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    ),
                    'username' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'username',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    ),
                    'email' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'email',
                        'table' => 'saml_user_links',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    ),
                    'updated_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'updated_at',
                        'table' => 'saml_user_links',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'unique_user_provider' => array('user_id', 'provider_id'),
                    'unique_subject_provider' => array('saml_subject', 'provider_id'),
                    'idx_saml_provider_id' => array('provider_id'),
                    'idx_saml_subject' => array('saml_subject')
                ),
                'foreign_keys' => array(
                    'fk_saml_user_links_users' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'user_groups',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 1,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'user_groups',
                        'flags' => 'primary_keynot_null'
                    ),
                    'name' => array(
                        'type' => 'text',
                        'notnull' => 1,
                        'length' => 255,
                        'fixed' => 0,
                        'name' => 'name',
                        'table' => 'user_groups',
                        'flags' => 'not_null'
                    ),
                    'description' => array(
                        'type' => 'text',
                        'notnull' => 0,
                        'length' => 65535,
                        'fixed' => 0,
                        'name' => 'description',
                        'table' => 'user_groups',
                        'flags' => ''
                    ),
                    'perm_templ' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'perm_templ',
                        'table' => 'user_groups',
                        'flags' => 'not_null'
                    ),
                    'created_by' => array(
                        'type' => 'integer',
                        'notnull' => 0,
                        'unsigned' => 0,
                        'name' => 'created_by',
                        'table' => 'user_groups',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'user_groups',
                        'flags' => 'not_null'
                    ),
                    'updated_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'updated_at',
                        'table' => 'user_groups',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'unique_name' => array('name'),
                    'idx_perm_templ' => array('perm_templ'),
                    'idx_created_by' => array('created_by'),
                    'idx_name' => array('name')
                ),
                'foreign_keys' => array(
                    'fk_user_groups_perm_templ' => array(
                        'table' => 'perm_templ',
                        'fields' => array('perm_templ' => 'id')
                    ),
                    'fk_user_groups_created_by' => array(
                        'table' => 'users',
                        'fields' => array('created_by' => 'id'),
                        'ondelete' => 'SET NULL'
                    )
                )
            ),
            array(
                'table_name' => 'user_group_members',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 1,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'user_group_members',
                        'flags' => 'primary_keynot_null'
                    ),
                    'group_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 1,
                        'name' => 'group_id',
                        'table' => 'user_group_members',
                        'flags' => 'not_null'
                    ),
                    'user_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'user_id',
                        'table' => 'user_group_members',
                        'flags' => 'not_null'
                    ),
                    'added_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'added_at',
                        'table' => 'user_group_members',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'unique_member' => array('group_id', 'user_id'),
                    'idx_user_id' => array('user_id'),
                    'idx_group_id' => array('group_id')
                ),
                'foreign_keys' => array(
                    'fk_user_group_members_group' => array(
                        'table' => 'user_groups',
                        'fields' => array('group_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    ),
                    'fk_user_group_members_user' => array(
                        'table' => 'users',
                        'fields' => array('user_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    )
                )
            ),
            array(
                'table_name' => 'zones_groups',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 1,
                        'autoincrement' => 1,
                        'name' => 'id',
                        'table' => 'zones_groups',
                        'flags' => 'primary_keynot_null'
                    ),
                    'domain_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 0,
                        'name' => 'domain_id',
                        'table' => 'zones_groups',
                        'flags' => 'not_null'
                    ),
                    'group_id' => array(
                        'type' => 'integer',
                        'notnull' => 1,
                        'unsigned' => 1,
                        'name' => 'group_id',
                        'table' => 'zones_groups',
                        'flags' => 'not_null'
                    ),
                    'zone_templ_id' => array(
                        'type' => 'integer',
                        'notnull' => 0,
                        'unsigned' => 0,
                        'name' => 'zone_templ_id',
                        'table' => 'zones_groups',
                        'flags' => ''
                    ),
                    'created_at' => array(
                        'type' => 'timestamp',
                        'notnull' => 1,
                        'default' => 'current_timestamp',
                        'name' => 'created_at',
                        'table' => 'zones_groups',
                        'flags' => 'not_null'
                    )
                ),
                'indexes' => array(
                    'unique_zone_group' => array('domain_id', 'group_id'),
                    'idx_domain_id' => array('domain_id'),
                    'idx_group_id' => array('group_id'),
                    'idx_zone_templ_id' => array('zone_templ_id')
                ),
                'foreign_keys' => array(
                    'fk_zones_groups_domain' => array(
                        'table' => 'domains',
                        'fields' => array('domain_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    ),
                    'fk_zones_groups_group' => array(
                        'table' => 'user_groups',
                        'fields' => array('group_id' => 'id'),
                        'ondelete' => 'CASCADE'
                    ),
                    'fk_zones_groups_zone_templ' => array(
                        'table' => 'zone_templ',
                        'fields' => array('zone_templ_id' => 'id'),
                        'ondelete' => 'SET NULL'
                    )
                )
            )
        );
    }
}
