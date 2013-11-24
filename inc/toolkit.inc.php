<?php

// TODO: display elapsed time and memory consumption,
// used to check improvements in refactored version 
$display_stats = false;
if ($display_stats) include('inc/benchmark.php');

ob_start();
/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2012  Poweradmin Development Team
 *      <https://www.poweradmin.org/trac/wiki/Credits>
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

if (! function_exists('session_start')) die(error('You have to install PHP session extension!'));
if (! function_exists('_')) die(error('You have to install PHP gettext extension!'));
if (! function_exists('mcrypt_encrypt')) die(error('You have to install PHP mcrypt extension!'));

session_start();

include_once("config-me.inc.php");

if(!@include_once("config.inc.php"))
{
	error( _('You have to create a config.inc.php!') );
}

/*************
 * Constants *
 *************/

if (isset($_GET["start"])) {
   define('ROWSTART', (($_GET["start"] - 1) * $iface_rowamount));
   } else {
   define('ROWSTART', 0);
}

if (isset($_GET["letter"])) {
   define('LETTERSTART', $_GET["letter"]);
   $_SESSION["letter"] = $_GET["letter"];
} elseif(isset($_SESSION["letter"])) {
   define('LETTERSTART', $_SESSION["letter"]);
} else {
   define('LETTERSTART', "a");
}

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"] ) ) {
   define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
   $_SESSION["zone_sort_by"] = $_GET["zone_sort_by"];
} elseif(isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"] )) {
   define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
   $_SESSION["zone_sort_by"] = $_POST["zone_sort_by"];
} elseif(isset($_SESSION["zone_sort_by"])) {
   define('ZONE_SORT_BY', $_SESSION["zone_sort_by"]);
} else {
   define('ZONE_SORT_BY', "name");
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"] )) {
   define('RECORD_SORT_BY', $_GET["record_sort_by"]);
   $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif(isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"] )) {
   define('RECORD_SORT_BY', $_POST["record_sort_by"]);
   $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif(isset($_SESSION["record_sort_by"])) {
   define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
   define('RECORD_SORT_BY', "name");
}

$valid_tlds = array(
  "localhost", "ac", "ad", "ae", "aero", "af", "ag", "ai", "al", "am", "an", "ao", "aq", "ar",
  "arpa", "as", "asia", "at", "au", "aw", "ax", "az", "ba", "bb", "bd", "be",
  "bf", "bg", "bh", "bi", "biz", "bj", "bm", "bn", "bo", "br", "bs", "bt", "bv",
  "bw", "by", "bz", "ca", "cat", "cc", "cd", "cf", "cg", "ch", "ci", "ck", "cl",
  "cm", "cn", "co", "com", "coop", "cr", "cu", "cv", "cw", "cx", "cy", "cz", "de", "dj",
  "dk", "dm", "do", "dz", "ec", "edu", "ee", "eg", "er", "es", "et", "eu", "fi",
  "fj", "fk", "fm", "fo", "fr", "ga", "gb", "gd", "ge", "gf", "gg", "gh", "gi",
  "gl", "gm", "gn", "gov", "gp", "gq", "gr", "gs", "gt", "gu", "gw", "gy", "hk",
  "hm", "hn", "hr", "ht", "hu", "id", "ie", "il", "im", "in", "info", "int", "io",
  "iq", "ir", "is", "it", "je", "jm", "jo", "jobs", "jp", "ke", "kg", "kh", "ki",
  "km", "kn", "kp", "kr", "kw", "ky", "kz", "la", "lb", "lc", "li", "lk", "lr",
  "ls", "lt", "lu", "lv", "ly", "ma", "mc", "md", "me", "mg", "mh", "mil", "mk",
  "ml", "mm", "mn", "mo", "mobi", "mp", "mq", "mr", "ms", "mt", "mu", "museum",
  "mv", "mw", "mx", "my", "mz", "na", "name", "nc", "ne", "net", "nf", "ng", "ni",
  "nl", "no", "np", "nr", "nu", "nz", "om", "org", "pa", "pe", "pf", "pg", "ph",
  "pk", "pl", "pm", "pn", "pr", "pro", "ps", "pt", "pw", "py", "qa", "re", "ro",
  "rs", "ru", "rw", "sa", "sb", "sc", "sd", "se", "sg", "sh", "si", "sj", "sk",
  "sl", "sm", "sn", "so", "sr", "st", "su", "sv", "sx", "sy", "sz", "tc", "td", "tel",
  "tf", "tg", "th", "tj", "tk", "tl", "tm", "tn", "to", "tp", "tr", "travel",
  "tt", "tv", "tw", "tz", "ua", "ug", "uk", "us", "uy", "uz", "va", "vc",
  "ve", "vg", "vi", "vn", "vu", "wf", "ws", "xn--0zwm56d", "xn--11b5bs3a9aj6g",
  "xn--3e0b707e", "xn--45brj9c", "xn--80akhbyknj4f", "xn--80ao21a", "xn--90a3ac",
  "xn--9t4b11yi5a", "xn--clchc0ea0b2g2a9gcd", "xn--deba0ad", "xn--fiqs8s",
  "xn--fiqz9s", "xn--fpcrj9c3d", "xn--fzc2c9e2c", "xn--g6w251d", "xn--gecrj9c",
  "xn--h2brj9c", "xn--hgbk6aj7f53bba", "xn--hlcj6aya9esc7a", "xn--j6w193g",
  "xn--jxalpdlp", "xn--kgbechtv", "xn--kprw13d", "xn--kpry57d", "xn--lgbbat1ad8j",
  "xn--mgbaam7a8h", "xn--mgbayh7gpa", "xn--mgbbh1a71e", "xn--mgbc0a9azcg",
  "xn--mgberp4a5d4ar", "xn--o3cw4h", "xn--ogbpf8fl", "xn--p1ai", "xn--pgbs0dh",
  "xn--s9brj9c", "xn--wgbh1c", "xn--wgbl6a", "xn--xkc2al3hye2a", "xn--xkc2dl3a5ee0h",
  "xn--yfro4i67o", "xn--ygbi2ammx", "xn--zckzah", "xxx", "ye", "yt", "za", "zm", "zw");

/* Database connection */

require_once("database.inc.php");
// Generates $db variable to access database.


// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");

// $rtypes - array of possible record types
$rtypes = array('A', 'AAAA', 'CNAME', 'HINFO', 'MX', 'NAPTR', 'NS', 'PTR', 'SOA', 'SPF', 'SRV', 'SSHFP', 'TXT', 'RP');

// If fancy records is enabled, extend this field.
if($dns_fancy) {
	$rtypes[14] = 'URL';
	$rtypes[15] = 'MBOXFW';
	$rtypes[16] = 'CURL';
	$rtypes[17] = 'LOC';
}


/*************
 * Includes  *
 *************/

require_once("i18n.inc.php");
require_once("error.inc.php");
require_once("auth.inc.php");
require_once("users.inc.php");
require_once("dns.inc.php");
require_once("record.inc.php");
require_once("templates.inc.php");

$db = dbConnect();
doAuthenticate();


/*************
 * Functions *
 *************/

/*
 * Display the page option: [1] [2] .. [n]
 */

function show_pages($amount,$rowamount,$id='')
{
   if ($amount > $rowamount) {
      if (!isset($_GET["start"])) $_GET["start"]=1;
      echo _('Show page') . ":<br>";
      for ($i=1;$i<=ceil($amount / $rowamount);$i++) {
         if ($_GET["start"] == $i) {
            echo "[ <b>".$i."</b> ] ";
         } else {
            echo " <a href=\"".htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES)."?start=".$i;
	    if ($id!='') echo "&id=".$id;
	    echo "\">[ ".$i." ]</a> ";
         }
      }
   }
}

/*
 * Display the alphabetic option: [0-9] [a] [b] .. [z]
 */

function show_letters($letterstart,$userid=true)
{
        echo _('Show zones beginning with') . ":<br>";

	$letter = "[[:digit:]]";
	if ($letterstart == "1")
	{
		echo "[ <span class=\"lettertaken\">0-9</span> ] ";
	}
	elseif (zone_letter_start($letter,$userid))
	{
		echo "<a href=\"".htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES)."?letter=1\">[ 0-9 ]</a> ";
	}
	else
	{
		echo "[ <span class=\"letternotavailable\">0-9</span> ] ";
	}

        foreach (range('a','z') as $letter)
        {
                if ($letter == $letterstart)
                {
                        echo "[ <span class=\"lettertaken\">".$letter."</span> ] ";
                }
                elseif (zone_letter_start($letter,$userid))
                {
                        echo "<a href=\"".htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES)."?letter=".$letter."\">[ ".$letter." ]</a> ";
                }
                else
                {
                        echo "[ <span class=\"letternotavailable\">".$letter."</span> ] ";
                }
        }

	if ($letterstart == 'all')
	{
		echo "[ <span class=\"lettertaken\"> Show all </span> ] ";
	} else {
		echo "<a href=\"".htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES)."?letter=all\">[ Show all ]</a> ";
	}
}

