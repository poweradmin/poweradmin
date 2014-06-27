<?php

require_once('../inc/error.inc.php');
require_once('../inc/i18n.inc.php');

if (isset($_POST['language'])) {
    $language = $_POST['language'];
} else {
    $language = "en_EN";
}

# FIXME: setlocale can fail if locale package is not installed ion the systme for that language
setlocale(LC_ALL, $language, $language . '.UTF-8');
$gettext_domain = 'messages';
if (!function_exists('bindtextdomain')) {
    die(error('You have to install PHP gettext extension!'));
}
bindtextdomain($gettext_domain, "./../locale");
textdomain($gettext_domain);
@putenv('LANG=' . $language);
@putenv('LANGUAGE=' . $language);

$local_config_file = "../inc/config.inc.php";

function get_random_key() {
    $key = '';

    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789~!@#$%^&*()_+=-][{}';
    $length = 46;

    $size = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $key .= $chars[mt_rand(0, $size - 1)];
    }

    return $key;
}

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Poweradmin</title>\n";
echo "  <link rel=stylesheet href=\"../style/example.css\" type=\"text/css\">\n";
echo "  <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">\n";
echo "  <script type=\"text/javascript\" src=\"../inc/helper.js\"></script>";
echo " </head>\n";
echo " <body>\n";

if (!isset($_POST['step']) || !is_numeric($_POST['step'])) {
    $step = 1;
} else {
    $step = $_POST['step'];
}

echo "  <h1>Poweradmin</h1>\n";
echo "  <h2>" . _('Installation step') . " " . $step . "</h2>\n";

