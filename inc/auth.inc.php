<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
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

//session_start();

function doAuthenticate() {
	global $db;
	global $EXPIRE;
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
	if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $EXPIRE))
	{
		logout( _('Session expired, please login again.'),"error");
	}

	// If the session hasn't expired yet, give our session a fresh new timestamp.
	$_SESSION["lastmod"] = time();

	if(isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"]))
	{
		//Username and password are set, lets try to authenticate.
		$result = $db->query("SELECT id, fullname FROM users WHERE username=". $db->quote($_SESSION["userlogin"])  ." AND password=". $db->quote(md5($_SESSION["userpwd"]))  ." AND active=1");
		if($result->numRows() == 1)
		{
			$rowObj = $result->fetchRow();
			$_SESSION["userid"] = $rowObj["id"];
			$_SESSION["name"] = $rowObj["fullname"];
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
	include_once('inc/header.inc.php');
	if ( $msg )
	{
		print "<div class=\"$type\">$msg</div>\n";
	}
	?>
	<h2><?php echo _('Login'); ?></h2>
	<?php
	?>
	<form method="post" action="<?php echo $_SERVER["PHP_SELF"] ?>">
	 <table border="0">
	  <tr>
	   <td class="n"><?php echo _('Login'); ?>:</td>
	   <td class="n"><input type="text" class="input" name="username"></td>
	  </tr>
	  <tr>
	   <td class="n"><?php echo _('Password'); ?>:</td>
	   <td class="n"><input type="password" class="input" name="password"></td>
	  </tr>
	  <tr>
	   <td class="n">&nbsp;</td>
	   <td class="n">
	    <input type="submit" name="authenticate" class="button" value=" <?php echo _('Login'); ?> ">
	   </td>
	  </tr>
	 </table>
	</form>
        <script type="text/javascript">
         <!--
          document.login.username.focus();
         //-->
        </script>
	<?php
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
