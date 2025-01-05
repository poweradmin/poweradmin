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

namespace Poweradmin\Infrastructure\Database;

use Exception;
use PDO;
use PDOException;
use Poweradmin\Domain\Service\DatabaseConnection;

class PDODatabaseConnection implements DatabaseConnection {
    public function connect(array $credentials): PDOLayer
    {
        $this->validateDatabaseType($credentials['db_type']);

        if ($credentials['db_type'] == 'sqlite') {
            $this->validateSQLiteCredentials($credentials);
        } else {
            $this->validateCredentialsForNonSQLite($credentials);
        }

        $dsn = $this->constructDSN($credentials);

        try {
            $pdo = new PDOLayer($dsn, $credentials['db_user'], $credentials['db_pass'], []);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if (isset($credentials['db_debug']) && $credentials['db_debug']) {
                $pdo->setOption('debug', 1);
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection error: " . $e->getMessage());
        }
    }

    private function validateDatabaseType($db_type): void {
        if (!in_array($db_type, ['mysql', 'mysqli', 'pgsql', 'sqlite'])) {
            $this->showErrorAndExit('No or unknown database type has been set.');
        }
    }

    private function validateCredentialsForNonSQLite($credentials): void {
        foreach (['db_user', 'db_pass', 'db_host', 'db_name'] as $key) {
            if (empty($credentials[$key])) {
                $this->showErrorAndExit("No $key has been set.");
            }
        }
    }

    private function validateSQLiteCredentials($credentials): void {
        if (empty($credentials['db_file'])) {
            $this->showErrorAndExit('No database file has been set.');
        }
    }

    private function constructDSN($credentials): string {
        $db_type = $credentials['db_type'];
        $db_port = empty($credentials['db_port']) ? $this->getDefaultPort($db_type) : $credentials['db_port'];

        if ($db_type === 'sqlite') {
            return "$db_type:{$credentials['db_file']}";
        } else {
            $dsn = "$db_type:host={$credentials['db_host']};port=$db_port;dbname={$credentials['db_name']}";

            if ($db_type === 'mysql' && $credentials['db_charset'] === 'utf8') {
                $dsn .= ';charset=utf8';
            }

            return $dsn;
        }
    }

    private function showErrorAndExit($message): void {
        // Implement error handling and exit logic
        throw new Exception(_($message));
    }

    private function getDefaultPort($db_type): ?int
    {
        return match ($db_type) {
            'mysql', 'mysqli' => 3306,
            'pgsql' => 5432,
            default => null,
        };
    }
}
