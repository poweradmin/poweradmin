<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Database functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
global $db_layer;

// if DB abstraction layer is not defined in configuration file then try to
// auto-configure otherwise fail gracefully

if (!isset($db_layer)) {
    // Use MDB2 by default because PDO support is quite experimental
    if (@include_once 'MDB2.php') {
        $db_layer = 'MDB2';
    } elseif (class_exists('PDO', false)) {
        $db_layer = 'PDO';
        include_once 'PDOLayer.php';
    } else {
        die(error('You have to install MDB2 or PDO library!'));
    }
} else {
    if ($db_layer == 'MDB2') {
        (@include_once 'MDB2.php') or die(error('You have to install MDB2 library!'));
    }

    if ($db_layer == 'PDO') {
        if (class_exists('PDO', false)) {
            include_once 'PDOLayer.php';
        } else {
            die(error('You have to install PDO library!'));
        }
    }
}

/** Print database error message
 *
 * @param object $msg Database error object
 */
function dbError($msg) {
    $debug = $msg->getDebugInfo();

    if (preg_match("/Unknown column 'zone_templ_id'/", $debug)) {
        $debug = ERR_DB_NO_DB_UPDATE;
    }

    echo "     <div class=\"error\">Error: " . $debug . "</div>\n";
    include_once("footer.inc.php");
    die();
}

if (isset($db_layer) && $db_layer == 'MDB2') {
    @PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'dbError');
}

/**  Connect to Database
 *
 * @return object $db Database object
 */
function dbConnect() {
    // XXX: one day all globals will die, I promise
    global $db_type;
    global $db_user;
    global $db_pass;
    global $db_host;
    global $db_port;
    global $db_name;
    global $db_file;
    global $db_layer;
    global $db_debug;
    global $db_ssl_ca;

    global $sql_regexp;

    if (!(isset($db_type) && $db_type == 'mysql' || $db_type == 'mysqli' || $db_type == 'pgsql' || $db_type == 'sqlite' || $db_type == 'sqlite3' || $db_type == 'oci8')) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_TYPE);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_user) && $db_user != "")) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_USER);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_pass) && $db_pass != '')) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_PASS);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_host) && $db_host != '')) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_HOST);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_name) && $db_name != '')) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_NAME);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_type != 'sqlite' && $db_type != 'sqlite3' && !(isset($db_port)) || $db_port == '') {
        if ($db_type == "mysql" || $db_type == "mysqli") {
            $db_port = 3306;
        } else if ($db_type == 'oci8') {
            $db_port = 1521;
        } else {
            $db_port = 5432;
        }
    }

    if (($db_type == 'sqlite' || $db_type == 'sqlite3') && (!(isset($db_file) && $db_file != ''))) {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_FILE);
        include_once("footer.inc.php");
        exit;
    }

    if ($db_layer == 'MDB2') {
        if ($db_type == 'sqlite') {
            $dsn = "$db_type:///$db_file";
        } else if ($db_type == 'sqlite3') {
            $dsn = "pdoSqlite:///$db_file";
        } else {
            if ($db_type == 'oci8') {
                $db_name = '?service=' . $db_name;
            }
            $dsn = "$db_type://$db_user:$db_pass@$db_host:$db_port/$db_name";
            if (($db_type == 'mysqli') && (isset($db_ssl_ca))) {
                $dsn .= "?ca=$db_ssl_ca";
            }
        }
    }

    if ($db_layer == 'PDO') {
        if ($db_type == 'sqlite' || $db_type == 'sqlite3') {
            $dsn = "$db_type:$db_file";
        } else {
            if ($db_type == 'oci8') {
                $db_name = '?service=' . $db_name;
            }
            $dsn = "$db_type:host=$db_host;port=$db_port;dbname=$db_name";
        }
    }

    if ($db_layer == 'MDB2') {
        $options = array(
            'portability' => MDB2_PORTABILITY_ALL ^ MDB2_PORTABILITY_EMPTY_TO_NULL,
        );

        if (($db_type == 'mysqli') && (isset($db_ssl_ca))) {
            $options['ssl'] = true;
        }

        $db = MDB2::connect($dsn, $options);
    }

    if ($db_layer == 'PDO') {
        $db = new PDOLayer($dsn, $db_user, $db_pass);
    }

    if (isset($db_debug) && $db_debug) {
        $db->setOption('debug', 1);
    }

    // FIXME - it's strange, but this doesn't work, perhaps bug in MDB2 library
    if (@PEAR::isError($db)) {
        // Error handling should be put.
        error(MYSQL_ERROR_FATAL, $db->getMessage());
    }

    // Do an ASSOC fetch. Gives us the ability to use ["id"] fields.
    if ($db_layer == 'MDB2') {
        $db->setFetchMode(MDB2_FETCHMODE_ASSOC);
    }

    /* erase info */
    $dsn = '';

    // Add support for regular expressions in both MySQL and PostgreSQL
    if ($db_type == 'mysql' || $db_type == 'mysqli' || $db_type == 'sqlite' || $db_type == 'sqlite3') {
        $sql_regexp = "REGEXP";
    } elseif ($db_type == "oci8") {
        # TODO: what is regexp syntax in Oracle?
        $sql_regexp = "";
    } elseif ($db_type == "pgsql") {
        $sql_regexp = "~";
    } else {
        include_once("header.inc.php");
        error(ERR_DB_NO_DB_TYPE);
        include_once("footer.inc.php");
        exit;
    }
    return $db;
}
