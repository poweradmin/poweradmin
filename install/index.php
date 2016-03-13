<?php

// Dependencies
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/inc/error.inc.php';
require_once dirname(__DIR__) . '/inc/i18n.inc.php';

// Constants
define('LOCAL_CONFIG_FILE', dirname(__DIR__) . '/inc/config.inc.php');
define('SESSION_KEY_LENGTH', 46);

if (isset($_POST['language']) && $_POST['language'] != 'en_EN') {
    $language = $_POST['language'];

    $locale = setlocale(LC_ALL, $language, $language . '.UTF-8');
    if ($locale == false) {
        error(ERR_LOCALE_FAILURE);
    }

    $gettext_domain = 'messages';
    if (!function_exists('bindtextdomain')) {
        die(error('You have to install PHP gettext extension!'));
    }
    bindtextdomain($gettext_domain, "./../locale");
    textdomain($gettext_domain);
    @putenv('LANG=' . $language);
    @putenv('LANGUAGE=' . $language);

} else {
    $language = 'en_EN';
}

$step = isset($_POST['step']) || is_numeric($_POST['step']) ? $_POST['step'] : 1;

$loader = new Twig_Loader_Filesystem('templates');
$twig = new Twig_Environment($loader);

echo $twig->render('header.html', array('current_step' => $step, 'message1' => _('Installation step')));

