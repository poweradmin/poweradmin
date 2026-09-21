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
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Handles the SAML assertion (/saml/acs) and single logout (/saml/sls) endpoints.
 */
class SamlCallbackController extends BaseController
{
    private SamlService $samlService;
    private AuthenticationService $authService;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        // Don't authenticate - this is the callback endpoint
        parent::__construct($request, false, $environment);

        // Initialize SAML services
        $samlConfigService = $this->services()->samlConfigurationService();
        $userProvisioningService = $this->services()->userProvisioningService();

        $this->samlService = new SamlService(
            $this->config,
            $samlConfigService,
            $userProvisioningService,
            $this->logger,
            $this->services()->authenticationService(),
            $this->services()->auditService(),
            $this->services()->mfaService(),
            $this->httpRequest
        );

        $this->authService = $this->services()->authenticationService();
    }

    /**
     * The identity provider posts here directly; the signed SAML response, not a
     * form token, is what authenticates the request.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
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
            $redirectPath = $this->samlService->handleAssertion();
            if ($redirectPath !== null) {
                $this->redirect($redirectPath);
                return;
            }

            // Log successful SAML login if session was established
            if (isset($_SESSION[SessionKeys::USERID])) {
                $this->services()->auditService()->logSsoLoginSuccess(AuthMethod::SAML);
            }
        } catch (\Exception $e) {
            // operation:login_error (not login_failed) - SAML assertion-handling
            // failure should not feed fail2ban brute-force counters.
            $this->services()->auditService()->logSsoLoginError(AuthMethod::SAML, $e->getMessage());

            $sessionEntity = new SessionEntity(
                _('SAML authentication failed: ') . $e->getMessage(),
                'danger'
            );
            $this->authService->auth($sessionEntity);
        }
    }

    private function handleSingleLogout(): void
    {
        // Captured first: a successful LogoutResponse clears the session during processing
        $username = $this->getUserContextService()->getLoggedInUsername();

        try {
            // Process SAML Single Logout
            $this->samlService->handleSingleLogout();

            $this->services()->auditService()->logSamlLogout($username);

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
