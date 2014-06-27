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
 *  Toolkit functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
// Fix for Strict Standards: Non-static method PEAR::setErrorHandling() should not be called statically
// TODO: remove after PEAR::MDB2 replacement with PDO
ini_set('error_reporting', E_ALL & ~ (E_NOTICE | E_STRICT));

// TODO: display elapsed time and memory consumption,
// used to check improvements in refactored version
$display_stats = false;
if ($display_stats)
    include('inc/benchmark.php');

ob_start();

require_once("error.inc.php");

if (!function_exists('session_start'))
    die(error('You have to install PHP session extension!'));
if (!function_exists('_'))
    die(error('You have to install PHP gettext extension!'));
if (!function_exists('mcrypt_encrypt'))
    die(error('You have to install PHP mcrypt extension!'));

session_start();

include_once("config-me.inc.php");

if (!@include_once("config.inc.php")) {
    error(_('You have to create a config.inc.php!'));
}

/* * ***********
 * Constants *
 * *********** */

if (isset($_GET["start"])) {
    define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
} else {
    /** Starting row
     */
    define('ROWSTART', 0);
}

if (isset($_GET["letter"])) {
    define('LETTERSTART', $_GET["letter"]);
    $_SESSION["letter"] = $_GET["letter"];
} elseif (isset($_SESSION["letter"])) {
    define('LETTERSTART', $_SESSION["letter"]);
} else {
    /** Starting letter
     */
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
    /** Field to sort zone by
     */
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
    /** Record to sort zone by
     */
    define('RECORD_SORT_BY', "name");
}

