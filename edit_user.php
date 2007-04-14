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
// $Id: edit_user.php,v 1.6 2002/12/10 01:29:47 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

if($_POST["commit"])
{
	if($_POST["username"] && $_POST["level"] && $_POST["fullname"])
	{
		if(!isset($_POST["active"]))
		{
			$active = 0;
		}
		else
		{
			$active = 1;
		}
		if(edit_user($_POST["number"], $_POST["username"], $_POST["fullname"], $_POST["email"], $_POST["level"], $_POST["description"], $active, $_POST["password"]))
		{
			clean_page($BASE_URL . $BASE_PATH . "users.php");
		}
		else
		{
			error("Error editting user!");
		}
	}
}

include_once("inc/header.inc.php");

if (!level(10))
{
	error("You need user level 10 to view this page... How did you get here, anyway?");
}

?>
<H2><? echo _('Edit user'); ?> "<?= get_fullname_from_userid($_GET["id"]) ?>"</H2>
<?
if (level(10))
{
?>
<FONT CLASS="nav"><BR><A HREF="users.php"><? echo _('User Admin'); ?></A> &gt;&gt; <? echo _('Edit User'); ?></FONT><BR><BR>
<?
}

$r = array();
$r = get_user_info($_GET["id"]);

?>
<FORM  METHOD="post">
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><? echo _('User name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="username" VALUE="<?=$r["username"]?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Full name'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="fullname" VALUE="<?=$r["fullname"]?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Password'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="password" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('E-mail'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="email" VALUE="<?=$r["email"]?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('User level'); ?>:</TD><TD CLASS="tdbg"><SELECT NAME="level"><OPTION VALUE="1" <? if($r["level"] == 1) { echo "SELECTED"; } ?>>1 (<? echo _('Normal user'); ?>)</OPTION><OPTION VALUE="5" <? if($r["level"] == 5) { echo "SELECTED"; } ?>>5 (<? echo _('Administrator'); ?>)</OPTION><OPTION VALUE="10" <? if($r["level"] == 10) { echo "SELECTED"; } ?>>10 (<? echo _('Administrator w/ user admin rights'); ?>)</OPTION></SELECT></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Description'); ?>:</TD><TD CLASS="tdbg"><TEXTAREA ROWS="6" COLS="30" CLASS="inputarea" NAME="description"><?=$r["description"]?></TEXTAREA></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Active'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="checkbox" NAME="active" VALUE="1" <? if($r["active"]) { ?>CHECKED<? } ?>></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="commit" VALUE="<? echo _('Commit changes'); ?>"></TD></TR>
<INPUT TYPE="HIDDEN" NAME="number" VALUE="<?= $_GET["id"] ?>">
</TABLE>
</FORM>
<?

include_once("inc/footer.inc.php");

?>
