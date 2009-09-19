<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
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

//session_start();

function doAuthenticate() {
	global $db;
	global $iface_expire;
	if (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
		logout();
	}

	// If a user had just entered his/her login && password, store them in our session.
	if(isset($_POST["authenticate"]))
	{
			$_SESSION["userpwd"] = $_POST["password"];
			$_SESSION["userlogin"] = $_POST["username"];
	}

	// Check if the session hasnt expired yet.
	if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire))
	{
		logout( _('Session expired, please login again.'),"error");
	}

	// If the session hasn't expired yet, give our session a fresh new timestamp.
	$_SESSION["lastmod"] = time();

	if(isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"]))
	{
		//Username and password are set, lets try to authenticate.
		$result = $db->query("SELECT id, fullname FROM users WHERE username=". $db->quote($_SESSION["userlogin"], 'text')  ." AND password=". $db->quote(md5($_SESSION["userpwd"]), 'text')  ." AND active=1");
		if($result->numRows() == 1)
		{
			$rowObj = $result->fetchRow();
			$_SESSION["userid"] = $rowObj["id"];
			$_SESSION["name"] = $rowObj["fullname"];
			if(isset($_POST["authenticate"]))
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
			auth( _('Authentication failed!'),"error");
		}
	}
	else
	{
		//No username and password set, show auth form (again).
		auth();
	}
}

/*
 * Print the login form.
 */

function auth($msg="",$type="success")
{
	global $tpl;
	
	$tpl->assign(array(
		"D_MSG"	=>	$msg,
		"D_TYPE"	=>	$type,
		"L_LOGIN"	=>	_('Login'),
		"L_PASSWORD"	=>	_('Password'),
		"S_PHP_SELF"	=>	$_SERVER['PHP_SELF'],
	));
	$tpl->display("login.tpl");
	include_once('inc/footer.inc.php');
	exit;
}


/*
 * Logout the user and kickback to login form.
 */

function logout($msg="")
{
	$type = '';
	if ( $msg == "" ) {
		$msg = _('You have logged out.');
		$type = "success";
	};
	unset($_SESSION["userid"]);
	unset($_SESSION["name"]);
	session_destroy();
	session_write_close();
	auth($msg, $type);
	exit;
}

?>
