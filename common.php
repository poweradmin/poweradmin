<?php
$root_path = __DIR__;
date_default_timezone_set("Europe/Berlin");
error_reporting(E_ALL ^ E_NOTICE);
require_once("libs/smarty/Smarty.class.php");
$template = new Smarty();
$template->template_dir = $root_path.'/templates';
$template->compile_dir = $root_path.'/cache';
$template->cache_dir = $root_path.'/cache';

require_once("inc/toolkit.inc.php");

verify_permission('search') ? $perm_search = "1" : $perm_search = "0" ;
verify_permission('zone_content_view_own') ? $perm_view_zone_own = "1" : $perm_view_zone_own = "0" ;
verify_permission('zone_content_view_other') ? $perm_view_zone_other = "1" : $perm_view_zone_other = "0" ;
verify_permission('supermaster_view') ? $perm_supermaster_view = "1" : $perm_supermaster_view = "0" ;
verify_permission('zone_master_add') ? $perm_zone_master_add = "1" : $perm_zone_master_add = "0" ;
verify_permission('zone_slave_add') ? $perm_zone_slave_add = "1" : $perm_zone_slave_add = "0" ;
verify_permission('supermaster_add') ? $perm_supermaster_add = "1" : $perm_supermaster_add = "0" ;

$template->assign(array(
	"perm_search"	=>	$perm_search,
	"perm_view_zone_own"	=>	$perm_view_zone_own,
	"perm_view_zone_other"	=>	$perm_view_zone_other,
	"perm_supermaster_view"	=>	$perm_supermaster_view,
	"perm_zone_master_add"	=>	$perm_zone_master_add,
	"perm_zone_slave_add"	=>	$perm_zone_slave_add,
	"perm_supermaster_add"	=>	$perm_supermaster_add,
	"iface_title"	=>	$iface_title,
	"iface_style"	=>	$iface_style,
	"login"	=>	(isset($_SESSION['userid'])) ? true : false,
	
	// Some language Strings
	"L_Index"	=>	_('Index'),
	"L_SearchZonesAndRecords"	=>	_('Search zones and records'),
	"L_ListZones"	=>	_('List zones'),
	"L_ListZoneTemplates"	=>	_('List zone templates'),
	"L_ListSupermasters"	=>	_('List supermasters'),
	"L_AddMasterZone"	=>	_('Add master zone'),
	"L_AddSlaveZone"	=>	_('Add slave zone'),
	"L_AddSupermaster"	=>	_('Add supermaster'),
	"L_ChangePassword"	=>	_('Change password'),
	"L_UserAdministration"	=>	_('User administration'),
	"L_Logout"	=>	_('Logout'),
));

$template->display("overall_header.tpl");
?>