<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\LegacyConfiguration;

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
    $def_permissions = getPermissionMappings();
    $db->executeMultiple($fill_perm_items, $def_permissions);
    if (method_exists($fill_perm_items, 'free')) {
        $fill_perm_items->free();
    }
}

function createAdministratorUser($db, $pa_pass, $default_config_file): void
{
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

    $config = new LegacyConfiguration($default_config_file);
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

        $instructions .= "CREATE USER '" . htmlspecialchars($databaseCredentials['pa_db_user']) . "'@'" . htmlspecialchars($pa_db_host) . "' IDENTIFIED WITH mysql_native_password BY '" . htmlspecialchars($databaseCredentials['pa_db_pass']) . "';\n";
        $instructions .= "GRANT SELECT, INSERT, UPDATE, DELETE ON " . htmlspecialchars($databaseCredentials['db_name']) . ".* TO '" . htmlspecialchars($databaseCredentials['pa_db_user']) . "'@'" . htmlspecialchars($pa_db_host) . "';\n";
    } elseif ($databaseCredentials['db_type'] == 'pgsql') {
        $instructions .= "CREATE USER " . htmlspecialchars($databaseCredentials['pa_db_user']) . " WITH PASSWORD '" . htmlspecialchars($databaseCredentials['pa_db_pass']) . "';\n";

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
