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

use PDO;
use Exception;

class DatabasePermissionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if the current database user has necessary permissions for migrations
     */
    public function hasCreateAndAlterPermissions(): bool
    {
        $db_type = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($db_type === 'sqlite') {
            return true;
        }

        try {
            switch ($db_type) {
                case 'mysql':
                    return $this->checkMysqlPermissions();
                case 'pgsql':
                    return $this->checkPostgresqlPermissions();
                default:
                    return false;
            }
        } catch (Exception $e) {
            // If we can't check permissions, assume we have them for now
            // This avoids problems with restricted MySQL users that can't execute SHOW GRANTS
            return true;
        }
    }

    private function checkMysqlPermissions(): bool
    {
        $query = $this->db->query("SHOW GRANTS FOR CURRENT_USER");
        $grants = $query->fetchAll();

        // Check for necessary permissions in MySQL grants
        foreach ($grants as $grant) {
            $grantStr = implode('', $grant);
            // Look for ALL PRIVILEGES or specific CREATE/ALTER privileges
            if (
                strpos($grantStr, 'ALL PRIVILEGES') !== false ||
                (strpos($grantStr, 'CREATE') !== false && strpos($grantStr, 'ALTER') !== false)
            ) {
                return true;
            }
        }
        return false;
    }

    private function checkPostgresqlPermissions(): bool
    {
        $query = $this->db->query("SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS can_create");
        $result = $query->fetch();
        return isset($result['can_create']) && $result['can_create'] === 't';
    }
}
