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

$num_all_domains = zone_count(0);
$doms = zone_count(0, LETTERSTART);
?>
   <h2><?php echo _('List all zones'); ?></h2>
<?php
        echo "<div class=\"showmax\">";
        show_pages($doms,$rowamount);
        echo "</div>";

if ($num_all_domains > $rowamount)
{
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART);
        echo "</div>";
}
?>
   <table>
    <tr>
     <th>&nbsp;</th>
     <th><?php echo _('Name'); ?></th>
     <th><?php echo _('Type'); ?></th>
     <th><?php echo _('Records'); ?></th>
     <th><?php echo _('Owner'); ?></th>
    </tr>
    <tr>

<?php
if ($num_all_domains < $rowamount) {
   $doms = get_domains(0,"all",ROWSTART,$rowamount);
} else {
   $doms = get_domains(0,LETTERSTART,ROWSTART,$rowamount);
   $num_show_domains = ($doms == -1) ? 0 : count($doms);
}

// If the user doesnt have any domains print a message saying so
if ($doms < 0)
{
	?>
    <tr>
     <td>&nbsp;</td>
     <td colspan="4"><?php echo _('There are no zones.'); ?></td>
    </tr>
<?php
}

// If he has domains, dump them (duh)
else
{
	foreach ($doms as $c)
	{
		?>
		
    <tr>
     <td>
      <a href="edit.php?id=<?php echo $c["id"] ?>"><img src="images/edit.gif" title="<?php echo _('Edit zone') . " " . $c['name']; ?>" alt="[ <?php echo _('Edit zone') . " " . $c['name']; ?> ]"></a>
<?php
		if (level(5))
		{
?>
      <a href="delete_domain.php?id=<?php echo $c["id"] ?>"><img src="images/delete.gif" title="<?php print _('Delete zone') . " " . $c['name']; ?>" alt="[<?php echo _('Delete zone') . " " . $c['name']; ?>]"></a>
<?php
		}
?>
     </td>
     <td class="y"><?php echo $c["name"] ?></td>
     <td class="y"><?php echo strtolower(get_domain_type($c["id"])) ?></td>
     <td class="y"><?php echo $c["numrec"] ?></td>

<?php
		$zone_owners = get_owners_from_domainid($c["id"]);
		if ($zone_owners == "")
		{
			echo "<td class=\"n\"></td>";
		}
		else
		{
			print "<td class=\"y\">".$zone_owners."</td>";
		}
		print "</tr>\n";
	}
}

?>
   </table>

<?php
if ($num_all_domains < $rowamount) {
?>
   <p><?php printf(_('This lists shows all %s zones(s) you have access to.'), $num_all_domains); ?></p>
<?php
}
else
{
?>
   <p><?php printf(_('This lists shows %s out of %s zones you have access to.'), $num_show_domains, $num_all_domains); ?></p>
<?php
}
?>


<?php // RZ TODO Check next, does it work? 
//  <small> echo _('You only administer some records of domains marked with an (*).'); </small>
?>

<?php
include_once("inc/footer.inc.php");
?>
