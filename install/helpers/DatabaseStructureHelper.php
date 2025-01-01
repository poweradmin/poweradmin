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
                        'notnull' => 1,
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
                )
            ),
            array(
                'table_name' => 'migrations',
                'options' => array('type' => 'innodb'),
                'fields' => array(
                    'version' => array
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
                    'apply_time' => array
                    (
                        'notnull' => 1,
                        'fixed' => 0,
                        'default' => 0,
                        'type' => 'integer',
                        'name' => 'apply_time',
                        'table' => 'migrations',
                        'flags' => 'not_null'
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
                    ),
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
                        'table' => 'log_zones',
                        'flags' => ''
                    ),
                )
            ),
        );
    }
}
