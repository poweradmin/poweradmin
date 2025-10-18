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
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Application\Service\OidcService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Service\RedirectService;

class OidcLoginController extends BaseController
{
    private OidcService $oidcService;
    private AuthenticationService $authService;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        // Don't authenticate - this is a login endpoint
        parent::__construct($request, false);

        $this->httpRequest = new Request();

        // Initialize OIDC services
        $logHandler = LoggerHandlerFactory::create($this->config->getAll());
        $logLevel = $this->config->get('logging', 'level', 'info');
        $logger = new Logger($logHandler, $logLevel);

        $oidcConfigService = new OidcConfigurationService($this->config, $logger);
        $oidcProvisioningService = new UserProvisioningService($this->db, $this->config, $logger);

        $this->oidcService = new OidcService(
            $this->config,
            $oidcConfigService,
            $oidcProvisioningService,
            $logger,
            $this->db,
            $this->httpRequest
        );

        // Initialize authentication service
        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
    }

    public function run(): void
    {
        // Check if OIDC is enabled
        if (!$this->oidcService->isEnabled()) {
            $sessionEntity = new SessionEntity(_('OIDC authentication is not enabled'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        // Get provider parameter or use default
        $providerId = $this->httpRequest->getQueryParam('provider');

        // If no provider specified, get the first available provider
        if (empty($providerId)) {
            $availableProviders = $this->oidcService->getAvailableProviders();
            if (empty($availableProviders)) {
                $sessionEntity = new SessionEntity(_('No OIDC providers are configured'), 'danger');
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
            $sessionEntity = new SessionEntity(
                _('Failed to initiate OIDC authentication: ') . $e->getMessage(),
                'danger'
            );
            $this->authService->auth($sessionEntity);
        }
    }
}
