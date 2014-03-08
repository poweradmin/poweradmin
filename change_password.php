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
 * Script that handles user password changes
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");

if (isset($_POST['submit']) && $_POST['submit']) {
    change_user_pass($_POST);
}

include_once("inc/header.inc.php");

echo "    <h2>" . _('Change password') . "</h2>\n";
echo "    <form method=\"post\" action=\"change_password.php\">\n";
echo "     <table border=\"0\" cellspacing=\"4\">\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('Current password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"currentpass\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('New password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"newpass\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">" . _('New password') . ":</td>\n";
echo "       <td class=\"n\"><input type=\"password\" class=\"input\" name=\"newpass2\" value=\"\"></td>\n";
echo "      </tr>\n";
echo "      <tr>\n";
echo "       <td class=\"n\">&nbsp;</td>\n";
echo "       <td class=\"n\">\n";
echo "        <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Change password') . "\">\n";
echo "       </td>\n";
echo "      </tr>\n";
echo "     </table>\n";
echo "    </form>\n";

include_once("inc/footer.inc.php");
