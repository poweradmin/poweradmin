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

require_once("inc/toolkit.inc.php");

if(isset($_POST["submit"])
&& isset($_POST['username']) && $_POST["username"] != ""
&& isset($_POST['password']) && $_POST["password"] != "" 
&& isset($_POST['fullname']) && $_POST["fullname"] != ""
&& isset($_POST['email']) && $_POST["email"] != ""
&& isset($_POST['level']) && $_POST["level"] > 0)
{
	if(substr_count($_POST["username"], " ") == 0)
	{
		if(strlen($_POST["password"]) < 8)
		{
		$error = _('Password length should be at least 8 characters.');
		}
		else
		{
			add_user($_POST["username"], $_POST["password"], $_POST["fullname"], $_POST["email"], $_POST["level"], $_POST["description"], $_POST["active"]);
			clean_page("users.php");
		}
	}
        else
        {
        	$error = _('Usernames can\'t contain spaces');
        }
}
elseif(isset($_POST["submit"]))
{
	$error = _('Please fill in all fields');
}

include_once("inc/header.inc.php");
if (isset($error) && $error != "") 
{
?>
	<div class="error"><?php echo $error ; ?></div>
<?php
}
?>
    <h2><?php echo _('User admin'); ?></h2>
<?php
if (!level(10)) 
{
	error(ERR_LEVEL_10);
}
?>
     <h3><?php echo _('Current users'); ?></h3>
<?php
$users = show_users('');
?>  

      <table>
       <tr>
        <th>&nbsp;</th>
        <th><?php echo _('Name'); ?></th>
        <th><?php echo _('Zones'); ?> (<?php echo _('access'); ?>)</th>
        <th><?php echo _('Zones'); ?> (<?php echo _('owner'); ?>)</th>
        <th><?php echo _('Zone list'); ?></th>
        <th><?php echo _('Level'); ?></th>
        <th><?php echo _('Status'); ?></th>
       </tr>
<?php
$users = show_users('',ROWSTART,ROWAMOUNT);
foreach ($users as $c)
{
        $domains = get_domains_from_userid($c["id"]);
	$num_zones_access = count($domains);
?>
       <tr>
        <td class="n"><a href="delete_user.php?id=<?php echo $c["id"] ?>"><img src="images/delete.gif" alt="[ <?php echo _('Delete user'); ?> ]"></a></td>
        <td class="n"><a href="edit_user.php?id=<?php echo $c["id"] ?>"><?php echo $c["fullname"] ?></A> (<?php echo $c["username"] ?>)</td>
        <td class="n"><?php echo $num_zones_access ?></td>
        <td class="n"><?php echo $c["numdomains"] ?></td>
        <td class="n">
        <?php
        foreach ($domains as $d)
        {
                ?><a href="delete_domain.php?id=<?php echo $d["id"] ?>"><img src="images/delete.gif" alt="[ <?php echo _('Delete domain'); ?> ]"></a>&nbsp;<a href="edit.php?id=<?php echo $d["id"] ?>"><?php echo $d["name"] ?><?php if ($d["partial"] == "1") { echo " *"; } ; ?></a><br><?php
        }
        ?></td>
	<td class="n"><?php echo $c["level"] ?></td>
	<td class="n"><?php echo get_status($c["active"]) ?></td>
       </tr><?php
        print "\n";
}
?>
       
      </table>
      <p><?php echo _('Users may only change some of the records of zones marked with an (*).'); ?></p>
      <p><?php echo _('Number of users') ;?>: <?php echo count($users); ?>.</p>
      <div class="showmax">
<?php
show_pages(count($users),ROWAMOUNT);
?>
      </div> <?php // eo div showmax ?>

      <h3><?php echo _('Create new user'); ?></h3>
      <form method="post" action="users.php">
       <table>
        <tr>
         <td class="n"><?php echo _('User name'); ?>:</td>
         <td class="n"><input type="text" class="input" name="username" value="<?php if (isset($error)) print $_POST["username"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><?php echo _('Full name'); ?>:</td>
	 <td class="n"><input type="text" class="input" NAME="fullname" VALUE="<?php if (isset($error)) print $_POST["fullname"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><?php echo _('Password'); ?>:</td>
	 <td class="n"><input type="password" class="input" NAME="password" VALUE="<?php if (isset($error)) print $_POST["password"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><?php echo _('E-mail'); ?>:</td>
	 <td class="n"><input type="text" class="input" NAME="email" VALUE="<?php if (isset($error)) print $_POST["email"]; ?>"></td>
	</tr>
	<tr>
	 <td class="n"><?php echo _('User level'); ?>:</td>
	 <td class="n">
	  <select name="level">
	   <option value="1">1 (<?php echo leveldescription(1) ?>)</option>
	   <option value="5">5 (<?php echo leveldescription(5) ?>)</option>
	   <option value="10">10 (<?php echo leveldescription(10) ?>)</option>
	  </select>
	 </td>
	</tr>
        <tr>
	 <td class="n"><?php echo _('Description'); ?>:</td>
	 <td class="n"><textarea rows="6" cols="30" class="inputarea" name="description"><?php if (isset($error)) print $_POST["description"]; ?></textarea></td>
	</tr>
	<tr>
	 <td class="n"><?php echo _('Active'); ?>:</td>
	 <td class="n"><input type="checkbox" name="active" value="1" checked></td>
	</tr>
	<tr>
	 <td class="n">&nbsp;</td>
	 <td class="n"><input type="submit" class="button" name="submit" value="<?php echo _('Add user'); ?>"></td>
	</tr>
       </table>
      </form>
<?php
include_once("inc/footer.inc.php");
?>
