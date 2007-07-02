<?php
require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

$num_all_domains = zone_count(0);
$doms = zone_count(0, LETTERSTART);
?>
   <h2><? echo _('List zones'); ?></h2>
<?
        echo "<div class=\"showmax\">";
        show_pages($doms,ROWAMOUNT);
        echo "</div>";

if ($num_all_domains > ROWAMOUNT)
{
        echo "<div class=\"showmax\">";
        show_letters(LETTERSTART);
        echo "</div>";
}
?>
   <table>
    <tr>
     <th>&nbsp;</th>
     <th><? echo _('Name'); ?></th>
     <th><? echo _('Type'); ?></th>
     <th><? echo _('Records'); ?></th>
     <th><? echo _('Owner'); ?></th>
    </tr>
    <tr>

<?
if ($num_all_domains < ROWAMOUNT) {
   $doms = get_domains(0,"all",ROWSTART,ROWAMOUNT);
} else {
   $doms = get_domains(0,LETTERSTART,ROWSTART,ROWAMOUNT);
   $num_show_domains = ($doms == -1) ? 0 : count($doms);
}

// If the user doesnt have any domains print a message saying so
if ($doms < 0)
{
	?>
    <tr>
     <td>&nbsp;</td>
     <td colspan="4"><? echo _('There are no zones.'); ?></td>
    </tr>
<?
}

// If he has domains, dump them (duh)
else
{
	foreach ($doms as $c)
	{
		?>
		
    <tr>
<?
		if (level(5))
		{
?>
     <td>
      <a href="edit.php?id=<? echo $c["id"] ?>"><img src="images/edit.gif" title="<? echo _('Edit zone') . " " . $c['name']; ?>" alt="[ <? echo _('Edit zone') . " " . $c['name']; ?> ]"></a>
      <a href="delete_domain.php?id=<? echo $c["id"] ?>"><img src="images/delete.gif" title="<? print _('Delete zone') . " " . $c['name']; ?>" alt="[<? echo _('Delete zone') . " " . $c['name']; ?>]"></a>
     </td>
<?
		}
		else
		{
?>
     <td class="n">
      &nbsp;
     </td>
<?
		}
?>
     <td class="y"><? echo $c["name"] ?></td>
     <td class="y"><? echo strtolower(get_domain_type($c["id"])) ?></td>
     <td class="y"><? echo $c["numrec"] ?></td>

<?
		$zone_owners = get_owners_from_domainid($c["id"]);
		if ($zone_owners == "")
		{
			echo "<td class=\"n\"></td>";
		}
		else
		{
			print "<td class=\"y\">".$zone_owners."</td>";
		}
		print "<tr>\n";
	}
}

?>
   </table>

<?
if ($num_all_domains < ROWAMOUNT) {
?>
   <p><? printf(_('This lists shows all %s zones(s) you have access to.'), $num_all_domains); ?></p>
<?
}
else
{
?>
   <p><? printf(_('This lists shows %s out of %s zones you have access to.'), $num_show_domains, $num_all_domains); ?></p>
<?
}
?>


<? // RZ TODO Check next, does it work? 
//  <small> echo _('You only administer some records of domains marked with an (*).'); </small>
?>

<?
include_once("inc/footer.inc.php");
?>
