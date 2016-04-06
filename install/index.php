<?php

// Check for debug-mode
if (array_key_exists('debug', $_GET) && $_GET['debug'] === 'true') {
    // Enable error-reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Set debug-mode constant
    define('DEBUG', true);
}

// Starting session
session_start();

// Set directory-constants
define('APPLICATION_DIRECTORY', dirname(__DIR__) . '/');
define('INSTALLER_DIRECTORY', __DIR__ . '/');

// Load autoloader & functions-file
require_once APPLICATION_DIRECTORY . 'vendor/autoload.php';
require_once INSTALLER_DIRECTORY . 'includes/functions.php';
require_once INSTALLER_DIRECTORY . 'includes/validation_functions.php';

// Import used classes
use Valitron\Validator as V;

// Set array with default-configuration
$config = [
    'configFile' => APPLICATION_DIRECTORY . 'inc/config.inc.php',

    'availableDatabaseDrivers' => Doctrine\DBAL\DriverManager::getAvailableDrivers(),
    'supportedDatabaseDrivers' => ['pdo_mysql' => 'MySQL', 'pdo_pgsql' => 'PostgreSQL', 'pdo_sqlite' => 'SQLite'],

    'defaultLocale' => 'en_US.UTF-8',
    'locales' => ['en_US.UTF-8' => _('English'), 'de_DE.UTF-8' => _('German')],

    'sessionKeyLength' => 48
];

// Add overlap of available and supported database-drivers to config
$config['availableSupportedDatabaseDrivers'] = array_intersect(
    array_keys($config['supportedDatabaseDrivers']),
    $config['availableDatabaseDrivers']
);

// Detect locale-change
if (array_key_exists('locale', $_POST) && array_key_exists($_POST['locale'], $config['locales'])) {
    // Change locale
    $_SESSION['locale'] = $_POST['locale'];
}

// Build array with parameters
$parameters = [
    'locale' => array_key_exists('locale', $_SESSION) ? $_SESSION['locale'] : $config['defaultLocale'],
    'step' => array_key_exists('step', $_GET) && $_GET['step'] >= 1 && $_GET['step'] <= 5 ? $_GET['step'] : 1
];

// Configure "gettext"-extension
configureGettextExtension($parameters['locale'], $config['defaultLocale']);

// Set locale for valitron
V::lang(substr($parameters['locale'], 0, 2));

// Create object of validator-library
$validator = new V($_POST);