function zone_letter_start($letter,$userid=true)
{
        global $db;
	global $sql_regexp;
        $query = "SELECT 
			domains.id AS domain_id,
			zones.owner,
			domains.name AS domainname
			FROM domains
			LEFT JOIN zones ON domains.id=zones.domain_id 
			WHERE substring(domains.name,1,1) ".$sql_regexp." ".$db->quote("^".$letter, 'text');
	$db->setLimit(1);
        $result = $db->query($query);
        $numrows = $result->numRows();
        if ( $numrows == "1" ) {
                return 1;
        } else {
                return 0;
        }
}

function error($msg) {
	if ($msg) {
		echo "     <div class=\"error\">Error: " . $msg . "</div>\n";
	} else {
		echo "     <div class=\"error\">" . _('An unknown error has occurred.') . "</div>\n"; 
	}
}

function success($msg) {
	if ($msg) {
		echo "     <div class=\"success\">" . $msg . "</div>\n";
	} else {
		echo "     <div class=\"success\">" . _('Something has been successfully performed. What exactly, however, will remain a mystery.') . "</div>\n"; 
	}
}


/*
 * Something has been done nicely, display a message and a back button.
 */
function message($msg)
{
    include_once("header.inc.php");
    ?>
    <P><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2><?php echo _('Success!'); ?></H2>
    <BR>
	<FONT STYLE="font-weight: Bold">
	<P>
	<?php
    if($msg)
    {
        echo nl2br($msg);
    }
    else
    {
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


/*
 * Reroute a user to a cleanpage of (if passed) arg
 */

function clean_page($arg='')
{
	if (!$arg)
	{
		header("Location: ".htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES)."?time=".time());
		exit;
	}
	else
	{
		if (preg_match('!\?!si', $arg))
		{
			$add = "&time=";
		}
		else
		{
			$add = "?time=";
		}
		header("Location: $arg$add".time());
		exit;
	}
}


function get_status($res)
{
	if ($res == '0')
	{
		return "<FONT CLASS=\"inactive\">" . _('Inactive') . "</FONT>";
	}
	elseif ($res == '1')
	{
		return "<FONT CLASS=\"active\">" . _('Active') . "</FONT>";
	}
}

function parse_template_value($val, $domain)
{
	$serial = date("Ymd");
	$serial .= "00";

	$val = str_replace('[ZONE]', $domain, $val);
	$val = str_replace('[SERIAL]', $serial, $val);
	return $val;
}


function is_valid_email($address) {
	$fields = preg_split("/@/", $address, 2);
	if((!preg_match("/^[0-9a-z]([-_.]?[0-9a-z])*$/i", $fields[0])) || (!isset($fields[1]) || $fields[1] == '' || !is_valid_hostname_fqdn($fields[1], 0))) {
		return false;
	}
	return true;
}


function v_num($string) {
	if (!preg_match("/^[0-9]+$/i", $string)) { 
		return false ;
	} else {
		return true ;
	}
}

// Debug print
function debug_print($var) {
	echo "<pre style=\"border: 2px solid blue;\">\n";
	if (is_array($var)) { print_r($var) ; } else { echo $var ; } 
	echo "</pre>\n";
}

// Set timezone (required for PHP5)
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

function generate_salt($len = 5) {
	$valid_characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@#$%^*()_-!';
	$valid_len = strlen($valid_characters) - 1;
	$salt = "";

	for($i = 0; $i < $len; $i++) {
		$salt .= $valid_characters[rand(0, $valid_len)];
	}

	return $salt;
}

function extract_salt($password) {
	return substr(strchr($password, ':'), 1);
}

function mix_salt($salt, $pass) {
	return md5($salt.$pass).':'.$salt;
}

function gen_mix_salt($pass) {
	$salt = generate_salt();
	return mix_salt($salt, $pass);
}

?>
