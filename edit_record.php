<?php

// +--------------------------------------------------------------------+
// | PowerAdmin								|
// +--------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PowerAdmin Team			|
// +--------------------------------------------------------------------+
// | This source file is subject to the license carried by the overal	|
// | program PowerAdmin as found on http://poweradmin.sf.net		|
// | The PowerAdmin program falls under the QPL License:		|
// | http://www.trolltech.com/developer/licensing/qpl.html		|
// +--------------------------------------------------------------------+
// | Authors: Roeland Nieuwenhuis <trancer <AT> trancer <DOT> nl>	|
// |          Sjeemz <sjeemz <AT> sjeemz <DOT> nl>			|
// +--------------------------------------------------------------------+

//
// $Id: edit_record.php,v 1.9 2003/05/14 22:48:13 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

if (isset($_GET["delid"])) {
   $db->query("DELETE FROM record_owners WHERE id='".$_GET["delid"]."'");
}

$xsid = (isset($_GET['id'])) ? $_GET['id'] : $_POST['recordid'];

if(!xs(recid_to_domid($xsid)))
{
    error(ERR_RECORD_ACCESS_DENIED);
}

/*
if($_SESSION["partial_".get_domain_name_from_id($_GET["domain"])] == 1 && !isset($_POST["recordid"])) 
{
    $checkPartial = $db->getOne("SELECT id FROM record_owners WHERE record_id='".$_GET["id"]."' AND user_id='".$_SESSION["userid"]."' LIMIT 1");
    if (empty($checkPartial)) {
        error(ERR_RECORD_ACCESS_DENIED);
    }
}
*/

if ($_POST["commit"])
{
        edit_record($_POST["recordid"], $_POST["domainid"], $_POST["name"], $_POST["type"], $_POST["content"], $_POST["ttl"], $_POST["prio"]);
        clean_page("edit.php?id=".$_POST["domainid"]);
} elseif($_SESSION["partial_".get_domain_name_from_id($_GET["domain"])] == 1)
{
    $checkPartial = $db->getOne("SELECT id FROM record_owners WHERE record_id='".$_GET["id"]."' AND user_id='".$_SESSION["userid"]."' LIMIT 1");
    if (empty($checkPartial)) {
        error(ERR_RECORD_ACCESS_DENIED);
    }
}


include_once("inc/header.inc.php");

?>
<H2><? echo _('Edit record in zone'); ?> "<?= get_domain_name_from_id($_GET["domain"]) ?>"</H2>
<FONT CLASS="nav"><BR><A HREF="index.php"><? echo _('DNS Admin'); ?></A> &gt;&gt; <A HREF="edit.php?id=<?= $_GET["domain"] ?>"><?= get_domain_name_from_id($_GET["domain"]) ?></A> &gt;&gt; <? echo _('Edit record'); ?><BR><BR></FONT>

<FORM METHOD="post" ACTION="edit_record.php">
<INPUT TYPE="hidden" NAME="recordid" VALUE="<?= $_GET["id"] ?>">
<INPUT TYPE="hidden" NAME="domainid" VALUE="<?= $_GET["domain"] ?>">
<TABLE BORDER="0" CELLSPACING="4">
<TR STYLE="font-weight: Bold"><TD CLASS="tdbg"><? echo _('Name'); ?></TD><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><? echo _('Type'); ?></TD><TD CLASS="tdbg"><? echo _('Priority'); ?></TD><TD CLASS="tdbg"><? echo _('Content'); ?></TD><TD CLASS="tdbg"><? echo _('TimeToLive'); ?></TD></TR>

<?
	$rec = get_record_from_id($_GET["id"]);
?>

<TR><TD CLASS="tdbg">

<? if ($_SESSION[$_GET["domain"]."_ispartial"] == 1)  { ?>

<INPUT TYPE="hidden" NAME="name" VALUE="<?= trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.')?>" CLASS="input">

<?= trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>
<? } else { ?>
<INPUT TYPE="text" NAME="name" VALUE="<?= trim(str_replace(get_domain_name_from_id($_GET["domain"]), '', $rec["name"]), '.') ?>" CLASS="input">
<? } ?>
.<?= get_domain_name_from_id($_GET["domain"]) ?></TD><TD CLASS="tdbg">IN</TD><TD CLASS="tdbg"><SELECT NAME="type">

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
	<OPTION<?= $add ?> VALUE="<?= $c ?>"><?= $c ?></OPTION><?
}

?>
</SELECT></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="prio" VALUE="<?= $rec["prio"] ?>" CLASS="sinput"></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="content" VALUE="<?= $rec["content"] ?>" CLASS="input"></TD><TD CLASS="tdbg"><INPUT TYPE="text" NAME="ttl" VALUE="<?= $rec["ttl"] ?>" CLASS="sinput"></TD></TR>
</TABLE>
<BR><INPUT TYPE="submit" NAME="commit" VALUE="<? echo _('Commit changes'); ?>" CLASS="button">&nbsp;&nbsp;<INPUT TYPE="reset" NAME="reset" VALUE="<? echo _('Reset changes'); ?>" CLASS="button">
</FORM>

<?if (level(10)) { ?>
<table style="width:140px">
<tr><td CLASS="tdbg"><b><? echo _('Sub-users'); ?></b></td><td CLASS="tdbg"> </td></tr>
<?
$x_result = $db->query("SELECT r.id,u.username FROM record_owners as r, users as u WHERE r.record_id='".$_GET['id']."' AND u.id=r.user_id");
while ($x_r = $x_result->fetchRow()) {
   echo "<tr><td CLASS=\"tdbg\">".$x_r["username"]."</td><td CLASS=\"tdbg\">";
   echo "<a href=\"".$_SERVER["PHP_SELF"]."?id=".$_GET["id"]."&domain=".$_GET["domain"]."&delid=".$x_r["id"]."\">";
   echo "<img src=\"images/delete.gif\" alt=\"" . _('trash') . "\" border=\"0\"/></a></td></tr>";
}
?>
</table>
<? }

include_once("inc/footer.inc.php");

?>
