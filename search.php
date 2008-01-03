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

require_once('inc/toolkit.inc.php');

if (isset($_POST['s_submit']) || isset($_POST['q']))
{
	$submitted=true;
	$search_result=search_record($_POST['q']);
}

// we will continue after the search form ... 
include_once('inc/header.inc.php');
?>

    <h2><? echo _('Search zones or records'); ?></h2>
    <h3>Query</h3>
    <table>
     <form method="post" action="<? echo $_SERVER['PHP_SELF']?>">
      <tr>
       <td class="n"><? echo _('Enter a hostname or IP address'); ?></td>
       <td class="n"><input type="text" class="input" name="q"></td>
      </tr>
      <tr>
       <td class="n">&nbsp;</td>
       <td class="n"><input type="submit" class="button" name="s_submit" value="<? echo _('Search'); ?>"></td>
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
	<h4><? echo _('Zones found'); ?>:</h4>
	<table>
	 <tr>
	  <th>&nbsp;</th>
	  <th><? echo _('Name'); ?></th>
	  <th><? echo _('Records'); ?></th>
	  <th><? echo _('Owner'); ?></th>
         </tr>
<?php
foreach($search_result['domains'] as $d)
{
?>
         <tr>
<?
  if (level(5))
  {
  ?>
     <td class="n">
      <a href="edit.php?id=<? echo $d["id"] ?>"><img src="images/edit.gif" title="<? echo _('Edit zone') . " " . $d['name']; ?>" alt="[ <? echo _('Edit zone') . " " . $d['name']; ?> ]"></a>
      <a href="delete_domain.php?id=<? echo $d["id"] ?>"><img src="images/delete.gif" title="<? print _('Delete zone') . " " . $d['name']; ?>" alt="[<? echo _('Delete zone') . " " . $d['name']; ?>]"></a>
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
     <td class="y"><? echo $d['name']?></td>
     <td class="y"><? echo $d['numrec']?></td>
     <td class="y"><? echo get_owner_from_id($d['owner'])?></td>
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
		<table>
			<tr>
				<td class="n">&nbsp;</td>
				<td class="n"><? echo _('Name'); ?></td>
				<td class="n"><? echo _('Type'); ?></td>
				<td class="n"><? echo _('Content'); ?></td>
				<td class="n"><? echo _('Priority'); ?></td>
				<td class="n"><? echo _('TTL'); ?></td>
			</tr>
		<?php
		foreach($search_result['records'] as $r)
		{
		?>
			<tr>
				<td class="n">
			<?php
			if (($r["type"] != "SOA" && $r["type"] != "NS") ||
			  ($GLOBALS["ALLOW_SOA_EDIT"] && $r["type"] == "SOA") ||
			  ($GLOBALS["ALLOW_NS_EDIT"] && $r["type"] == "NS") ||
			  ($r["type"] == "NS" && get_name_from_record_id($r["id"]) != get_domain_name_from_id(recid_to_domid($r["id"])) && 
			  $GLOBALS["ALLOW_NS_EDIT"] != 1))
			{
				?>
				<a href="edit_record.php?id=<? echo $r['id']?>&amp;domain=<? echo $r['domain_id']?>"><img src="images/edit.gif" alt="[ <? echo _('Edit record'); ?> ]" border="0"></a>
				<a href="delete_record.php?id=<? echo $r['id']?>&amp;domain=<? echo $r['domain_id']?>"><img src="images/delete.gif" alt="[ <? echo _('Delete record'); ?> ]" border="0"></a>
				<?php 
			} // big if ;-)
			?>
			</td>
			<td class="y"><? echo $r['name']?></td>
			<td class="y"><? echo $r['type']?></td>
			<td class="y"><? echo $r['content']?></td>
			<?php
			if ($r['prio'] != 0)
			{
				?><td class="y"><? echo $r['prio']?></td><?php
			}
			else 
			{
			?><td class="n"></td><?php
			} // else
			?><td class="y"><? echo $r['ttl']?></td>
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
		<table border="0" cellspacing="4">
			<tr>
				<td width="510" class="n">
				<? echo _('Nothing found for query'); ?> "<? echo $_POST['q']?>".
				</td>
			</tr>
		</table>
	<?
	}
		
}
include_once('inc/footer.inc.php');
?>

