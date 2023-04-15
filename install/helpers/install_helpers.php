<?php

use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Configuration;

require_once '../inc/database.inc.php';
require_once 'database_helpers.php';

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

function step4($twig, $current_step, $default_config_file): void {
    echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";

    $databaseCredentials = [
        'db_user' => $_POST['user'],
        'db_pass' => $_POST['pass'],
        'db_host' => $_POST['host'],
        'db_port' => $_POST['dbport'],
        'db_name' => $_POST['name'],
        'db_charset' => $_POST['charset'],
        'db_collation' => $_POST['collation'],
        'db_type' => $_POST['type'],
    ];

    if ($databaseCredentials['db_type'] == 'sqlite') {
        $databaseCredentials['db_file'] = $databaseCredentials['db_name'];
    }

    $pa_pass = $_POST['pa_pass'];
    $db = dbConnect($databaseCredentials, $isQuiet = false, $installerMode = true);
    updateDatabase($db, $databaseCredentials);

    createAdministratorUser($db, $pa_pass, $default_config_file);

    echo _('done!') . "</p>";

    if ($databaseCredentials['db_type'] == 'sqlite') {
        $current_step = 5;
    }

    renderTemplate($twig, 'step4.html', array_merge([
        'current_step' => $current_step,
        'language' => htmlspecialchars($_POST['language']),
        'pa_pass' => htmlspecialchars($pa_pass),
    ], $databaseCredentials));
}

function step5($twig, $current_step, $language): void
{
    $current_step++;

    $databaseCredentials = [
        'db_user' => $_POST['db_user'],
        'db_pass' => $_POST['db_pass'],
        'db_host' => $_POST['db_host'],
        'db_port' => $_POST['db_port'],
        'db_name' => $_POST['db_name'],
        'db_charset' => $_POST['db_charset'],
        'db_collation' => $_POST['db_collation'],
        'db_type' => $_POST['db_type'],
    ];

    if ($databaseCredentials['db_type'] == 'sqlite') {
        $databaseCredentials['db_file'] = $databaseCredentials['db_name'];
    } else {
        $databaseCredentials['pa_db_user'] = $_POST['pa_db_user'];
        $databaseCredentials['pa_db_pass'] = $_POST['pa_db_pass'];
    }

    $pa_pass = $_POST['pa_pass'];
    $hostmaster = $_POST['dns_hostmaster'];
    $dns_ns1 = $_POST['dns_ns1'];
    $dns_ns2 = $_POST['dns_ns2'];

    $db = dbConnect($databaseCredentials, $isQuiet = false, $installerMode = true);

    $instructions = generateDatabaseUserInstructions($db, $databaseCredentials);

    renderTemplate($twig, 'step5.html', array(
        'current_step' => $current_step,
        'language' => htmlspecialchars($language),
        'db_host' => htmlspecialchars($databaseCredentials['db_host']),
        'db_name' => htmlspecialchars($databaseCredentials['db_name']),
        'db_port' => htmlspecialchars($databaseCredentials['db_port']),
        'db_type' => htmlspecialchars($databaseCredentials['db_type']),
        'db_user' => htmlspecialchars($databaseCredentials['db_user']),
        'db_pass' => htmlspecialchars($databaseCredentials['db_pass']),
        'db_charset' => htmlspecialchars($databaseCredentials['db_charset']),
        'pa_db_user' => isset($databaseCredentials['pa_db_user']) ? htmlspecialchars($databaseCredentials['pa_db_user']) : '',
        'pa_db_pass' => isset($databaseCredentials['pa_db_pass']) ? htmlspecialchars($databaseCredentials['pa_db_pass']) : '',
        'pa_pass' => htmlspecialchars($pa_pass),
        'dns_hostmaster' => htmlspecialchars($hostmaster),
        'dns_ns1' => htmlspecialchars($dns_ns1),
        'dns_ns2' => htmlspecialchars($dns_ns2),
        'instructions' => $instructions
    ));
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
    $db_user = $_POST['pa_db_user'] ?? '';
    $db_pass = $_POST['pa_db_pass'] ?? '';
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
