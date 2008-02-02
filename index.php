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
   <h3><?php echo _('Welcome'); ?>, <?php echo $_SESSION["name"] ?></h3>
   <ul>
    <li><a href="search.php"><?php echo _('Search zones or records'); ?></a></li>
    <li><a href="list_zones.php"><?php echo _('List all zones'); ?></a></li>
<?php
if (level(5))
{
?>
    <li><a href="list_supermasters.php"><?php echo _('List all supermasters'); ?></a></li>
    <li><a href="add_zone_master.php"><?php echo _('Add master zone'); ?></a></li>
    <li><a href="add_zone_slave.php"><?php echo _('Add slave zone'); ?></a></li>
    <li><a href="add_supermaster.php"><?php echo _('Add supermaster'); ?></a></li>
<?php
}
?>
    <li><a href="change_password.php"><?php echo _('Change password'); ?></a></li>
<?php
if (level(10))
{
?>
    <li><a href="users.php"><?php echo _('User administration'); ?></a></li>
<?php
}
?>
    <li><a href="index.php?logout"><?php echo _('Logout'); ?></a></li>
   </ul>

<?php
include_once("inc/footer.inc.php");
?>
