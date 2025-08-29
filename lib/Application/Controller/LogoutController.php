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

use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\SessionService;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Service\RedirectService;

class LogoutController extends BaseController
{
    private AuthenticationService $authService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $sessionService = new SessionService();
        $redirectService = new RedirectService();
        $this->authService = new AuthenticationService($sessionService, $redirectService);
    }

    public function run(): void
    {
        // Check if user was authenticated via OIDC
        $oidcAuthenticated = $_SESSION['oidc_authenticated'] ?? false;
        $oidcProviderId = $_SESSION['oidc_provider'] ?? null;

        if ($oidcAuthenticated && $oidcProviderId) {
            $this->performOidcLogout($oidcProviderId);
        } else {
            $this->performStandardLogout();
        }
    }

    private function performOidcLogout(string $providerId): void
    {
        try {
            // Initialize OIDC configuration service
            $logHandler = LoggerHandlerFactory::create($this->config->getAll());
            $logLevel = $this->config->get('logging', 'level', 'info');
            $logger = new Logger($logHandler, $logLevel);
            $oidcConfigService = new OidcConfigurationService($this->config, $logger);

            // Get provider configuration
            $providerConfig = $oidcConfigService->getProviderConfig($providerId);

            if ($providerConfig && !empty($providerConfig['logout_url'])) {
                // Build logout URL with redirect parameter
                $logoutUrl = $providerConfig['logout_url'];
                $returnUrl = $this->getBaseUrl() . '/login';

                // Add return URL parameter if supported
                if (strpos($logoutUrl, '?') !== false) {
                    $logoutUrl .= '&redirect_uri=' . urlencode($returnUrl);
                } else {
                    $logoutUrl .= '?redirect_uri=' . urlencode($returnUrl);
                }

                // Clear local session first
                $this->clearSession();

                // Redirect to OIDC provider logout
                header('Location: ' . $logoutUrl);
                exit;
            }
        } catch (\Exception $e) {
            // Log error but continue with standard logout
            error_log('OIDC logout error: ' . $e->getMessage());
        }

        // Fallback to standard logout if OIDC logout fails
        $this->performStandardLogout();
    }

    private function performStandardLogout(): void
    {
        $sessionEntity = new SessionEntity(_('You have logged out.'), 'success');
        $this->authService->logout($sessionEntity);
    }

    private function clearSession(): void
    {
        // Clear OIDC-specific session data
        unset(
            $_SESSION['oidc_authenticated'],
            $_SESSION['oidc_provider'],
            $_SESSION['oidc_state']
        );

        // Clear standard session data
        session_destroy();
    }

    private function getBaseUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePrefix = $this->config->get('interface', 'base_url_prefix', '');

        return $scheme . '://' . $host . $basePrefix;
    }
}