// Switch through installer-steps
switch ($parameters['step']) {
    case 1:
        if (array_key_exists('submit', $_POST)) {
            // Add validation-rules
            $validator->rule('required', 'confirmInformation');
            $validator->rule('accepted', 'confirmInformation');

            // Validate form-data
            if ($validator->validate()) {
                // Set step to session to avoid skipping
                $_SESSION['step'] = 2;

                // Redirect user to next step
                header('location: ' . $_SERVER['PHP_SELF'] . '?step=2' . (defined('DEBUG') ? '&debug=true' : ''));
                exit;
            } else {
                // Get validation-errors
                $errors = $validator->errors();
            }
        }
        break;

    case 2:
        // Check for step in session to prevent skipping
        checkStep(2, 1, false);

        // Do tests to check php-requirements
        $phpRequirements = [
            'php-version' => version_compare(PHP_VERSION, '5.5', '>='),
            'php-module-session' => function_exists('session_start'),
            'php-module-gettext' => function_exists('gettext'),
            'php-module-mcrypt' => function_exists('mcrypt_decrypt'),
            'php-function-exec' => !in_array('exec', explode(',', ini_get('disable_functions')))
        ];

        // Create config-file && check if it's writable
        $createConfigFile = !file_exists($config['configFile']) ? touch($config['configFile']) : true;
        $configFileIsWritable = is_writable($config['configFile']);
        break;

    case 3:
        // Check for step in session to prevent skipping
        checkStep(3, 2);

        // Check for form
        if (array_key_exists('dbDriver', $_POST) && in_array($_POST['dbDriver'], $config['availableSupportedDatabaseDrivers'])) {
            // Set field-names as labels
            $validator->labels([
                'dbFile' => _('Database-File'),
                'dbHost' => _('Host'),
                'dbPort' => _('Port'),
                'dbUsername' => _('Username'),
                'dbPassword' => _('Password'),
                'dbDatabase' => _('Database'),
                'dbCharset' => _('Charset')
            ]);

            // Switch database-drivers
            switch ($_POST['dbDriver']) {
                case 'pdo_mysql':
                case 'pdo_pgsql':
                    // Set validation-rules
                    $validator->rule('required', ['dbHost', 'dbPort', 'dbUsername', 'dbPassword', 'dbDatabase', 'dbCharset']);
                    $validator->rule('numeric', 'dbPort');
                    $validator->rule('in', 'dbCharset', ['latin1', 'utf8']);

                    if ($validator->validate()) {
                        // Build parameters-array
                        $dbParameters = [
                            'driver' => $_POST['dbDriver'],
                            'host' => $_POST['dbHost'],
                            'port' => $_POST['dbPort'],
                            'user' => $_POST['dbUsername'],
                            'password' => $_POST['dbPassword'],
                            'dbname' => $_POST['dbDatabase'],
                            'charset' => $_POST['dbCharset']
                        ];
                    }
                    break;

                case 'pdo_sqlite':
                    // Set validation-rules
                    $validator->rule('required', 'dbFile');
                    $validator->rule('isFile', 'dbFile');

                    if ($validator->validate()) {
                        // Build parameters-array
                        $dbParameters = [
                            'driver' => $_POST['dbDriver'],
                            'path' => $_POST['dbFile']
                        ];

                        // Check for username as optional parameter
                        if (array_key_exists('dbUsername', $_POST) && !empty($_POST['dbUsername'])) {
                            $parameters['user'] = $_POST['dbUsername'];
                        }

                        // Check for password as optional parameter
                        if (array_key_exists('dbPassword', $_POST) && !empty($_POST['dbPassword'])) {
                            $parameters['password'] = $_POST['dbPassword'];
                        }
                    }
                    break;
            }

            if (isset($dbParameters) && Poweradmin\Db::validateParameters($dbParameters)) {
                // Create connection to test data
                $connectionStatus = Poweradmin\Db::createConnection($dbParameters);

                // Set type-mapping for pgsql & sqlite
                Poweradmin\Db::getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('bool', 'boolean');

                if ($connectionStatus) {
                    // Get existing tables
                    $tables = Poweradmin\Db::getConnection()->getSchemaManager()->listTables();

                    // Set required tables
                    $requiredTables = ['domains', 'records', 'supermasters', 'domainmetadata', 'cryptokeys', 'tsigkeys'];

                    // Check for tables
                    if (count($tables) > 0) {
                        // Loop through tables to remove existing
                        foreach ($tables as $i => $table) {
                            // Get array-key of table in requiredTables-array
                            $tableArrayKey = array_search($table->getName(), $requiredTables);

                            // Check if table is from PowerDNS to support custom tables in the same database
                            if ($tableArrayKey !== false) {
                                // Remove table from array
                                unset($requiredTables[$tableArrayKey]);
                            }
                        }
                    }

                    // Check if all required tables exists
                    if (count($requiredTables) === 0) {
                        // Set database-parameters to session && unlock next step
                        $_SESSION['dbParameters'] = $dbParameters;
                        $_SESSION['step'] = 4;
                    }
                }
            }
        }
        break;

    case 4:
        // Check for step in session to prevent skipping
        checkStep(4, 3);

        // Check for submit
        if (array_key_exists('submit', $_POST)) {
            // Set field-labels
            $validator->labels([
                'hostmaster' => _('E-Mail of Hostmaster'),
                'primaryNameserver' => _('Primary Nameserver'),
                'secondaryNameserver' => _('Secondary Nameserver'),
                'tertiaryNameserver' => _('Tertiary Nameserver'),
                'fullname' => _('Full Name'),
                'email' => _('E-Mail'),
                'username' => _('Username'),
                'password' => _('Password'),
                'passwordRepeat' => _('Password Repeat')
            ]);

            // Set validation-rules
            $validator->rule('required', ['hostmaster', 'primaryNameserver', 'secondaryNameserver', 'fullname', 'email', 'username', 'password', 'passwordRepeat']);
            $validator->rule('email', ['hostmaster', 'email']);
            $validator->rule('equals', 'password', 'passwordRepeat');
            $validator->rule('different', 'primaryNameserver', 'secondaryNameserver');
            $validator->rule('different', 'primaryNameserver', 'tertiaryNameserver');
            $validator->rule('different', 'secondaryNameserver', 'tertiaryNameserver');

            // Validate form
            if ($validator->validate()) {
                $_SESSION['generalData'] = [
                    'hostmaster' => str_replace('@', '.', $_POST['hostmaster']),
                    'primaryNameserver' => $_POST['primaryNameserver'],
                    'secondaryNameserver' => $_POST['secondaryNameserver']
                ];

                // Check for tertiary-nameserver as optional parameter
                if (array_key_exists('tertiaryNameserver', $_POST) && !empty($_POST['tertiaryNameserver'])) {
                    $_SESSION['generalData']['tertiaryNameserver'] = $_POST['tertiaryNameserver'];
                }

                $_SESSION['userData'] = [
                    'fullname' => $_POST['fullname'],
                    'email' => $_POST['email'],
                    'username' => $_POST['username'],
                    'password' => $_POST['password']
                ];

                // Unlock next step
                $_SESSION['step'] = 5;

                // Redirect user to next step
                header('location: ' . $_SERVER['PHP_SELF'] . '?step=5' . (defined('DEBUG') ? '&debug=true' : ''));
                exit;
            }
        }
        break;

    case 5:
        // Check for step in session to prevent skipping
        checkStep(5, 4);

        // Validate db-parameters from session
        if (!Poweradmin\Db::validateParameters($_SESSION['dbParameters'])) {
            // @ToDo: real error-handling
            die('<strong>Error:</strong> Error in db-parameters please contact the developer-team!');
        }

        // Create database-connection, get connection, get schema-manager get query-builder
        Poweradmin\Db::createConnection($_SESSION['dbParameters']);
        $dbConnection = Poweradmin\Db::getConnection();
        $schemaManager = $dbConnection->getSchemaManager();
        $queryBuilder = $dbConnection->createQueryBuilder();


        // Load database-structure
        $databaseStructure = include INSTALLER_DIRECTORY . 'includes/database_structure.php';

        // Loop through tables to create them
        foreach ($databaseStructure as $tableName => $tableData) {
            // Check if table exists
            if ($schemaManager->tablesExist($tableName)) {
                // Drop table
                $schemaManager->dropTable($tableName);
            }

            // Create Table
            $table = new Doctrine\DBAL\Schema\Table($tableName);

            // Loop through columns
            foreach ($tableData['columns'] as $fieldName => $fieldData) {
                // Add column
                $table->addColumn(
                    $fieldName,
                    $fieldData['type'],
                    array_key_exists('options', $fieldData) ? $fieldData['options'] : []
                );
            }

            // Check for primary-key
            if (array_key_exists('primaryKey', $tableData)) {
                // Set primary-key
                $table->setPrimaryKey($tableData['primaryKey']);
            }

            // Check for unique-index
            if (array_key_exists('unique', $tableData)) {
                // Add unique-index
                $table->addUniqueIndex($tableData['unique']);
            }

            // Add table
            $schemaManager->createTable($table);
        }


        // Load permissions
        $permissions = include INSTALLER_DIRECTORY . 'includes/permissions.php';

        // Set counter-variable
        $permissionsCreated = 0;

        // Loop through permissions
        foreach ($permissions as $i => $permission) {
            // Insert permission
            $dbConnection->executeQuery(
                $queryBuilder->insert('perm_items')->values(['id' => '?', 'name' => '?', 'descr' => '?']),
                [$permission[0], $permission[1], $permission[2]]
            );

            ++$permissionsCreated;
        }

        // Create default permission-template
        $permissionTemplateResult = $dbConnection->executeQuery(
            $queryBuilder->insert('perm_templ')->values(['name' => '?', 'descr' => '?']),
            ['Administrator', _('Administrator template with full rights.')]
        );

        $permissionTemplateItemResult = $dbConnection->executeQuery(
            $queryBuilder->insert('perm_templ_items')->values(['templ_id' => '?', 'perm_id' => '?']),
            [1, 53]
        );


        // Get user-data
        $user = $_SESSION['userData'];

        // Replace password with hashed password
        $user['password'] = Poweradmin\Password::hash($user['password']);

        // Create user-query
        $userQuery = $queryBuilder->insert('users')->values([
            'username' => '?', 'password' => '?', 'fullname' => '?', 'email' => '?', 'description' => '?',
            'perm_templ' => '?', 'use_ldap' => '?', 'active' => '?'
        ]);

        // Insert user
        $userResult = $dbConnection->executeQuery(
            $userQuery,
            [
                $user['username'], $user['password'], $user['fullname'], $user['email'],
                _('Administrator with full rights.'), 1, 0, 1
            ]
        );


        // Get template of config-file
        $configFileContent = file_get_contents(INSTALLER_DIRECTORY . 'includes/config_template.php');

        // Get db-parameters & general-data from session
        $dbParameters = $_SESSION['dbParameters'];
        $generalData = $_SESSION['generalData'];

        // Replace placeholder
        $configFileContent = str_replace([
            '%dbType%',
            $dbParameters['driver'] === 'pdo_sqlite' ? '%dbFile%' : '$db_file',
            array_key_exists('user', $dbParameters) ? '%dbUsername%' : '$db_user',
            array_key_exists('password', $dbParameters) ? '%dbPassword%' : '$db_pass',
            $dbParameters['driver'] !== 'pdo_sqlite' ? '%dbHost%' : '$db_host',
            $dbParameters['driver'] !== 'pdo_sqlite' ? '%dbPort%' : '$db_port',
            $dbParameters['driver'] !== 'pdo_sqlite' ? '%dbDatabase%' : '$db_name',
            $dbParameters['driver'] !== 'pdo_sqlite' ? '%dbCharset%' : '$db_charset',
            '%sessionKey%',
            '%locale%',
            '%hostmaster%',
            '%primaryNameserver%',
            '%secondaryNameserver%',
            array_key_exists('tertiaryNameserver', $generalData) ? '%tertiaryNameserver%' : '$dns_ns3'
        ], [
            substr($_SESSION['dbParameters']['driver'], 4),
            $dbParameters['driver'] === 'pdo_sqlite' ? $dbParameters['path'] : '//$db_file',
            array_key_exists('user', $dbParameters) ? $dbParameters['user'] : '//$db_user',
            array_key_exists('password', $dbParameters) ? $dbParameters['password'] : '//$db_pass',
            $dbParameters['driver'] !== 'pdo_sqlite' ? $dbParameters['host'] : '//$db_host',
            $dbParameters['driver'] !== 'pdo_sqlite' ? $dbParameters['port'] : '//$db_port',
            $dbParameters['driver'] !== 'pdo_sqlite' ? $dbParameters['dbname'] : '//$db_name',
            $dbParameters['driver'] !== 'pdo_sqlite' ? $dbParameters['charset'] : '//$db_charset',
            Poweradmin\Password::salt($config['sessionKeyLength']),
            $parameters['locale'],
            $generalData['hostmaster'],
            $generalData['primaryNameserver'],
            $generalData['secondaryNameserver'],
            array_key_exists('tertiaryNameserver', $generalData) ? $generalData['tertiaryNameserver'] : '//$dns_ns3'
        ], $configFileContent);

        // Write config-file
        $configWriteResult = file_put_contents($config['configFile'], $configFileContent);
        break;
}

// Include template for set step
include INSTALLER_DIRECTORY . 'templates/step' . $parameters['step'] . '.php';

// Write locale to session
$_SESSION['locale'] = $parameters['locale'];
