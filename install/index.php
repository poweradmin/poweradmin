<?php

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
echo "  <h2>Installation step " . $step . "</h2>";

switch($step) {
	case 1:
		$step++;
		echo "<p>This installer expects you to have a PowerDNS database accessable from this server. This installer also expects you to have never ran Poweradmin before, or that you want to overwrite the Poweradmin part of the database. If you have had Poweradmin running before, any data in the following tables will be destroyed:</p>\n";
		echo "<ul><li>perm_items</li><li>perm_templ</li><li>perm_templ_items</li><li>users</li><li>zones</li></ul>\n";
		echo "<p>This installer will, of course, not touch the data in the PowerDNS tables of the database. However, it is, of course, recommended you create a backup of your database before proceeding.</p>";
		echo "<p>Finaly, if you see any errors during the installation process, a problem report would be. You can report problems (and ask for help) on the <a href=\"https://www.poweradmin.org/trac/wiki/Mailinglists\">poweradmin-users</a> mailinglist or you can create <a href=\"https://www.poweradmin.org/trac/newticket\">a ticket</a> in the ticketsystem.</p>";
		echo "<p>Do you want to proceed now?</p>\n";
		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"Go to step " . $step . "\">";
		echo "</form>";
		break;

	case 2:	
		$step++;
		echo "<p>To prepare the database for using Poweradmin, the installer needs to modify the PowerDNS database. It'll add a number of tables and it'll fill these tables with some data. If the tables are already present, the installer will drop them first.</p>";
		echo "<p>To do all of this, the installer needs to access the database with an account which has sufficient rights. If you trust the installer, you may give it the username and password of the database user root. Otherwise, make sure the user has enough rights, before actually proceeding.</p>";
		echo "<form method=\"post\">";
		echo "<table>\n";
		echo "<tr><td>Username</td><td><input type=\"text\" name=\"user\" value=\"\"></td><td>The username to use to connect to the database, make sure the username has sufficient rights to perform administrative task to the PowerDNS database (e.g. \"root\").</td></tr>\n";
		echo "<tr><td>Password</td><td><input type=\"password\" name=\"pass\" value=\"\"></td><td>The password for this username.</td></tr>\n";
		echo "<tr><td>Hostname</td><td><input type=\"text\" name=\"host\" value=\"\"></td><td>The hostname on which the PowerDNS database resides. Frequently, this will be \"localhost\".</td></tr>\n";
		echo "<tr><td>Database</td><td><input type=\"text\" name=\"name\" value=\"\"></td><td>The name of the PowerDNS database.</td></tr>\n";
		echo "</table>\n";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"Go to step " . $step . "\">";
		echo "</form>";
		break;

	case 3:
		$step++;
		echo "<p>Updating database... ";
		include_once("../inc/config-me.inc.php");
		include_once("database-structure.inc.php");
		$db_user = $_POST['user'];
		$db_pass = $_POST['pass'];
		$db_host = $_POST['host'];
		$db_name = $_POST['name'];
		$db_type = "mysql";
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

		echo "done!</p>";

		echo "<p>We have now updated the PowerDNS database to work with Poweradmin. You now want to give limited rights to Poweraadmin so it can update the data in the tables. To do this, you should create a new user and give it rights to select, delete, insert and update records in the PowerDNS database. In MySQL should now perform the following command:</p>";
		echo "<p><tt>GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON powerdns-database.*<br>TO 'poweradmin-user'@'localhost'<br>IDENTIFIED BY 'poweradmin-password';</tt></p>";
		echo "<p>On PgSQL you would use:</p>";
		echo "<p><tt>$ createuser poweradmin-user<br>Shall the new role be a superuser? (y/n) n<br>Shall the new user be allowed to create databases? (y/n) n<br>Shall the new user be allowed to create more new users? (y/n) n<br>CREATE USER<br>$ psql powerdns-database<br>psql> GRANT  SELECT, INSERT, DELETE, UPDATE<br>ON powerdns-database<br>TO poweradmin-user;</tt></p>";
		echo "<p>After you have added the new user, proceed with this installation procedure.</p>\n";
		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"host\" value=\"" . $db_host . "\">";
		echo "<input type=\"hidden\" name=\"name\" value=\"" . $db_name . "\">";
		echo "<input type=\"hidden\" name=\"type\" value=\"" . $db_type . "\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"Go to step " . $step . "\">";
		echo "</form>";
		break;
	
	case 4:
		$step++;
		$db_host = $_POST['host'];
		$db_name = $_POST['name'];
		$db_type = $_POST['type'];
		echo "<p>Now we will put together the configuration. To do so, I need some details:</p>";
		echo "<form method=\"post\">";
		echo "<table>";
		echo "<tr><td>Username</td><td><input type=\"text\" name=\"db_user\" value=\"\"></td><td>The username as created in the previous step.</td></tr>";
		echo "<tr><td>Password</td><td><input type=\"text\" name=\"db_pass\" value=\"\"></td><td>The password for this username.</td></tr>";
		echo "<tr><td>Hostmaster</td><td><input type=\"text\" name=\"dns_hostmaster\" value=\"\"></td><td>When creating SOA records and no hostmaster is provided, this value here will be used. Should be in the form \"hostmaster.example.net\".</td></tr>";
		echo "<tr><td>Primary nameserver</td><td><input type=\"text\" name=\"dns_ns1\" value=\"\"></td><td>When creating new zones using the template, this value will be used as primary nameserver. Should be like \"ns1.example.net\".</td></tr>";
		echo "<tr><td>Secondary nameserver</td><td><input type=\"text\" name=\"dns_ns2\" value=\"\"></td><td>When creating new zones using the template, this value will be used as secondary nameserver. Should be like \"ns2.example.net\".</td></tr>";
		echo "</table>";
		echo "<input type=\"hidden\" name=\"db_host\" value=\"" . $db_host . "\">";
		echo "<input type=\"hidden\" name=\"db_name\" value=\"" . $db_name . "\">";
		echo "<input type=\"hidden\" name=\"db_type\" value=\"" . $db_type . "\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"Go to step " . $step . "\">";
		echo "</form>";
		break;

	case 5:
		$step++;
		echo "<p>The configuration is printed here. You should now create the file " . $local_config_file . " in the Poweradmin root directory yourself. It should contain the following few lines:</p>";

		echo "<pre>";
		echo "\$db_host\t\t= \"" . $_POST['db_host'] . "\";\n" .
			"\$db_user\t\t= \"" . $_POST['db_user'] . "\";\n" .
			"\$db_pass\t\t= \"" . $_POST['db_pass'] . "\";\n" .
			"\$db_name\t\t= \"" . $_POST['db_name'] . "\";\n" .
			"\$db_type\t\t= \"" . $_POST['db_type'] . "\";\n" .
			"\n" .
			"\$dns_hostmaster\t\t= \"" . $_POST['dns_hostmaster'] . "\";\n" .
			"\$dns_ns1\t\t= \"" . $_POST['dns_ns1'] . "\";\n" .
			"\$dns_ns2\t\t= \"" . $_POST['dns_ns2'] . "\";\n" .
			"\n";
		echo "</pre>";
		echo "<form method=\"post\">";
		echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
		echo "<input type=\"submit\" name=\"submit\" value=\"Go to step " . $step . "\">";
		echo "</form>";
		break;

	case 6:
		$step++;
		echo "<p>Now we have finished the configuration, you should remove the directory \"install/\" from the Poweradmin root directory. You will not be able to use the file if it exists. Do it now.</p>";
		echo "<p>After you have removed the file, you can login to <a href=\"index.php\">Poweradmin</a> with username \"admin\" and password \"admin\". You are highly encouraged to change these as soon as you are logged in.</p>";
		break;

	default:
		break;
}

echo "<div class=\"footer\">";
echo "<a href=\"https://www.poweradmin.org/\">a complete(r) <strong>poweradmin</strong></a> - <a href=\"https://www.poweradmin.org/trac/wiki/Credits\">credits</a>";
echo "</div></body></html>";

?>



