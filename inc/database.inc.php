<?

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

// Filename: auth.inc.php
// Startdate: 26-10-2002
// Description: Constructs the database class.
//
// $Id: database.inc.php,v 1.3 2002/12/27 02:45:08 azurazu Exp $
//

require_once("dal.inc.php");

function dbError($msg)
{
        // General function for printing critical errors.
        include_once("header.inc.php");
        ?>
        <P><TABLE CLASS="error"><TR><TD CLASS="error"><H2><? echo _('Oops! An error occured!'); ?></H2>
        <BR>
        <FONT STYLE="font-weight: Bold"><?= $msg->getDebugInfo(); ?><BR><BR><a href="javascript:history.go(-1)">&lt;&lt; <? echo _('back'); ?></a></FONT><BR></TD></TR></TABLE></P>
        <?
        die();
}

// Setup error handling.
PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'dbError');

//$dsn = "mysql://padev:blapadev@localhost/padev" ;
$dsn = "$dbdsntype://$dbuser:$dbpass@$dbhost/$dbdatabase";
$db = DB::connect($dsn);

if (DB::isError($db))
{
	// Error handling should be put.
        error(MYSQL_ERROR_FATAL, $db->getMessage());
}

// Do an ASSOC fetch. Gives us the ability to use ["id"] fields.
$db->setFetchMode(DB_FETCHMODE_ASSOC);


/* erase info */
$mysql_pass = $dsn = '';


?>
