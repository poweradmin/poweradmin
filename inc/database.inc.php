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

/**
 * Database functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

require_once 'PDOLayer.php';

/**  Connect to Database
 *
 * @return object $db Database object
 */
function dbConnect($isQuiet = true) {
    global $db_type;
    global $db_user;
    global $db_pass;
    global $db_host;
    global $db_port;
    global $db_name;
    global $db_charset;
    global $db_file;
    global $db_debug;

    global $sql_regexp;

    if (!(isset($db_type) && $db_type == 'mysql' || $db_type == 'mysqli' || $db_type == 'pgsql' || $db_type == 'sqlite' || $db_type == 'sqlite3')) {
        include_once("header.inc.php");
        if (!file_exists('install')) {
            error(_('No or unknown database type has been set in config.inc.php.'));
        }
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_user) && $db_user != "")) {
//        include_once("header.inc.php");
        error(_('No database username has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_pass) && $db_pass != '')) {
        include_once("header.inc.php");
        error(_('No database password has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_host) && $db_host != '')) {
        include_once("header.inc.php");
        error(_('No database host has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_name) && $db_name != '')) {
        include_once("header.inc.php");
        error(_('No database name has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_port)) || $db_port == '') {
        if ($db_type == "mysql" || $db_type == "mysqli") {
            $db_port = 3306;
        } else {
            $db_port = 5432;
        }
    }

    if (($db_type == 'sqlite' || $db_type == 'sqlite3') && (!(isset($db_file) && $db_file != ''))) {
        include_once("header.inc.php");
        error(_('No database file has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type == 'sqlite' || $db_type == 'sqlite3') {
        $dsn = "$db_type:$db_file";
    } else {
        $dsn = "$db_type:host=$db_host;port=$db_port;dbname=$db_name";
    }

    if ($db_type === 'mysql' && $db_charset === 'utf8') {
        $dsn .= ';charset=utf8';
    }

    $db = new PDOLayer($dsn, $db_user, $db_pass, [], $isQuiet);

    // http://stackoverflow.com/a/4361485/567193
    if ($db_type === 'mysql' && $db_charset === 'utf8' && version_compare(phpversion(), '5.3.6', '<')) {
        $db->exec('set names utf8');
    }

    if (isset($db_debug) && $db_debug) {
        $db->setOption('debug', 1);
    }

    /* erase info */
    $dsn = '';

    if ($db_type == 'mysql' || $db_type == 'mysqli') {
        $sql_regexp = "REGEXP";
    } else if ($db_type == 'sqlite' || $db_type == 'sqlite3') {
        $sql_regexp = 'GLOB';
    } elseif ($db_type == "pgsql") {
        $sql_regexp = "~";
    } else {
        include_once("header.inc.php");
        error(_('No or unknown database type has been set in config.inc.php.'));
        include_once("footer.inc.php");
        exit;
    }
    return $db;
}

// SUBSTR/SUBSTRING selector
function dbfunc_substr()
{
    global $db_type;
    if ($db_type == "sqlite") {
        return "SUBSTR";
    } else {
        return "SUBSTRING";
    }
}
