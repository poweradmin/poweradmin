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

require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");

if (!level(5))
{
	 error(ERR_LEVEL_5);
}

if($_POST["submit"])
{
	$master_ip = $_POST["master_ip"];
	$ns_name = $_POST["ns_name"];
	$account = $_POST["account"];
	if (!$error)
	{
		if (!is_valid_ip($master_ip) && !is_valid_ip6($master_ip))
		{
			$error = _('Given master IP address is not valid IPv4 or IPv6.');
		}
		elseif (!is_valid_hostname($ns_name))
		{
			$error = _('Given hostname for NS record not valid.');
		}
		elseif (!validate_account($account))
		{
			$error = _('Account name is not valid (may contain only alpha chars).');
		}
		else    
		{
			if(add_supermaster($master_ip, $ns_name, $account))
			{
				$success = _('Successfully added supermaster.');
			}
		}
	}
}

include_once("inc/header.inc.php");
    
    if ($error != "")
    {
    	?><div class="error"><? echo _('Error'); ?>: <? echo $error; ?></div><?
    }
    elseif ($success != "")
    {
    	?><div class="success"><? echo $success; ?></div><?
    }
    
    ?>
    <h2><? echo _('Add supermaster'); ?></h2>
    <form method="post" action="add_supermaster.php">
     <table>
      <tr>
       <td class="n"><? echo _('IP address of supermaster'); ?>:</td>
       <td class="n">
        <input type="text" class="input" name="master_ip" value="<? if ($error) print $_POST["master_ip"]; ?>">
       </td>
      </tr>
      <tr>
       <td class="n"><? echo _('Hostname in NS record'); ?>:</td>
       <td class="n">
        <input type="text" class="input" name="ns_name" value="<? if ($error) print $_POST["ns_name"]; ?>">
       </td>
      </tr>
      <tr>
       <td class="n"><? echo _('Account'); ?>:</td>
       <td class="n">
        <input type="text" class="input" name="account" value="<? if ($error) print $_POST["account"]; ?>">
       </td>
      </tr>
      <tr>
       <td class="n">&nbsp;</td>
       <td class="n">
        <input type="submit" class="button" name="submit" value="<? echo _('Add supermaster'); ?>">
       </td>
      </tr>
     </table>
    </form>
<?
include_once("inc/footer.inc.php");
?>
