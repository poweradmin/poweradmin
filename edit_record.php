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

if (isset($_GET["delid"])) {
   delete_record_owner($_GET["domain"],$_GET["delid"],$_GET["id"]);
}

$xsid = (isset($_GET['id'])) ? $_GET['id'] : $_POST['recordid'];

if(!xs(recid_to_domid($xsid)))
{
    error(ERR_RECORD_ACCESS_DENIED);
}

if ($_POST["commit"])
{
        edit_record($_POST["recordid"], $_POST["domainid"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
        clean_page("edit.php?id=".$_POST["domainid"]);
} elseif($_SESSION["partial_".get_domain_name_from_id($_GET["domain"])] == 1)
{
	$db->setLimit(1);
    $checkPartial = $db->queryOne("SELECT id FROM record_owners WHERE record_id=".$db->quote($_GET["id"])." AND user_id=".$db->quote($_SESSION["userid"]));
    if (empty($checkPartial)) {
        error(ERR_RECORD_ACCESS_DENIED);
    }
}
include_once("inc/header.inc.php");
?>
    <h2><?php echo _('Edit record in zone'); ?> "<?php echo  get_domain_name_from_id($_GET["domain"]) ?>"</h2>
<?php

$x_result = $db->query("SELECT r.id,u.fullname FROM record_owners as r, users as u WHERE r.record_id=".$db->quote($_GET['id'])." AND u.id=r.user_id");
if (level(10) && ($x_result->numRows() > 0)) 
{
?>
    <div id="meta">
     <div id="meta-left">
      <table>
       <tr>
        <th><?php echo _('Sub-owners'); ?></td>
        <th>&nbsp;</td>
       </tr>
<?php
	while ($x_r = $x_result->fetchRow()) 
	{
?>
        <tr>
	 <td class="tdbg"><?php echo $x_r["fullname"]; ?></td>
	 <td class="tdbg"><a href="<?php echo $_SERVER["PHP_SELF"]; ?>?id=<?php echo $_GET["id"]; ?>&amp;domain=<?php echo $_GET["domain"]; ?>&amp;delid=<?php echo $x_r["id"]; ?>"><img src="images/delete.gif" alt="trash"></a></td>
	</tr>
<?php
	}
?>
       </table>
      </div>
     </div>
<?php 
}
?>
    <form method="post" action="edit_record.php">
     <input type="hidden" name="recordid" value="<?php echo  $_GET["id"] ?>">
     <input type="hidden" name="domainid" value="<?php echo  $_GET["domain"] ?>">
     <table>
      <tr>
       <th><?php echo _('Name'); ?></td>
       <th>&nbsp;</td>
       <th><?php echo _('Type'); ?></td>
       <th><?php echo _('Priority'); ?></td>
       <th><?php echo _('Content'); ?></td>
       <th><?php echo _('TTL'); ?></td>
      </tr>
<?php
	$rec = get_record_from_id($_GET["id"]);
?>
       <tr>
        <td>
<?php 
if ($_SESSION[$_GET["domain"]."_ispartial"] == 1)  
{
?>
         <input type="hidden" name="name" value="<?php echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.')?>" class="input">

<?php echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>
<?php 
} 
else 
{ 
?>
         <input type="text" name="name" value="<?php echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>" class="input">
<?php 
} 
?>
.<?php echo  get_domain_name_from_id($_GET["domain"]) ?>
        </td>
	<td class="n">IN</td>
	<td>
	 <select name="type">
<?php
foreach (get_record_types() as $c)
{
	if ($c == $rec["type"])
	{
		$add = " SELECTED";
	}
	else
	{
		$add = "";
	}
	?>
	<option<?php echo  $add ?> value="<?php echo  $c ?>"><?php echo  $c ?></option><?php
}

?>
         </select>
	</td>
	<td><input type="text" name="prio" value="<?php echo  $rec["prio"] ?>" class="sinput"></td>
	<td><input type="text" name="content" value="<?php echo  $rec["content"] ?>" class="input"></td>
	<td><input type="text" name="ttl" value="<?php echo  $rec["ttl"] ?>" class="sinput"></td>
       </tr>
      </table>
      <p>
       <input type="submit" name="commit" value="<?php echo _('Commit changes'); ?>" class="button">&nbsp;&nbsp;
       <input type="reset" name="reset" value="<?php echo _('Reset changes'); ?>" class="button">
      </p>
     </form>
<?php
include_once("inc/footer.inc.php");
?>
