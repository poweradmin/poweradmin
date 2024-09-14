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

namespace PoweradminInstall;

use PDO;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\AppConfiguration;

class DatabaseHelper
{
    private $db;
    private $databaseCredentials;

    public function __construct($db, $databaseCredentials)
    {
        $this->db = $db;
        $this->databaseCredentials = $databaseCredentials;
    }

    public function updateDatabase(): void
    {
        $current_tables = $this->db->listTables();
        $def_tables = DatabaseStructureHelper::getDefaultTables();

        foreach ($def_tables as $table) {
            if (in_array($table['table_name'], $current_tables)) {
                $this->db->dropTable($table['table_name']);
            }

            $options = $table['options'];

            if ($this->databaseCredentials['db_charset']) {
                $options['charset'] = $this->databaseCredentials['db_charset'];
            }

            if ($this->databaseCredentials['db_collation']) {
                $options['collation'] = $this->databaseCredentials['db_collation'];
            }
            $this->db->createTable($table['table_name'], $table['fields'], $options);
        }

        $fill_perm_items = $this->db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        $def_permissions = PermissionHelper::getPermissionMappings();
        $this->db->executeMultiple($fill_perm_items, $def_permissions);
        if (method_exists($fill_perm_items, 'free')) {
            $fill_perm_items->free();
        }
    }

    public function createAdministratorUser($pa_pass, $default_config_file): void
    {
        // Create an administrator user with the appropriate permissions
        $adminName = 'Administrator';
        $adminDescr = 'Administrator template with full rights.';
        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
        $stmt->execute([':name' => $adminName, ':descr' => $adminDescr]);

        $permTemplId = $this->db->lastInsertId();

        $uberAdminUser = 'user_is_ueberuser';
        $stmt = $this->db->prepare("SELECT id FROM perm_items WHERE name = :name");
        $stmt->execute([':name' => $uberAdminUser]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $uberAdminUserId = $row['id'];

        $permTemplItemsQuery = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:perm_templ_id, :uber_admin_user_id)");
        $permTemplItemsQuery->execute([':perm_templ_id' => $permTemplId, ':uber_admin_user_id' => $uberAdminUserId]);

        $config = new AppConfiguration($default_config_file);
        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );
        $user_query = $this->db->prepare("INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('admin', ?, 'Administrator', 'admin@example.net', 'Administrator with full rights.', ?, 1, 0)");
        $user_query->execute(array($userAuthService->hashPassword($pa_pass), $permTemplId));
    }

    public function generateDatabaseUserInstructions(): string
    {
        $instructions = "";

        if ($this->databaseCredentials['db_type'] == 'mysql') {
            $pa_db_host = $this->databaseCredentials['db_host'];

            $sql = 'SELECT USER()';
            $result = $this->db->queryRow($sql);
            if (isset($result['user()'])) {
                $current_db_user = $result['user()'];
                $pa_db_host = substr($current_db_user, strpos($current_db_user, '@') + 1);
            }

            $instructions .= "CREATE USER '" . htmlspecialchars($this->databaseCredentials['pa_db_user']) . "'@'%' IDENTIFIED BY '" . htmlspecialchars($databaseCredentials['pa_db_pass']) . "';\n";
            $instructions .= "GRANT SELECT, INSERT, UPDATE, DELETE ON " . htmlspecialchars($databaseCredentials['db_name']) . ".* TO '" . htmlspecialchars($databaseCredentials['pa_db_user']) . "'@'%';\n";
        } elseif ($this->databaseCredentials['db_type'] == 'pgsql') {
            $instructions .= "CREATE USER " . htmlspecialchars($this->databaseCredentials['pa_db_user']) . " WITH PASSWORD '" . htmlspecialchars($databaseCredentials['pa_db_pass']) . "';\n";

            $def_tables = DatabaseStructureHelper::getDefaultTables();
            $grantTables = $this->getGrantTables($def_tables);
            foreach ($grantTables as $tableName) {
                $instructions .= "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . htmlspecialchars($databaseCredentials['pa_db_user']) . ";\n";
            }
            $grantSequences = $this->getGrantSequences($def_tables);
            foreach ($grantSequences as $sequenceName) {
                $instructions .= "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . htmlspecialchars($databaseCredentials['pa_db_user']) . ";\n";
            }
        }

        return $instructions;
    }

    private function getGrantTables($def_tables): array
    {
        // Tables from PowerDNS
        $grantTables = array('supermasters', 'domains', 'domainmetadata', 'cryptokeys', 'records');

        // Include PowerAdmin tables
        foreach ($def_tables as $table) {
            $grantTables[] = $table['table_name'];
        }

        return $grantTables;
    }

    private function getGrantSequences($def_tables): array
    {
        // For PostgreSQL you need to grant access to sequences
        $grantSequences = array('domains_id_seq', 'domainmetadata_id_seq', 'cryptokeys_id_seq', 'records_id_seq');
        foreach ($def_tables as $table) {
            // ignore tables without primary key
            if ($table['table_name'] == 'migrations') {
                continue;
            }
            if ($table['table_name'] == 'records_zone_templ') {
                continue;
            }
            $grantSequences[] = $table['table_name'] . '_id_seq';
        }

        return $grantSequences;
    }
}
