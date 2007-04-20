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
// $Id: users.php,v 1.11 2003/02/05 23:22:33 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

if($_POST["submit"]
&& $_POST["username"] != ""
&& $_POST["password"] != "" 
&& $_POST["fullname"] != ""
&& $_POST["email"] != ""
&& $_POST["level"] > 0)
{
	if(substr_count($_POST["username"], " ") == 0)
	{
		add_user($_POST["username"], $_POST["password"], $_POST["fullname"], $_POST["email"], $_POST["level"], $_POST["description"], $_POST["active"]);
        	clean_page($BASE_URL . $BASE_PATH . "users.php");
        }
        else
        {
        	$error = _('Usernames can\'t contain spaces');
        }
}
elseif($_POST["submit"])
{
	$error = _('Please fill in all fields');
}

// Dirty hack, maybe revise?
include_once("inc/header.inc.php");
?>
<H2><? echo _('User admin'); ?></H2>
<P CLASS="nav">
<A HREF="index.php"><? echo _('DNS Admin'); ?></A> <A HREF="search.php"><? echo _('Search records'); ?></A></P><BR><?
// End

if (!level(10)) 
{
	error(ERR_LEVEL_10);
}

if ($error != "") 
{
        ?><H3><FONT COLOR="red"><? echo _('Error'); ?>: <?= $error ?></FONT></H3><?
}

echo "<B>" . _('Current users (click to edit)') . ":</B>";

$users = show_users('');

echo "<br /><br /><small><b>" . _('Number of users') . ":</b> ".count($users);

show_pages(count($users),ROWAMOUNT);
?>

<br /><br /><TABLE BORDER="0" CELLSPACING="4">
<TR STYLE="font-weight: Bold;"><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><? echo _('Name'); ?></TD><TD CLASS="tdbg"><? echo _('Domains'); ?></TD><TD CLASS="tdbg"><? echo _('Domain list'); ?></TD><TD CLASS="tdbg"><? echo _('Level'); ?></TD><TD CLASS="tdbg"><? echo _('Status'); ?></TD></TR>
<?
$users = show_users('',ROWSTART,ROWAMOUNT);
foreach ($users as $c)
{
        ?>
        <TR>
        <TD VALIGN="top" CLASS="tdbg"><A HREF="delete_user.php?id=<?= $c["id"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('Delete user'); ?> ]" BORDER="0"></A></TD>
        <TD VALIGN="top" CLASS="tdbg"><A HREF="edit_user.php?id=<?= $c["id"] ?>"><?= $c["fullname"] ?></A> (<?= $c["username"] ?>)</TD>
        <TD VALIGN="top" CLASS="tdbg"><?= $c["numdomains"] ?></TD>
        <TD CLASS="tdbg">
        <?
        $domains = get_domains_from_userid($c["id"]);
        foreach ($domains as $d)
        {
                ?><A HREF="delete_domain.php?id=<?= $d["id"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('Delete domain'); ?> ]" BORDER="0"></A>&nbsp;<A HREF="edit.php?id=<?= $d["id"] ?>"><?= $d["name"] ?></A><BR><?
        }
        ?></TD><TD CLASS="tdbg"><?= $c["level"] ?></TD><TD VALIGN="middle" CLASS="tdbg"><?= get_status($c["active"]) ?></TD></TR><?
        print "\n";
}
?>
</TABLE>
<BR><BR>

<FORM METHOD="post" action="users.php">
<B><? echo _('Create new user'); ?>:</B><BR>
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><? echo _('User name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="username" VALUE="<? if ($error) print $_POST["username"]; ?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Full name'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="fullname" VALUE="<? if ($error) print $_POST["fullname"]; ?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Password'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="password" VALUE="<? if ($error) print $_POST["password"]; ?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('E-mail'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="email" VALUE="<? if ($error) print $_POST["email"]; ?>"></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('User level'); ?>:</TD><TD CLASS="tdbg"><SELECT NAME="level"><OPTION VALUE="1">1 (<?= leveldescription(1) ?>)</OPTION><OPTION VALUE="5">5 (<?= leveldescription(5) ?>)</OPTION><OPTION VALUE="10">10 (<?= leveldescription(10) ?>)</OPTION></SELECT></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Description'); ?>:</TD><TD CLASS="tdbg"><TEXTAREA ROWS="6" COLS="30" CLASS="inputarea" NAME="description"><? if ($error) print $_POST["description"]; ?></TEXTAREA></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Active'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="checkbox" NAME="active" VALUE="1" CHECKED></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="submit" VALUE="<? echo _('Add user'); ?>"></TD></TR>
</TABLE>
</FORM>
<?
include_once("inc/footer.inc.php");
?>
