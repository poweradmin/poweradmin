<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
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
 * Authentication functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Authenticate Session
 *
 * Checks if user is logging in, logging out, or session expired and performs
 * actions accordingly
 *
 * @return null
 */
function doAuthenticate() {
	global $auth_mode;
	global $session_key;
	global $iface_expire;
	if (isset($_SESSION['userid']) && isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] == "logout") {
		logout( _('You have logged out.'), 'success');
	}

	// If a user had just entered his/her login && password, store them in our session.
	if (isset($_POST["authenticate"]))
	{
		if ($auth_mode == "ldap"){
			// Something tells me that if it wasn't necesary, this would be a bad idea
			$_SESSION['clearpass'] = $_POST['password'];
		} else {
			$_SESSION["userpwd"] = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($session_key), $_POST['password'], MCRYPT_MODE_CBC, md5(md5($session_key))));;
		}
		$_SESSION["userlogin"] = $_POST["username"];
		$_SESSION["userlang"] = $_POST["userlang"];
	}
	// Check if the session hasnt expired yet.
	if ((isset($_SESSION["userid"])) && ($_SESSION["lastmod"] != "") && ((time() - $_SESSION["lastmod"]) > $iface_expire))
	{
		logout( _('Session expired, please login again.'), 'error');
	}

	// If the session hasn't expired yet, give our session a fresh new timestamp.
	$_SESSION["lastmod"] = time();


	if ($auth_mode == "ldap"){
		ldapAuthenticate();
	} else {
		sqlAuthenticate();
	}
}

function ldapAuthenticate()
{
	global $ldap_host;
	global $ldap_port;
	global $ldap_basedn;
	global $ldap_search_filter;

	$user = $_SESSION["userlogin"];
	$pass = $_SESSION['clearpass'];

	if (isset($ldap_host)){
		$host = $ldap_host;
	} else {
		$host = 'localhost';
	}

	if (isset($ldap_port)){
		$port = $ldap_port;
	} else {
		$port = 389;
	}
	if (isset($ldap_search_filter)){
		$needles = array("%u", "%h", "%p");
		$replace = array($user, $ldap_host, $port);
		$ldapsearch = str_replace($needles, $replace, $ldap_search_filter);
	} else {
		$ldapsearch = '(&(uid=' . $user . ")(objectClass=posixAccount))";
	}
	
	
	if ($user!= "" && $pass != "") {
		$directory=ldap_connect($host, $port);
		ldap_set_option($directory, LDAP_OPT_PROTOCOL_VERSION, 3);
		$record = ldap_search(
			$directory, $ldap_basedn, $ldapsearch
		);
		if ($record) {
			$result = ldap_get_entries($directory, $record);
			if ($result['count'] != 0) {
				if (@ldap_bind($directory, $result[0]['dn'], $pass) ) {
					// pretend to be user 1 (default admin user) for other checks
					$_SESSION["userid"] = 1;
					return $result[0];
				} else {
					logout( _("ldap bind failed somehow"), 'error');
				}
			} else {
				logout( _("Zero results found"). 'error');
			}
		} else {
			logout( _("No record found"), 'error');
		 }
	} else {
		logout( _("Empty user/pass"), 'error');
	}
}

function sqlAuthenticate() {
	global $db;
	global $syslog_use, $syslog_ident, $syslog_facility;
	global $session_key;
	global $password_encryption;

	if (isset($_SESSION["userlogin"]) && isset($_SESSION["userpwd"]))
	{
		//Username and password are set, lets try to authenticate.
		$session_pass = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($session_key), base64_decode($_SESSION["userpwd"]), MCRYPT_MODE_CBC, md5(md5($session_key))), "\0");

		if ($password_encryption == 'md5salt') {
			$result = $db->query("SELECT id, fullname, password FROM users WHERE username=". $db->quote($_SESSION["userlogin"], 'text')  ." AND active=1");
		} else {
			$result = $db->query("SELECT id, fullname, password FROM users WHERE username=". $db->quote($_SESSION["userlogin"], 'text')  ." AND active=1");
		}
		
		if($result->numRows() == 1)
		{
			$rowObj = $result->fetchRow();
			
			if ($password_encryption == 'md5salt') {
				$session_password = mix_salt(extract_salt($rowObj["password"]), $session_pass);
			} else {
				$session_password = md5($session_pass);
			}

			if ($session_password == $rowObj["password"]) {
				
				$_SESSION["userid"] = $rowObj["id"];
				$_SESSION["name"] = $rowObj["fullname"];
				
				if(isset($_POST["authenticate"]))
				{
					// Log to syslog if it's enabled
					if($syslog_use)
					{
						openlog($syslog_ident, LOG_PERROR, $syslog_facility);
						$syslog_message = sprintf('Successful authentication attempt from [%s] for user \'%s\'', $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"]);
						syslog(LOG_INFO, $syslog_message);
						closelog();
					}
					//If a user has just authenticated, redirect him to requested page
					session_write_close();
                                        $redirect_url = ($_POST["query_string"] ? $_SERVER['SCRIPT_NAME'] . "?" . $_POST["query_string"] : $_SERVER['SCRIPT_NAME']);
                                        clean_page($redirect_url);
					exit;
				}
			} else if (isset($_POST['authenticate'])) {
//				auth( _('Authentication failed! - <a href="reset_password.php">(forgot password)</a>'),"error");
                auth( _('Authentication failed!'),"error");
			} else {
				auth();
			}		
			
		} else if (isset($_POST['authenticate'])) {
			// Log to syslog if it's enabled
			if ($syslog_use)
			{
				openlog($syslog_ident, LOG_PERROR, $syslog_facility);
				$syslog_message = sprintf('Failed authentication attempt from [%s]', $_SERVER['REMOTE_ADDR']);
				syslog(LOG_WARNING, $syslog_message);
				closelog();
			}

			//Authentication failed, retry.
//			auth( _('Authentication failed! - <a href="reset_password.php">(forgot password)</a>'),"error");
            auth( _('Authentication failed!'),"error");
		} else {
			auth();
		}
		
	} else {
		//No username and password set, show auth form (again).
		auth();
	}
}

/** Print the login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='success', 'error']
 *
 * @return null
 */
function auth($msg="",$type="success")
{
	include_once('inc/header.inc.php');
	include('inc/config.inc.php');
	
	if ( $msg )
	{
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
	   		<?php	// List available languages (sorted alphabetically)
	   			include_once('inc/countrycodes.inc.php');
	   			$locales = scandir('locale/');
	   			foreach ($locales as $locale) {
	   				if (strlen($locale) == 5) { $locales_fullname[$locale] = $countrycodes[substr($locale, 0, 2)]; }
	   			}
	   			asort($locales_fullname);
	   			foreach ($locales_fullname as $locale => $language) {
	   				if ($locale == $iface_lang) {
						echo _('<option selected value="'.$locale.'">'.$language);
	   				} else {
						echo _('<option value="'.$locale.'">'.$language);
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

function logout($msg="",$type="")
{
	unset($_SESSION["userid"]);
	unset($_SESSION["name"]);
	unset($_SESSION["clearpass"]);
	unset($_SESSION["userpwd"]);
	session_unset();
	session_destroy();
	session_write_close();
	auth($msg, $type);
	exit;
}
?>
