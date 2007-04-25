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

require_once("inc/i18n.inc.php");
require_once("inc/toolkit.inc.php");

if ($_POST["submit"])
{
	$domain = trim($_POST["domain"]);
	$owner = $_POST["owner"];
	$webip = $_POST["webip"];
	$mailip = $_POST["mailip"];
	$empty = $_POST["empty"];
	$dom_type = isset($_POST["dom_type"]) ? $_POST["dom_type"] : "NATIVE";
	$slave_master = $_POST["slave_master"];
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
		elseif ($dom_type == "SLAVE" && !is_valid_ip($slave_master))
		{
			$error = "IP of master NS for slave domain is not valid!";
		}
		//elseif (isset($mailip) && is_valid_ip(
		else
		{
			add_domain($domain, $owner, $webip, $mailip, $empty, $dom_type, $slave_master);
			clean_page();
		}
	}
}

if($_POST["add_supermaster"])
{
	$master_ip = $_POST["master_ip"];
	$ns_name =   $_POST["ns_name"];
	$account = $_POST["account"];
	if (!is_valid_ip($master_ip) && !is_valid_ip6($master_ip))
        {
        	error('Given master IP address is not valid IPv4 or IPv6.');
	}
        if (!is_valid_hostname($ns_name))
        {
                error('Given hostname for NS record not valid.');
        }
	if (!validate_account($account))
	{
		error('Given account name is not valid (may contain only alpha chars).');
	}
	else
	{
		add_supermaster($master_ip, $ns_name, $account);

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
<H2><? echo _('DNS Admin'); ?></H2>

<P FONT CLASS="nav">
<?
if (level(10))
{
	?><A HREF="users.php"><? echo _('User admin'); ?></A> <?
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
echo "<br /><small><b>" . _('Number of domains') . ": </b>".$num_domains."<br />";

if ($num_domains > ROWAMOUNT) {
   show_letters(LETTERSTART,$doms);
   echo "<br />";
}

$doms = get_domains(0,LETTERSTART);
show_pages(count($doms),ROWAMOUNT);

?>

<br />
<TABLE BORDER="0" CELLSPACING="4">
<TR STYLE="font-weight: Bold;">
 <TD CLASS="tdbg">&nbsp;</TD>
 <TD CLASS="tdbg"><? echo _('Name'); ?></TD>
 <TD CLASS="tdbg"><? echo _('Type'); ?></TD>
 <TD CLASS="tdbg"><? echo _('Records'); ?></TD>
 <TD CLASS="tdbg"><? echo _('Owner'); ?></TD>
</TR>
<?

if ($num_domains < ROWAMOUNT) {
   $doms = get_domains(0,"all",ROWSTART,ROWAMOUNT);
} else {
   $doms = get_domains(0,LETTERSTART,ROWSTART,ROWAMOUNT);
}

// If the user doesnt have any domains print a message saying so
if ($doms < 0)
{
	?><TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg" COLSPAN="5"><b><? echo _('No domains in this listing, sorry.'); ?></b></FONT></TD></TR><?
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
			<A HREF="delete_domain.php?id=<?= $c["id"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('Delete zone'); ?> ]" BORDER="0"></A><?
		}
		else
		{
			print "&nbsp;";
		}
		?>
		</TD>
		<TD CLASS="tdbg"><A HREF="edit.php?id=<?= $c["id"] ?>"><?= $c["name"] ?></A></TD>
		<TD CLASS="tdbg"><?= strtolower(get_domain_type( $c["id"])) ?></TD>
		<TD CLASS="tdbg"><?= $c["numrec"] ?></TD>
		<TD CLASS="tdbg"><?= get_owner_from_id($c["owner"]) ?></TD>
		</TR><?
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
        $users = show_users();
        ?>
        <BR><BR>
        <FORM METHOD="post" ACTION="index.php">
        <B><? echo _('Create new master domain'); ?>:</B><BR>
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
        <TR><TD CLASS="tdbg"><? echo _('Domain type'); ?>:</TD><TD CLASS="tdbg">
        <SELECT NAME="dom_type">
        <OPTION VALUE="MASTER">master</OPTION>
        <OPTION VALUE="NATIVE">native</OPTION>
        </SELECT>
        </TD></TR>
        <TR><TD CLASS="tdbg"><? echo _('Create zone without'); ?><BR><? echo _('applying records-template'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="checkbox" NAME="empty" VALUE="1"></TD></TR>
        <TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="submit" VALUE="<? echo _('Add domain'); ?>"></TD></TR>
        </TABLE>
        </FORM>

        <br><br>
        <form method="post" action="index.php">
	<input type="hidden" name="empty" value="1">
	<input type="hidden" name="dom_type" value="SLAVE">
         <b><? echo _('Create new slave domain'); ?></b>
         <table border="0" cellspacing="4">
          <tr>
           <td class="tdbg"><? echo _('Domain name'); ?>:</td>
           <td width="510" class="tdbg">
            <input type="text" class="input" name="domain" value="<? if ($error) print $_POST["domain"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="tdbg"><? echo _('IP of master NS'); ?>:</td>
           <td width="510" class="tdbg">
            <input type="text" class="input" name="slave_master" value="<? if ($error) print $_POST["slave_master"]; ?>">
           </td>
          </tr>
          <tr>
           <td class="tdbg"><? echo _('Owner'); ?>:</td>
           <td width="510" class="tdbg">
            <select name="owner">
             <? 
             foreach ($users as $u)
             {
               ?><option value="<?= $u['id'] ?>"><?= $u['fullname'] ?></option><?
             }  
            ?>
            </select>
           </td>
          </tr>
          <tr>
           <td class="tdbg">&nbsp;</td>
           <td class="tdbg">
            <input type="submit" class="button" name="submit" value="<? echo _('Add domain'); ?>">
           </td>
          </tr>
         </table>
        </form>

<?
}

if (level(5))
{ 
	$supermasters = get_supermasters();
	$num_supermasters = count($supermasters);
?>

	<br><br>
	<b><? echo _('Current supermasters'); ?>:</b><br>
	<small><b><? echo _('Number of supermasters'); ?>: </b><? echo $num_supermasters; ?></b></small>
	<table border="0" cellspacing="4">
	 <tr style="font-weight: bold;">
	  <td class="tdbg">&nbsp;</td>
	  <td class="tdbg"><? echo _('IP address of supermaster'); ?></td>
	  <td class="tdbg"><? echo _('My hostname in NS record'); ?></td>
	  <td class="tdbg"><? echo _('Account'); ?></td>
	 </tr>
<?
	if ($supermasters < 0)
	{
?>
         <tr>
	  <td class="tdbg">&nbsp;</td>
	  <td class="tdbg" colspan="3">
	   <b><? echo _('No supermasters in this listing, sorry.'); ?></b>
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
	  <td class="tdbg">
	   <a href="delete_supermaster.php?master_ip=<?= $c["master_ip"] ?>"><IMG SRC="images/delete.gif" ALT="[ <? echo _('Delete supermaster'); ?> ]" BORDER="0"></A></td>
	  <td class="tdbg"><?= $c["master_ip"] ?></td>
	  <td class="tdbg"><?= $c["ns_name"] ?></td>
	  <td class="tdbg"><?= $c["account"] ?></td>
	 </tr>
<?
		}
	}
?>
	</table>
		
	<br><br>
	<form method="post" action="index.php">
	 <b><? echo _('Add supermaster'); ?>:</b><br>
	 <table border="0" cellspacing="4">
 	  <tr>
 	   <td class="tdbg"><? echo _('IP address of supermaster'); ?>:</td>
 	   <td width="510" class="tdbg">
 	    <input type="text" class="input" name="master_ip" value="<? if ($error) print $_POST["master_ip"]; ?>">
 	   </td>
 	  </tr>
 	  <tr>
 	   <td class="tdbg"><? echo _('My hostname in NS record'); ?>:</td>
 	   <td width="510" class="tdbg">
 	    <input type="text" class="input" name="ns_name" value="<? if ($error) print $_POST["ns_name"]; ?>">
 	   </td>
 	  </tr>
 	  <tr>
 	   <td class="tdbg"><? echo _('Account'); ?>:</td>
 	   <td width="510" class="tdbg">
 	    <input type="text" class="input" name="account" value="<? if ($error) print $_POST["account"]; ?>">
 	   </td>
 	  </tr>
          <tr>
           <td class="tdbg">&nbsp;</td>
           <td class="tdbg">
            <input type="submit" class="button" name="add_supermaster" value="<? echo _('Add supermaster'); ?>">
           </td>
          </tr>
         </table>
        </form>
<?
}
?>

<BR><BR>
<FORM METHOD="post" ACTION="index.php">
<B><? echo _('Change password'); ?>:</B><BR>
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><? echo _('Current password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="currentpass" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('New password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="newpass" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg"><? echo _('New password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="newpass2" VALUE=""></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="passchange" VALUE="<? echo _('Change password'); ?>"></TD></TR>
</TABLE>
</FORM>
<?
include_once("inc/footer.inc.php");
?>
