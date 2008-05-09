<?php

$language = $_POST['language'];
setlocale(LC_ALL, $language);
$gettext_domain = 'messages';
bindtextdomain($gettext_domain, "./../locale");
textdomain($gettext_domain);
@putenv('LANG='.$language);
@putenv('LANGUAGE='.$language);

$local_config_file = "../inc/config.inc.php";

function error($msg) {
	if ($msg) {
		echo "     <div class=\"error\">Error: " . $msg . "</div>\n";
	} else {
		echo "     <div class=\"error\">" . _('An unknown error has occurred.') . "</div>\n";
	}
}

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Poweradmin</title>\n";
echo "  <link rel=stylesheet href=\"../style/example.inc.php\" type=\"text/css\">\n";
echo " </head>\n";
echo " <body>\n";

if (!isset($_POST['step']) || !is_numeric($_POST['step'])) {
	$step = 1;
} else {
	$step = $_POST['step'];
}

echo "  <h1>Poweradmin</h1>";
echo "  <h2>" . _('Installation step') . " " . $step . "</h2>";

switch($step) {

	case 1:
		$step++;

		echo "<p>\n";
		echo " <form method=\"post\">\n";
		echo "  <input type=\"radio\" name=\"language\" value=\"en_EN\"> I prefer to proceed in english.<br>\n";
		echo "  <input type=\"radio\" name=\"language\" value=\"nl_NL\"> Ik ga graag verder in het Nederlands.<br><br>\n";
		echo "  <input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "  <input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo " </form>\n";
		echo "</p>\n";
		break;

	case 2:
		$step++;

		echo "<p>" . _('This installer expects you to have a PowerDNS database accessable from this server. This installer also expects you to have never ran Poweradmin before, or that you want to overwrite the Poweradmin part of the database. If you have had Poweradmin running before, any data in the following tables will be destroyed: perm_items, perm_templ, perm_templ_items, users and zones. This installer will, of course, not touch the data in the PowerDNS tables of the database. However, it is recommended that you create a backup of your database before proceeding.') . "</p>\n";

		echo "<p>" . _('The alternative for this installer is a manual installation. Refer to the poweradmin.org website if you want to go down that road.') . "</p>\n";

		echo "<p>" . _('Finally, if you see any errors during the installation process, a problem report would be appreciated. You can report problems (and ask for help) on the poweradmin-users mailinglist.') . "</p>";

		echo "<p>" . _('Do you want to proceed now?') . "</p>\n";

		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo "</form>";
		break;

	case 3:	
		$step++;
		echo "<p>" . _('To prepare the database for using Poweradmin, the installer needs to modify the PowerDNS database. It will add a number of tables and it will fill these tables with some data. If the tables are already present, the installer will drop them first.') . "</p>";

		echo "<p>" . _('To do all of this, the installer needs to access the database with an account which has sufficient rights. If you trust the installer, you may give it the username and password of the database user root. Otherwise, make sure the user has enough rights, before actually proceeding.') . "</p>";

		echo "<form method=\"post\">";
		echo " <table>\n";
		echo "  <tr>\n";
		echo "   <td>" . _('Username') . "</td>\n";
		echo "   <td><input type=\"text\" name=\"user\" value=\"\"></td>\n";
		echo "   <td>" . _('The username to use to connect to the database, make sure the username has sufficient rights to perform administrative task to the PowerDNS database (the installer wants to drop, create and fill tables to the database).') . "</td>\n";
		echo "  </tr>\n";
		echo " <tr>\n";
		echo "  <td>" . _('Password') . "</td>\n";
		echo "  <td><input type=\"password\" name=\"pass\" value=\"\"></td>\n";
		echo "  <td>" . _('The password for this username.') . "</td>\n";
		echo " </tr>\n";
		echo " <tr>\n";
		echo "  <td>" . _('Hostname') . "</td>\n";
		echo "  <td><input type=\"text\" name=\"host\" value=\"\"></td>\n";
		echo "  <td>" . _('The hostname on which the PowerDNS database resides. Frequently, this will be "localhost".') . "</td>\n";
		echo " </tr>\n";
		echo " <tr>\n";
		echo "  <td>" . _('Database') . "</td>\n";
		echo "  <td><input type=\"text\" name=\"name\" value=\"\"></td>\n";
		echo "  <td>" . _('The name of the PowerDNS database.') . "</td>\n";
		echo " </tr>\n";
		echo " <tr>\n";
		echo "  <td>" . _('Database type') . "</td>\n";
		echo "  <td>" .
			"<select name=\"type\">" . 
			"<option value=\"mysql\">MySQL</option>" . 
			"<option value=\"pgsql\">PostgreSQL</option>" . 
			"</td>\n";
		echo "  <td>" . _('The type of the PowerDNS database.') . "</td>\n";
		echo " </tr>\n";
		echo "</table>\n";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo "</form>";
		break;

	case 4:
		$step++;
		echo "<p>" . _('Updating database...') . " ";
		include_once("../inc/config-me.inc.php");
		include_once("database-structure.inc.php");
		$db_user = $_POST['user'];
		$db_pass = $_POST['pass'];
		$db_host = $_POST['host'];
		$db_name = $_POST['name'];
		$db_type = $_POST['type'];
		require_once("../inc/database.inc.php");
		$db = dbConnect();
		$db->loadModule('Manager');
		$db->loadModule('Extended');
		$current_tables = $db->listTables();
		foreach ($def_tables as $table) {
			if (in_array($table['table_name'], $current_tables)) $db->dropTable($table['table_name']);
			$db->createTable($table['table_name'], $table['fields']);
		}
		$fill_perm_items = $db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
		$db->extended->executeMultiple($fill_perm_items, $def_permissions);
		$fill_perm_items->free();
		foreach ($def_remaining_queries as $query) {
			$db->query($query);
		}
		echo _('done!') . "</p>";

		echo "<p>" . _('We have now updated the PowerDNS database to work with Poweradmin. You now want to give limited rights to Poweradmin so it can update the data in the tables. To do this, you should create a new user and give it rights to select, delete, insert and update records in the PowerDNS database.') . " ";
		if ($db_type=='mysql') {
			echo _('In MySQL you should now perform the following command:') . "</p>";
			echo "<p><tt>GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON powerdns-database.*<br>TO 'poweradmin-user'@'localhost'<br>IDENTIFIED BY 'poweradmin-password';</tt></p>";
		} elseif ($db_type == 'pgsql') {
			echo _('On PgSQL you would use:') . "</p>";
			echo "<p><tt>$ createuser poweradmin-user<br>" .
				"Shall the new role be a superuser? (y/n) n<br>" . 
				"Shall the new user be allowed to create databases? (y/n) n<br>" . 
				"Shall the new user be allowed to create more new users? (y/n) n<br>" . 
				"CREATE USER<br>" . 
				"$ psql powerdns-database<br>" .
				"psql> GRANT  SELECT, INSERT, DELETE, UPDATE<br>" . 
				"ON powerdns-database<br>" .
				"TO poweradmin-user;</tt></p>\n";
		}
		echo "<p>" . _('After you have added the new user, proceed with this installation procedure.') . "</p>\n";
		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"host\" value=\"" . $db_host . "\">";
		echo "<input type=\"hidden\" name=\"name\" value=\"" . $db_name . "\">";
		echo "<input type=\"hidden\" name=\"type\" value=\"" . $db_type . "\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo "</form>";
		break;
	
	case 5:
		$step++;
		$db_host = $_POST['host'];
		$db_name = $_POST['name'];
		$db_type = $_POST['type'];
		echo "<p>" . _('Now we will put together the configuration. To do so, the installer needs some details:') . "</p>\n";
		echo "<form method=\"post\">";
		echo " <table>";
		echo "  <tr>";
		echo "   <td>" . _('Username') . "</td>\n";
		echo "   <td><input type=\"text\" name=\"db_user\" value=\"\"></td>\n";
		echo "   <td>" . _('The username as created in the previous step.') . "</td>\n";
		echo "  </tr>\n";
		echo "  <tr>\n";
		echo "   <td>" . _('Password') . "</td>\n";
		echo "   <td><input type=\"text\" name=\"db_pass\" value=\"\"></td>\n";
		echo "   <td>" . _('The password for this username.') . "</td>\n";
		echo "  </tr>\n";
		echo "  <tr>\n";
		echo "   <td>" . _('Hostmaster') . "</td>\n";
		echo "   <td><input type=\"text\" name=\"dns_hostmaster\" value=\"\"></td>\n";
		echo "   <td>" . _('When creating SOA records and no hostmaster is provided, this value here will be used. Should be in the form "hostmaster.example.net".') . "</td>\n";
		echo "  </tr>\n";
		echo "  <tr>\n";
		echo "   <td>" . _('Primary nameserver') . "</td>\n";
		echo "   <td><input type=\"text\" name=\"dns_ns1\" value=\"\"></td>\n";
		echo "   <td>" . _('When creating new zones using the template, this value will be used as primary nameserver. Should be like "ns1.example.net".') . "</td>\n";
		echo "  </tr>\n";
		echo "  <tr>\n";
		echo "   <td>" . _('Secondary nameserver') . "</td>\n";;
		echo "   <td><input type=\"text\" name=\"dns_ns2\" value=\"\"></td>\n";
		echo "   <td>" . _('When creating new zones using the template, this value will be used as secondary nameserver. Should be like "ns2.example.net".') . "</td>\n";
		echo "  </tr>\n";
		echo "</table>";
		echo "<input type=\"hidden\" name=\"db_host\" value=\"" . $db_host . "\">";
		echo "<input type=\"hidden\" name=\"db_name\" value=\"" . $db_name . "\">";
		echo "<input type=\"hidden\" name=\"db_type\" value=\"" . $db_type . "\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo "</form>";
		break;

	case 6:
		$step++;
		$config = "<?\n\n" .
			"\$db_host\t\t= \"" . $_POST['db_host'] . "\";\n" .
			"\$db_user\t\t= \"" . $_POST['db_user'] . "\";\n" .
			"\$db_pass\t\t= \"" . $_POST['db_pass'] . "\";\n" .
			"\$db_name\t\t= \"" . $_POST['db_name'] . "\";\n" .
			"\$db_type\t\t= \"" . $_POST['db_type'] . "\";\n" .
			"\n" .
			"\$iface_lang\t\t= \"" . $_POST['language'] . "\";\n" .
			"\n" .
			"\$dns_hostmaster\t\t= \"" . $_POST['dns_hostmaster'] . "\";\n" .
			"\$dns_ns1\t\t= \"" . $_POST['dns_ns1'] . "\";\n" .
			"\$dns_ns2\t\t= \"" . $_POST['dns_ns2'] . "\";\n" .
			"\n?>\n";

		if (is_writeable($local_config_file)) {
			$h_config = fopen($local_config_file, "w");
			fwrite($h_config, $config);
			fclose($h_config);
			echo "<p>" . _('The installer was able to write to the file "') . $local_config_file . _('". A basic configuration, based on the details you have given, has been created.') . "</p>\n";
		} else {
			echo "<p>" . _('The installer is unable to write to the file "') . $local_config_file . _('" (which is in itself good). The configuration is printed here. You should now create the file "') . $local_config_file . _('" in the Poweradmin root directory yourself. It should contain the following few lines:') . "</p>\n";
			echo "<pre>";
			echo $config;
			echo "</pre>";
		};
		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
		echo "</form>";
		break;

	case 7:
		$step++;
		echo "<p>" . _('Now we have finished the configuration, you should (must!) remove the directory "install/" from the Poweradmin root directory. You will not be able to use Poweradmin if it exists. Do it now.') . "</p>";
		echo "<p>" . _('After you have removed the directory, you can login to <a href="index.php">Poweradmin</a> with username "admin" and password "admin". You are highly encouraged to change these as soon as you are logged in.') . "</p>";
		break;

	default:
		break;
}

echo "<div class=\"footer\">";
echo "<a href=\"https://www.poweradmin.org/\">a complete(r) <strong>poweradmin</strong></a> - <a href=\"https://www.poweradmin.org/trac/wiki/Credits\">credits</a>";
echo "</div></body></html>";

?>



