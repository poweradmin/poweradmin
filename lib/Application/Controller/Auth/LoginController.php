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
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Auth\SamlService;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Renders the login page with its CSRF token and the enabled OIDC and SAML providers.
 */
class LoginController extends BaseController
{
    private ?CsrfTokenService $csrfTokenService = null;
    private ?SamlService $samlService = null;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        // Only authenticate on POST requests (when form is submitted)
        $authenticate = $_SERVER['REQUEST_METHOD'] === 'POST';
        parent::__construct($request, $authenticate, $environment);
    }

    private function csrfTokenService(): CsrfTokenService
    {
        return $this->csrfTokenService ??= new CsrfTokenService();
    }

    private function samlService(): SamlService
    {
        return $this->samlService ??= new SamlService(
            $this->config,
            $this->services()->samlConfigurationService(),
            $this->services()->userProvisioningService(),
            $this->logger,
            $this->services()->authenticationService(),
            $this->services()->auditService(),
            $this->services()->mfaService()
        );
    }

    /**
     * The login form sends `_token` carrying the login token, which
     * SessionAuthenticator validates against its own session key.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        if (isset($_SESSION[SessionKeys::USERID])) {
            $this->redirect('/');
            return;
        }

        [$msg, $type] = $this->getSessionMessages();

        if (file_exists('install')) {
            $this->render('empty.html', []);
            return;
        }

        $this->renderLogin($msg, $type);
    }

    private function getSessionMessages(): array
    {
        $msg = $_SESSION[SessionKeys::LOGIN_MESSAGE] ?? '';
        $type = $_SESSION[SessionKeys::LOGIN_MESSAGE_TYPE] ?? '';
        unset($_SESSION[SessionKeys::LOGIN_MESSAGE], $_SESSION[SessionKeys::LOGIN_MESSAGE_TYPE]);
        return [$msg, $type];
    }

    private function renderLogin(string $msg, string $type): void
    {
        $loginToken = $this->csrfTokenService()->generateToken();
        $_SESSION[SessionKeys::LOGIN_TOKEN] = $loginToken;

        $oidcEnabled = $this->config->get('oidc', 'enabled', false);
        $oidcProviders = $oidcEnabled ? $this->buildOidcProviders() : [];

        $samlEnabled = $this->samlService()->isEnabled();
        $samlProviders = $samlEnabled ? $this->samlService()->getAvailableProviders() : [];

        $this->render('login.html', [
            'login_token' => $loginToken,
            // Kept for 4.4.0 theme forks whose login form still posts it
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'msg' => $msg,
            'type' => $type,
            'recaptcha_enabled' => $this->config->get('security', 'recaptcha.enabled', false),
            'recaptcha_site_key' => $this->config->get('security', 'recaptcha.site_key', ''),
            'recaptcha_version' => $this->config->get('security', 'recaptcha.version', 'v3'),
            'password_reset_enabled' => $this->config->get('security', 'password_reset.enabled', false),
            'username_recovery_enabled' => $this->config->get('security', 'username_recovery.enabled', false),
            'oidc_enabled' => $oidcEnabled,
            'oidc_providers' => $oidcProviders,
            'saml_enabled' => $samlEnabled,
            'saml_providers' => $samlProviders,
        ]);
    }

    private function buildOidcProviders(): array
    {
        $providers = [];
        foreach ($this->config->get('oidc', 'providers', []) as $id => $config) {
            $isEnabled = !isset($config['enabled']) || $config['enabled'];
            $hasCredentials = !empty($config['client_id']) && !empty($config['client_secret']);

            if ($isEnabled && $hasCredentials) {
                $providers[$id] = [
                    'id' => $id,
                    'display_name' => $config['display_name'] ?? ucfirst($id),
                ];
            }
        }
        return $providers;
    }
}
