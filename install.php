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
                <P><TABLE CLASS="error"><TR><TD CLASS="error"><H2><?php echo _('Oops! An error occured!'); ?></H2>
       	        <BR>
               	<FONT STYLE="font-weight: Bold"><?php nl2br($msg) ?><BR><BR><a href="javascript:history.go(-1)">&lt;&lt; back</a></FONT><BR></TABLE>
                <?php
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
                $sqlrecowns =   "CREATE TABLE record_owners (
                                  id int(11) NOT NULL auto_increment,
                                  user_id int(11) NOT NULL default '0',
                                  record_id int(11) NOT NULL default '0',
                                  PRIMARY KEY  (id)
                                ) TYPE=InnoDB";
	}

	// PGSQL Is trivial still, the relations are different.
	if($dbdsntype == "pgsql")
	{
		$sqlusers =	"CREATE TABLE users (
				id SERIAL PRIMARY KEY,
				username varchar(16) NOT NULL,
				password varchar(34) NOT NULL,
				fullname varchar(255) NOT NULL,
				email varchar(255) NOT NULL,
				description text NOT NULL,
				level smallint DEFAULT 0,
				active smallint DEFAULT 0
				)";
		$sqlzones =	"CREATE TABLE zones (
				id SERIAL PRIMARY KEY,
				domain_id integer NOT NULL,
				owner integer NOT NULL,
				comment text NULL
				)";
                $sqlrecowns =   "CREATE TABLE record_owners (
                                id SERIAL PRIMARY KEY,
                                user_id integer NOT NULL,
                                record_id integer NOT NULL
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
                $reszones = $db->query($sqlrecowns);

                if($db->isError($reszones))
                {
                        error("Can not create record_owners table in $dbdatabase");
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
<h2><?php echo _('PowerAdmin has succesfully been installed.'); ?></h2>
<br />
<?php echo _('Remove this file (install.php) from your webdir.'); ?><br />
<b><?php echo _('WARNING'); ?>:</b> <?php echo _('PowerAdmin will not work until you delete install.php'); ?><br />
<br />
<?php echo _('You can click'); ?> <a href="index.php">here</a> <?php echo _('to start using PowerAdmin'); ?>
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

<H2><?php echo _('PowerAdmin for PowerDNS'); ?></H2>
<BR>
<B><?php echo _('This config file will setup your database to be ready for PowerAdmin. Please fill in the next fields which will create an
administrator login.'); ?><BR>
<?php echo _('Fields marked with a'); ?> <FONT COLOR="#FF0000">*</FONT> <?php echo _('are required.'); ?>
</B><BR><BR>

<FORM METHOD="post">
<TABLE BORDER="0" CELLSPACING="4">
<TR><TD CLASS="tdbg"><?php echo _('Login Name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="login" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><?php echo _('Password'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="password" CLASS="input" NAME="password" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><?php echo _('Full name'); ?>:</TD><TD WIDTH="510" CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="fullname" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><?php echo _('Email'); ?>:</TD><TD CLASS="tdbg"><INPUT TYPE="text" CLASS="input" NAME="email" VALUE=""> <FONT COLOR="#FF0000">*</FONT> </TD></TR>
<TR><TD CLASS="tdbg"><?php echo _('Description'); ?>:</TD><TD CLASS="tdbg"><TEXTAREA ROWS="6" COLS="30" CLASS="inputarea" NAME="description"></TEXTAREA></TD></TR>
<TR><TD CLASS="tdbg">&nbsp;</TD><TD CLASS="tdbg"><INPUT TYPE="submit" CLASS="button" NAME="submit" VALUE="<?php echo _('Make Account'); ?>"></TD></TR>
</TABLE>
</FORM>
<?php
}
include_once('inc/footer.inc.php');
?>
