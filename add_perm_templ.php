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
 * Script that handles requests to add new permission template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (!verify_permission('templ_perm_edit')) {
    error(ERR_PERM_EDIT_PERM_TEMPL);
} else {

    if (isset($_POST['commit'])) {
        if (add_perm_templ($_POST)) {
            success(SUC_PERM_TEMPL_ADD);
        }
    }

    $perms_avail = get_permissions_by_template_id();

    /*
      Display new permission form
     */

    echo "    <h2>" . _('Add permission template') . "</h2>\n";
    echo "    <form method=\"post\" action=\"\">\n";
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
    echo "     <table>\n";
    echo "      <tr>\n";
    echo "       <th>&nbsp;</th>\n";
    echo "       <th>" . _('Name') . "</th>\n";
    echo "       <th>" . _('Description') . "</th>\n";
    echo "      </tr>\n";

    /*
      Display available permissions settings for inclusion
      in the new permission
     */
    foreach ($perms_avail as $perm_a) {

        echo "      <tr>\n";
        echo "       <td><input type=\"checkbox\" name=\"perm_id[]\" value=\"" . $perm_a['id'] . "\"></td>\n";
        echo "       <td>" . $perm_a['name'] . "</td>\n";
        echo "       <td>" . _($perm_a['descr']) . "</td>\n";
        echo "      </tr>\n";
    }
    echo "     </table>\n";
    echo "     <input type=\"submit\" class=\"button\" name=\"commit\" value=\"" . _('Commit changes') . "\">\n";
    echo "     </form>\n";
}

include_once("inc/footer.inc.php");
