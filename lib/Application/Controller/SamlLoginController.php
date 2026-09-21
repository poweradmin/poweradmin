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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Application\Service\Auth\AuthenticationService;

/**
 * Starts the SAML login flow by redirecting to the chosen or first configured identity provider.
 */
class SamlLoginController extends BaseController
{
    private SamlService $samlService;
    private AuthenticationService $authService;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        // Don't authenticate - this is a login endpoint
        parent::__construct($request, false, $environment);

        // Initialize SAML services
        $samlConfigService = new SamlConfigurationService($this->config, $this->logger);
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

    public function run(): void
    {
        // Check if SAML is enabled
        if (!$this->samlService->isEnabled()) {
            $sessionEntity = new SessionEntity(_('SAML authentication is not enabled'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        // Get provider parameter or use default
        $providerId = $this->httpRequest->getQueryParam('provider');

        // If no provider specified, get the first available provider
        if (empty($providerId)) {
            $availableProviders = $this->samlService->getAvailableProviders();
            if (empty($availableProviders)) {
                $sessionEntity = new SessionEntity(_('No SAML providers are configured'), 'danger');
                $this->authService->auth($sessionEntity);
                return;
            }

            // Use the first available provider
            $providerId = array_key_first($availableProviders);
        }

        // Validate provider
        if (!$this->validateProvider($providerId)) {
            $sessionEntity = new SessionEntity(_('Invalid SAML provider'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        try {
            // Initiate SAML authentication flow
            $authUrl = $this->samlService->initiateAuthFlow($providerId);

            // Redirect to SAML IdP
            header('Location: ' . $authUrl);
            exit;
        } catch (\Exception $e) {
            $sessionEntity = new SessionEntity(_('SAML authentication failed: ') . $e->getMessage(), 'danger');
            $this->authService->auth($sessionEntity);
        }
    }

    private function validateProvider(string $provider): bool
    {
        // Sanitize provider ID - only allow alphanumeric characters and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $provider)) {
            return false;
        }

        // Check if provider is actually available
        $availableProviders = $this->samlService->getAvailableProviders();
        return isset($availableProviders[$provider]);
    }
}
