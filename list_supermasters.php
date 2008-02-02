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
include_once("inc/header.inc.php");

if (!level(5))
{
?>
     <h3><?php echo _('Oops!'); ?></h3>
     <p><?php echo _('You are not allowed to add supermasters with your current access level!'); ?></p>
<?php
} 
else
{

	$supermasters = get_supermasters(0);
	$num_supermasters = ($supermasters == -1) ? 0 : count($supermasters);
	?>

	   <h3><?php printf(_('List all %s supermasters'), $num_supermasters); ?></h3>
	   <table>
	    <tr>
	     <th>&nbsp;</td>
	     <th><?php echo _('IP address of supermaster'); ?></td>
	     <th><?php echo _('Hostname in NS record'); ?></td>
	     <th><?php echo _('Account'); ?></td>
	    </tr>
	<?php
	   if ($num_supermasters == 0)
	   {
	?>
	    <tr>
	     <td class="n">&nbsp;</td>
	     <td class="n" colspan="3">
	      <?php echo _('No supermasters in this listing, sorry.'); ?>
	     </td>
	    </tr>
	<?php
	   }
	   else
	   {
		   foreach ($supermasters as $c)
		   {
	?>
	    <tr>
	     <td class="n">
	      <a href="delete_supermaster.php?master_ip=<?php echo $c["master_ip"] ?>"><img src="images/delete.gif" title="<?php print _('Delete supermaster') . ' ' . $c["master_ip"]; ?>" alt="[ <?php echo _('Delete supermaster'); ?> ]"></a>
	     </td>
	     <td class="y"><?php echo $c["master_ip"] ?></td>
	     <td class="y"><?php echo $c["ns_name"] ?></td>
	     <td class="y"><?php echo $c["account"] ?></td>
	    </tr>
	<?php
		   }
	   }
	?>
	   </table>
<?php
}

include_once("inc/footer.inc.php");
?>
