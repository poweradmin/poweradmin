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

include_once("inc/header.inc.php");
require_once("inc/config.inc.php");
require_once("inc/database.inc.php");

// Initialize variables
global $db;
$bad_array = array();
$check_tables = array('zones', 'users', 'records', 'domains');

function error()
{
	return true;
}

PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'error');

foreach($check_tables as $table)
{
	if(DB::isError($db->query("select * from $table")))
	{
		$bad_array[] = $table;
	}
}

$bad = (count($bad_array) == 0) ? false : true;

// Start error or message output

echo "<P>";

if($bad)
{
	?><TABLE CLASS="error"><TR><TD CLASS="error"><H2><? echo _('Not all tables are ok!'); ?></H2><?
}
else
{
	?><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2><? echo _('Successful!'); ?></H2><?
}
?>
<BR>
<FONT STYLE="font-weight: Bold">
<?
if($bad)
{
	echo _('Sorry, but there are error(s) found in the following table(s):'); 
	foreach($bad_array as $table)
	{
		echo " '$table'";
		}
		?>.</P><P><? echo _('Please fix these errors and run the script again.'); ?></P><?
	}
	else
	{
		echo _('Successful! Everything is set up ok, you can rumble!');
	}
?>
<BR></TD></TR></TABLE></P>
<?
	include_once("inc/footer.inc.php");
?>
