<?php

use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Configuration;

function updateDatabase($db, $databaseCredentials): void
{
    $current_tables = $db->listTables();
    $def_tables = getDefaultTables();

    foreach ($def_tables as $table) {
        if (in_array($table['table_name'], $current_tables)) {
            $db->dropTable($table['table_name']);
        }

        $options = $table['options'];

        if ($databaseCredentials['db_charset']) {
            $options['charset'] = $databaseCredentials['db_charset'];
        }

        if ($databaseCredentials['db_collation']) {
            $options['collation'] = $databaseCredentials['db_collation'];
        }
        $db->createTable($table['table_name'], $table['fields'], $options);
    }

    $fill_perm_items = $db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
    $def_permissions = include 'includes/permissions.php';
    $db->executeMultiple($fill_perm_items, $def_permissions);
    if (method_exists($fill_perm_items, 'free')) {
        $fill_perm_items->free();
    }
}

function createAdministratorUser($db, $pa_pass, $default_config_file) {
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
}

function generateDatabaseUserInstructions($db, $databaseCredentials): string
{
    $instructions = "";

    if ($databaseCredentials['db_type'] == 'mysql') {
        $pa_db_host = $databaseCredentials['db_host'];

        $sql = 'SELECT USER()';
        $result = $db->queryRow($sql);
        if (isset($result['user()'])) {
            $current_db_user = $result['user()'];
            $pa_db_host = substr($current_db_user, strpos($current_db_user, '@') + 1);
        }

        $instructions .= "CREATE USER '" . htmlspecialchars($databaseCredentials['pa_db_user']) . "'@'" . htmlspecialchars($pa_db_host) . "' IDENTIFIED BY '" . htmlspecialchars($databaseCredentials['pa_db_pass']) . "';\n";
        $instructions .= "GRANT SELECT, INSERT, UPDATE, DELETE ON " . htmlspecialchars($databaseCredentials['db_name']) . ".* TO '" . htmlspecialchars($databaseCredentials['pa_db_user']) . "'@'" . htmlspecialchars($pa_db_host) . "';\n";
    } elseif ($databaseCredentials['db_type'] == 'pgsql') {
        $instructions .= "createuser -E -P " . htmlspecialchars($databaseCredentials['pa_db_user']) . "\n";
        $instructions .= "Enter password for new role: " . htmlspecialchars($databaseCredentials['pa_db_pass']) . "\n";
        $instructions .= "Enter it again: " . htmlspecialchars($databaseCredentials['pa_db_pass']) . "\n";
        $instructions .= "CREATE USER\n";
        $instructions .= "psql " . htmlspecialchars($databaseCredentials['db_name']) . "\n";

        $def_tables = getDefaultTables();
        $grantTables = getGrantTables($def_tables);
        foreach ($grantTables as $tableName) {
            $instructions .= "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . htmlspecialchars($databaseCredentials['pa_db_user']) . ";\n";
        }
        $grantSequences = getGrantSequences($def_tables);
        foreach ($grantSequences as $sequenceName) {
            $instructions .= "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . htmlspecialchars($databaseCredentials['pa_db_user']) . ";\n";
        }
    }

    return $instructions;
}
