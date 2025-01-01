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

use Poweradmin\AppConfiguration;

require_once __DIR__ . '/vendor/autoload.php';

$config = new AppConfiguration();

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'migrations',
        'default_environment' => $config->get('db_type'),
        'mysql'                   => [
            'adapter' => 'mysql',
            'host'    => $config->get('db_host'),
            'name'    => $config->get('db_name'),
            'user'    => $config->get('db_user'),
            'pass'    => $config->get('db_pass'),
            'port'    => $config->get('db_port'),
            'charset' => $config->get('db_charset'),
        ],
        'pgsql' => [
            'adapter' => 'pgsql',
            'host'    => $config->get('db_host'),
            'name'    => $config->get('db_name'),
            'user'    => $config->get('db_user'),
            'pass'    => $config->get('db_pass'),
            'port'    => $config->get('db_port'),
            'charset' => $config->get('db_charset'),
        ],
        'sqlite' => [
            'adapter' => 'sqlite',
            'name'    => $config->get('db_file'),
        ],

    ],
];