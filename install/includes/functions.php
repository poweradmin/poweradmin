<?php

/**
 * Checks if required step is in session, if not redirect to $failureStep
 *
 * @param integer $requiredStep
 * @param integer $failureStep
 * @param bool $equal
 */
function checkStep($requiredStep, $failureStep, $equal = true)
{
    if (!array_key_exists('step', $_SESSION) || ($equal === true && $_SESSION['step'] !== $requiredStep) || ($equal === false && $_SESSION['step'] < $requiredStep)) {
        // Redirect to failure-step
        header('location: ' . $_SERVER['PHP_SELF'] . '?step=' . $failureStep);
        exit;
    }
}

/**
 * Configures the PHP-Extension "gettext" to use the configured locale
 *
 * @param string $locale
 * @param string $defaultLocale
 */
function configureGettextExtension($locale, $defaultLocale)
{
    // Check for module "gettext"
    if (!function_exists('gettext')) {
        // @ToDo: real error-handling
        die('<strong>Error:</strong> The PHP-Extension "gettext" is missing so it is not possible to change the language!');
    }

    // Set translation-domain
    bindtextdomain('poweradmin_installer', realpath(INSTALLER_DIRECTORY . 'locales'));
    bind_textdomain_codeset('poweradmin_installer', 'UTF-8');
    textdomain('poweradmin_installer');

    // Set locale
    $result = setlocale(LC_ALL, $locale);
    putenv('LANG=' . $locale);
    putenv('LANGUAGE=' . $locale);

    // Check for error -> locale not exist
    if (!$result && $locale !== $defaultLocale) {
        // Try to set default locale
        $result = setlocale(LC_ALL, $defaultLocale);
        putenv('LANG=' . $defaultLocale);
        putenv('LANGUAGE=' . $defaultLocale);
    }

    // Check for error -> locale and even default locale not exist
    if (!$result) {
        // @ToDo: real error-handling
        die(sprintf('<strong>Error:</strong> The locale "%s" ' . ($locale !== $defaultLocale ? 'and the default locale "%s" ' : '') . 'does not exist!', $locale, $defaultLocale));
    }
}

/**
 * Creates user with specified user-data
 *
 * @param array $userData
 * @param string $connectionName
 * @throws \Doctrine\DBAL\DBALException
 */
function createUser($userData, $connectionName = 'default')
{
    // Get database-connection && query-builder
    $dbConnection = Poweradmin\Db::getConnection($connectionName);
    $queryBuilder = $dbConnection->createQueryBuilder();

    // Replace password with hashed password
    $userData['password'] = Poweradmin\Password::hash($userData['password']);

    // Create user-query
    $userQuery = $queryBuilder->insert('users')->values([
        'username' => '?', 'password' => '?', 'fullname' => '?', 'email' => '?', 'description' => '?',
        'perm_templ' => '?', 'use_ldap' => '?', 'active' => '?'
    ]);

    // Insert user
    $dbConnection->executeQuery(
        $userQuery,
        [
            $userData['username'], $userData['password'], $userData['fullname'], $userData['email'],
            'Administrator with full rights.', 1, false, true
        ]
    );
}

/**
 * Returns default-port for selected db-driver
 *
 * @return int
 */
function getDbPortDefault()
{
    return array_key_exists('dbDriver', $_POST) && $_POST['dbDriver'] === 'pgsql' ? 5432 : 3306;
}

/**
 * Checks if value exist for field and returns it, otherwise returns default
 *
 * @param string $field
 * @param string $default
 * @return string
 */
function getValue($field, $default = '')
{
    if (array_key_exists($field, $_POST) && !empty($_POST[$field])) {
        return $_POST[$field];
    }

    return $default;
}
