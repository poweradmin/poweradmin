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

// Special TLDs for testing and documentation purposes
// http://tools.ietf.org/html/rfc2606#section-2
array_push($valid_tlds, 'test', 'example', 'invalid', 'localhost');

// Database connection
require_once("database.inc.php");

// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");

// The following is a list of supported record types by PowerDNS
// https://doc.powerdns.com/authoritative/appendices/types.html

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

/** Print paging menu
 *
 * Display the page option: [ < ][ 1 ] .. [ 8 ][ 9 ][ 10 ][ 11 ][ 12 ][ 13 ][ 14 ][ 15 ][ 16 ] .. [ 34 ][ > ]
 *
 * @param int $amount Total number of items
 * @param int $rowamount Per page number of items
 * @param int $id Page specific ID (Zone ID, Template ID, etc)
 *
 * @return null
 */
function show_pages($amount, $rowamount, $id = '') {
    if ($amount > $rowamount) {
        $num = 8;
        $poutput = '';
        $lastpage = ceil($amount / $rowamount);
        $startpage = 1;

        if (!isset($_GET["start"]))
            $_GET["start"] = 1;
        $start = $_GET["start"];

        if ($lastpage > $num & $start > ($num / 2)) {
            $startpage = ($start - ($num / 2));
        }

        echo _('Show page') . ":<br>";

        if ($lastpage > $num & $start > 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start - 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ < ]';
            $poutput .= '</a>';
        }
        if ($start != 1) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=1';
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ 1 ]';
            $poutput .= '</a>';
            if ($startpage > 2)
                $poutput .= ' .. ';
        }

        for ($i = $startpage; $i <= min(($startpage + $num), $lastpage); $i++) {
            if ($start == $i) {
                $poutput .= '[ <b>' . $i . '</b> ]';
            } elseif ($i != $lastpage & $i != 1) {
                $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
                $poutput .= '?start=' . $i;
                if ($id != '')
                    $poutput .= '&id=' . $id;
                $poutput .= '">';
                $poutput .= '[ ' . $i . ' ]';
                $poutput .= '</a>';
            }
        }

        if ($start != $lastpage) {
            if (min(($startpage + $num), $lastpage) < ($lastpage - 1))
                $poutput .= ' .. ';
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . $lastpage;
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ ' . $lastpage . ' ]';
            $poutput .= '</a>';
        }

        if ($lastpage > $num & $start < $lastpage) {
            $poutput .= '<a href=" ' . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES);
            $poutput .= '?start=' . ($start + 1);
            if ($id != '')
                $poutput .= '&id=' . $id;
            $poutput .= '">';
            $poutput .= '[ > ]';
            $poutput .= '</a>';
        }

        echo $poutput;
    }
}

/** Print alphanumeric paging menu
 *
 * Display the alphabetic option: [0-9] [a] [b] .. [z]
 *
 * @param string $letterstart Starting letter/number or 'all'
 * @param int $userid Current user ID
 *
 * @return null
 */
function show_letters($letterstart, $userid) {
    global $db;

    $char_range = array_merge(range('a', 'z'), array('_'));

    $allowed = zone_content_view_others($userid);

    $query = "SELECT
			DISTINCT ".dbfunc_substr()."(domains.name, 1, 1) AS letter
			FROM domains
			LEFT JOIN zones ON domains.id = zones.domain_id
			WHERE " . $allowed . " = 1
			OR zones.owner = " . $userid . "
			ORDER BY 1";
    $db->setLimit(36);

    $available_chars = array();
    $digits_available = 0;

    $response = $db->query($query);

    while ($row = $response->fetchRow()) {
        if (preg_match("/[0-9]/", $row['letter'])) {
            $digits_available = 1;
        } elseif (in_array($row['letter'], $char_range)) {
            array_push($available_chars, $row['letter']);
        }
    }

    echo _('Show zones beginning with') . ":<br>";

    if ($letterstart == "1") {
        echo "<span class=\"lettertaken\">[ 0-9 ]</span> ";
    } elseif ($digits_available) {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=1\">[ 0-9 ]</a> ";
    } else {
        echo "[ <span class=\"letternotavailable\">0-9</span> ] ";
    }

    foreach ($char_range as $letter) {
        if ($letter == $letterstart) {
            echo "<span class=\"lettertaken\">[ " . $letter . " ]</span> ";
        } elseif (in_array($letter, $available_chars)) {
            echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=" . $letter . "\">[ " . $letter . " ]</a> ";
        } else {
            echo "[ <span class=\"letternotavailable\">" . $letter . "</span> ] ";
        }
    }

    if ($letterstart == 'all') {
        echo "<span class=\"lettertaken\">[ Show all ]</span>";
    } else {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=all\">[ Show all ]</a> ";
    }
}

/** Check if current user allowed to view any zone content
 *
 * @param int $userid Current user ID
 *
 * @return int 1 if user has permission to view other users zones content, 0 otherwise
 */
