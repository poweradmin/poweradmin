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

use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\RedirectService;

class AuthenticationService
{
    private SessionService $sessionService;
    private RedirectService $redirectService;
    private ConfigurationManager $config;

    public function __construct(SessionService $sessionService, RedirectService $redirectService)
    {
        $this->sessionService = $sessionService;
        $this->redirectService = $redirectService;
        $this->config = ConfigurationManager::getInstance();
    }

    public function logout(SessionEntity $sessionEntity): void
    {
        $this->sessionService->endSession();
        $this->sessionService->setSessionData($sessionEntity);
        $this->redirectToLogin();
    }

    public function auth(SessionEntity $sessionEntity): void
    {
        $this->sessionService->startSession($sessionEntity);
        $this->redirectToLogin();
    }

    private function redirectToLogin(): void
    {
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . '/login');
    }

    public function redirectToIndex(): void
    {
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . '/');
    }
}
