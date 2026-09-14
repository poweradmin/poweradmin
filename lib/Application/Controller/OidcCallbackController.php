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
use Poweradmin\Domain\Service\SessionKeys;

class OidcCallbackController extends BaseController
{
    private OidcService $oidcService;
    private AuthenticationService $authService;
    private Request $httpRequest;

    public function __construct(array $request)
    {
        // Don't authenticate - this is the callback endpoint
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

    /**
     * The identity provider posts here directly; the OIDC state parameter, not a
     * form token, is what ties the response to this session.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Check if OIDC is enabled
        if (!$this->oidcService->isEnabled()) {
            $sessionEntity = new SessionEntity(_('OIDC authentication is not enabled'), 'danger');
            $this->authService->auth($sessionEntity);
            return;
        }

        // Handle error responses from provider (POST body when response_mode=form_post)
        $error = $this->httpRequest->getParam('error');
        if (!empty($error)) {
            $errorDescription = $this->httpRequest->getParam('error_description', 'Unknown error');

            // operation:login_error (not login_failed) - IdP-side handshake error
            // should not feed fail2ban brute-force counters.
            $this->createAuditService()->logSsoLoginError('oidc', $error);

            $sessionEntity = new SessionEntity(
                _('Authentication failed: ') . $errorDescription,
                'danger'
            );
            $this->authService->auth($sessionEntity);
            return;
        }

        // Process the OIDC callback
        $this->oidcService->handleCallback();

        // Log successful OIDC login if session was established
        if (isset($_SESSION[SessionKeys::USERID])) {
            $this->createAuditService()->logSsoLoginSuccess('oidc');
        }
    }
}
