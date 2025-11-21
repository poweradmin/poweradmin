<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Domain\Service\DatabaseSchemaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DatabaseHelper
{
    private PDOCommon $db;
    private DatabaseSchemaService $schemaService;
    private array $databaseCredentials;
    private const REQUIRED_PDNS_TABLES = ['domains', 'records', 'supermasters', 'domainmetadata', 'comments'];

    public function __construct(PDOCommon $db, array $databaseCredentials)
    {
        $this->db = $db;
        $this->schemaService = new DatabaseSchemaService($db);
        $this->databaseCredentials = $databaseCredentials;
    }

    /**
     * Check if required PowerDNS tables exist in the database
     *
     * @return array Missing PowerDNS tables
     */
    public function checkPowerDnsTables(): array
    {
        $missingTables = [];
        $existingTables = $this->schemaService->listTables();

        foreach (self::REQUIRED_PDNS_TABLES as $table) {
            if (!in_array($table, $existingTables)) {
                $missingTables[] = $table;
            }
        }

        return $missingTables;
    }

    public function updateDatabase(): void
    {
        // For SQLite, verify file permissions before proceeding
        if (isset($this->databaseCredentials['db_type']) && $this->databaseCredentials['db_type'] === 'sqlite') {
            $this->verifySQLiteAccess();
        }

        $current_tables = $this->schemaService->listTables();
        $def_tables = DatabaseStructureHelper::getDefaultTables();

        // Disable foreign key checks for the duration of this operation
        $dbType = $this->databaseCredentials['db_type'];
        try {
            if ($dbType === 'mysql') {
                $this->db->exec('SET foreign_key_checks = 0');
            } elseif ($dbType === 'pgsql') {
                // PostgreSQL doesn't have a direct equivalent to SET foreign_key_checks
                // We'll handle this differently if needed
            } elseif ($dbType === 'sqlite') {
                $this->db->exec('PRAGMA foreign_keys = OFF');
            }
        } catch (\Exception $e) {
            // If we can't disable foreign key checks, we'll continue anyway
            // and handle any errors that occur
        }

        try {
            foreach ($def_tables as $table) {
                if (in_array($table['table_name'], $current_tables)) {
                    $this->schemaService->dropTable($table['table_name']);
                }

                $options = $table['options'];

                if (isset($this->databaseCredentials['db_charset']) && $this->databaseCredentials['db_charset']) {
                    $options['charset'] = $this->databaseCredentials['db_charset'];
                }

                if (isset($this->databaseCredentials['db_collation']) && $this->databaseCredentials['db_collation']) {
                    $options['collation'] = $this->databaseCredentials['db_collation'];
                }
                $this->schemaService->createTable($table['table_name'], $table['fields'], $options);

                // Set default value for the 'type' column in user_mfa table
                if ($table['table_name'] === 'user_mfa') {
                    $dbType = $this->databaseCredentials['db_type'];

                    switch ($dbType) {
                        case 'mysql':
                            $this->db->exec("ALTER TABLE `user_mfa` ALTER COLUMN `type` SET DEFAULT 'app'");
                            break;
                        case 'pgsql':
                            $this->db->exec("ALTER TABLE user_mfa ALTER COLUMN type SET DEFAULT 'app'");
                            break;
                        case 'sqlite':
                            // SQLite doesn't support ALTER COLUMN with SET DEFAULT
                            // We'll need to ensure new rows have this value explicitly set
                            break;
                    }
                }
            }
        } finally {
            // Re-enable foreign key checks after we're done
            try {
                if ($dbType === 'mysql') {
                    $this->db->exec('SET foreign_key_checks = 1');
                } elseif ($dbType === 'sqlite') {
                    $this->db->exec('PRAGMA foreign_keys = ON');
                }
            } catch (\Exception $e) {
                // Ignore any errors when re-enabling foreign key checks
            }
        }

        $fill_perm_items = $this->db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        $def_permissions = PermissionHelper::getPermissionMappings();
        $this->schemaService->executeMultiple($fill_perm_items, $def_permissions);
    }

    /**
     * Verify that the SQLite database file is accessible and writable
     *
     * @throws \RuntimeException If there are any permission or access issues
     */
    private function verifySQLiteAccess(): void
    {
        $dbFile = isset($this->databaseCredentials['db_file']) ? $this->databaseCredentials['db_file'] : $this->databaseCredentials['db_name'];

        // Check if the database file exists
        if (!file_exists($dbFile)) {
            throw new \RuntimeException(sprintf(
                _('The SQLite database file %s does not exist. Please create the database file before proceeding.'),
                $dbFile
            ));
        }

        // Check if the file is readable
        if (!is_readable($dbFile)) {
            throw new \RuntimeException(sprintf(
                _('The SQLite database file %s is not readable. Please check file permissions.'),
                $dbFile
            ));
        }

        // Check if the file is writable
        if (!is_writable($dbFile)) {
            throw new \RuntimeException(sprintf(
                _('The SQLite database file %s is not writable. Please check file permissions.'),
                $dbFile
            ));
        }

        // Check if we can actually open the database file
        try {
            $testConn = new \PDO("sqlite:{$dbFile}");
            $testConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $testConn->exec('PRAGMA quick_check');
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf(
                _('Could not access the SQLite database file %s: %s'),
                $dbFile,
                $e->getMessage()
            ));
        }
    }

    public function createAdministratorUser($pa_pass): void
    {
        // Create permission templates
        $templates = [
            ['name' => 'Administrator', 'descr' => 'Administrator template with full rights.'],
            ['name' => 'Zone Manager', 'descr' => 'Full management of own zones including creation, editing, deletion, and templates.'],
            ['name' => 'Editor', 'descr' => 'Edit own zone records but cannot modify SOA and NS records.'],
            ['name' => 'Viewer', 'descr' => 'Read-only access to own zones with search capability.'],
            ['name' => 'Guest', 'descr' => 'Temporary access with no permissions. Suitable for users awaiting approval or limited access.']
        ];

        $templateIds = [];
        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
        foreach ($templates as $template) {
            $stmt->execute([':name' => $template['name'], ':descr' => $template['descr']]);
            $templateIds[$template['name']] = $this->db->lastInsertId();
        }

        // Get permission IDs for template assignments
        $permissionNames = [
            'user_is_ueberuser', 'zone_master_add', 'zone_slave_add', 'zone_content_view_own',
            'zone_content_edit_own', 'zone_meta_edit_own', 'search', 'user_edit_own',
            'zone_templ_add', 'zone_templ_edit', 'api_manage_keys', 'zone_delete_own',
            'zone_content_edit_own_as_client'
        ];

        $permissionIds = [];
        $stmt = $this->db->prepare("SELECT id, name FROM perm_items WHERE name IN (" . implode(',', array_fill(0, count($permissionNames), '?')) . ")");
        $stmt->execute($permissionNames);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissionIds[$row['name']] = $row['id'];
        }

        // Assign permissions to templates
        $templatePermissions = [
            'Administrator' => ['user_is_ueberuser'],
            'Zone Manager' => ['zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                               'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                               'api_manage_keys', 'zone_delete_own'],
            'Editor' => ['zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client'],
            'Viewer' => ['zone_content_view_own', 'search'],
            'Guest' => []
        ];

        $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:templ_id, :perm_id)");
        foreach ($templatePermissions as $templateName => $permissions) {
            foreach ($permissions as $permName) {
                if (isset($permissionIds[$permName])) {
                    $stmt->execute([
                        ':templ_id' => $templateIds[$templateName],
                        ':perm_id' => $permissionIds[$permName]
                    ]);
                }
            }
        }

        // Create admin user with Administrator template
        $config = ConfigurationManager::getInstance();
        $config->initialize();
        $userAuthService = new UserAuthenticationService(
            $config->get('security', 'password_encryption'),
            $config->get('security', 'password_cost')
        );
        $user_query = $this->db->prepare(
            "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) " .
            "VALUES ('admin', ?, 'Administrator', 'admin@example.net', 'Administrator with full rights.', ?, 1, 0, 'sql')"
        );
        $user_query->execute(array($userAuthService->hashPassword($pa_pass), $templateIds['Administrator']));

        // Create group-type permission templates for default groups
        $groupTemplates = [
            ['name' => 'Administrators', 'descr' => 'Full administrative access for group members.', 'template_type' => 'group'],
            ['name' => 'Zone Managers', 'descr' => 'Full zone management for group members.', 'template_type' => 'group'],
            ['name' => 'Editors', 'descr' => 'Edit zone records (no SOA/NS) for group members.', 'template_type' => 'group'],
            ['name' => 'Viewers', 'descr' => 'Read-only zone access for group members.', 'template_type' => 'group'],
            ['name' => 'Guests', 'descr' => 'Temporary group with no permissions. Suitable for users awaiting approval.', 'template_type' => 'group'],
        ];

        $groupTemplateIds = [];
        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr, template_type) VALUES (:name, :descr, :template_type)");
        foreach ($groupTemplates as $template) {
            $stmt->execute([':name' => $template['name'], ':descr' => $template['descr'], ':template_type' => $template['template_type']]);
            $groupTemplateIds[$template['name']] = $this->db->lastInsertId();
        }

        // Assign permissions to group templates (same as corresponding user templates)
        $groupTemplatePermissions = [
            'Administrators' => ['user_is_ueberuser'],
            'Zone Managers' => ['zone_master_add', 'zone_slave_add', 'zone_content_view_own', 'zone_content_edit_own',
                               'zone_meta_edit_own', 'search', 'user_edit_own', 'zone_templ_add', 'zone_templ_edit',
                               'api_manage_keys', 'zone_delete_own'],
            'Editors' => ['zone_content_view_own', 'search', 'user_edit_own', 'zone_content_edit_own_as_client'],
            'Viewers' => ['zone_content_view_own', 'search'],
            'Guests' => [],
        ];

        $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:templ_id, :perm_id)");
        foreach ($groupTemplatePermissions as $templateName => $permissions) {
            foreach ($permissions as $permName) {
                if (isset($permissionIds[$permName]) && isset($groupTemplateIds[$templateName])) {
                    $stmt->execute([
                        ':templ_id' => $groupTemplateIds[$templateName],
                        ':perm_id' => $permissionIds[$permName]
                    ]);
                }
            }
        }

        // Create default user groups using group-type templates
        $defaultGroups = [
            ['name' => 'Administrators', 'description' => 'Full administrative access to all system functions.'],
            ['name' => 'Zone Managers', 'description' => 'Full zone management including creation, editing, and deletion.'],
            ['name' => 'Editors', 'description' => 'Edit zone records but cannot modify SOA and NS records.'],
            ['name' => 'Viewers', 'description' => 'Read-only access to zones with search capability.'],
            ['name' => 'Guests', 'description' => 'Temporary group with no permissions. Suitable for users awaiting approval.'],
        ];

        $stmt = $this->db->prepare("INSERT INTO user_groups (name, description, perm_templ, created_by) VALUES (:name, :description, :perm_templ, NULL)");
        foreach ($defaultGroups as $group) {
            if (isset($groupTemplateIds[$group['name']])) {
                $stmt->execute([
                    ':name' => $group['name'],
                    ':description' => $group['description'],
                    ':perm_templ' => $groupTemplateIds[$group['name']]
                ]);
            }
        }
    }

    public function generateDatabaseUserInstructions(?string $pdns_db_name = null): array
    {
        $instructions = [];

        if ($this->databaseCredentials['db_type'] == 'mysql') {
            $db_hosts = ['%', $this->databaseCredentials['db_host']];

            $user = htmlspecialchars($this->databaseCredentials['pa_db_user']);
            $pass = htmlspecialchars($this->databaseCredentials['pa_db_pass']);
            $db = htmlspecialchars($this->databaseCredentials['db_name']);

            $db_hosts = array_unique(array_map('htmlspecialchars', $db_hosts));

            foreach ($db_hosts as $host) {
                $hostLabel = $host === '%' ? 'Any Host (%)' : "Specific Host ($host)";
                $commands = "CREATE USER '$user'@'$host' IDENTIFIED BY '$pass';\nGRANT SELECT, INSERT, UPDATE, DELETE ON $db.* TO '$user'@'$host';";

                // Add grants for PowerDNS database if defined and different from main database
                if (!empty($pdns_db_name) && $pdns_db_name !== $this->databaseCredentials['db_name']) {
                    $pdns_db = htmlspecialchars($pdns_db_name);
                    $commands .= "\nGRANT SELECT, INSERT, UPDATE, DELETE ON $pdns_db.* TO '$user'@'$host';";
                }

                $instructions[] = [
                    'title' => $hostLabel,
                    'commands' => $commands
                ];
            }
        } elseif ($this->databaseCredentials['db_type'] == 'pgsql') {
            $commands = "CREATE USER " . htmlspecialchars($this->databaseCredentials['pa_db_user']) . " WITH PASSWORD '" . htmlspecialchars($this->databaseCredentials['pa_db_pass']) . "';\n";

            $def_tables = DatabaseStructureHelper::getDefaultTables();
            $grantTables = $this->getGrantTables($def_tables);
            foreach ($grantTables as $tableName) {
                $commands .= "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . htmlspecialchars($this->databaseCredentials['pa_db_user']) . ";\n";
            }
            $grantSequences = $this->getGrantSequences($def_tables);
            foreach ($grantSequences as $sequenceName) {
                $commands .= "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . htmlspecialchars($this->databaseCredentials['pa_db_user']) . ";\n";
            }

            $instructions[] = [
                'title' => 'PostgreSQL Commands',
                'commands' => trim($commands)
            ];
        }

        return $instructions;
    }

    private function getGrantTables($def_tables): array
    {
        // Tables from PowerDNS
        $grantTables = array('supermasters', 'domains', 'domainmetadata', 'cryptokeys', 'records', 'comments', 'tsigkeys');

        // Include Poweradmin tables
        foreach ($def_tables as $table) {
            $grantTables[] = $table['table_name'];
        }

        return $grantTables;
    }

    private function getGrantSequences($def_tables): array
    {
        // For PostgreSQL you need to grant access to sequences
        $grantSequences = array('domains_id_seq', 'domainmetadata_id_seq', 'cryptokeys_id_seq', 'records_id_seq', 'comments_id_seq');
        foreach ($def_tables as $table) {
            // ignore tables without autoincrement id
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
