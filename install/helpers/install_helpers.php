<?php

use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Configuration;

function checkConfigFile($current_step, $local_config_file, $twig): void
{
    if ($current_step == 1 && file_exists($local_config_file)) {
        echo "<p class='alert alert-danger'>" . _('There is already a configuration file in place, so the installation will be skipped.') . "</p>";
        echo $twig->render('footer.html');
        exit;
    }
}

function renderTemplate($twig, $templateName, $data): void
{
    $data['next_step'] = $data['current_step'] + 1;
    echo $twig->render($templateName, $data);
}

function step1($twig, $current_step): void
{
    renderTemplate($twig, 'step1.html', array('current_step' => $current_step));
}

function step2($twig, $current_step, $language): void
{
    renderTemplate($twig, 'step2.html', array('current_step' => $current_step, 'language' => htmlspecialchars($language)));
}

function step3($twig, $current_step, $language): void
{
    renderTemplate($twig, 'step3.html', array('current_step' => $current_step, 'language' => htmlspecialchars($language)));
}

function step4($twig, $current_step, $default_config_file): void
{
    echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";

    global $db_type;
    global $db_user;
    global $db_pass;
    global $db_host;
    global $db_port;
    global $db_name;
    global $db_charset;
    global $db_file;

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
    $db = dbConnect($isQuiet = false, $installerMode = true);
    $current_tables = $db->listTables();
    $def_tables = getDefaultTables();

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
    $def_permissions = include 'includes/permissions.php';
    $db->executeMultiple($fill_perm_items, $def_permissions);
    if (method_exists($fill_perm_items, 'free')) {
        $fill_perm_items->free();
    }

    // Create an administrator user with the appropriate permissions
    $adminName = 'Administrator';
    $adminDescr = 'Administrator template with full rights.';
    $stmt = $db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
    $stmt->execute([':name' => $adminName, ':descr' => $adminDescr]);

    $permTemplId = $db->lastInsertId();

    $uberAdminUser = 'user_is_ueberuser';
    $stmt = $db->prepare("SELECT id FROM perm_items WHERE name = :name");
    $stmt->execute([':name' => $uberAdminUser]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $uberAdminUserId = $row['id'];

    $permTemplItemsQuery = $db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:perm_templ_id, :uber_admin_user_id)");
    $permTemplItemsQuery->execute([':perm_templ_id' => $permTemplId, ':uber_admin_user_id' => $uberAdminUserId]);

    $config = new Configuration($default_config_file);
    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );
    $user_query = $db->prepare("INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('admin', ?, 'Administrator', 'admin@example.net', 'Administrator with full rights.', ?, 1, 0)");
    $user_query->execute(array($userAuthService->hashPassword($pa_pass), $permTemplId));

    echo _('done!') . "</p>";

    renderTemplate($twig, 'step4.html', array(
        'current_step' => $current_step,
        'language' => htmlspecialchars($_POST['language']),
        'db_user' => htmlspecialchars($db_user),
        'db_pass' => htmlspecialchars($db_pass),
        'db_host' => htmlspecialchars($db_host),
        'db_port' => htmlspecialchars($db_port),
        'db_name' => htmlspecialchars($db_name),
        'db_type' => htmlspecialchars($db_type),
        'db_charset' => htmlspecialchars($db_charset),
        'pa_pass' => htmlspecialchars($pa_pass)
    ));
}

function step5($twig, $current_step, $language): void
{
    $current_step++;

    global $db_type;
    global $db_user;
    global $db_pass;
    global $db_host;
    global $db_port;
    global $db_name;
    global $db_charset;
    global $db_file;

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
    $hostmaster = $_POST['dns_hostmaster'];
    $dns_ns1 = $_POST['dns_ns1'];
    $dns_ns2 = $_POST['dns_ns2'];

    require_once("../inc/database.inc.php");
    $db = dbConnect($isQuiet = false, $installerMode = true);

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
        echo "<p><code>";
        echo "CREATE USER '" . htmlspecialchars($pa_db_user) . "'@'" . htmlspecialchars($pa_db_host) . "' IDENTIFIED BY '" . htmlspecialchars($pa_db_pass) . "';<br>";
        echo "GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON " . htmlspecialchars($db_name) . ".*<br>TO '" . htmlspecialchars($pa_db_user) . "'@'" . htmlspecialchars($pa_db_host) . "';</code></p>";
    } elseif ($db_type == 'pgsql') {
        echo _('On PgSQL you would use:') . "</p>";
        echo "<p><code>$ createuser -E -P " . htmlspecialchars($pa_db_user) . "<br>" .
            "Enter password for new role: " . htmlspecialchars($pa_db_pass) . "<br>" .
            "Enter it again: " . htmlspecialchars($pa_db_pass) . "<br>" .
            "CREATE USER<br>" .
            "$ psql " . htmlspecialchars($db_name) . "<br>";
        echo "psql> ";
        $def_tables = getDefaultTables();
        $grantTables = getGrantTables($def_tables);
        foreach ($grantTables as $tableName) {
            echo "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . htmlspecialchars($pa_db_user) . ";<br />";
        }
        $grantSequences = getGrantSequences($def_tables);
        foreach ($grantSequences as $sequenceName) {
            echo "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . htmlspecialchars($pa_db_user) . ";<br />";
        }
        echo "</code></p>\n";
    }
    echo "<p>" . _('After you have added the new user, proceed with this installation procedure.') . "</p>\n";
    echo "<form method=\"post\">";
    echo "<input type=\"hidden\" name=\"db_host\" value=\"" . htmlspecialchars($db_host) . "\">";
    echo "<input type=\"hidden\" name=\"db_name\" value=\"" . htmlspecialchars($db_name) . "\">";
    echo "<input type=\"hidden\" name=\"db_port\" value=\"" . htmlspecialchars($db_port) . "\">";
    echo "<input type=\"hidden\" name=\"db_type\" value=\"" . htmlspecialchars($db_type) . "\">";
    echo "<input type=\"hidden\" name=\"db_user\" value=\"" . htmlspecialchars($db_user) . "\">";
    echo "<input type=\"hidden\" name=\"db_pass\" value=\"" . htmlspecialchars($db_pass) . "\">";
    echo "<input type=\"hidden\" name=\"db_charset\" value=\"" . htmlspecialchars($db_charset) . "\">";
    if ($db_type != 'sqlite') {
        echo "<input type=\"hidden\" name=\"pa_db_user\" value=\"" . htmlspecialchars($pa_db_user) . "\">";
        echo "<input type=\"hidden\" name=\"pa_db_pass\" value=\"" . htmlspecialchars($pa_db_pass) . "\">";
    }
    echo "<input type=\"hidden\" name=\"pa_pass\" value=\"" . htmlspecialchars($pa_pass) . "\">";
    echo "<input type=\"hidden\" name=\"dns_hostmaster\" value=\"" . htmlspecialchars($hostmaster) . "\">";
    echo "<input type=\"hidden\" name=\"dns_ns1\" value=\"" . htmlspecialchars($dns_ns1) . "\">";
    echo "<input type=\"hidden\" name=\"dns_ns2\" value=\"" . htmlspecialchars($dns_ns2) . "\">";
    echo "<input type=\"hidden\" name=\"step\" value=\"" . htmlspecialchars($current_step) . "\">";
    echo "<input type=\"hidden\" name=\"language\" value=\"" . htmlspecialchars($language) . "\">";
    echo "<input type=\"submit\" name=\"submit\" class=\"btn btn-primary btn-sm\" value=\"" . _('Go to step') . " " . htmlspecialchars($current_step) . "\">";
    echo "</form>";
}

