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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Service\RedirectService;

class SamlCallbackController extends BaseController
{
    private SamlService $samlService;
    private AuthenticationService $authService;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        // Don't authenticate - this is the callback endpoint
        parent::__construct($request, false);

        $this->httpRequest = new Request();

        // Initialize SAML services
        $logHandler = LoggerHandlerFactory::create($this->config->getAll());
        $logLevel = $this->config->get('logging', 'level', 'info');
        $logger = new Logger($logHandler, $logLevel);

        $samlConfigService = new SamlConfigurationService($this->config, $logger);
        $userProvisioningService = new UserProvisioningService($this->db, $this->config, $logger);

        $this->samlService = new SamlService(
            $this->config,
            $samlConfigService,
            $userProvisioningService,
            $logger,
            $this->httpRequest
        );

        // Initialize authentication service
        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
    }

    public function run(): void
    {
        // Check if SAML is enabled
        if (!$this->samlService->isEnabled()) {
            $sessionEntity = new SessionEntity(_('SAML authentication is not enabled'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        // Determine the SAML operation based on the route
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($requestUri, '/saml/acs') !== false) {
            // Handle SAML Assertion Consumer Service (ACS)
            $this->handleAssertion();
        } elseif (strpos($requestUri, '/saml/sls') !== false) {
            // Handle SAML Single Logout Service (SLS)
            $this->handleSingleLogout();
        } else {
            // Unknown SAML endpoint
            $sessionEntity = new SessionEntity(_('Unknown SAML endpoint'), 'danger');
            $this->authService->auth($sessionEntity);
        }
    }

    private function handleAssertion(): void
    {
        try {
            // Process the SAML assertion
            $this->samlService->handleAssertion();
        } catch (\Exception $e) {
            $sessionEntity = new SessionEntity(
                _('SAML authentication failed: ') . $e->getMessage(),
                'danger'
            );
            $this->authService->auth($sessionEntity);
        }
    }

    private function handleSingleLogout(): void
    {
        try {
            // Process SAML Single Logout
            $this->samlService->handleSingleLogout();

            // Clear the session and redirect to login
            $sessionEntity = new SessionEntity(_('You have been logged out'), 'info');
            $this->authService->logout($sessionEntity);
        } catch (\Exception $e) {
            // Even if SLO fails, we should still log the user out locally
            $sessionEntity = new SessionEntity(
                _('Logout completed (with warnings): ') . $e->getMessage(),
                'warning'
            );
            $this->authService->logout($sessionEntity);
        }
    }
}
