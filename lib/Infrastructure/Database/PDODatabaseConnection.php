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

class PDODatabaseConnection implements DatabaseConnection
{
    public function connect(array $credentials): PDOCommon
    {
        $this->validateDatabaseType($credentials['db_type']);

        if ($credentials['db_type'] == 'sqlite') {
            $this->validateSQLiteCredentials($credentials);
        } else {
            $this->validateCredentialsForNonSQLite($credentials);
        }

        $dsn = $this->constructDSN($credentials);
        $options = $this->buildDriverOptions($credentials);

        try {
            $pdo = new PDOCommon($dsn, $credentials['db_user'], $credentials['db_pass'], $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if (isset($credentials['db_debug']) && $credentials['db_debug']) {
                $pdo->setDebug(true);
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection error: " . $e->getMessage());
        }
    }

    private function validateDatabaseType($db_type): void
    {
        if (empty($db_type)) {
            $this->showErrorAndExit('No database type has been set. Please check your configuration file.');
        }

        if (!in_array($db_type, ['mysql', 'mysqli', 'pgsql', 'sqlite'])) {
            $this->showErrorAndExit('Unknown database type: "' . $db_type . '". Supported types are: mysql, mysqli, pgsql, and sqlite.');
        }
    }

    private function validateCredentialsForNonSQLite($credentials): void
    {
        foreach (['db_user', 'db_pass', 'db_host', 'db_name'] as $key) {
            if (empty($credentials[$key])) {
                $this->showErrorAndExit("No $key has been set.");
            }
        }
    }

    private function validateSQLiteCredentials($credentials): void
    {
        if (empty($credentials['db_file'])) {
            $this->showErrorAndExit('No database file has been set.');
        }
    }

    private function constructDSN($credentials): string
    {
        $db_type = $credentials['db_type'];
        $db_port = empty($credentials['db_port']) ? $this->getDefaultPort($db_type) : $credentials['db_port'];

        if ($db_type === 'sqlite') {
            return "$db_type:{$credentials['db_file']}";
        }

        $dsn = "$db_type:host={$credentials['db_host']};port=$db_port;dbname={$credentials['db_name']}";

        if (in_array($db_type, ['mysql', 'mysqli']) && $credentials['db_charset'] === 'utf8') {
            $dsn .= ';charset=utf8';
        }

        // PostgreSQL SSL configuration via DSN parameters
        if ($db_type === 'pgsql') {
            $dsn .= $this->buildPostgreSQLSslDsn($credentials);
        }

        return $dsn;
    }

    /**
     * Build PostgreSQL SSL DSN parameters.
     *
     * PostgreSQL uses sslmode parameter in DSN:
     * - disable: No SSL
     * - allow: Try non-SSL first, then SSL
     * - prefer: Try SSL first, then non-SSL (default)
     * - require: Require SSL (no cert verification)
     * - verify-ca: Require SSL + verify CA
     * - verify-full: Require SSL + verify CA + verify hostname
     *
     * @param array $credentials Database credentials including SSL settings
     * @return string DSN SSL parameters
     */
    private function buildPostgreSQLSslDsn(array $credentials): string
    {
        $sslEnabled = !empty($credentials['db_ssl']);
        $sslVerify = !empty($credentials['db_ssl_verify']);

        if (!$sslEnabled) {
            // SSL not explicitly enabled - use 'prefer' for backwards compatibility
            // This allows connections to work with or without SSL
            return ';sslmode=prefer';
        }

        // SSL is enabled
        if ($sslVerify) {
            // Full verification: verify CA and hostname
            $sslMode = 'verify-full';
        } else {
            // SSL required but no certificate verification
            $sslMode = 'require';
        }

        $dsnParts = ";sslmode=$sslMode";

        // Add certificate paths if provided
        if (!empty($credentials['db_ssl_ca'])) {
            $dsnParts .= ";sslrootcert={$credentials['db_ssl_ca']}";
        }

        if (!empty($credentials['db_ssl_cert'])) {
            $dsnParts .= ";sslcert={$credentials['db_ssl_cert']}";
        }

        if (!empty($credentials['db_ssl_key'])) {
            $dsnParts .= ";sslkey={$credentials['db_ssl_key']}";
        }

        return $dsnParts;
    }

    private function showErrorAndExit($message): void
    {
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

    /**
     * Build PDO driver options for the connection.
     *
     * For MySQL/MariaDB: Configures SSL/TLS settings based on credentials.
     * By default, SSL certificate verification is disabled for backwards
     * compatibility with servers that don't use SSL or use self-signed certificates.
     *
     * SSL behavior:
     * - ssl=false (default): Disables SSL cert verification for compatibility
     * - ssl=true, ssl_verify=false: Enables SSL but skips certificate verification
     * - ssl=true, ssl_verify=true: Enables SSL with full certificate verification
     * - ssl_ca/ssl_key/ssl_cert: Optional paths for certificate-based authentication
     *
     * @param array $credentials Database credentials including SSL settings
     * @return array PDO driver options
     */
    private function buildDriverOptions(array $credentials): array
    {
        $options = [];
        $db_type = $credentials['db_type'];

        if (!in_array($db_type, ['mysql', 'mysqli'])) {
            return $options;
        }

        $sslEnabled = !empty($credentials['db_ssl']);
        $sslVerify = !empty($credentials['db_ssl_verify']);

        if ($sslEnabled) {
            // SSL is explicitly enabled
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $sslVerify;

            // Set CA certificate if provided
            if (!empty($credentials['db_ssl_ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $credentials['db_ssl_ca'];
            }

            // Set client certificate if provided (for mutual TLS)
            if (!empty($credentials['db_ssl_cert'])) {
                $options[PDO::MYSQL_ATTR_SSL_CERT] = $credentials['db_ssl_cert'];
            }

            // Set client key if provided (for mutual TLS)
            if (!empty($credentials['db_ssl_key'])) {
                $options[PDO::MYSQL_ATTR_SSL_KEY] = $credentials['db_ssl_key'];
            }
        } else {
            // SSL not explicitly enabled - disable verification for backwards compatibility.
            // Newer versions of MariaDB Connector/C enforce SSL verification by default,
            // which breaks connections to servers without SSL or with self-signed certificates.
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }
}
