<?php
session_start();
require_once("inc/i18n.inc.php");
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
