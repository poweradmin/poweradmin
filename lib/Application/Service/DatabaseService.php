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

namespace Poweradmin\Application\Service;

use Exception;
use Poweradmin\Domain\Service\DatabaseConnection;
use Poweradmin\Infrastructure\Database\PDOCommon;
use RuntimeException;

class DatabaseService
{
    private DatabaseConnection $databaseConnection;

    public function __construct(DatabaseConnection $databaseConnection)
    {
        $this->databaseConnection = $databaseConnection;
    }

    public function connect(array $credentials): PDOCommon
    {
        try {
            return $this->databaseConnection->connect($credentials);
        } catch (Exception $e) {
            $errorMsg = "Database connection failed: " . $e->getMessage();

            // Provide more helpful error messages for configuration issues
            if (empty($credentials['db_type'])) {
                $errorMsg .= " Check that your config/settings.php file has the correct database configuration.";
            }

            throw new RuntimeException($errorMsg);
        }
    }
}