switch ($step) {

    case 1:
        echo $twig->render('step1.html', array('next_step' => ++$step, 'next_step_button' => _('Go to step')));
        break;

    case 2:
        echo $twig->render('step2.html', array(
            'message1' => _('This installer expects you to have a PowerDNS database accessable from this server. This installer also expects you to have never ran Poweradmin before, or that you want to overwrite the Poweradmin part of the database. If you have had Poweradmin running before, any data in the following tables will be destroyed: perm_items, perm_templ, perm_templ_items, users and zones. This installer will, of course, not touch the data in the PowerDNS tables of the database. However, it is recommended that you create a backup of your database before proceeding.'),
            'message2' => _('The alternative for this installer is a manual installation. Refer to the poweradmin.org website if you want to go down that road.'),
            'message3' => _('Finally, if you see any errors during the installation process, a problem report would be appreciated. You can report problems (and ask for help) on the <a href="http://groups.google.com/group/poweradmin" target="blank">poweradmin</a> mailinglist.'),
            'message4' => _('Do you want to proceed now?'),
            'language' => $_POST['language'],
            'next_step' => ++$step,
            'next_step_button' => _('Go to step')));
        break;

    case 3:
        echo $twig->render('step3.html', array(
            'username' => _('Username'),
            'password' => _('Password'),
            'database_type' => _('Database type'),
            'hostname' => _('Hostname'),
            'db_port' => _('DB Port'),
            'database' => _('Database'),
            'db_title' => _('The name of the PowerDNS database.'),
            'db_charset' => _('DB charset'),
            'db_collation' => _('DB collation'),
            'admin_password' => _('Poweradmin administrator password'),

            'message1' => _('To prepare the database for using Poweradmin, the installer needs to modify the PowerDNS database. It will add a number of tables and it will fill these tables with some data. If the tables are already present, the installer will drop them first.'),
            'message2' => _('To do all of this, the installer needs to access the database with an account which has sufficient rights. If you trust the installer, you may give it the username and password of the database user root. Otherwise, make sure the user has enough rights, before actually proceeding.'),
            'message3' => _('The username to use to connect to the database, make sure the username has sufficient rights to perform administrative task to the PowerDNS database (the installer wants to drop, create and fill tables to the database).'),
            'message4' => _('The password for this username.'),
            'message5' => _('The type of the PowerDNS database.'),
            'message6' => _('The hostname on which the PowerDNS database resides. Frequently, this will be "localhost".'),
            'message7' => _('The port the database server is listening on.'),
            'message8' => _('The path and filename to the PowerDNS SQLite database.'),
            'message9' => _('The charset (encoding) which will be used for new tables. Leave it empty then default database charset will be used.'),
            'message10' => _('Set of rules for comparing characters in database. Leave it empty then default database collation will be used.'),
            'message11' => _('The password of the Poweradmin administrator. This administrator has full rights to Poweradmin using the web interface.'),

            'language' => $_POST['language'],
            'next_step' => ++$step,
            'next_step_button' => _('Go to step')));
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
        $db_charset = $_POST['charset'];
        $db_collation = $_POST['collation'];
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
            if (in_array($table['table_name'], $current_tables)) {
                $db->dropTable($table['table_name']);
            }

            $options = $table['options'];

            if ($db_charset) {
                $options['charset'] = $db_charset;
            }

            if ($db_collation) {
                $options['collation'] = $db_collation;
            }
            $db->createTable($table['table_name'], $table['fields'], $options);
        }

        $fill_perm_items = $db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        $db->extended->executeMultiple($fill_perm_items, $def_permissions);
        if (method_exists($fill_perm_items, 'free')) {
            $fill_perm_items->free();
        }
        foreach ($def_remaining_queries as $query_nr => $user_query) {
            if ($query_nr === 0) {
                $user_query = sprintf($user_query, $db->quote(Poweradmin\Password::hash($pa_pass), 'text'));
            }
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
            echo "   <td><input type=\"password\" name=\"pa_db_pass\" value=\"\" autocomplete=\"off\"></td>\n";
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
        echo "<input type=\"hidden\" name=\"db_charset\" value=\"" . $db_charset . "\">";
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
        $db_charset = $_POST['db_charset'];
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
            echo "<p><code>GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON " . $db_name . ".*<br>TO '" . $pa_db_user . "'@'" . $pa_db_host . "'<br>IDENTIFIED BY '" . $pa_db_pass . "';</code></p>";
        } elseif ($db_type == 'pgsql') {
            echo _('On PgSQL you would use:') . "</p>";
            echo "<p><code>$ createuser -E -P " . $pa_db_user . "<br>" .
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
            echo "</code></p>\n";
        }
        echo "<p>" . _('After you have added the new user, proceed with this installation procedure.') . "</p>\n";
        echo "<form method=\"post\">";
        echo "<input type=\"hidden\" name=\"db_host\" value=\"" . $db_host . "\">";
        echo "<input type=\"hidden\" name=\"db_name\" value=\"" . $db_name . "\">";
        echo "<input type=\"hidden\" name=\"db_port\" value=\"" . $db_port . "\">";
        echo "<input type=\"hidden\" name=\"db_type\" value=\"" . $db_type . "\">";
        echo "<input type=\"hidden\" name=\"db_user\" value=\"" . $db_user . "\">";
        echo "<input type=\"hidden\" name=\"db_pass\" value=\"" . $db_pass . "\">";
        echo "<input type=\"hidden\" name=\"db_charset\" value=\"" . $db_charset . "\">";
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
            "\$db_type\t\t= '" . $_POST['db_type'] . "';\n";

        if ($_POST['db_charset']) {
            $config .= "\$db_charset\t\t= '" . $_POST['db_charset'] . "';\n";
        }

        $config .= "\n" .
            "\$session_key\t\t= '" . Poweradmin\Password::salt(SESSION_KEY_LENGTH) . "';\n" .
            "\n" .
            "\$iface_lang\t\t= '" . $_POST['language'] . "';\n" .
            "\n" .
            "\$dns_hostmaster\t\t= '" . $_POST['dns_hostmaster'] . "';\n" .
            "\$dns_ns1\t\t= '" . $_POST['dns_ns1'] . "';\n" .
            "\$dns_ns2\t\t= '" . $_POST['dns_ns2'] . "';\n";

        if (is_writeable(LOCAL_CONFIG_FILE)) {
            $h_config = fopen(LOCAL_CONFIG_FILE, "w");
            fwrite($h_config, $config);
            fclose($h_config);
            echo "<p>" . _('The installer was able to write to the file "') . LOCAL_CONFIG_FILE . _('". A basic configuration, based on the details you have given, has been created.') . "</p>\n";
        } else {
            echo "<p>" . _('The installer is unable to write to the file "') . LOCAL_CONFIG_FILE . _('" (which is in itself good). The configuration is printed here. You should now create the file "')
                . LOCAL_CONFIG_FILE . _('" in the Poweradmin root directory yourself. It should contain the following few lines:') . "</p>\n";
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
        echo $twig->render('step7.html', array(
            'message1' => _('Now we have finished the configuration.'),
            'message2' => _('If you want support for the URLs used by other dynamic DNS providers, run "cp install/htaccess.dist .htaccess" and enable mod_rewrite in Apache.'),
            'message3' => _('You should (must!) remove the directory "install/" from the Poweradmin root directory. You will not be able to use Poweradmin if it exists. Do it now.'),
            'message4' => _('After you have removed the directory, you can login to <a href="../index.php">Poweradmin</a>.')));
        break;

    default:
        break;
}

include_once('../inc/version.inc.php');

echo "<div class=\"footer\">";
echo "<a href=\"http://www.poweradmin.org/\">a complete(r) <strong>poweradmin</strong> v$VERSION</a> - <a href=\"http://www.poweradmin.org/credits.html\">credits</a>";
echo "</div></body></html>";
