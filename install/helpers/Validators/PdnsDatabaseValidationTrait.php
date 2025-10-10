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

namespace PoweradminInstall\Validators;

use PDO;
use PDOException;

/**
 * Trait for validating PowerDNS database configuration
 *
 * @method string buildDsn(array $input) Builds a DSN string from input data (provided by DatabaseValidationTrait)
 */
trait PdnsDatabaseValidationTrait
{
    /**
     * Validate PowerDNS database/schema exists and has required tables
     *
     * @param array $data Form data containing database settings
     * @return array Array of validation errors
     */
    private function validatePdnsDatabase(array $data): array
    {
        $errors = [];

        // Skip validation if pdns_db_name is empty
        if (empty($data['pdns_db_name'])) {
            return $errors;
        }

        // Only MySQL supports separate PowerDNS databases
        if (isset($data['db_type']) && $data['db_type'] !== 'mysql') {
            $errors['pdns_db_name'] = _('Separate PowerDNS databases are only supported with MySQL. For PostgreSQL and SQLite, please use the same database.');
            return $errors;
        }

        // Use the same connection credentials but different database name
        $pdnsCredentials = [
            'db_host' => $data['db_host'],
            'db_port' => $data['db_port'] ?? ($data['db_type'] === 'mysql' ? '3306' : '5432'),
            'db_user' => $data['db_user'],
            'db_pass' => $data['db_pass'],
            'db_name' => $data['pdns_db_name'],
            'db_type' => $data['db_type'],
            'db_charset' => $data['db_charset'] ?? '',
        ];

        // Test PowerDNS database connection using the existing buildDsn method
        try {
            $dsn = $this->buildDsn($pdnsCredentials);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            $pdnsDb = new PDO($dsn, $pdnsCredentials['db_user'], $pdnsCredentials['db_pass'], $options);

            // Check for required PowerDNS tables
            $missingTables = $this->checkRequiredPdnsTables($pdnsDb, $pdnsCredentials['db_type']);
            if (!empty($missingTables)) {
                $errors['pdns_db_name'] = sprintf(
                    _('Missing PowerDNS tables in database "%s": %s. Please ensure PowerDNS is properly installed in this database.'),
                    $pdnsCredentials['db_name'],
                    implode(', ', $missingTables)
                );
            }
        } catch (PDOException $e) {
            $errors['pdns_db_name'] = sprintf(
                _('Cannot connect to PowerDNS database "%s": %s'),
                $data['pdns_db_name'],
                $e->getMessage()
            );
        }

        return $errors;
    }

    /**
     * Check if required PowerDNS tables exist in the database
     *
     * @param PDO $db Database connection
     * @param string $dbType Database type
     * @return array Array of missing table names
     */
    private function checkRequiredPdnsTables(PDO $db, string $dbType): array
    {
        // Core PowerDNS tables that must exist
        $requiredTables = ['domains', 'records', 'supermasters', 'domainmetadata'];
        $missingTables = [];

        foreach ($requiredTables as $table) {
            if (!$this->pdnsTableExists($db, $table, $dbType)) {
                $missingTables[] = $table;
            }
        }

        return $missingTables;
    }

    /**
     * Check if a PowerDNS table exists in the database
     *
     * @param PDO $db Database connection
     * @param string $tableName Table name to check
     * @param string $dbType Database type
     * @return bool True if table exists
     */
    private function pdnsTableExists(PDO $db, string $tableName, string $dbType): bool
    {
        try {
            if ($dbType === 'mysql') {
                $query = "SHOW TABLES LIKE :table";
            } elseif ($dbType === 'pgsql') {
                $query = "SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = :table
                )";
            } else {
                return false;
            }

            $stmt = $db->prepare($query);
            $stmt->execute([':table' => $tableName]);

            if ($dbType === 'mysql') {
                return $stmt->rowCount() > 0;
            } else {
                return $stmt->fetchColumn();
            }
        } catch (PDOException $e) {
            // If we can't check, assume table doesn't exist
            return false;
        }
    }
}
