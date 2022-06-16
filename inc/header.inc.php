<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Web interface header
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
global $iface_style;
global $iface_title;
global $ignore_install_dir;
global $session_key;

header('Content-type: text/html; charset=utf-8');
$file_version = time();
echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>" . $iface_title . "</title>\n";
echo "  <link rel=stylesheet href=\"style/" . $iface_style . ".css?time=" . $file_version . "\" type=\"text/css\">\n";
echo "  <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n";
echo "  <script type=\"text/javascript\" src=\"inc/helper.js?time=" . $file_version . "\"></script>\n";
//add bootsrap
echo '<meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
       ';
echo " </head>\n";
echo " <body>\n";

//navbar
echo '<nav class="navbar navbar-expand-sm bg-dark navbar-dark">
            <div class="container-fluid">';
if (file_exists('inc/custom_header.inc.php')) {
    include('inc/custom_header.inc.php');
} else {
    echo  '<a href="#" class="navbar-brand">'.$iface_title.'</a>' ;
}

if ($ignore_install_dir == false && file_exists ( 'install' )) {
    echo "<div>\n";
    error(ERR_INSTALL_DIR_EXISTS);
    include ('inc/footer.inc.php');
    exit();
} elseif (isset($_SESSION ["userid"])) {
    do_hook('verify_permission', 'search') ? $perm_search = "1" : $perm_search = "0";
    do_hook('verify_permission', 'zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0";
    do_hook('verify_permission', 'zone_content_view_others') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0";
    do_hook('verify_permission', 'supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0";
    do_hook('verify_permission', 'zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0";
    do_hook('verify_permission', 'zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0";
    do_hook('verify_permission', 'supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0";
    do_hook('verify_permission', 'user_is_ueberuser') ? $perm_is_godlike = "1" : $perm_is_godlike = "0";

    if ($perm_is_godlike == 1 && $session_key == 'p0w3r4dm1n') {
        error(ERR_DEFAULT_CRYPTOKEY_USED);
        echo "<br>";
    }

   echo '<div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
            <div class="navbar-nav">';

        echo '<a href="index.php" class="nav-item nav-link">'.  _('Index').'</a>';
        if ($perm_search == "1") {
            echo '<a href="search.php" class="nav-item nav-link">'. _('Search zones and records').'</a>';
        }

        echo '<div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">List</a>
                    <div class="dropdown-menu">';
                        
                        if ($perm_view_zone_own == "1" || $perm_view_zone_other == "1") {
                            echo "   <a href=\"list_zones.php\" class=\"dropdown-item\"> " . _('List zones') . "</a>\n";
                        }
                        if ($perm_zone_master_add) {
                            echo "    <a href=\"list_zone_templ.php\" class=\"dropdown-item\" >" . _('List zone templates') . "</a>\n";
                        }
                        if ($perm_supermaster_view) {
                            echo "    <a href=\"list_supermasters.php\" class=\"dropdown-item\">" . _('List supermasters') . "</a>\n";
                        }

        echo            '</div>
                </div>';

        echo '<div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">Add</a>
                    <div class="dropdown-menu">';
                        
                        if ($perm_zone_master_add) {
                            echo "    <a href=\"add_zone_master.php\" class=\"dropdown-item\">" . _('Add master zone') . "</a>\n";
                        }
                        if ($perm_zone_slave_add) {
                            echo "    <a href=\"add_zone_slave.php\" class=\"dropdown-item\">" . _('Add slave zone') . "</a>\n";
                        }
                        if ($perm_supermaster_add) {
                            echo "    <a href=\"add_supermaster.php\" class=\"dropdown-item\">" . _('Add supermaster') . "</a>\n";
                        }

        echo            '</div>
                </div>';

        if ($perm_zone_master_add) {
            echo "    <a href=\"bulk_registration.php\" class=\"nav-item nav-link\">" . _('Bulk registration') . "</a>\n";
        }
        if ($_SESSION ["auth_used"] != "ldap") {
            echo "    <a href=\"change_password.php\" class=\"nav-item nav-link\" >" . _('Change password') . "</a>\n";
        }
        echo "    <a href=\"users.php\" class=\"nav-item nav-link\">" . _('User administration') . "</a>\n";
        echo "</div>";

        echo "<div class=\"navbar-nav\">";
        echo "    <a href=\"index.php?logout\"  class=\"nav-item nav-link\">" . _('Logout') . "</a>\n";
        echo "</div>";
echo '</div></div>';

}
//close navbar
echo '      </div>
        </nav>';


echo "    <div class=\"content\">\n";
