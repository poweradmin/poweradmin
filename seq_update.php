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
// File: seq_update.php
// Description: synches your database after manual insertions.
// Doesnt do much, just searches the highest record_id and updates the seq table with this

require_once("inc/toolkit.inc.php");
require_once("inc/header.inc.php");

// Ok we have to synch it all.
// What to do? Find the MAX(id) on each table and set it to the _seq table.

echo "<P><B>" . _('Synching databases. This is useful if you did manual insertions (in case you havent been here yet).') . "</B></P>";

if(!level(10))
{
    error(ERR_LEVEL_10);
}

function seq_update(&$item)
{
	global $db;
	$number_u = $db->getOne("SELECT MAX(id) FROM $item");
	if($number_u > 1)
	{
		echo $number_u;
		$number_u_seq = $db->getOne("SELECT id FROM " . $item . "_seq");
		if($number_u_seq < $number_u)
		{
			$number_u += 1;
			$db->query("UPDATE " . $item . "_seq SET id='$number_u'");
		}
	}
}

$tables = array('users', 'zones', 'records', 'domains');

array_walk($tables, 'seq_update');

message("All tables are successfully synchronized.");

php?>
