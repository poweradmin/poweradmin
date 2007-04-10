<?

// +--------------------------------------------------------------------+
// | PowerAdmin								|
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team			|
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal	|
// | program PowerAdmin as found on http://poweradmin.sf.net		|
// | The PowerAdmin program falls under the QPL License:		|
// | http://www.trolltech.com/developer/licensing/qpl.html		|
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>	|
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>			|
// +--------------------------------------------------------------------+

// Filename: auth.inc.php
// Startdate: 26-10-2002
// Description: file is supposed to validate users and check whether they are authorized.
// If they are authorized this code handles that they can access stuff.
//
// $Id: auth.inc.php,v 1.6 2003/01/13 22:08:52 azurazu Exp $
//

session_start();

if (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout")
{
	logout();
}

// If a user had just entered his/her login && password, store them in our session.
if(isset($_POST["authenticate"]))
{
    	$_SESSION["userpwd"] = $_POST["password"];
    	$_SESSION["userlogin"] = $_POST["username"];
}

// Check if the session hasnt expired yet.
if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $EXPIRE))
{
	logout("Session expired, please login again.");
}

// If the session hasn't expired yet, give our session a fresh new timestamp.
$_SESSION["lastmod"] = time();

if(isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"]))
{
    //Username and password are set, lets try to authenticate.
	$result = $db->query("SELECT id, fullname, level FROM users WHERE username='". $_SESSION["userlogin"]  ."' AND password='". md5($_SESSION["userpwd"])  ."' AND active=1");
	if($result->numRows() == 1)
	{
        	$rowObj = $result->fetchRow();
		$_SESSION["userid"] = $rowObj["id"];
		$_SESSION["name"] = $rowObj["fullname"];
		$_SESSION["level"] = $rowObj["level"];
        	if($_POST["authenticate"])
        	{
            		//If a user has just authenticated, redirect him to index with timestamp, so post-data gets lost.
            		session_write_close();
            		clean_page("index.php");
            		exit;
        	}
    	}
    	else
    	{
        	//Authentication failed, retry.
	        auth("Authentication failed!");
	}
}
else
{
	//No username and password set, show auth form (again).
	auth();
}

/*
 * Print the login form.
 */

function auth($msg="")
{
	include_once('inc/header.inc.php');
	?>
	<H2>PowerAdmin for PowerDNS</H2><H3>Please login:</H3>
	<?
	if($msg)
	{
		print "<font class=\"warning\">$msg</font>\n";

	}
	?>
	<FORM METHOD="post" ACTION="<?= $_SERVER["PHP_SELF"] ?>">
	<TABLE BORDER="0">
	<TR><TD STYLE="background-color: #FCC229;">Login:</TD><TD STYLE="background-color: #FCC229;"><INPUT TYPE="text" CLASS="input" NAME="username"></TD></TR>
	<TR><TD STYLE="background-color: #FCC229;">Password:</TD><TD STYLE="background-color: #FCC229;"><INPUT TYPE="password" CLASS="input" NAME="password"></TD></TR>
	<TR><TD STYLE="background-color: #FCC229;">&nbsp;</TD><TD STYLE="background-color: #FCC229;"><INPUT TYPE="submit" NAME="authenticate" CLASS="button" VALUE=" Login "></TD></TR>
	</TABLE>
	<?
	include_once('inc/footer.inc.php');
	exit;
}


/*
 * Logout the user and kickback to login form.
 */

function logout($msg="You have logged out.")
{
	session_destroy();
	session_write_close();
	auth($msg);
	exit;
}

?>
