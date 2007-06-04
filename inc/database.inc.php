<?

require_once("MDB2.php");

function dbError($msg)
{
        // General function for printing critical errors.
        include_once("header.inc.php");
        ?>
	<h2><? echo _('Oops! An error occured!'); ?></h2>
	<p class="error"><? echo $msg->getDebugInfo(); ?></p>
	<?        
	include_once("footer.inc.php");
        die();
}

PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'dbError');

$dsn = "$dbdsntype://$dbuser:$dbpass@$dbhost/$dbdatabase";
$db = MDB2::connect($dsn);

if (MDB2::isError($db))
{
	// Error handling should be put.
        error(MYSQL_ERROR_FATAL, $db->getMessage());
}

// Do an ASSOC fetch. Gives us the ability to use ["id"] fields.
$db->setFetchMode(MDB2_FETCHMODE_ASSOC);

/* erase info */
$mysql_pass = $dsn = '';


?>
