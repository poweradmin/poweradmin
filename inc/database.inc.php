<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
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

require_once("MDB2.php");

function dbError($msg)
{
        // General function for printing critical errors.
        include_once("header.inc.php");
        ?>
	<h2><?php echo _('Oops! An error occured!'); ?></h2>
	<p class="error"><?php echo $msg->getDebugInfo(); ?></p>
	<?php        
	include_once("footer.inc.php");
        die();
}

PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'dbError');

function dbConnect() {
	global $dbdsntype;
	global $dbuser;
	global $dbpass;
	global $dbhost;
	global $dbdatabase;
	global $sql_regexp;
	
	if (!(isset($dbuser) && $dbuser != "")) {
		include_once("header.inc.php");
		error(ERR_DB_NO_DB_USER);
		include_once("footer.inc.php");
		exit;
	}
		
	if (!(isset($dbpass) && $dbpass != "")) {
		include_once("header.inc.php");
		error(ERR_DB_NO_DB_PASS);
		include_once("footer.inc.php");
		exit;
	}
		
	if (!(isset($dbhost) && $dbhost != "")) {
		include_once("header.inc.php");
		error(ERR_DB_NO_DB_HOST);
		include_once("footer.inc.php");
		exit;
	}
		
	if (!(isset($dbdatabase) && $dbdatabase != "")) {
		include_once("header.inc.php");
		error(ERR_DB_NO_DB_NAME);
		include_once("footer.inc.php");
		exit;
	}
		
	if ((!isset($dbdsntype)) || (!($dbdsntype == "mysql" || $dbdsntype == "pgsql"))) {
		include_once("header.inc.php");
		error(ERR_DB_NO_DB_TYPE);
		include_once("footer.inc.php");
		exit;
	}
		
	$dsn = "$dbdsntype://$dbuser:$dbpass@$dbhost/$dbdatabase";
	$db = MDB2::connect($dsn);
	$db->setOption('portability', MDB2_PORTABILITY_ALL ^ MDB2_PORTABILITY_EMPTY_TO_NULL);

	if (MDB2::isError($db)) {
		// Error handling should be put.
		error(MYSQL_ERROR_FATAL, $db->getMessage());
	}

	// Do an ASSOC fetch. Gives us the ability to use ["id"] fields.
	$db->setFetchMode(MDB2_FETCHMODE_ASSOC);

	/* erase info */
	$mysql_pass = $dsn = '';

	// Add support for regular expressions in both MySQL and PostgreSQL
	if ( $dbdsntype == "mysql" ) {
		$sql_regexp = "REGEXP";
	} elseif ( $dbdsntype == "pgsql" ) {
		$sql_regexp = "~";
	} else {
		error(ERR_DB_NO_DB_TYPE);
	};
	return $db;
}
?>
