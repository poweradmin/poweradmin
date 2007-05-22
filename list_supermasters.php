<?php
require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");

if (!level(5))
{
?>
     <h3><? echo _('Oops!'); ?></h3>
     <p><? echo _('You are not allowed to add supermasters with your current access level!'); ?></p>
<?
} 
else
{

	$supermasters = get_supermasters(0);
	$num_supermasters = count($supermasters);
	if ($supermasters < 0)
	{
		$num_supermasters = "0";
	}
	else
	{
		$num_supermasters = count($supermasters);
	}

	?>

	   <h3><? printf(_('List all %s supermasters'), $num_supermasters); ?></h3>
	   <table>
	    <tr>
	     <th>&nbsp;</td>
	     <th><? echo _('IP address of supermaster'); ?></td>
	     <th><? echo _('Hostname in NS record'); ?></td>
	     <th><? echo _('Account'); ?></td>
	    </tr>
	<?
	   if ($num_supermasters == 0)
	   {
	?>
	    <tr>
	     <td class="n">&nbsp;</td>
	     <td class="n" colspan="3">
	      <? echo _('No supermasters in this listing, sorry.'); ?>
	     </td>
	    </tr>
	<?
	   }
	   else
	   {
		   foreach ($supermasters as $c)
		   {
	?>
	    <tr>
	     <td class="n">
	      <a href="delete_supermaster.php?master_ip=<?= $c["master_ip"] ?>"><img src="images/delete.gif" title="<? print _('Delete supermaster') . ' ' . $c["master_ip"]; ?>" alt="[ <? echo _('Delete supermaster'); ?> ]"></a>
	     </td>
	     <td class="y"><?= $c["master_ip"] ?></td>
	     <td class="y"><?= $c["ns_name"] ?></td>
	     <td class="y"><?= $c["account"] ?></td>
	    </tr>
	<?
		   }
	   }
	?>
	   </table>
<?
}

include_once("inc/footer.inc.php");
?>
