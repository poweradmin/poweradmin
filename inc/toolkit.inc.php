<?php
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 *  Toolkit functions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

include_once 'config-me.inc.php';
include_once 'tlds.inc.php';
include_once 'record-types.inc.php';

if (!@include_once("config.inc.php")) {
    error(_('You have to create a config.inc.php!'));
}

global $display_stats;
global $iface_rowamount;

if ($display_stats) {
    include_once('inc/benchmark.php');
}

ob_start();

require_once("error.inc.php");
require_once('inc/countrycodes.inc.php');

session_start();

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    define('ROWSTART', 0);
}

if (isset($_GET["letter"])) {
    define('LETTERSTART', $_GET["letter"]);
    $_SESSION["letter"] = $_GET["letter"];
} elseif (isset($_SESSION["letter"])) {
    define('LETTERSTART', $_SESSION["letter"]);
} else {
    define('LETTERSTART', "a");
}

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
    $_SESSION["zone_sort_by"] = $_GET["zone_sort_by"];
} elseif (isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
    $_SESSION["zone_sort_by"] = $_POST["zone_sort_by"];
} elseif (isset($_SESSION["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_SESSION["zone_sort_by"]);
} else {
    define('ZONE_SORT_BY', "name");
}

if (isset($_SESSION["userlang"])) {
    $iface_lang = $_SESSION["userlang"];
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    define('RECORD_SORT_BY', $_GET["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    define('RECORD_SORT_BY', $_POST["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
    define('RECORD_SORT_BY', "name");
}

// Database connection
require_once("database.inc.php");

// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");

/* * ***********
 * Includes  *
 * *********** */
$db = dbConnect();
require_once "plugin.inc.php";
require_once "i18n.inc.php";
require_once "auth.inc.php";
require_once "users.inc.php";
require_once "dns.inc.php";
require_once "record.inc.php";
require_once "dnssec.inc.php";
require_once "templates.inc.php";

//do_hook('hook_post_includes');
do_hook('authenticate');

/* * ***********
 * Functions *
 * *********** */

/** Print success message (toolkit.inc)
 *
 * @param string $msg Success message
 *
 * @return null
 */
function success($msg) {
    if ($msg) {
        echo "     <div class=\"success\">" . $msg . "</div>\n";
    } else {
        echo "     <div class=\"success\">" . _('Something has been successfully performed. What exactly, however, will remain a mystery.') . "</div>\n";
    }
}

/** Validate numeric string
 *
 * @param string $string number
 *
 * @return boolean true if number, false otherwise
 */
function v_num($string) {
    if (!preg_match("/^[0-9]+$/i", $string)) {
        return false;
    } else {
        return true;
    }
}
