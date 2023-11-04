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
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\DependencyCheck;

if (!@include_once('config.inc.php')) {
    if (!file_exists('install')) {
        $error = new ErrorMessage(_('You have to create a config.inc.php!'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
    }
}

session_start();

require_once 'database.inc.php';
require_once 'inc/authenticate.php';
require_once 'inc/users.php';
require_once 'i18n.inc.php';

DependencyCheck::verifyExtensions();

global $db_host, $db_port, $db_user, $db_pass, $db_name, $db_charset, $db_collation, $db_type, $db_file;

$databaseCredentials = [
    'db_host' => $db_host,
    'db_port' => $db_port,
    'db_user' => $db_user,
    'db_pass' => $db_pass,
    'db_name' => $db_name,
    'db_charset' => $db_charset,
    'db_collation' => $db_collation,
    'db_type' => $db_type,
    'db_file' => $db_file,
];

$db = dbConnect($databaseCredentials);

authenticate();
