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
 * Script which displays available actions
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

echo "     <h3>" . _('Welcome') . " " . $_SESSION["name"] . "</h3>\n";

verify_permission('search') ? $perm_search = "1" : $perm_search = "0";
verify_permission('zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0";
verify_permission('zone_content_view_others') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0";
verify_permission('supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0";
verify_permission('zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0";
verify_permission('zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0";
verify_permission('supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0";

echo "    <ul>\n";
echo "    <li><a href=\"index.php\">" . _('Index') . "</a></li>\n";
if ($perm_search == "1") {
    echo "    <li><a href=\"search.php\">" . _('Search zones and records') . "</a></li>\n";
}
if ($perm_view_zone_own == "1" || $perm_view_zone_other == "1") {
    echo "    <li><a href=\"list_zones.php\">" . _('List zones') . "</a></li>\n";
}
if ($perm_zone_master_add) {
    echo "    <li><a href=\"list_zone_templ.php\">" . _('List zone templates') . "</a></li>\n";
}
if ($perm_supermaster_view) {
    echo "    <li><a href=\"list_supermasters.php\">" . _('List supermasters') . "</a></li>\n";
}
if ($perm_zone_master_add) {
    echo "    <li><a href=\"add_zone_master.php\">" . _('Add master zone') . "</a></li>\n";
}
if ($perm_zone_slave_add) {
    echo "    <li><a href=\"add_zone_slave.php\">" . _('Add slave zone') . "</a></li>\n";
}
if ($perm_supermaster_add) {
    echo "    <li><a href=\"add_supermaster.php\">" . _('Add supermaster') . "</a></li>\n";
}
if ($_SESSION["auth_used"] != "ldap") {
    echo "    <li><a href=\"change_password.php\">" . _('Change password') . "</a></li>\n";
}
echo "    <li><a href=\"users.php\">" . _('User administration') . "</a></li>\n";
echo "    <li><a href=\"index.php?logout\">" . _('Logout') . "</a></li>\n";
echo "   </ul>\n";

include_once("inc/footer.inc.php");
