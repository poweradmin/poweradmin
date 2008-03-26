<?php

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007, 2008  Rejo Zenger <rejo@zenger.nl>
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

session_start();


if(!@include_once("config.inc.php"))
{
	error( _('You have to create a config.inc.php!') );
}

if(is_file( dirname(__FILE__) . '/../install.php'))
{
	error( _('You have to remove install.php before this program will run') );
}

if(is_file( dirname(__FILE__) . '/../migrator.php'))
{
        error( _('You have to remove migrator.php before this program will run') );
}

/*************
 * Constants *
 *************/
define('ROWAMOUNT', $ROWAMOUNT);

if (isset($_GET["start"])) {
   define('ROWSTART', (($_GET["start"] - 1) * ROWAMOUNT));
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

/* Database connection */

require_once("database.inc.php");
// Generates $db variable to access database.


// Array of the available zone types
$server_types = array("MASTER", "SLAVE", "NATIVE");


/*************
 * Includes  *
 *************/

require_once("error.inc.php");
require_once("auth.inc.php");
require_once("i18n.inc.php");
require_once("users.inc.php");
require_once("dns.inc.php");
require_once("record.inc.php");

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
            echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?start=".$i;
	    if ($id!='') echo "&id=".$id;
	    echo "\">".$i."</a> ] ";
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
		echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?letter=1\">0-9</a> ] ";
	}
	else
	{
		echo "[ <span class=\"letternotavailble\">0-9</span> ] ";
	}

        foreach (range('a','z') as $letter)
        {
                if ($letter == $letterstart)
                {
                        echo "[ <span class=\"lettertaken\">".$letter."</span> ] ";
                }
                elseif (zone_letter_start($letter,$userid))
                {
                        echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?letter=".$letter."\">".$letter."</a> ] ";
                }
                else
                {
                        echo "[ <span class=\"letternotavailble\">".$letter."</span> ] ";
                }
        }
}

function zone_letter_start($letter,$userid=true)
{
        global $db;
	global $sql_regexp;
        $query = "SELECT 
			domains.id AS domain_id,
			zones.owner,
			records.id,
			domains.name AS domainname
			FROM domains
			LEFT JOIN zones ON domains.id=zones.domain_id 
			LEFT JOIN records ON records.domain_id=domains.id
			AND substring(domains.name,1,1) ".$sql_regexp." ".$db->quote("^".$letter);
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
		header("Location: ".$_SERVER["PHP_SELF"]."?time=".time());
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

function parse_template_value($val, $domain, $webip, $mailip)
{
	$val = str_replace('##DOMAIN##', $domain, $val);
	$val = str_replace('##WEBIP##', $webip, $val);
	$val = str_replace('##MAILIP##', $mailip, $val);
	return $val;
}


/*
 * Validates an email address.
 * Checks if there is something before the at '@' sign and its followed by a domain and a tld of minimum 2
 * and maximum of 4 characters.
 */
function is_valid_email($email)
{
	if(!eregi("^[0-9a-z]([-_.]?[0-9a-z])*@[0-9a-z]([-.]?[0-9a-z])*\\.([a-z]{2,6}$)", $email))
	{
		return false;
	}
	return true;
}


function v_num($string) {
	if (!eregi("^[0-9]+$", $string)) { 
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

?>
