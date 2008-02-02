<?php

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
  <title><?php echo _('Poweradmin'); ?></title>
  <link rel=stylesheet href="style/<?php echo $STYLE; ?>.inc.php" type="text/css">
 </head>
 <body>
<?php
if(file_exists('inc/custom_header.inc.php')) 
{
	include('inc/custom_header.inc.php');
}
?>
  <h1><?php echo _('Poweradmin'); ?></h1> 
<?php
if (isset($_SESSION["userid"]))
{
?>
  
	  <div class="menu">
	   <span class="menuitem"><a href="index.php"><?php echo _('Index'); ?></a></span>
	   <span class="menuitem"><a href="search.php"><?php echo _('Search zones or records'); ?></a></span>
	   <span class="menuitem"><a href="list_zones.php"><?php echo _('List all zones'); ?></a></span>
	<?php
	if (level(5))
	{
	?>
	   <span class="menuitem"><a href="list_supermasters.php"><?php echo _('List all supermasters'); ?></a></span>
	   <span class="menuitem"><a href="add_zone_master.php"><?php echo _('Add master zone'); ?></a></span>
	   <span class="menuitem"><a href="add_zone_slave.php"><?php echo _('Add slave zone'); ?></a></span>
	   <span class="menuitem"><a href="add_supermaster.php"><?php echo _('Add supermaster'); ?></a></span>
	<?php
	}
	?>
	   <span class="menuitem"><a href="change_password.php"><?php echo _('Change password'); ?></a></span>
	<?php
	if (level(10))
	{
	?>
	   <span class="menuitem"><a href="users.php"><?php echo _('User administration'); ?></a></span>
	<?php
	}
	?>
	   <span class="menuitem"><a href="index.php?logout"><?php echo _('Logout'); ?></a></span>

	  </div> <!-- /menu -->
<?php
}
?>
  <div class="content">

  

