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
 
 /*
  * We will define here some general needed Stuff for all files.
  */
require_once($root_path."/inc/config-me.inc.php");
require_once($root_path."/inc/config.inc.php");
define("STYLE", $style);
require_once("./inc/libs/smarty/Smarty.class.php");
$tpl = new Smarty();
$tpl->template_dir = $root_path.'/styles/templates/'.STYLE.'/';
$tpl->compile_dir = $root_path.'/cache/';
$tpl->cache_dir = $root_path.'/cache';
$tpl->assign(array(
	"STYLE"	=>	STYLE,
));
$tpl->display("overall_header.tpl");
require_once($root_path."/inc/users.inc.php");
if (file_exists('install')) {
	error(ERR_INSTALL_DIR_EXISTS);
	include($root_path.'/inc/footer.inc.php');
	exit;
} elseif (isset($_SESSION["userid"])) {
	verify_permission('search') ? $perm_search = "1" : $perm_search = "0" ;
	verify_permission('zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0" ;
	verify_permission('zone_content_view_other') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0" ;
	verify_permission('supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0" ;
	verify_permission('zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0" ;
	verify_permission('zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0" ;
	verify_permission('supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0" ;
	$tpl->assign(array(
		"PERM_SEARCH"	=>	$perm_search,
		"PERM_VIEW_ZONE_OWN"	=>	$perm_view_zone_own,
		"PERM_VIEW_ZONE_OTHER"	=>	$perm_view_zone_other,
		"PERM_SUPERMASTER_VIEW"	=>	$perm_supermaster_view,
		"PERM_SUPERMASTER_ADD"	=>	$perm_supermaster_add,
		"PERM_ZONE_MASTER_ADD"	=>	$perm_zone_master_add,
		"PERM_ZONE_SLAVE_ADD"	=>	$perm_zone_slave_add,
		"S_DISPLAY_MENU"	=>	true,
		
		"L_INDEX"	=>	_('Index'),
		"L_SEARCH"	=>	_('Search zones and records'),
		"L_LIST_ZONES"	=>	_('List zones'),
		"L_LIST_ZONE_TEMPLATES"	=>	_('List zone templates'),
		"L_LIST_SUPERMASTERS"	=>	_('List supermasters'),
		"L_ADD_MASTER_ZONE"	=>	_('Add master zone'),
		"L_ADD_SLAVE_ZONE"	=>	_('Add slave zone'),
		"L_ADD_SUPERMASTER"	=>	_('Add supermaster'),
		"L_CHANGE_PASSWORD"	=>	_('Change password'),
		"L_USER_ADMINISTRATION"	=>	_('User administration'),
		"L_LOGOUT"	=>	_('Logout'),
	));
}
$tpl->display("top_menu.tpl");
require_once($root_path."/inc/toolkit.inc.php");
?>