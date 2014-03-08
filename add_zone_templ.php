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
 * Script that handles requests to add new zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (!verify_permission('zone_master_add')) {
    error(ERR_PERM_ADD_ZONE_TEMPL);
} else {

    if (isset($_POST['commit'])) {
        if (add_zone_templ($_POST, $_SESSION['userid'])) {
            success(SUC_ZONE_TEMPL_ADD);
        } // TODO: otherwise repopulate values to form
    }

    /*
      Display new zone template form
     */

    $username = get_fullname_from_userid($_SESSION['userid']);
    echo "    <h2>" . _('Add zone template for') . " " . $username . "</h2>\n";
    echo "    <form method=\"post\" action=\"add_zone_templ.php\">\n";
    echo "     <table>\n";
    echo "      <tr>\n";
    echo "       <th>" . _('Name') . "</th>\n";
    echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_name\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "      <tr>\n";
    echo "       <th>" . _('Description') . "</th>\n";
    echo "       <td><input class=\"wide\" type=\"text\" name=\"templ_descr\" value=\"\"></td>\n";
    echo "      </tr>\n";
    echo "     </table>\n";
    echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Add zone template') . "\">\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
