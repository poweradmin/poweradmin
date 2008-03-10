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

if (!level(5))
{
        error(ERR_LEVEL_5);

}

if (isset($_POST["submit"]))
{
     $domain = trim($_POST["domain"]);
     $owner = $_POST["owner"];
     $slave_master = $_POST["slave_master"];
     $dom_type = "SLAVE";
     if (!isset($error))
     {
             if (!is_valid_domain($domain))
             {
                     $error = "Zone name is invalid!";
             }
             elseif (domain_exists($domain))
             {
                     $error = "Zone already exists!";
             }
             elseif (!is_valid_ip($slave_master))
             {
                     $error = "IP of master NS for slave zone is not valid!";
             }
             else
             {
                     if(add_domain($domain, $owner, '', '', 1, $dom_type, $slave_master))
		     {
                                $success = _('Successfully added slave zone.');
		     }
             }
     }
}

include_once("inc/header.inc.php");

	if ((isset($error)) && ($error != ""))
	{
	        ?><div class="error"><?php echo _('Error'); ?>: <?php echo $error; ?></div><?php
	}
	elseif ((isset($success)) && ($success != ""))
	{
		?><div class="success"><?php echo $success; ?></div><?php
	}
	
	$users = show_users();
	
	?>
	    <h2><?php echo _('Add slave zone'); ?></h2>
	    <form method="post" action="add_zone_slave.php">
	     <table>
	      <tr>
	       <td class="n"><?php echo _('Zone name'); ?>:</td>
	       <td class="n">
	        <input type="text" class="input" name="domain" value="<?php if (isset($error)) print $_POST["domain"]; ?>">
	       </td>
	      </tr>
	      <tr>
	       <td class="n"><?php echo _('IP of master NS'); ?>:</td>
	       <td class="n">
	        <input type="text" class="input" name="slave_master" value="<?php if (isset($error)) print $_POST["slave_master"]; ?>">
	       </td>
	      </tr>
	      <tr>
	       <td class="n"><?php echo _('Owner'); ?>:</td>
	       <td class="n">
	        <select name="owner">
	         <?php 
	         foreach ($users as $u)
	         {
	           ?><option value="<?php echo $u['id'] ?>"><?php echo $u['fullname'] ?></option><?php
	         } 
	        ?>
	        </select>
	       </td>
	      </tr>
	      <tr>
	       <td class="n">&nbsp;</td>
	       <td class="n">
	        <input type="submit" class="button" name="submit" value="<?php echo _('Add domain'); ?>">
	       </td>
	      </tr>
	     </table>
	    </form>
<?php
include_once("inc/footer.inc.php");
?>
