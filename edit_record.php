<?

require_once("inc/toolkit.inc.php");

if (isset($_GET["delid"])) {
   $db->query("DELETE FROM record_owners WHERE id='".$_GET["delid"]."'");
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
    $checkPartial = $db->queryOne("SELECT id FROM record_owners WHERE record_id='".$_GET["id"]."' AND user_id='".$_SESSION["userid"]."' LIMIT 1");
    if (empty($checkPartial)) {
        error(ERR_RECORD_ACCESS_DENIED);
    }
}
include_once("inc/header.inc.php");
?>
    <h2><? echo _('Edit record in zone'); ?> "<? echo  get_domain_name_from_id($_GET["domain"]) ?>"</h2>
<?

$x_result = $db->query("SELECT r.id,u.fullname FROM record_owners as r, users as u WHERE r.record_id='".$_GET['id']."' AND u.id=r.user_id");
if (level(10) && ($x_result->numRows() > 0)) 
{
?>
    <div id="meta">
     <div id="meta-left">
      <table>
       <tr>
        <th><? echo _('Sub-owners'); ?></td>
        <th>&nbsp;</td>
       </tr>
<?
	while ($x_r = $x_result->fetchRow()) 
	{
?>
        <tr>
	 <td class="tdbg"><? echo $x_r["fullname"]; ?></td>
	 <td class="tdbg"><a href="<? echo $_SERVER["PHP_SELF"]; ?>?id=<? echo $_GET["id"]; ?>&amp;domain=<? echo $_GET["domain"]; ?>&amp;delid=<? echo $x_r["id"]; ?>"><img src="images/delete.gif" alt="trash"></a></td>
	</tr>
<?
	}
?>
       </table>
      </div>
     </div>
<? 
}
?>
    <form method="post" action="edit_record.php">
     <input type="hidden" name="recordid" value="<? echo  $_GET["id"] ?>">
     <input type="hidden" name="domainid" value="<? echo  $_GET["domain"] ?>">
     <table>
      <tr>
       <th><? echo _('Name'); ?></td>
       <th>&nbsp;</td>
       <th><? echo _('Type'); ?></td>
       <th><? echo _('Priority'); ?></td>
       <th><? echo _('Content'); ?></td>
       <th><? echo _('TTL'); ?></td>
      </tr>
<?
	$rec = get_record_from_id($_GET["id"]);
?>
       <tr>
        <td>
<? 
if ($_SESSION[$_GET["domain"]."_ispartial"] == 1)  
{
?>
         <input type="hidden" name="name" value="<? echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.')?>" class="input">

<? echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>
<? 
} 
else 
{ 
?>
         <input type="text" name="name" value="<? echo  trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>" class="input">
<? 
} 
?>
.<? echo  get_domain_name_from_id($_GET["domain"]) ?>
        </td>
	<td class="n">IN</td>
	<td>
	 <select name="type">
<?
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
	<option<? echo  $add ?> value="<? echo  $c ?>"><? echo  $c ?></option><?
}

?>
         </select>
	</td>
	<td><input type="text" name="prio" value="<? echo  $rec["prio"] ?>" class="sinput"></td>
	<td><input type="text" name="content" value="<? echo  $rec["content"] ?>" class="input"></td>
	<td><input type="text" name="ttl" value="<? echo  $rec["ttl"] ?>" class="sinput"></td>
       </tr>
      </table>
      <p>
       <input type="submit" name="commit" value="<? echo _('Commit changes'); ?>" class="button">&nbsp;&nbsp;
       <input type="reset" name="reset" value="<? echo _('Reset changes'); ?>" class="button">
      </p>
     </form>
<?
include_once("inc/footer.inc.php");
?>
