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
use Poweradmin\Application\Service\OidcService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Web\FlashMessage;
use Poweradmin\Application\Service\Auth\AuthenticationService;

/**
 * Starts the OIDC login flow by redirecting to the chosen or first configured provider.
 */
class OidcLoginController extends BaseController
{
    private OidcService $oidcService;
    private AuthenticationService $authService;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        // Don't authenticate - this is a login endpoint
        parent::__construct($request, false, $environment);

        // Initialize OIDC services
        $oidcConfigService = $this->services()->oidcConfigurationService();
        $oidcProvisioningService = $this->services()->userProvisioningService();

        $this->oidcService = new OidcService(
            $this->config,
            $oidcConfigService,
            $oidcProvisioningService,
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
        // Check if OIDC is enabled
        if (!$this->oidcService->isEnabled()) {
            $sessionEntity = new FlashMessage(_('OIDC authentication is not enabled'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        // Get provider parameter or use default
        $providerId = $this->httpRequest->getQueryParam('provider');

        // If no provider specified, get the first available provider
        if (empty($providerId)) {
            $availableProviders = $this->oidcService->getAvailableProviders();
            if (empty($availableProviders)) {
                $sessionEntity = new FlashMessage(_('No OIDC providers are configured'), 'danger');
                $this->authService->auth($sessionEntity);
                return;
            }

            // Use the first available provider
            $providerId = array_key_first($availableProviders);
        }

        try {
            // Initiate the OIDC authentication flow
            $authUrl = $this->oidcService->initiateAuthFlow($providerId);

            // Redirect to OIDC provider
            header('Location: ' . $authUrl);
            exit;
        } catch (\Exception $e) {
            $sessionEntity = new FlashMessage(
                _('Failed to initiate OIDC authentication: ') . $e->getMessage(),
                'danger'
            );
            $this->authService->auth($sessionEntity);
        }
    }
}
