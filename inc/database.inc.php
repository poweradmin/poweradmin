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

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\PDOLayer;

function dbConnect(array $databaseCredentials, $isQuiet = true, $installerMode = false): PDOLayer
{
    validateDatabaseType($databaseCredentials['db_type'], $installerMode);

    if (in_array($databaseCredentials['db_type'], ['sqlite', 'sqlite3'])) {
        validateSQLiteCredentials($databaseCredentials, $installerMode);
    } else {
        validateCredentialsForNonSQLite($databaseCredentials, $installerMode);
    }

    $dsn = constructDSN($databaseCredentials);

    $db = new PDOLayer($dsn, $databaseCredentials['db_user'], $databaseCredentials['db_pass'], [], $isQuiet);
    if (isset($databaseCredentials['db_debug']) && $databaseCredentials['db_debug']) {
        $db->setOption('debug', 1);
    }

    unset($dsn);

    global $sql_regexp;
    $sql_regexp = determineSQLRegexp($databaseCredentials['db_type']);

    return $db;
}

function validateDatabaseType($db_type, $installerMode): void
{
    if (!in_array($db_type, ['mysql', 'mysqli', 'pgsql', 'sqlite', 'sqlite3'])) {
        showErrorAndExit(_('No or unknown database type has been set in config.inc.php.'), $installerMode);
    }
}

function showErrorAndExit($message, $installerMode): void
{
    if (!$installerMode) {
        include_once("header.inc.php");
    }

    if ($installerMode || file_exists('inc/config.inc.php')) {
        $error = new ErrorMessage($message);
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
    }

    if (!$installerMode) {
        include_once("footer.inc.php");
    }

    exit;
}

function validateCredentialsForNonSQLite($credentials, $installerMode): void
{
    foreach (['db_user', 'db_pass', 'db_host', 'db_name'] as $key) {
        if (empty($credentials[$key])) {
            showErrorAndExit(_("No $key has been set in config.inc.php."), $installerMode);
        }
    }
}

function validateSQLiteCredentials($credentials, $installerMode): void
{
    if (empty($credentials['db_file'])) {
        showErrorAndExit(_('No database file has been set in config.inc.php.'), $installerMode);
    }
}

function constructDSN($credentials): string
{
    $db_type = $credentials['db_type'];
    $db_port = $credentials['db_port'] ?? getDefaultPort($db_type);

    if ($db_type === 'sqlite' || $db_type === 'sqlite3') {
        return "$db_type:{$credentials['db_file']}";
    } else {
        $dsn = "$db_type:host={$credentials['db_host']};port=$db_port;dbname={$credentials['db_name']}";

        if ($db_type === 'mysql' && $credentials['db_charset'] === 'utf8') {
            $dsn .= ';charset=utf8';
        }

        return $dsn;
    }
}

function getDefaultPort($db_type): ?int
{
    return match ($db_type) {
        'mysql', 'mysqli' => 3306,
        'pgsql' => 5432,
        default => null,
    };
}

function determineSQLRegexp($db_type): string
{
    return match ($db_type) {
        'mysql', 'mysqli' => 'REGEXP',
        'sqlite', 'sqlite3' => 'GLOB',
        'pgsql' => '~',
        default => throw new Exception("Unsupported database type for regular expressions: $db_type"),
    };
}