function step6($twig, $current_step, $language, $default_config_file, $local_config_file): void
{
    // No need to set database port if it's standard port for that db
    $db_port = ($_POST['db_type'] == 'mysql' && $_POST['db_port'] != 3306)
    || ($_POST['db_type'] == 'pgsql' && $_POST['db_port'] != 5432) ? $_POST['db_port'] : '';

    // For SQLite we should provide path to db file
    $db_file = $_POST['db_type'] == 'sqlite' ? $db_file = $_POST['db_name'] : '';

    $config = new Configuration($default_config_file);
    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );

    $session_key = $userAuthService->generateSalt(SESSION_KEY_LENGTH);
    $iface_lang = $language;
    $dns_hostmaster = $_POST['dns_hostmaster'];
    $dns_ns1 = $_POST['dns_ns1'];
    $dns_ns2 = $_POST['dns_ns2'];
    $dns_ns3 = ''; // $_POST['dns_ns3'];
    $dns_ns4 = ''; // $_POST['dns_ns4'];
    $db_host = $_POST['db_host'];
    $db_user = $_POST['pa_db_user'];
    $db_pass = $_POST['pa_db_pass'];
    $db_name = $_POST['db_name'];
    $db_type = $_POST['db_type'];
    $db_charset = $_POST['db_charset'];
    $pa_pass = $_POST['pa_pass'];

    $configuration = str_replace(
        [
            '%dbType%',
            '%dbFile%',
            '%dbHost%',
            '%dbPort%',
            '%dbUser%',
            '%dbPassword%',
            '%dbName%',
            '%dbCharset%',
            '%sessionKey%',
            '%locale%',
            '%hostMaster%',
            '%primaryNameServer%',
            '%secondaryNameServer%',
            '%thirdNameServer%',
            '%fourthNameServer%',
        ],
        [
            $db_type,
            $db_file,
            $db_host,
            $db_port,
            $db_user,
            $db_pass,
            $db_name,
            $db_charset,
            $session_key,
            $iface_lang,
            $dns_hostmaster,
            $dns_ns1,
            $dns_ns2,
            $dns_ns3,
            $dns_ns4,
        ],
        file_get_contents('includes/config_template.php')
    );

    // Try to create configuration file
    $config_file_created = false;

    if (is_writeable($local_config_file)) {
        $local_config = fopen($local_config_file, "w");
        fwrite($local_config, $configuration);
        fclose($local_config);
        $config_file_created = true;
    }

    $userAuthService = new UserAuthenticationService(
        $config->get('password_encryption'),
        $config->get('password_encryption_cost')
    );

    renderTemplate($twig, 'step6.html', array(
        'current_step' => (int)htmlspecialchars($current_step),
        'language' => htmlspecialchars($language),
        'config_file_created' => $config_file_created,
        'local_config_file' => $local_config_file,
        'session_key' => $userAuthService->generateSalt(SESSION_KEY_LENGTH),
        'iface_lang' => htmlspecialchars($language),
        'dns_hostmaster' => htmlspecialchars($dns_hostmaster),
        'dns_ns1' => htmlspecialchars($dns_ns1),
        'dns_ns2' => htmlspecialchars($dns_ns2),
        'db_host' => htmlspecialchars($db_host),
        'db_user' => htmlspecialchars($db_user),
        'db_pass' => htmlspecialchars($db_pass),
        'db_name' => htmlspecialchars($db_name),
        'db_file' => $db_type == 'sqlite' ? htmlspecialchars($db_name) : '',
        'db_type' => htmlspecialchars($db_type),
        'db_port' => htmlspecialchars($db_port),
        'db_charset' => htmlspecialchars($db_charset),
        'pa_pass' => htmlspecialchars($pa_pass)
    ));
}

function step7($twig): void
{
    renderTemplate($twig, 'step7.html', array(
        'current_step' => 7,
    ));
}
