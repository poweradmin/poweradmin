<?

/*  PowerAdmin, a friendly web-based admin tool for PowerDNS.
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

global $STYLE;
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<html>
 <head>
  <title><? echo _('Poweradmin'); ?></title>
  <link rel=stylesheet href="style/<? echo $STYLE; ?>.inc.php" type="text/css">
 </head>
 <body>
<?
if(file_exists('inc/custom_header.inc.php')) 
{
	include('inc/custom_header.inc.php');
}
?>
  <h1><? echo _('Poweradmin'); ?></h1> 
<?
if (isset($_SESSION["userid"]))
{
?>
  
	  <div class="menu">
	   <span class="menuitem"><a href="index.php"><? echo _('Index'); ?></a></span>
	   <span class="menuitem"><a href="search.php"><? echo _('Search zones or records'); ?></a></span>
	   <span class="menuitem"><a href="list_zones.php"><? echo _('List all zones'); ?></a></span>
	<?
	if (level(5))
	{
	?>
	   <span class="menuitem"><a href="list_supermasters.php"><? echo _('List all supermasters'); ?></a></span>
	   <span class="menuitem"><a href="add_zone_master.php"><? echo _('Add master zone'); ?></a></span>
	   <span class="menuitem"><a href="add_zone_slave.php"><? echo _('Add slave zone'); ?></a></span>
	   <span class="menuitem"><a href="add_supermaster.php"><? echo _('Add supermaster'); ?></a></span>
	<?
	}
	?>
	   <span class="menuitem"><a href="change_password.php"><? echo _('Change password'); ?></a></span>
	<?
	if (level(10))
	{
	?>
	   <span class="menuitem"><a href="users.php"><? echo _('User administration'); ?></a></span>
	<?
	}
	?>
	   <span class="menuitem"><a href="index.php?logout"><? echo _('Logout'); ?></a></span>

	  </div> <!-- /menu -->
<?
}
?>
  <div class="content">

  