$valid_tlds = array("ac", "academy", "actor", "ad", "ae", "aero", "af", "ag",
    "agency", "ai", "al", "am", "an", "ao", "aq", "ar", "arpa", "as", "asia",
    "at", "au", "aw", "ax", "az", "ba", "bar", "bargains", "bb", "bd", "be",
    "berlin", "best", "bf", "bg", "bh", "bi", "bid", "bike", "biz", "bj", "blue",
    "bm", "bn", "bo", "boutique", "br", "bs", "bt", "build", "builders", "buzz",
    "bv", "bw", "by", "bz", "ca", "cab", "camera", "camp", "cards", "careers",
    "cat", "catering", "cc", "cd", "center", "ceo", "cf", "cg", "ch", "cheap",
    "christmas", "ci", "ck", "cl", "cleaning", "clothing", "club", "cm", "cn",
    "co", "codes", "coffee", "com", "community", "company", "computer", "condos",
    "construction", "contractors", "cool", "coop", "cr", "cruises", "cu", "cv",
    "cw", "cx", "cy", "cz", "dance", "dating", "de", "democrat", "diamonds",
    "directory", "dj", "dk", "dm", "do", "domains", "dz", "ec", "edu",
    "education", "ee", "eg", "email", "enterprises", "equipment", "er", "es",
    "estate", "et", "eu", "events", "expert", "exposed", "farm", "fi", "fish",
    "fj", "fk", "flights", "florist", "fm", "fo", "foundation", "fr", "futbol",
    "ga", "gallery", "gb", "gd", "ge", "gf", "gg", "gh", "gi", "gift", "gl",
    "glass", "gm", "gn", "gov", "gp", "gq", "gr", "graphics", "gs", "gt", "gu",
    "guitars", "guru", "gw", "gy", "hk", "hm", "hn", "holdings", "holiday",
    "house", "hr", "ht", "hu", "id", "ie", "il", "im", "immobilien", "in",
    "industries", "info", "institute", "int", "international", "io", "iq", "ir",
    "is", "it", "je", "jm", "jo", "jobs", "jp", "kaufen", "ke", "kg", "kh", "ki",
    "kim", "kitchen", "kiwi", "km", "kn", "koeln", "kp", "kr", "kred", "kw", "ky",
    "kz", "la", "land", "lb", "lc", "li", "lighting", "limo", "link", "lk", "lr",
    "ls", "lt", "lu", "luxury", "lv", "ly", "ma", "maison", "management", "mango",
    "marketing", "mc", "md", "me", "menu", "mg", "mh", "mil", "mk", "ml", "mm",
    "mn", "mo", "mobi", "moda", "monash", "mp", "mq", "mr", "ms", "mt", "mu",
    "museum", "mv", "mw", "mx", "my", "mz", "na", "nagoya", "name", "nc", "ne",
    "net", "neustar", "nf", "ng", "ni", "ninja", "nl", "no", "np", "nr", "nu",
    "nz", "okinawa", "om", "onl", "org", "pa", "partners", "parts", "pe", "pf",
    "pg", "ph", "photo", "photography", "photos", "pics", "pink", "pk", "pl",
    "plumbing", "pm", "pn", "post", "pr", "pro", "productions", "properties",
    "ps", "pt", "pub", "pw", "py", "qa", "qpon", "re", "recipes", "red",
    "rentals", "repair", "report", "reviews", "rich", "ro", "rs", "ru", "ruhr",
    "rw", "sa", "sb", "sc", "sd", "se", "sexy", "sg", "sh", "shiksha", "shoes",
    "si", "singles", "sj", "sk", "sl", "sm", "sn", "so", "social", "solar",
    "solutions", "sr", "st", "su", "supplies", "supply", "support", "sv", "sx",
    "sy", "systems", "sz", "tattoo", "tc", "td", "technology", "tel", "tf", "tg",
    "th", "tienda", "tips", "tj", "tk", "tl", "tm", "tn", "to", "today", "tokyo",
    "tools", "tp", "tr", "training", "travel", "tt", "tv", "tw", "tz", "ua", "ug",
    "uk", "uno", "us", "uy", "uz", "va", "vacations", "vc", "ve", "ventures",
    "vg", "vi", "viajes", "villas", "vision", "vn", "vote", "voting", "voto",
    "voyage", "vu", "wang", "watch", "wed", "wf", "wien", "wiki", "works", "ws",
    "xn--3bst00m", "xn--3ds443g", "xn--3e0b707e", "xn--45brj9c", "xn--55qw42g",
    "xn--55qx5d", "xn--6frz82g", "xn--6qq986b3xl", "xn--80ao21a", "xn--80asehdb",
    "xn--80aswg", "xn--90a3ac", "xn--c1avg", "xn--cg4bki",
    "xn--clchc0ea0b2g2a9gcd", "xn--d1acj3b", "xn--fiq228c5hs", "xn--fiq64b",
    "xn--fiqs8s", "xn--fiqz9s", "xn--fpcrj9c3d", "xn--fzc2c9e2c", "xn--gecrj9c",
    "xn--h2brj9c", "xn--i1b6b1a6a2e", "xn--io0a7i", "xn--j1amh", "xn--j6w193g",
    "xn--kprw13d", "xn--kpry57d", "xn--l1acc", "xn--lgbbat1ad8j", "xn--mgb9awbf",
    "xn--mgba3a4f16a", "xn--mgbaam7a8h", "xn--mgbab2bd", "xn--mgbayh7gpa",
    "xn--mgbbh1a71e", "xn--mgbc0a9azcg", "xn--mgberp4a5d4ar", "xn--mgbx4cd0ab",
    "xn--ngbc5azd", "xn--nqv7f", "xn--nqv7fs00ema", "xn--o3cw4h", "xn--ogbpf8fl",
    "xn--p1ai", "xn--pgbs0dh", "xn--q9jyb4c", "xn--s9brj9c", "xn--unup4y",
    "xn--wgbh1c", "xn--wgbl6a", "xn--xkc2al3hye2a", "xn--xkc2dl3a5ee0h",
    "xn--yfro4i67o", "xn--ygbi2ammx", "xn--zfr164b", "xxx", "xyz", "ye", "yt",
    "za", "zm", "zone", "zw");

// Special TLDs for testing and documentation purposes
// http://tools.ietf.org/html/rfc2606#section-2
array_push($valid_tlds, 'test', 'example', 'invalid', 'localhost');

/* Database connection */
require_once("database.inc.php");
// Generates $db variable to access database.
// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");

// $rtypes - array of possible record types
$rtypes = array(
    'A',
    'AAAA',
    'AFSDB',
    'CERT',
    'CNAME',
    'DHCID',
    'DLV',
    'DNSKEY',
    'DS',
    'EUI48',
    'EUI64',
    'HINFO',
    'IPSECKEY',
    'KEY',
    'KX',
    'LOC',
    'MINFO',
    'MR',
    'MX',
    'NAPTR',
    'NS',
    'NSEC',
    'NSEC3',
    'NSEC3PARAM',
    'OPT',
    'PTR',
    'RKEY',
    'RP',
    'RRSIG',
    'SOA',
    'SPF',
    'SRV',
    'SSHFP',
    'TLSA',
    'TSIG',
    'TXT',
    'WKS',
);

// If fancy records is enabled, extend this field.
if ($dns_fancy) {
    $rtypes[] = 'URL';
    $rtypes[] = 'MBOXFW';
    $rtypes[] = 'CURL';
}


/* * ***********
 * Includes  *
 * *********** */

require_once("i18n.inc.php");
require_once("auth.inc.php");
require_once("users.inc.php");
require_once("dns.inc.php");
require_once("record.inc.php");
require_once("dnssec.inc.php");
require_once("templates.inc.php");

