<?
session_start();
// +--------------------------------------------------------------------+
// | PowerAdmin                                                         |
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team                        |
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal   |
// | program PowerAdmin as found on http://poweradmin.sf.net            |
// | The PowerAdmin program falls under the QPL License:                |
// | http://www.trolltech.com/developer/licensing/qpl.html              |
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>       |
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>                      |
// +--------------------------------------------------------------------+

// Filename: toolkit.inc.php
// Startdate: 26-10-2002
// Description: general functions needed on a large variety of locations.
// Kills the db.inc.php.
// If you include this file you include the whole 'backend'
//
// $Id: toolkit.inc.php,v 1.13 2003/02/24 01:46:31 azurazu Exp $
//

/*************
 * Constants  *
  *************/

define(ROWAMOUNT, 500);

if (isset($_GET["start"])) {
   define(ROWSTART, (($_GET["start"] - 1) * ROWAMOUNT));
   } else {
   define(ROWSTART, 0);
}

if (isset($_GET["letter"])) {
   define(LETTERSTART, $_GET["letter"]);
   $_SESSION["letter"] = $_GET["letter"];
} elseif(isset($_SESSION["letter"])) {
   define(LETTERSTART, $_SESSION["letter"]);
} else {
   define(LETTERSTART, "a");
}

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

/* Database connection */

require_once("database.inc.php");
// Generates $db variable to access database.

/*************
 * Includes  *
 *************/

require_once("error.inc.php");
require_once("auth.inc.php");
require_once("i18n.inc.php");
require_once("users.inc.php");
require_once("dns.inc.php");
require_once("record.inc.php");


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
      echo "<br /><br />" . _('Show page') . " ";
      for ($i=1;$i<=ceil($amount / $rowamount);$i++) {
         if ($_GET["start"] == $i) {
            echo "[ <b>".$i."</b> ] ";
         } else {
            echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?start=".$i;
	    if ($id!='') echo "&id=".$id;
	    echo "\">".$i."</a> ] ";
         }
      }
      echo "</small>";
   }
}

/*
 * Display the alphabetic option: [0-9] [a] [b] .. [z]
 */

function show_letters($letterstart,$doms)
{
   foreach ($doms as $dom) {
      if (is_numeric($dom["name"][0])) {
         $letter_taken["0"] = 1;
      } else {
         $letter_taken[$dom["name"][0]] = 1;
      }
   }

   echo _('Show domains beginning with:') . "<br />";
   if ($letterstart == 1) {
      echo "[ <b>0-9</b> ] ";
   } elseif ($letter_taken["0"] != 1) {
      echo "[ 0-9 ] ";
   } else {
      echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?letter=1\">0-9</a> ] ";
   }
   
   foreach (range('a','z') as $letter) {
      if ($letterstart === $letter) {
         echo "[ <b>".$letter."</b> ] ";
      } elseif ($letter_taken[$letter] != 1) {
         echo "[ <span style=\"color:#999\">".$letter."</span> ] ";
      } else {
          echo "[ <a href=\"".$_SERVER["PHP_SELF"]."?letter=".$letter."\">".$letter."</a> ] ";
      }
   }
}

/*
 * Print a nice useraimed error.
 */
function error($msg)
{
	// General function for printing critical errors.
	if ($msg)
	{
		include_once("header.inc.php");
	?>
	<P><TABLE CLASS="error"><TR><TD CLASS="error"><H2><? echo _('Oops! An error occured!'); ?></H2>
	<BR>
	<FONT STYLE="font-weight: Bold"><?= nl2br($msg) ?><BR><BR><a href="javascript:history.go(-1)">&lt;&lt; <? echo _('back'); ?></a></FONT><BR></TD></TR></TABLE></P>
	<?
		include_once("footer.inc.php");
		die();
	}
	else
	{
		include_once("footer.inc.php");
		die("No error specified!");
	}
}

/*
 * Something has been done nicely, display a message and a back button.
 */
function message($msg)
{
    include_once("header.inc.php");
    ?>
    <P><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2><? echo _('Success!'); ?></H2>
    <BR>
	<FONT STYLE="font-weight: Bold">
	<P>
	<?
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
    <a href="javascript:history.go(-1)">&lt;&lt; <? echo _('back'); ?></a></FONT>
    </P>
    </TD></TR></TABLE></P>
    <?
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

function level($l)
{
	if ($_SESSION["level"] >= $l)
	{
		return 1;
	}
	else
	{
		return 0;
	}
}

function xs($zoneid)
{
	global $db;
	if (is_numeric($zoneid) && is_numeric($_SESSION["level"]))
	{
		$result = $db->query("SELECT id FROM zones WHERE owner=".$_SESSION["userid"]." AND domain_id=$zoneid");
		$result_extra = $db->query("SELECT record_owners.id FROM record_owners,records WHERE record_owners.user_id=".$_SESSION["userid"]." AND records.domain_id = $zoneid AND records.id = record_owners.record_id LIMIT 1");

                if ($result->numRows() == 1 || $_SESSION["level"] >= 5)
                {
			$_SESSION[$zoneid."_ispartial"] = 0;
			return true;
		}
		elseif ($result_extra->numRows() == 1)
		{
			$_SESSION[$zoneid."_ispartial"] = 1;
			return true;
		}
		else
		{
			return false;
		}
	}
	else
	{
        	return false;
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
?>
