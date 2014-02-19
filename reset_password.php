<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
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
 * Script that handles request to reset password
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/* Disable reset password functionality, because of improper implementation.
    If you know admin or any other user email, then you initiate password change
    to some random which will be send to him by email, but that can be done
    by some malicious user or some script, which can reset password every sec. */
exit;

/** Reset Password functionality
 *
 */


session_start();
/** Print error message
 *
 * @param string $msg Error message
 *
 * @return null
 */
function error($msg) {
/* TODO: reimplement displaying of errors/messages */
    if ($msg) {
        echo "     <div class=\"error\">Error: " . $msg . "</div>\n";
    } else {
        echo "     <div class=\"error\">" . _('An unknown error has occurred.') . "</div>\n";
    }
}

include_once("inc/error.inc.php");
include_once("inc/config-me.inc.php");
if(!@include_once("inc/config.inc.php"))
{
        error( _('You have to create a config.inc.php!') );
}

require_once("inc/database.inc.php");
// Generates $db variable to access database.
$db = dbConnect();

if(isset($_POST['submit']) && $_POST['submit']) {

        global $db;
	$email = $_POST['emailaddr'];
        $query = "SELECT id, password, email FROM users WHERE email = " . $db->quote($email, 'text');
        $response = $db->query($query);
        if (PEAR::isError($response)) { error($response->getMessage()); return false; }

        $rinfo = $response->fetchRow();

        if(isset($rinfo['email'])) {
		$newpass = mt_rand();
                $query = "UPDATE users SET password = " . $db->quote(md5($newpass), 'text') . " WHERE id = " . $db->quote($rinfo['id'], 'integer') ;
                $response = $db->query($query);
                if (PEAR::isError($response)) { error($response->getMessage()); return false; }


		$to = $rinfo['email'];
		$subject = "Password Reset";
		$headers = "From: Poweradmin";
		$body = "New Password: ".$newpass."
		";
		$mail_sent = @mail($to, $subject, $body, $headers);
		

		echo "A new password has been emailed to the registered email address. <a href='index.php'>(Return to login)</a>";
//                logout( _('Password has been changed, please login.'), 'success');

		
        } else {
                error(ERR_USER_WRONG_CURRENT_PASS);
                return false;
        }
}


global $iface_style;
global $iface_title;
global $ignore_install_dir;

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>" . $iface_title ."</title>\n";
echo "  <link rel=stylesheet href=\"style/" . $iface_style . ".css\" type=\"text/css\">\n";
echo "  <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n";
echo " </head>\n";
echo " <body>\n";


if (! function_exists('session_start')) die(error('You have to install PHP session extension!'));
if (! function_exists('_')) die(error('You have to install PHP gettext extension!'));
if (! function_exists('mcrypt_encrypt')) die(error('You have to install PHP mcrypt extension!'));


echo "    <h2>" . _('Reset Password') . "</h2>\n";
echo "    <form method=\"post\" action=\"reset_password.php\">\n";
echo "     <table border=\"0\" cellspacing=\"4\">\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('Registered Email Address') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"text\" class=\"input\" name=\"emailaddr\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">&nbsp;</td>\n";
echo "       <td class=\"n\">\n";
echo "        <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Reset password') . "\">\n";
echo "       </td>\n";
echo "      </tr>\n";
echo "     </table>\n";
echo "    </form>\n";

include_once("inc/footer.inc.php");


?>
