<?
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

  <div class="content">

  

