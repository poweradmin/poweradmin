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

require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if($post['commit']) {
	change_user_pass($post);
}

echo "    <h2>" . _('Change password') . "</h2>\n";
echo "    <form method=\"post\" action=\"change_password.php\">\n";
echo "     <table border=\"0\" CELLSPACING=\"4\">\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('Current password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"password_now\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('New password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"password_new1\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('New password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"password_new2\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">&nbsp;</td>\n";
echo "       <td class=\"n\">\n";
echo "        <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Change password') . "\">\n";
echo "       </td>\n";
echo "      </tr>\n";
echo "     </table>\n";
echo "    </form>\n";

include_once("inc/footer.inc.php");
?>