$db = dbConnect();
doAuthenticate();


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
 * @param boolean $userid unknown usage
 *
 * @return null
 */
function show_letters($letterstart, $userid = true) {
    echo _('Show zones beginning with') . ":<br>";

    $letter = "[[:digit:]]";
    if ($letterstart == "1") {
        echo "<span class=\"lettertaken\">[ 0-9 ]</span> ";
    } elseif (zone_letter_start($letter, $userid)) {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=1\">[ 0-9 ]</a> ";
    } else {
        echo "[ <span class=\"letternotavailable\">0-9</span> ] ";
    }

    foreach (range('a', 'z') as $letter) {
        if ($letter == $letterstart) {
            echo "<span class=\"lettertaken\">[ " . $letter . " ]</span> ";
        } elseif (zone_letter_start($letter, $userid)) {
            echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=" . $letter . "\">[ " . $letter . " ]</a> ";
        } else {
            echo "[ <span class=\"letternotavailable\">" . $letter . "</span> ] ";
        }
    }

    if ($letterstart == '_') {
        echo "<span class=\"lettertaken\">[ _ ]</span> ";
    } elseif (zone_letter_start('_', $userid)) {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=_\">[ _ ]</a> ";
    } else {
        echo "[ <span class=\"letternotavailable\">_</span> ] ";
    }

    if ($letterstart == 'all') {
        echo "<span class=\"lettertaken\">[ Show all ]</span>";
    } else {
        echo "<a href=\"" . htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES) . "?letter=all\">[ Show all ]</a> ";
    }
}

/** Check if any zones start with letter
 *
 * @param string $letter Starting Letter
 * @param boolean $userid unknown usage
 *
 * @return int 1 if rows found, 0 otherwise
 */
function zone_letter_start($letter, $userid = true) {
    global $db;
    global $sql_regexp;
    $query = "SELECT
			domains.id AS domain_id,
			zones.owner,
			domains.name AS domainname
			FROM domains
			LEFT JOIN zones ON domains.id=zones.domain_id
			WHERE substring(domains.name,1,1) " . $sql_regexp . " " . $db->quote("^" . $letter, 'text');
    $db->setLimit(1);
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

/** Print active status
 *
 * @param int $res status, 0 for inactive, 1 active
 *
 * @return string html containing status
 */
function get_status($res) {
    if ($res == '0') {
        return "<FONT CLASS=\"inactive\">" . _('Inactive') . "</FONT>";
    } elseif ($res == '1') {
        return "<FONT CLASS=\"active\">" . _('Active') . "</FONT>";
    }
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

/** Set timezone (required for PHP5)
 *
 * Set timezone to configured tz or UTC it not set
 *
 * @return null
 */
function set_timezone() {
    global $timezone;

    if (function_exists('date_default_timezone_set')) {
        if (isset($timezone)) {
            date_default_timezone_set($timezone);
        } else if (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }
}

/** Generate random salt for encryption
 *
 * @param int $len salt length (default=5)
 *
 * @return string salt string
 */
function generate_salt($len = 5) {
    $valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
    $valid_len = strlen($valid_characters) - 1;
    $salt = "";

    for ($i = 0; $i < $len; $i++) {
        $salt .= $valid_characters[rand(0, $valid_len)];
    }

    return $salt;
}

/** Extract salt from password
 *
 * @param string $password salted password
 *
 * @return string salt
 */
function extract_salt($password) {
    return substr(strchr($password, ':'), 1);
}

/** Generate salted password
 *
 * @param string $salt salt
 * @param string $pass password
 *
 * @return string salted password
 */
function mix_salt($salt, $pass) {
    return md5($salt . $pass) . ':' . $salt;
}

/** Generate random salt and salted password
 *
 * @param string $pass password
 *
 * @return salted password
 */
function gen_mix_salt($pass) {
    $salt = generate_salt();
    return mix_salt($salt, $pass);
}


function do_log($syslog_message,$priority){
    global $syslog_use, $syslog_ident, $syslog_facility;
    if ($syslog_use) {
        openlog($syslog_ident, LOG_PERROR, $syslog_facility);
        syslog($priority, $syslog_message);
        closelog();
    }
}

function log_error($syslog_message) {
    do_log($syslog_message,LOG_ERR);
}

function log_warn($syslog_message) {
    do_log($syslog_message,LOG_WARNING);
}

function log_notice($syslog_message) {
    do_log($syslog_message,LOG_NOTICE);
}

function log_info($syslog_message) {
    do_log($syslog_message,LOG_INFO);
}
