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

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Database credential mapping service
 *
 * Handles mapping of database configuration to PDO connection credentials
 * following DDD principles by encapsulating domain logic for database credentials
 */
class DatabaseCredentialMapper
{
    /**
     * Maps database configuration to PDO connection credentials
     *
     * @param ConfigurationInterface $config Configuration manager instance
     * @return array<string, mixed> Mapped credentials array
     */
    public static function mapCredentials(ConfigurationInterface $config): array
    {
        $dbConfig = $config->getGroup('database');

        return [
            'db_host' => $dbConfig['host'] ?? '',
            'db_port' => $dbConfig['port'] ?? '',
            'db_user' => $dbConfig['user'] ?? '',
            'db_pass' => $dbConfig['password'] ?? '',
            'db_name' => $dbConfig['name'] ?? '',
            'db_charset' => $dbConfig['charset'] ?? '',
            'db_collation' => $dbConfig['collation'] ?? '',
            'db_type' => $dbConfig['type'] ?? '',
            'db_file' => $dbConfig['file'] ?? '',
            'db_debug' => $dbConfig['debug'] ?? false,
        ];
    }
}
