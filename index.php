<?php
session_start();
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
// $Id: index.php,v 1.13 2003/05/10 20:20:05 azurazu Exp $
//

require_once("inc/toolkit.inc.php");

/*
// Checks if the user migrated his database, can be deprecated in the future
function check_updated()
{
	global $db;
	$checkzone = $db->query("select * from zones");
	$zonetables = $checkzone->tableInfo();
	//var_dump($zonetables[1]);
	if(strcmp($zonetables[1]["name"], "name") == 0)
	{
		include_once("inc/header.inc.php");
		error("You have to migrate your database first! 
		
		The reason for this migration is that PowerAdmin wasnt supporting the gmysql backend yet. Now it fully does we have to support it aswell. 
		The gmysql users another table and other fields though, therefore we had to change the layout of the zones table. In this version thats fully done, but before this we have to migrate it. 
		
		Please be sure you have a working backup of your data! 
		we assume it all works but cant guarantuee it for 100% because we dont have 
		too many betatesters.<BR>
		
		Do the following to migrate: 
		- rename the file migrator.php-pa in your webdir to migrator.php.
	 	- Go <A HREF='migrator.php'>here</A> to migrate it.
		
		It is recommended to synchronize your database aswell after the update");
		die();
	}
}

// Call above function
check_updated();
*/

if ($_POST["submit"])
{
	$domain = trim($_POST["domain"]);
	$owner = $_POST["owner"];
	$webip = $_POST["webip"];
	$mailip = $_POST["mailip"];
	$empty = $_POST["empty"];
	$dom_type = isset($_POST["dom_type"]) ? $_POST["dom_type"] : "NATIVE";
	if(!$empty)
	{
		$empty = 0;
		if(!eregi('in-addr.arpa', $domain) && (!is_valid_ip($webip) || !is_valid_ip($mailip)) )
		{
			$error = "Web or Mail ip is invalid!";
		}
	}
	if (!$error)
	{
		if (!is_valid_domain($domain))
		{
			$error = "Domain name is invalid!";
		}
		elseif (domain_exists($domain))
		{
			$error = "Domain already exists!";
		}
		//elseif (isset($mailip) && is_valid_ip(
		else
		{
			add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type);
			clean_page();
		}
	}
}

if($_POST["passchange"])
{
    if(strlen($_POST["newpass"]) < 4)
    {
        error('Length of the pass should be at least 4');
    }
    else
    {
	   change_user_pass($_POST["currentpass"], $_POST["newpass"], $_POST["newpass2"]);
	}
}

include_once("inc/header.inc.php");
?>
<H2>DNS Admin</H2>

<P FONT CLASS="nav">
<?
if (level(10))
{
	?><A HREF="users.php"><? echo _('User Admin'); ?></A> <A HREF="seq_update.php"><? echo _('Synchronize Database'); ?></A><?
}
?>
 <A HREF="search.php"><? echo _('Search records'); ?></A>
</P>

<BR>
<? echo _('Welcome'); ?>, <?= $_SESSION["name"] ?>.
<BR>

<? echo _('Your userlevel is'); ?>: <?= $_SESSION["level"] ?> (<?= leveldescription($_SESSION["level"]) ?>)
<BR><BR>

<?
if ($error != "")
{
	?><H3><FONT COLOR="red"><? echo _('Error'); ?>: <?= $error ?></FONT></H3><?
}
?>

<B><? echo _('Current domains in DNS (click to view or edit)'); ?>:</B>
<BR>

<?
$doms = get_domains(0);
$num_domains = count($doms);
echo "<br /><small><b><? echo _('Number of domains'); ?>: </b>".$num_domains."<br />";

if ($num_domains > ROWAMOUNT) {
   show_letters(LETTERSTART,$doms);
   echo "<br />";
}

$doms = get_domains(0,LETTERSTART);
show_pages(count($doms),ROWAMOUNT);

?>