switch ($step) {

    case 1:
        $step++;

        echo " <form method=\"post\" action=\"\">\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"en_EN\" checked> I prefer to proceed in english.<br>\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"nl_NL\"> Ik ga graag verder in het Nederlands.<br>\n";
        echo "	<input type=\"radio\" name=\"language\" value=\"de_DE\"> Ich mache in Deutsch weiter.<br>\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"ja_JP\"> 日本語で続ける<br>\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"pl_PL\"> Chcę kontynuować po polsku.<br>\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"fr_FR\"> Je préfère continuer en français.<br>\n";
        echo "  <input type=\"radio\" name=\"language\" value=\"nb_NO\"> Jeg ønsker å forsette på norsk.<br>\n";
        echo "  <input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
        echo "  <input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
        echo " </form>\n";
        break;

    case 2:
        $step++;

        echo "<p>" . _('This installer expects you to have a PowerDNS database accessable from this server. This installer also expects you to have never ran Poweradmin before, or that you want to overwrite the Poweradmin part of the database. If you have had Poweradmin running before, any data in the following tables will be destroyed: perm_items, perm_templ, perm_templ_items, users and zones. This installer will, of course, not touch the data in the PowerDNS tables of the database. However, it is recommended that you create a backup of your database before proceeding.') . "</p>\n";

        echo "<p>" . _('The alternative for this installer is a manual installation. Refer to the poweradmin.org website if you want to go down that road.') . "</p>\n";

        echo "<p>" . _('Finally, if you see any errors during the installation process, a problem report would be appreciated. You can report problems (and ask for help) on the <a href="http://groups.google.com/group/poweradmin" target=\"blank\">poweradmin</a> mailinglist.') . "</p>";

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
        echo "  <tr id=\"username_row\">\n";
        echo "   <td>" . _('Username') . "</td>\n";
        echo "   <td><input type=\"text\" name=\"user\" value=\"\"></td>\n";
        echo "   <td>" . _('The username to use to connect to the database, make sure the username has sufficient rights to perform administrative task to the PowerDNS database (the installer wants to drop, create and fill tables to the database).') . "</td>\n";
        echo "  </tr>\n";
        echo " <tr id=\"password_row\">\n";
        echo "  <td>" . _('Password') . "</td>\n";
        echo "  <td><input type=\"password\" name=\"pass\" value=\"\" autocomplete=\"off\"></td>\n";
        echo "  <td>" . _('The password for this username.') . "</td>\n";
        echo " </tr>\n";
        echo " <tr>\n";
        echo "  <td width=\"210\">" . _('Database type') . "</td>\n";
        echo "  <td>" .
        "<select name=\"type\" onChange=\"changePort(this.value)\">" .
        "<option value=\"mysql\">MySQL</option>" .
        "<option value=\"pgsql\">PostgreSQL</option>" .
        "<option value=\"sqlite\">SQLite</option>" .
        "</td>\n";
        echo "  <td>" . _('The type of the PowerDNS database.') . "</td>\n";
        echo " </tr>\n";
        echo " <tr id=\"hostname_row\">\n";
        echo "  <td>" . _('Hostname') . "</td>\n";
        echo "  <td><input type=\"text\" id=\"host\" name=\"host\" value=\"localhost\"></td>\n";
        echo "  <td>" . _('The hostname on which the PowerDNS database resides. Frequently, this will be "localhost".') . "</td>\n";
        echo " </tr>\n";
        echo " <tr id=\"dbport_row\">\n";
        echo "  <td>" . _('DB Port') . "</td>\n";
        echo "  <td><input type=\"text\" id=\"dbport\" name=\"dbport\" value=\"3306\"></td>\n";
        echo "  <td>" . _('The port the database server is listening on.') . "</td>\n";
        echo " </tr>\n";
        echo " <tr>\n";
        echo "  <td>" . _('Database') . "</td>\n";
        echo "  <td><input type=\"text\" name=\"name\" value=\"\"></td>\n";
        echo "  <td><span id=\"db_name_title\">" . _('The name of the PowerDNS database.') . "</span>"
                . "<span id=\"db_path_title\" style=\"display: none;\">" . _('The path and filename to the PowerDNS SQLite database.') . "</span></td>\n";
        echo " </tr>\n";
        echo "  <tr>\n";
        echo "   <td>" . _('Poweradmin administrator password') . "</td>\n";
        echo "   <td><input type=\"text\" name=\"pa_pass\" value=\"\" autocomplete=\"off\"></td>\n";
        echo "   <td>" . _('The password of the Poweradmin administrator. This administrator has full rights to Poweradmin using the web interface.') . "</td>\n";
        echo "  </tr>\n";
        echo "</table>\n";
        echo "<br>\n";
        echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
        echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
        echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
        echo "</form>";
        break;

    case 4:
        $step++;
        echo "<p>" . _('Updating database...') . " ";
        include_once("../inc/config-me.inc.php");
        $db_user = $_POST['user'];
        $db_pass = $_POST['pass'];
        $db_host = $_POST['host'];
        $db_port = $_POST['dbport'];
        $db_name = $_POST['name'];
        $db_type = $_POST['type'];
        if ($db_type == 'sqlite') {
            $db_file = $db_name;
        }
        $pa_pass = $_POST['pa_pass'];
        require_once("../inc/database.inc.php");
        $db = dbConnect();
        $db->loadModule('Manager');
        $db->loadModule('Extended');
        include_once("database-structure.inc.php");
        $current_tables = $db->listTables();
        foreach ($def_tables as $table) {
            if (in_array($table['table_name'], $current_tables))
                $db->dropTable($table['table_name']);
            $db->createTable($table['table_name'], $table['fields'], $table['options']);
        }
        $fill_perm_items = $db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        $db->extended->executeMultiple($fill_perm_items, $def_permissions);
        if (method_exists($fill_perm_items, 'free')) {
            $fill_perm_items->free();
        }
        foreach ($def_remaining_queries as $user_query) {
            $db->query($user_query);
        }
        echo _('done!') . "</p>";

        echo "<p>" . _('Now we will gather all details for the configuration itself.') . "</p>\n";
        echo "<form method=\"post\">";
        echo " <table>";
        echo "  <tr>";
        if ($db_type != 'sqlite') {
            echo "   <td>" . _('Username') . "</td>\n";
            echo "   <td><input type=\"text\" name=\"pa_db_user\" value=\"\"></td>\n";
            echo "   <td>" . _('The username for Poweradmin. This new user will have limited rights only.') . "</td>\n";
            echo "  </tr>\n";
            echo "  <tr>\n";
            echo "   <td>" . _('Password') . "</td>\n";
            echo "   <td><input type=\"text\" name=\"pa_db_pass\" value=\"\" autocomplete=\"off\"></td>\n";
            echo "   <td>" . _('The password for this username.') . "</td>\n";
            echo "  </tr>\n";
        }
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
        echo "   <td>" . _('Secondary nameserver') . "</td>\n";
        ;
        echo "   <td><input type=\"text\" name=\"dns_ns2\" value=\"\"></td>\n";
        echo "   <td>" . _('When creating new zones using the template, this value will be used as secondary nameserver. Should be like "ns2.example.net".') . "</td>\n";
        echo "  </tr>\n";
        echo "</table>";
        echo "<br>\n";
        echo "<input type=\"hidden\" name=\"db_user\" value=\"" . $db_user . "\">";
        echo "<input type=\"hidden\" name=\"db_pass\" value=\"" . $db_pass . "\">";
        echo "<input type=\"hidden\" name=\"db_host\" value=\"" . $db_host . "\">";
        echo "<input type=\"hidden\" name=\"db_port\" value=\"" . $db_port . "\">";
        echo "<input type=\"hidden\" name=\"db_name\" value=\"" . $db_name . "\">";
        echo "<input type=\"hidden\" name=\"db_type\" value=\"" . $db_type . "\">";
        echo "<input type=\"hidden\" name=\"pa_pass\" value=\"" . $pa_pass . "\">";
        echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
        echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
        echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
        echo "</form>";
        break;

    case 5:
        $step++;
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];
        $db_host = $_POST['db_host'];
        $db_port = $_POST['db_port'];
        $db_name = $_POST['db_name'];
        $db_type = $_POST['db_type'];
        if ($db_type == 'sqlite') {
            $db_file = $db_name;
        } else {
            $pa_db_user = $_POST['pa_db_user'];
            $pa_db_pass = $_POST['pa_db_pass'];
        }
        $pa_pass = $_POST['pa_pass'];
        $dns_hostmaster = $_POST['dns_hostmaster'];
        $dns_ns1 = $_POST['dns_ns1'];
        $dns_ns2 = $_POST['dns_ns2'];

        $db_layer = 'PDO';
        require_once("../inc/database.inc.php");
        $db = dbConnect();
        include_once("database-structure.inc.php");

        echo "<p>" . _('You now want to give limited rights to Poweradmin so it can update the data in the tables. To do this, you should create a new user and give it rights to select, delete, insert and update records in the PowerDNS database.') . " ";
        if ($db_type == 'mysql') {
            $pa_db_host = $db_host;

            $sql = 'SELECT USER()';
            $result = $db->queryRow($sql);
            if (isset($result['user()'])) {
                $current_db_user = $result['user()'];
                $pa_db_host = substr($current_db_user, strpos($current_db_user, '@') + 1);
            }

            echo _('In MySQL you should now perform the following command:') . "</p>";
            echo "<p><tt>GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON " . $db_name . ".*<br>TO '" . $pa_db_user . "'@'" . $pa_db_host . "'<br>IDENTIFIED BY '" . $pa_db_pass . "';</tt></p>";
        } elseif ($db_type == 'pgsql') {
            echo _('On PgSQL you would use:') . "</p>";
            echo "<p><tt>$ createuser -E -P " . $pa_db_user . "<br>" .
            "Enter password for new role: " . $pa_db_pass . "<br>" .
            "Enter it again: " . $pa_db_pass . "<br>" .
            "Shall the new role be a superuser? (y/n) n<br>" .
            "Shall the new user be allowed to create databases? (y/n) n<br>" .
            "Shall the new user be allowed to create more new users? (y/n) n<br>" .
            "CREATE USER<br>" .
            "$ psql " . $db_name . "<br>";
            echo "psql> ";
            foreach ($grantTables as $tableName) {
                echo "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . $pa_db_user . ";<br />";
            }
            foreach ($grantSequences as $sequenceName) {
                echo "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . $pa_db_user . ";<br />";
            }
            echo "</tt></p>\n";
        }
        echo "<p>" . _('After you have added the new user, proceed with this installation procedure.') . "</p>\n";
        echo "<form method=\"post\">";
        echo "<input type=\"hidden\" name=\"db_host\" value=\"" . $db_host . "\">";
        echo "<input type=\"hidden\" name=\"db_name\" value=\"" . $db_name . "\">";
        echo "<input type=\"hidden\" name=\"db_port\" value=\"" . $db_port . "\">";
        echo "<input type=\"hidden\" name=\"db_type\" value=\"" . $db_type . "\">";
        echo "<input type=\"hidden\" name=\"db_user\" value=\"" . $db_user . "\">";
        echo "<input type=\"hidden\" name=\"db_pass\" value=\"" . $db_pass . "\">";
        if ($db_type != 'sqlite') {
            echo "<input type=\"hidden\" name=\"pa_db_user\" value=\"" . $pa_db_user . "\">";
            echo "<input type=\"hidden\" name=\"pa_db_pass\" value=\"" . $pa_db_pass . "\">";
        }
        echo "<input type=\"hidden\" name=\"pa_pass\" value=\"" . $pa_pass . "\">";
        echo "<input type=\"hidden\" name=\"dns_hostmaster\" value=\"" . $dns_hostmaster . "\">";
        echo "<input type=\"hidden\" name=\"dns_ns1\" value=\"" . $dns_ns1 . "\">";
        echo "<input type=\"hidden\" name=\"dns_ns2\" value=\"" . $dns_ns2 . "\">";
        echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
        echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
        echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
        echo "</form>";
        break;

    case 6:
        $step++;

        require_once("../inc/database.inc.php");
        global $db_layer;

        $db_type = $_POST['db_type'];
        $pa_pass = $_POST['pa_pass'];
        $db_port = $_POST['db_port'];

        $config = "<?php\n\n" .
                ( $db_type == 'sqlite' ? "\$db_file\t\t= '" . $_POST['db_name'] . "';\n" :
                "\$db_host\t\t= '" . $_POST['db_host'] . "';\n" .
                "\$db_user\t\t= '" . $_POST['pa_db_user'] . "';\n" .
                "\$db_pass\t\t= '" . $_POST['pa_db_pass'] . "';\n" .
                "\$db_name\t\t= '" . $_POST['db_name'] . "';\n" .
                (($db_type == 'mysql' && $db_port != 3306) || ($db_type == 'pgsql' && $db_port != 5432) ? "\$db_port\t\t= '" . $db_port . "';\n" : '')) .
                "\$db_type\t\t= '" . $_POST['db_type'] . "';\n" .
                "\$db_layer\t\t= 'PDO';\n" .
                "\n" .
                "\$session_key\t\t= '" . get_random_key() . "';\n" .
                "\n" .
                "\$iface_lang\t\t= '" . $_POST['language'] . "';\n" .
                "\n" .
                "\$dns_hostmaster\t\t= '" . $_POST['dns_hostmaster'] . "';\n" .
                "\$dns_ns1\t\t= '" . $_POST['dns_ns1'] . "';\n" .
                "\$dns_ns2\t\t= '" . $_POST['dns_ns2'] . "';\n";

        if (is_writeable($local_config_file)) {
            $h_config = fopen($local_config_file, "w");
            fwrite($h_config, $config);
            fclose($h_config);
            echo "<p>" . _('The installer was able to write to the file "') . $local_config_file . _('". A basic configuration, based on the details you have given, has been created.') . "</p>\n";
        } else {
            echo "<p>" . _('The installer is unable to write to the file "') . $local_config_file . _('" (which is in itself good). The configuration is printed here. You should now create the file "') . $local_config_file . _('" in the Poweradmin root directory yourself. It should contain the following few lines:') . "</p>\n";
            echo "<pre>";
            echo htmlentities($config);
            echo "</pre>";
        }
        echo "<form method=\"post\">";
        echo "<input type=\"hidden\" name=\"pa_pass\" value=\"" . $pa_pass . "\">";
        echo "<input type=\"hidden\" name=\"step\" value=\"" . $step . "\">";
        echo "<input type=\"hidden\" name=\"language\" value=\"" . $language . "\">";
        echo "<input type=\"submit\" name=\"submit\" value=\"" . _('Go to step') . " " . $step . "\">";
        echo "</form>";
        break;

    case 7:
        $step++;
        echo "<p>" . _('Now we have finished the configuration.') . "</p>";
        echo "<p>" . _('If you want support for the URLs used by other dynamic DNS providers, run "cp install/htaccess.dist .htaccess" and enable mod_rewrite in Apache.') . "</p>";
        echo "<p>" . _('You should (must!) remove the directory "install/" from the Poweradmin root directory. You will not be able to use Poweradmin if it exists. Do it now.') . "</p>";
        echo "<p>" . _('After you have removed the directory, you can login to <a href="../index.php">Poweradmin</a> with username "admin" and password "') . $_POST['pa_pass'] . _('". You are highly encouraged to change these as soon as you are logged in.') . "</p>";
        break;

    default:
        break;
}

include_once('../inc/version.inc.php');
echo "<div class=\"footer\">";
echo "<a href=\"http://www.poweradmin.org/\">a complete(r) <strong>poweradmin</strong> v$VERSION</a> - <a href=\"http://www.poweradmin.org/credits.html\">credits</a>";
echo "</div></body></html>";
