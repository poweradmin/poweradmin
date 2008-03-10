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
        $domain = (isset($_POST['domain']) ? trim($_POST["domain"]) : '');
        $owner = (isset($_POST['owner']) ? $_POST["owner"] : 0 );
        $webip = (isset($_POST["webip"]) ? $_POST['webip'] : '');
        $mailip = (isset($_POST["mailip"]) ? $_POST['mailip'] : '');
        $empty = (isset($_POST["empty"]) ? $_POST['empty'] : 0);
        $dom_type = (isset($_POST["dom_type"]) ? $_POST["dom_type"] : "NATIVE");
        if(!$empty)
        {
                $empty = 0;
                if(!eregi('in-addr.arpa', $domain) && (!is_valid_ip($webip) || !is_valid_ip($mailip)) )
                {
                        $error = "Web or Mail ip is invalid!";
                }
        }
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
                //elseif (isset($mailip) && is_valid_ip(
                else
                {
                        add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type, '');
			$success = _('Successfully added master zone.');
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

	?>
	<h2>Add master zone</h2>
	<?php

	// Zone type set to master and native only, slave zones are created
	// on a different page. 
        $zone_types = array("MASTER", "NATIVE");
        $users = show_users();
        ?>
        <form method="post" action="add_zone_master.php">
         <table>
          <tr>
           <td class="n"><?php echo _('Zone name'); ?>:</td>
           <td class="n">
            <input type="text" class="input" name="domain" value="<?php if (isset($error)) print $_POST["domain"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="n"><?php echo _('Web IP'); ?>:</td>
           <td class="n">
            <input type="text" class="input" name="webip" value="<?php if (isset($error)) print $_POST["webip"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="n"><?php echo _('Mail IP'); ?>:</TD>
           <td class="n">
            <input type="text" class="input" name="mailip" value="<?php if (isset($error)) print $_POST["mailip"]; ?>">
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
           <td class="n"><?php echo _('Zone type'); ?>:</td>
           <td class="n">
            <select name="dom_type">
        <?php
        foreach($zone_types as $s)
        {
           ?><option value="<?php echo $s?>"><?php echo $s ?></option><?php
        }
        ?>
            </select>
           </td>
          </tr>
          <tr>
           <td class="n"><?php echo _('Create zone without applying records-template'); ?>:</td>
	   <td class="n"><input type="checkbox" name="empty" value="1"></td>
	  </tr>
          <tr>
	   <td class="n">&nbsp;</td>
	   <td class="n">
	    <input type="submit" class="button" name="submit" value="<?php echo _('Add zone'); ?>">
	   </td>
	  </tr>
         </table>
        </form>
<?php

include_once("inc/footer.inc.php");
