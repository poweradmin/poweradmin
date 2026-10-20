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

namespace Poweradmin\Application\Controller\Auth;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;

/**
 * "Continue as" on the login page: lifts the signed-out mark set at logout so the
 * next page signs the web server's user in again.
 */
class RemoteUserLoginController extends BaseController
{
    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        parent::__construct($request, false, $environment);
    }

    /**
     * The form posts the login page's token, as the session holds no other after a logout.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Another site must not be able to undo a logout
        $token = $this->httpRequest->getPostParam('_token');
        if (!is_string($token) || !(new CsrfTokenService($this->session()))->validateToken($token, SessionKeys::LOGIN_TOKEN)) {
            $this->redirect('/login');
            return;
        }

        $this->session()->remove(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT);
        $this->redirect('/');
    }
}
