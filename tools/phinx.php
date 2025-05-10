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

/**
 * Phinx configuration script for Poweradmin database migrations
 *
 * Usage: vendor/bin/phinx migrate -c tools/phinx.php
 */

// Ensure script is only run from command line
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit('This script can only be executed from the command line.');
}

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

require_once __DIR__ . '/../vendor/autoload.php';

$config = ConfigurationManager::getInstance();
$config->initialize();

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/../db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/../db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'migrations',
        'default_environment' => $config->get('database', 'type'),
        'mysql'                   => [
            'adapter' => 'mysql',
            'host'    => $config->get('database', 'host'),
            'name'    => $config->get('database', 'name'),
            'user'    => $config->get('database', 'user'),
            'pass'    => $config->get('database', 'password'),
            'port'    => $config->get('database', 'port'),
            'charset' => $config->get('database', 'charset'),
        ],
        'pgsql' => [
            'adapter' => 'pgsql',
            'host'    => $config->get('database', 'host'),
            'name'    => $config->get('database', 'name'),
            'user'    => $config->get('database', 'user'),
            'pass'    => $config->get('database', 'password'),
            'port'    => $config->get('database', 'port'),
            'charset' => $config->get('database', 'charset'),
        ],
        'sqlite' => [
            'adapter' => 'sqlite',
            'name'    => $config->get('database', 'file'),
        ],

    ],
];