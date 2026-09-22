<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Base for session-authenticated internal API endpoints: requires a login and the X-CSRF-Token header on writes.
 */
abstract class InternalApiController extends AbstractApiController
{
    /**
     * InternalApiController constructor
     *
     * @param array $requestParams The request parameters
     */
    public function __construct(array $requestParams)
    {
        // Call parent constructor with authentication enabled
        // This will use session-based authentication
        parent::__construct($requestParams, true);

        // Additional validation for internal API
        $this->validateAuthentication();
        $this->validateCsrfHeader();
    }

    /**
     * Internal API calls ride the browser session, so a state-changing verb needs a
     * token like any other form post. It travels in a header because these endpoints
     * take JSON bodies rather than form fields.
     */
    protected function validateCsrfHeader(): void
    {
        if (in_array(strtoupper($this->request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (!$this->config->get('security', 'global_token_validation', true)) {
            return;
        }

        $token = (string) $this->request->headers->get('X-CSRF-Token', '');
        if (!(new CsrfTokenService($this->session()))->validateToken($token)) {
            $response = $this->returnApiError('Invalid CSRF token', 403);
            $this->sendAndHalt($response);
        }
    }

    /**
     * Validate that the user is authenticated using session
     */
    protected function validateAuthentication(): void
    {
        if (!$this->session()->has(SessionKeys::USERID)) {
            $response = $this->returnErrorResponse('Unauthorized access', 401);
            $this->sendAndHalt($response);
        }
    }

    /**
     * Validate that the user has the required permission
     *
     * @param string $permission The permission to check
     */
    protected function validatePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            $response = $this->returnErrorResponse('Forbidden: insufficient permissions', 403);
            $this->sendAndHalt($response);
        }
    }
}
