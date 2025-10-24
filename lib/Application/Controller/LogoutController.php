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

use Exception;
use Poweradmin\Application\Service\OidcConfigurationService;
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
        // Check if user was authenticated via external auth
        $authMethod = $_SESSION['auth_method_used'] ?? null;
        $oidcProviderId = $_SESSION['oidc_provider'] ?? null;
        $samlProviderId = $_SESSION['saml_provider'] ?? null;

        if ($authMethod === 'oidc' && $oidcProviderId) {
            $this->performOidcLogout($oidcProviderId);
        } elseif ($authMethod === 'saml' && $samlProviderId) {
            $this->performSamlLogout($samlProviderId);
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
                // Build logout URL with provider-specific parameters
                $logoutUrl = $providerConfig['logout_url'];
                $returnUrl = $this->getBaseUrl() . '/login';

                // Determine parameter name based on provider
                $paramName = $this->getLogoutParameterName($logoutUrl, $providerConfig);

                // Build logout URL
                $separator = strpos($logoutUrl, '?') !== false ? '&' : '?';
                $logoutUrl .= $separator . $paramName . '=' . urlencode($returnUrl);

                // Add client_id if required by provider
                if ($this->requiresClientIdInLogout($providerConfig)) {
                    $logoutUrl .= '&client_id=' . urlencode($providerConfig['client_id']);
                }

                // Clear local session first
                $this->clearSession();

                // Redirect to OIDC provider logout
                header('Location: ' . $logoutUrl);
                exit;
            }
        } catch (Exception $e) {
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

    private function performSamlLogout(string $providerId): void
    {
        try {
            // Initialize SAML service for logout
            $logHandler = LoggerHandlerFactory::create($this->config->getAll());
            $logLevel = $this->config->get('logging', 'level', 'info');
            $logger = new Logger($logHandler, $logLevel);

            $samlConfigService = new SamlConfigurationService($this->config, $logger);
            $userProvisioningService = new UserProvisioningService($this->db, $this->config, $logger);
            $samlService = new SamlService(
                $this->config,
                $samlConfigService,
                $userProvisioningService,
                $logger,
                $this->db
            );

            // Initiate SAML Single Logout
            $logoutUrl = $samlService->initiateSingleLogout($providerId);

            if ($logoutUrl) {
                // Mark session for clearing after SLO callback
                $_SESSION['saml_slo_pending'] = true;

                // Clear user data but preserve SAML state for SLO callback
                $this->clearUserSession();

                // Redirect to IdP logout
                header('Location: ' . $logoutUrl);
                exit;
            } else {
                // Fallback to standard logout if SAML logout fails
                $this->performStandardLogout();
            }
        } catch (Exception $e) {
            // Log error and fallback to standard logout
            error_log('SAML logout error: ' . $e->getMessage());
            $this->performStandardLogout();
        }
    }

    private function getLogoutParameterName(string $logoutUrl, array $config): string
    {
        // Auth0 uses 'returnTo', others use 'redirect_uri'
        if (strpos($logoutUrl, 'auth0.com') !== false) {
            return 'returnTo';
        }

        // Azure AD uses 'post_logout_redirect_uri'
        if (strpos($logoutUrl, 'microsoftonline.com') !== false) {
            return 'post_logout_redirect_uri';
        }

        // Default OIDC standard
        return 'redirect_uri';
    }

    private function requiresClientIdInLogout(array $config): bool
    {
        // Some providers require client_id in logout requests
        $provider = strtolower($config['name'] ?? '');
        return in_array($provider, ['keycloak', 'authentik']);
    }

    private function clearSession(): void
    {
        // Clear OIDC-specific session data
        unset(
            $_SESSION['oidc_authenticated'],
            $_SESSION['oidc_provider'],
            $_SESSION['oidc_state']
        );

        // Clear SAML-specific session data
        unset(
            $_SESSION['saml_authenticated'],
            $_SESSION['saml_provider'],
            $_SESSION['saml_session_index']
        );

        // Clear LDAP session cache
        unset(
            $_SESSION['ldap_auth_timestamp'],
            $_SESSION['ldap_auth_ip'],
            $_SESSION['ldap_auth_username']
        );

        // Clear general external auth data
        unset($_SESSION['auth_method_used']);

        // Clear standard session data
        session_destroy();
    }

    private function clearUserSession(): void
    {
        // Clear OIDC-specific session data
        unset(
            $_SESSION['oidc_authenticated'],
            $_SESSION['oidc_provider'],
            $_SESSION['oidc_state']
        );

        // Clear user session data but preserve SAML state for SLO callback
        unset(
            $_SESSION['saml_authenticated'],
            $_SESSION['saml_session_index']
        );
        // Keep $_SESSION['saml_provider'] for SLO callback

        // Clear LDAP session cache
        unset(
            $_SESSION['ldap_auth_timestamp'],
            $_SESSION['ldap_auth_ip'],
            $_SESSION['ldap_auth_username']
        );

        // Clear general external auth data
        unset($_SESSION['auth_method_used']);

        // Clear user-specific data but keep session alive
        unset(
            $_SESSION['userid'],
            $_SESSION['userlogin'],
            $_SESSION['userfullname'],
            $_SESSION['userpasswd'],
            $_SESSION['useremail'],
            $_SESSION['usertype'],
            $_SESSION['userlevel']
        );
    }

    private function getBaseUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePrefix = $this->config->get('interface', 'base_url_prefix', '');

        return $scheme . '://' . $host . $basePrefix;
    }
}
