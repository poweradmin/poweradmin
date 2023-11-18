<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

include_once 'config-defaults.inc.php';

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\DependencyCheck;
use Poweradmin\LegacyConfiguration;

if (!file_exists('config.inc.php')) {
    $error = new ErrorMessage(_('The configuration file (config.inc.php) does not exist. Please use the <a href="install/">installer</a> to create it.'));
    $errorPresenter = new ErrorPresenter();
    $errorPresenter->present($error);
    exit();
}

session_start();

DependencyCheck::verifyExtensions();

require_once 'inc/authenticate.php';
require_once 'inc/users.php';
require_once 'i18n.inc.php';

$config = new LegacyConfiguration();

$credentials = [
    'db_host' => $config->get('db_host'),
    'db_port' => $config->get('db_port'),
    'db_user' => $config->get('db_user'),
    'db_pass' => $config->get('db_pass'),
    'db_name' => $config->get('db_name'),
    'db_charset' => $config->get('db_charset'),
    'db_collation' => $config->get('db_collation'),
    'db_type' => $config->get('db_type'),
    'db_file' => $config->get('db_file'),
];

$databaseConnection = new PDODatabaseConnection();
$databaseService = new DatabaseService($databaseConnection);
$db = $databaseService->connect($credentials);

authenticate();
