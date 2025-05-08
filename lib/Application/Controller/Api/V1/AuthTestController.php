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

namespace Poweradmin\Application\Controller\Api\v1;

use Poweradmin\Application\Controller\Api\ApiBaseController;
use Poweradmin\Domain\Model\UserEntity;
use Poweradmin\Domain\Model\UserManager;

/**
 * API controller for testing authentication methods
 *
 * @package Poweradmin\Application\Controller\Api\v1
 */
class AuthTestController extends ApiBaseController
{
    /**
     * Run the controller
     */
    public function run(): void
    {
        // Check if user is authenticated
        if (!isset($_SESSION['userid'])) {
            $this->returnErrorResponse('Authentication required', 401, 'auth_required');
            return;
        }

        // Get user details
        $userId = $_SESSION['userid'] ?? 0;
        $authMethod = $_SESSION['auth_used'] ?? 'unknown';
        $username = UserEntity::getUserNameById($this->db, $userId);
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');

        // Return success response with authentication details
        $this->returnJsonResponse([
            'authenticated' => true,
            'user_id' => $userId,
            'username' => $username,
            'auth_method' => $authMethod,
            'is_admin' => $isAdmin,
            'permissions' => $this->getUserPermissions(),
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get a list of permissions for the current user
     *
     * @return array Array of permission names
     */
    private function getUserPermissions(): array
    {
        $permissions = UserManager::getPermissionsByTemplateId($this->db, 0, true);
        $userPermissions = [];

        foreach ($permissions as $permission) {
            if (UserManager::verifyPermission($this->db, $permission)) {
                $userPermissions[] = $permission;
            }
        }

        return $userPermissions;
    }
}
