<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2017  Poweradmin Development Team
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
 * Script which displays available actions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2017  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

echo "     <h3>" . _('Welcome') . " " . $_SESSION["name"] . "</h3>\n";

do_hook('verify_permission', 'search') ? $perm_search = "1" : $perm_search = "0";
do_hook('verify_permission', 'zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0";
do_hook('verify_permission', 'zone_content_view_others') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0";
do_hook('verify_permission', 'supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0";
do_hook('verify_permission', 'zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0";
do_hook('verify_permission', 'zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0";
do_hook('verify_permission', 'supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0";

echo "    <ul>\n";
echo "    <li><a href=\"index.php\"><i class=\"fa fa-home\" title=\"Edit\" style=\"margin:10px;\"></i> " . _('Index') . "</a></li>\n";
if ($perm_search == "1") {
    echo "    <li><a href=\"search.php\"><i class=\"fa fa-search\" style=\"margin:10px;\"></i> " . _('Search zones and records') . "</a></li>\n";
}
if ($perm_view_zone_own == "1" || $perm_view_zone_other == "1") {
    echo "    <li><a href=\"list_zones.php\"><i class=\"fa fa-list-ul\" style=\"margin:10px;\"></i> " . _('List zones') . "</a></li>\n";
}
if ($perm_zone_master_add) {
    echo "    <li><a href=\"list_zone_templ.php\"><i class=\"fa fa-list-ol\" style=\"margin:10px;\"></i> " . _('List zone templates') . "</a></li>\n";
}
if ($perm_supermaster_view) {
    echo "    <li><a href=\"list_supermasters.php\"><i class=\"fa fa-list-alt\" style=\"margin:10px;\"></i> " . _('List supermasters') . "</a></li>\n";
}
if ($perm_zone_master_add) {
    echo "    <li><a href=\"add_zone_master.php\"><i class=\"fa fa-plus-circle\" style=\"margin:10px;\"></i> " . _('Add master zone') . "</a></li>\n";
}
if ($perm_zone_slave_add) {
    echo "    <li><a href=\"add_zone_slave.php\"><i class=\"fa fa-plus-square\" style=\"margin:10px;\"></i> " . _('Add slave zone') . "</a></li>\n";
}
if ($perm_supermaster_add) {
    echo "    <li><a href=\"add_supermaster.php\"><i class=\"fa fa-plus-square-o\" style=\"margin:10px;\"></i> " . _('Add supermaster') . "</a></li>\n";
}
if ($_SESSION["auth_used"] != "ldap") {
    echo "    <li><a href=\"change_password.php\"><i class=\"fa fa-lock\" style=\"margin:10px;\"></i> " . _('Change password') . "</a></li>\n";
}
echo "    <li><a href=\"users.php\"><i class=\"fa fa-users\" style=\"margin:10px;\"></i>". _('User administration') . "</a></li>\n";
echo "    <li><a href=\"index.php?logout\"><i class=\"fa fa-sign-out\" style=\"margin:10px;\"></i> " . _('Logout') . "</a></li>\n";
echo "   </ul>\n";

include_once("inc/footer.inc.php");


