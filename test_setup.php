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

// Filename: test_setup.php
// Description: tests your PowerAdmin install.
// Adding tables? Increase the total count to (2^numberoftables)-1
// Substract its binary value and put it in the last failure close.
// Yes this is binary, why? Because thats cool! Squeeky geeky stuff!
//
// $Id: test_setup.php,v 1.4 2003/01/08 00:40:08 azurazu Exp $
//

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
	?><TABLE CLASS="error"><TR><TD CLASS="error"><H2>Not all tables are ok!</H2><?
}
else
{
	?><TABLE CLASS="messagetable"><TR><TD CLASS="message"><H2>Successful!</H2><?
}
?>
<BR>
<FONT STYLE="font-weight: Bold">
<?
if($bad)
{
	?>Sorry, but there are error(s) found in the following table(s):<?
	foreach($bad_array as $table)
	{
		echo " '$table'";
		}
		?>.</P><P>Please fix these errors and run the script again.</P><?
	}
	else
	{
		echo "Successful! Everything is set up ok, you can rumble!";
	}
?>
<BR></TD></TR></TABLE></P>
<?
	include_once("inc/footer.inc.php");
?>