function zone_content_view_others($userid) {
    global $db;

    $query = "SELECT
		DISTINCT u.id
		FROM 	users u,
		        perm_templ pt,
		        perm_templ_items pti,
		        (SELECT id FROM perm_items WHERE name
			    IN ('zone_content_view_others', 'user_is_ueberuser')) pit
                WHERE u.id = " . $userid . "
                AND u.perm_templ = pt.id
                AND pti.templ_id = pt.id
                AND pti.perm_id  = pit.id";

    $result = $db->queryOne($query);

    return ($result ? 1 : 0);
}

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

/** Print message
 *
 * Something has been done nicely, display a message and a back button.
 *
 * @param string $msg Message
 *
 * @return null
 */
function message($msg) {
    include_once("header.inc.php");
    ?>
    <P><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2><?php echo _('Success!'); ?></H2>
                <BR>
                <FONT STYLE="font-weight: Bold">
                <P>
                    <?php
                    if ($msg) {
                        echo nl2br($msg);
                    } else {
                        echo _('Successful!');
                    }
                    ?>
                </P>
                <BR>
                <P>
                    <a href="javascript:history.go(-1)">&lt;&lt; <?php echo _('back'); ?></a></FONT>
                </P>
            </TD></TR></TABLE></P>
    <?php
    include_once("footer.inc.php");
}

/** Send 302 Redirect with optional argument
 *
 * Reroute a user to a cleanpage of (if passed) arg
 *
 * @param string $arg argument string to add to url
 *
 * @return null
 */
function clean_page($arg = '') {
    if (!$arg) {
        header("Location: " . htmlentities($_SERVER['SCRIPT_NAME'], ENT_QUOTES) . "?time=" . time());
        exit;
    } else {
        if (preg_match('!\?!si', $arg)) {
            $add = "&time=";
        } else {
            $add = "?time=";
        }
        header("Location: $arg$add" . time());
        exit;
    }
}

/** Validate user name string
 *
 * @param string $username user name string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_username($username) {
    return $username != '';
}

/** Validate email address string
 *
 * @param string $address email address string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_email($address) {
    $fields = preg_split("/@/", $address, 2);
    if ((!preg_match("/^[0-9a-z]([-_.]?[0-9a-z])*$/i", $fields[0])) || (!isset($fields[1]) || $fields[1] == '' || !is_valid_hostname_fqdn($fields[1], 0))) {
        return false;
    }
    return true;
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

/** Debug print
 *
 * @param string $var debug statement
 *
 * @return null
 */
function debug_print($var) {
    echo "<pre style=\"border: 2px solid blue;\">\n";
    if (is_array($var)) {
        print_r($var);
    } else {
        echo $var;
    }
    echo "</pre>\n";
}

function do_log($syslog_message, $priority) {
    global $syslog_use, $syslog_ident, $syslog_facility;
    if ($syslog_use) {
        openlog($syslog_ident, LOG_PERROR, $syslog_facility);
        syslog($priority, $syslog_message);
        closelog();
    }
}

function log_error($syslog_message) {
    do_log($syslog_message, LOG_ERR);
}

function log_warn($syslog_message) {
    do_log($syslog_message, LOG_WARNING);
}

function log_notice($syslog_message) {
    do_log($syslog_message, LOG_NOTICE);
}

function log_info($syslog_message) {
    do_log($syslog_message, LOG_INFO);
}

/** Print the login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='success', 'error']
 *
 * @return null
 */
function auth($msg = "", $type = "success") {
    include_once 'inc/header.inc.php';
    include_once 'inc/config.inc.php';
    global $iface_lang;

    if ($msg) {
        print "<div class=\"$type\">$msg</div>\n";
    }
    ?>
    <h2><?php echo _('Log in'); ?></h2>
    <form method="post" action="<?php echo htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES); ?>">
        <input type="hidden" name="query_string" value="<?php echo htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <table border="0">
            <tr>
                <td class="n" width="100"><?php echo _('Username'); ?>:</td>
                <td class="n"><input type="text" class="input" name="username" id="username"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Password'); ?>:</td>
                <td class="n"><input type="password" class="input" name="password"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Language'); ?>:</td>
                <td class="n">
                    <select class="input" name="userlang">
                        <?php
                        // List available languages (sorted alphabetically)
                        $locales = scandir('locale/');
                        foreach ($locales as $locale) {
                            if (strlen($locale) == 5) {
                                $locales_fullname[$locale] = get_country_code($locale);
                            }
                        }
                        asort($locales_fullname);
                        foreach ($locales_fullname as $locale => $language) {
                            if (substr($locale, 0, 2) == substr($iface_lang, 0, 2)) {
                                echo _('<option selected value="' . $locale . '">' . $language);
                            } else {
                                echo _('<option value="' . $locale . '">' . $language);
                            }
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="n">&nbsp;</td>
                <td class="n">
                    <input type="submit" name="authenticate" class="button" value=" <?php echo _('Go'); ?> ">
                </td>
            </tr>
        </table>
    </form>
    <script type="text/javascript">
        <!--
      document.getElementById('username').focus();
        //-->
    </script>
    <?php
    include_once('inc/footer.inc.php');
    exit;
}

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout($msg = "", $type = "") {
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}
