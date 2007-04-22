<?php

// +--------------------------------------------------------------------+
// | PowerAdmin                                                         |
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team                        |
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal   |
// | program PowerAdmin as found on http://poweradmin.sf.net            |
// | The PowerAdmin program falls under the QPL License:                |
// | http://www.trolltech.com/developer/licensing/qpl.html              |
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>       |
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>                      |
// +--------------------------------------------------------------------+

// Filename: search.php
// Startdate: 9-01-2003
// Searches the database for corresponding records or domains.
//
// The sourecode for this program was donated by DeViCeD, THANKS!
//
// $Id: search.php,v 1.1 2003/01/09 23:23:39 azurazu Exp $
//

require_once('inc/toolkit.inc.php');

if (isset($_POST['s_submit']) || isset($_POST['q']))
{
	$submitted = true;
	$search_result = search_record($_POST['q']);
}


// we will continue after the search form ... 
include_once('inc/header.inc.php');
?>
<P><H2><? echo _('Search zones or records'); ?></H2></P>
<P CLASS="nav">
<A HREF="index.php"><? echo _('DNS Admin'); ?></A> 
<?
if (level(10))
{
	?><A HREF="users.php"><? echo _('User admin'); ?></A> <?
}
?>
</P><BR>
<? echo _('Type a hostname or a record in the box below and press search to see if the record exists in the system.'); ?>
	<table border = "0" cellspacing = "4">
	<form method = "post" action="<?=$_SERVER['PHP_SELF']?>">
		<tr>
			<td class = "tdbg"><b><? echo _('Enter a hostname or IP address'); ?></b></td>
			<td width = "510" class = "tdbg"><input type = "text" class = "input" name = "q"></td>
		</tr>
		<tr>
			<td class = "tdbg">&nbsp;</td>
			<td class = "tdbg"><input type = "submit" class = "button" name = "s_submit" value = "<? echo _('Search'); ?>"></td>
		</tr>
	</form>
	</table>


<?php
// results
if ($submitted)
{
	echo '<br><br>';

  	// let's check if we found any domains ...
	if (count($search_result) == 2 && count($search_result['domains']))
  	{
	?>
	<b><? echo _('Domains found'); ?>:</b>
	<p>
	<table border = "0" cellspacing = "4">
		<tr style = "font-weight: Bold;">
			<td class = "tdbg">&nbsp;</td>
			<td class = "tdbg"><? echo _('Name'); ?></td>
			<td class = "tdbg"><? echo _('Records'); ?></td>
			<td class = "tdbg"><? echo _('Owner'); ?></td>
		</tr>
		<?php
		foreach($search_result['domains'] as $d)
		{
			?>	
			<tr>
			<td class = "tdbg">
			<?php 
			if (level(5))
			{
				echo '<a href = "delete_domain.php?id='.$d['id'].'"><img src = "images/delete.gif" alt = "[ ' .  _('Delete zone') . ' ]" border = "0"></a>';
			}
			else 
			{
				echo '&nbsp;';
			}
			?>
			</td>
			<td class = "tdbg"><a href = "edit.php?id=<?=$d['id']?>"><?=$d['name']?></a></td>
			<td class = "tdbg"><?=$d['numrec']?></td>
			<td class = "tdbg"><?=get_owner_from_id($d['owner'])?></td>
			</tr>
			<?php
		} // end foreach ...
		?>
	</table>
	<br><br>
	<?php
	} // end if
	
	
	// any records ?!
	if(count($search_result['records']))
	{
		?>
		<b><? echo _('Records found'); ?>:</b>
		<p>
		<table border = "0" cellspacing = "4">
			<tr style = "font-weight: Bold;">
				<td class = "tdbg">&nbsp;</td>
				<td class = "tdbg"><? echo _('Name'); ?></td>
				<td class = "tdbg"><? echo _('Type'); ?></td>
				<td class = "tdbg"><? echo _('Content'); ?></td>
				<td class = "tdbg"><? echo _('Priority'); ?></td>
				<td class = "tdbg"><? echo _('TTL'); ?></td>
			</tr>
		<?php
		foreach($search_result['records'] as $r)
		{
		?>
			<tr>
				<td class = "tdbg">
			<?php
			if (($r["type"] != "SOA" && $r["type"] != "NS") ||
			  ($GLOBALS["ALLOW_SOA_EDIT"] && $r["type"] == "SOA") ||
			  ($GLOBALS["ALLOW_NS_EDIT"] && $r["type"] == "NS") ||
			  ($r["type"] == "NS" && get_name_from_record_id($r["id"]) != get_domain_name_from_id(recid_to_domid($r["id"])) && 
			  $GLOBALS["ALLOW_NS_EDIT"] != 1))
			{
				?>
				<a href = "edit_record.php?id=<?=$r['id']?>&amp;domain=<?=$r['domain_id']?>"><img src = "images/edit.gif" alt = "[ <? echo _('Edit record'); ?> ]" border = "0"></a>
				<a href = "delete_record.php?id=<?=$r['id']?>&amp;domain=<?=$r['domain_id']?>"><img src = "images/delete.gif" alt = "[ <? echo _('Delete record'); ?> ]" border = "0"></a>
				<?php 
			} // big if ;-)
			?>
			</td>
			<td style = "border: 1px solid #000000;"><?=$r['name']?></td>
			<td style = "border: 1px solid #000000;"><?=$r['type']?></td>
			<td style = "border: 1px solid #000000;"><?=$r['content']?></td>
			<?php
			if ($r['prio'] != 0)
			{
				?><td style = "border: 1px solid #000000;"><?=$r['prio']?></td><?php
			}
			else 
			{
			?><td class = "tdbg"></td><?php
			} // else
			?><td style = "border: 1px solid #000000;"><?=$r['ttl']?></td>
			</tr>
			<?php
		} // foreach
	?>
	</table>
	<?php
	} // if
	if(count($search_result['domains']) == 0 && count($search_result['records']) == 0)
	{
	?>
		<table border = "0" cellspacing = "4">
			<tr>
				<td width = "510" class = "tdbg">
				<? echo _('Nothing found for query'); ?> "<?=$_POST['q']?>"
				</td>
			</tr>
		</table>
	<?
	}
		
}
include_once('inc/footer.inc.php');
?>