<br />
<TABLE BORDER="0" CELLSPACING="4">
<TR STYLE="font-weight: Bold;"><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><? echo _('Name'); ?></TD><TD CLASS="tdbg"><? echo _('Records'); ?></TD><TD CLASS="tdbg"><? echo _('Owner'); ?></TD></TR>
<?

if ($num_domains < ROWAMOUNT) {
   $doms = get_domains(0,"all",ROWSTART,ROWAMOUNT);
} else {
   $doms = get_domains(0,LETTERSTART,ROWSTART,ROWAMOUNT);
}

// If the user doesnt have any domains print a message saying so
if ($doms < 0)
{
	?><TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg" COLSPAN="4"><b><? echo _('No domains in this listing, sorry.'); ?></b></FONT></TD></TR><?
}

// If he has domains, dump them (duh)
else
{
	foreach ($doms as $c)
	{
		?><TR><TD CLASS="tdbg">
		<? if (level(5))
		{
			?>
			<A HREF="delete_domain.php?id=<?= $c["id"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('delete zone'); ?> ]" BORDER="0"></A><?
		}
		else
		{
			print "&nbsp;";
		}
		?>
		</TD><TD CLASS="tdbg"><A HREF="edit.php?id=<?= $c["id"] ?>"><?= $c["name"] ?></A></TD><TD CLASS="tdbg"><?= $c["numrec"] ?></TD><TD CLASS="tdbg"><?= get_owner_from_id($c["owner"]) ?></TD></TR><?
		print "\n";
	}
}

?>
</TABLE>

<small><? echo _('You only administer some records of domains marked with an (*).'); ?></small>

<?
if (level(5))
{
	// Get some data.
	$server_types = array("MASTER", "SLAVE", "NATIVE");
	$users = show_users();
	?>
	<BR><BR>
	<FORM METHOD="post" ACTION="index.php">
	<B><? echo _('Create new domain'); ?>:</B><BR>
	<TABLE BORDER="0" CELLSPACING="4">
	<TR><TD CLASS="tdbg"><? echo _('Domain name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="domain" VALUE="<? if ($error) print $_POST["domain"]; ?>"></TD></TR>
	<TR><TD CLASS="tdbg"><? echo _('Web IP'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="webip" VALUE="<? if ($error) print $_POST["webip"]; ?>"></TD></TR>
	<TR><TD CLASS="tdbg"><? echo _('Mail IP'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="mailip" VALUE="<? if ($error) print $_POST["mailip"]; ?>"></TD></TR>
	<TR><TD CLASS="tdbg"><? echo _('Owner'); ?>:</TD><TD CLASS="tdbg">
	<SELECT NAME="owner">
	<?
	foreach ($users as $u)
	{
        	?><OPTION VALUE="<?= $u['id'] ?>"><?= $u['fullname'] ?></OPTION><?
	}
	?>
	</SELECT>
	</TD></TR>
	<?
	if($MASTER_SLAVE_FUNCTIONS == true)
	{
	?>
	<TR><TD CLASS="tdbg"><? echo _('Domain type'); ?>:</TD><TD CLASS="tdbg">
	<SELECT NAME="dom_type">
	<?
	foreach($server_types as $s)
	{
		?><OPTION VALUE="<?=$s?>"><?=$s ?></OPTION><?
	}
	?>
	</SELECT>
	</TD></TR>
	<? } ?>
	<TR><TD CLASS="tdbg"><? echo _('Create zone without'); ?><BR><? echo _('applying records-template'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="checkbox" NAME="empty" VALUE="1"></TD></TR>
	<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="submit" VALUE="<? echo _('Add domain'); ?>"></TD></TR>
	</TABLE>
	</FORM>
<?
}
?>

<BR><BR>
<FORM METHOD="post" ACTION="index.php">
<B><? echo _('Change your password'); ?>:</B><BR>
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><? echo _('Current password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="currentpass" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('New Password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="newpass" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('New Password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="newpass2" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="passchange" VALUE="<? echo _('Change password'); ?>"></TD></TR>
</TABLE>
</FORM>
<?
include_once("inc/footer.inc.php");
?>
