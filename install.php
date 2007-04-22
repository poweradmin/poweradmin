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
// | Add-ons: Wim Mostrey <wim <AT> mostrey <DOT> be>                   |
// +--------------------------------------------------------------------+

// Filename: install.php
// Description: installs your PowerAdmin
//
// $Id: install.php,v 1.12 2003/01/07 23:29:24 lyon Exp $
//

// addslashes to vars if magic_quotes_gpc is off
function slash_input_data(&$data)
{
	if ( is_array($data) )
	{
		foreach ( $data as $k => $v )
		{
			$data[$k] = ( is_array($v) ) ? slash_input_data($v) : addslashes($v);
		}
	}
	return $data;
}

set_magic_quotes_runtime(0);

// If magic quotes is off, addslashes
if ( !get_magic_quotes_gpc() )
{
	$_GET = slash_input_data($_GET);
	$_POST = slash_input_data($_POST);
	$_COOKIE = slash_input_data($_COOKIE);
}


error_reporting(E_ALL);
if(!@require_once("inc/config.inc.php"))
{
	error("You have to create a config.inc.php!");
}
include_once("inc/header.inc.php");

$sup_types = array('mysql');

function error($msg=false)
{
       	// General function for printing critical errors.
        if ($msg)
	    {
		?>
                <P><TABLE CLASS="error"><TR><TD CLASS="error"><H2><? echo _('Oops! An error occured!'); ?></H2>
       	        <BR>
               	<FONT STYLE="font-weight: Bold"><?= nl2br($msg) ?><BR><BR><a href="javascript:history.go(-1)">&lt;&lt; back</a></FONT><BR></TABLE>
                <?
      	        die();
        }
	    else
	    {
       	        die("No error specified!");
        }
}

if(isset($_POST["submit"]))
{
	//$dbtype = $_POST["dbtype"];
	require_once("inc/database.inc.php");

	if($dbdsntype == "mysql")
	{
		$sqlusers =	"CREATE TABLE users (
				  id int(11) NOT NULL auto_increment,
				  username varchar(16) NOT NULL default '',
				  password varchar(34) NOT NULL default '',
				  fullname varchar(255) NOT NULL default '',
				  email varchar(255) NOT NULL default '',
				  description text NOT NULL,
				  level tinyint(3) NOT NULL default '0',
				  active tinyint(1) NOT NULL default '0',
				  PRIMARY KEY  (id)
				) TYPE=InnoDB";
		$sqlzones =	"CREATE TABLE zones (
  				  id int(11) NOT NULL auto_increment,
				  domain_id int(11) NOT NULL default '0',
				  owner int(11) NOT NULL default '0',
				  comment text,
				  PRIMARY KEY  (id)
				) TYPE=InnoDB";
	}

	// PGSQL Is trivial still, the relations are different.
	if($dbdsntype == "pgsql")
	{
		$sqlusers =	"CREATE TABLE users (
				id SERIAL PRIMARY KEY,
				username varchar(16) NOT NULL,
				password varchar(255) NOT NULL,
				fullname varchar(255) NOT NULL,
				email varchar(255) NOT NULL,
				description text NOT NULL,
				level smallint DEFAULT 0,
				active smallint DEFAULT 0
				)";
		$sqlzones =	"CREATE TABLE zones (
				id SERIAL PRIMARY KEY,
				name varchar(255) NOT NULL,
				owner smallint NOT NULL,
				comment text NULL
				)";
	}

	if(!empty($_POST['login']) && !empty($_POST['password']) && !empty($_POST['fullname']) && !empty($_POST['email']))
	{
		// Declare default tables.



		// It just tries to rough create. If it flunks.. bad a user exists or the dbase exists.

		$resusers = $db->query($sqlusers);

		if($db->isError($resusers))
		{
			error("Can not create table users in $dbdatabase");
		}

		$reszones = $db->query($sqlzones);

		if($db->isError($reszones))
		{
			error("Can not create zones table in $dbdatabase");
		}

		$sqlinsert =	"INSERT INTO 
					users 
					(username, password, fullname, email, description, level, active)
				VALUES (
					'". $_POST['login'] ."', 
					'". md5(stripslashes($_POST['password'])) ."',
					'". $_POST["fullname"] ."',
					'". $_POST["email"] ."',
					'". $_POST["description"] ."',
					10,
					1)";

		$resadmin = $db->query($sqlinsert);

		if($db->isError($resadmin))
		{

			error("Can not add the admin to database $dbdatabase.users");
		}
		else
		{

			?>
<h2><? echo _('PowerAdmin has succesfully been installed.'); ?></h2>
<br />
<? echo _('Remove this file (install.php) from your webdir.'); ?><br />
<b><? echo _('WARNING'); ?>:</b> <? echo _('PowerAdmin will not work until you delete install.php'); ?><br />
<br />
<? echo _('You can click'); ?> <a href="index.php">here</a> <? echo _('to start using PowerAdmin'); ?>
</BODY></HTML>
<?php
			die();
		}

	}
	else
	{
		echo "<DIV CLASS=\"warning\">" . _('You didnt fill in one of the required fields!') . "</DIV>";
	}
}

else
{
?>

<H2><? echo _('PowerAdmin for PowerDNS'); ?></H2>
<BR>
<B><? echo _('This config file will setup your database to be ready for PowerAdmin. Please fill in the next fields which will create an
administrator login.'); ?><BR>
<? echo _('Fields marked with a'); ?> <FONT COLOR="#FF0000">*</FONT> <? echo _('are required.'); ?>
</B><BR><BR>

<FORM METHOD="post">
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><? echo _('Login Name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="login" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="password" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Full name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="fullname" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Email'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="email" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><? echo _('Description'); ?>:</TD><TD CLASS="tdbg"><TEXTAREA ROWS="6" COLS="30" CLASS="inputarea" NAME="description"></TEXTAREA></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="submit" VALUE="<? echo _('Make Account'); ?>"></TD></TR>
</TABLE>
</FORM>

<BR><BR>
<FONT CLASS="footer"><B>PowerAdmin v1.0</B>&nbsp;Copyright &copy;2002 The
PowerAdmin Team</FONT></BODY></HTML><? } ?>
