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

session_start();
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");
?>
   <h3><? echo _('Welcome'); ?>, <? echo $_SESSION["name"] ?></h3>
   <ul>
    <li><a href="search.php"><? echo _('Search zones or records'); ?></a></li>
    <li><a href="list_zones.php"><? echo _('List all zones'); ?></a></li>
<?
if (level(5))
{
?>
    <li><a href="list_supermasters.php"><? echo _('List all supermasters'); ?></a></li>
    <li><a href="add_zone_master.php"><? echo _('Add master zone'); ?></a></li>
    <li><a href="add_zone_slave.php"><? echo _('Add slave zone'); ?></a></li>
    <li><a href="add_supermaster.php"><? echo _('Add supermaster'); ?></a></li>
<?
}
?>
    <li><a href="change_password.php"><? echo _('Change password'); ?></a></li>
<?
if (level(10))
{
?>
    <li><a href="users.php"><? echo _('User administration'); ?></a></li>
<?
}
?>
    <li><a href="index.php?logout"><? echo _('Logout'); ?></a></li>
   </ul>

<?
include_once("inc/footer.inc.php");
?>
