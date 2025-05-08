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

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\Domain\Model\UserManager;

/**
 * API controller for testing API key validation
 *
 * @package Poweradmin\Application\Controller\Api
 */
class KeyValidationController extends ApiBaseController
{
    /**
     * Run the controller
     */
    public function run(): void
    {
        if (!$this->config->get('api', 'api_keys_enabled', false)) {
            $this->returnErrorResponse('API keys are disabled in system settings.', 403, 'api_keys_disabled');
            return;
        }

        // Check if user is authenticated
        if (!isset($_SESSION['userid'])) {
            $this->returnErrorResponse('Authentication required', 401, 'auth_required');
            return;
        }

        // Return success response
        $this->returnJsonResponse([
            'valid' => true,
            'user_id' => $_SESSION['userid'],
            'auth_method' => $_SESSION['auth_used'] ?? 'unknown',
            'is_admin' => UserManager::verifyPermission($this->db, 'user_is_ueberuser')
        ]);
    }
}
