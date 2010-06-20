<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://rejo.zenger.nl/poweradmin> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
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

global $iface_style;

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Poweradmin</title>\n";
echo "  <link rel=stylesheet href=\"style/" . $iface_style . ".css\" type=\"text/css\">\n";
echo "  <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n";
echo " </head>\n";
echo " <body>\n";

if(file_exists('inc/custom_header.inc.php')) {
	include('inc/custom_header.inc.php');
}

echo "  <h1>Poweradmin</h1>\n";

if (file_exists('install')) {
	echo "<div>\n";
	error(ERR_INSTALL_DIR_EXISTS);
	include('inc/footer.inc.php');
	exit;
} elseif (isset($_SESSION["userid"])) {
	verify_permission('search') ? $perm_search = "1" : $perm_search = "0" ;
	verify_permission('zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0" ;
	verify_permission('zone_content_view_other') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0" ;
	verify_permission('supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0" ;
	verify_permission('zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0" ;
	verify_permission('zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0" ;
	verify_permission('supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0" ;

	echo "    <div class=\"menu\">\n";
	echo "    <span class=\"menuitem\"><a href=\"index.php\">" . _('Index') . "</a></span>\n";
	if ( $perm_search == "1" ) { 
		echo "    <span class=\"menuitem\"><a href=\"search.php\">" . _('Search zones and records') . "</a></span>\n"; 
	}
	if ( $perm_view_zone_own == "1" || $perm_view_zone_other == "1" ) { 
		echo "    <span class=\"menuitem\"><a href=\"list_zones.php\">" . _('List zones') . "</a></span>\n"; 
	}
	if ( $perm_zone_master_add ) { 
		echo "    <span class=\"menuitem\"><a href=\"list_zone_templ.php\">" . _('List zone templates') . "</a></span>\n"; 
	}
	if ( $perm_supermaster_view ) { 
		echo "    <span class=\"menuitem\"><a href=\"list_supermasters.php\">" . _('List supermasters') . "</a></span>\n"; 
	}
	if ( $perm_zone_master_add ) { 
		echo "    <span class=\"menuitem\"><a href=\"add_zone_master.php\">" . _('Add master zone') . "</a></span>\n"; 
	}
	if ( $perm_zone_slave_add ) { 
		echo "    <span class=\"menuitem\"><a href=\"add_zone_slave.php\">" . _('Add slave zone') . "</a></span>\n"; 
	}
	if ( $perm_supermaster_add ) { 
		echo "    <span class=\"menuitem\"><a href=\"add_supermaster.php\">" . _('Add supermaster') . "</a></span>\n"; 
	}
	echo "    <span class=\"menuitem\"><a href=\"change_password.php\">" . _('Change password') . "</a></span>\n";
	echo "    <span class=\"menuitem\"><a href=\"users.php\">" . _('User administration') . "</a></span>\n";
	echo "    <span class=\"menuitem\"><a href=\"index.php?logout\">" . _('Logout') . "</a></span>\n";
	echo "    </div> <!-- /menu -->\n";
}
echo "    <div class=\"content\">\n";
?>
