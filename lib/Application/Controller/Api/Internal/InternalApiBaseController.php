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

/**
 * Base controller for Internal API
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\ApiBaseController;
use Poweradmin\Domain\Model\UserManager;

abstract class InternalApiBaseController extends ApiBaseController
{
    /**
     * Constructor for InternalApiBaseController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        // Ensure user is authenticated for internal API
        $this->validateAuthentication();
    }

    /**
     * Validate that the user is authenticated
     */
    protected function validateAuthentication(): void
    {
        if (!isset($_SESSION["userid"])) {
            $this->returnErrorResponse('Unauthorized access', 401);
        }
    }

    /**
     * Check if the user has the required permission
     *
     * @param string $permission The permission to check
     * @return bool True if the user has the permission, false otherwise
     */
    protected function hasPermission(string $permission): bool
    {
        return UserManager::verifyPermission($this->db, $permission);
    }

    /**
     * Validate that the user has the required permission
     *
     * @param string $permission The permission to check
     */
    protected function validatePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            $this->returnErrorResponse('Forbidden: insufficient permissions', 403);
        }
    }
}
