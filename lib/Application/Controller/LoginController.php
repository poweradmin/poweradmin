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

use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;

class LoginController extends BaseController
{
    private CsrfTokenService $csrfTokenService;
    private SamlService $samlService;

    public function __construct(array $request)
    {
        // Only authenticate on POST requests (when form is submitted)
        $authenticate = $_SERVER['REQUEST_METHOD'] === 'POST';
        parent::__construct($request, $authenticate);

        $this->csrfTokenService = new CsrfTokenService();

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
            $this->db
        );
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
        $loginToken = $this->csrfTokenService->generateToken();
        $_SESSION[SessionKeys::LOGIN_TOKEN] = $loginToken;

        $oidcEnabled = $this->config->get('oidc', 'enabled', false);
        $oidcProviders = $oidcEnabled ? $this->buildOidcProviders() : [];

        $samlEnabled = $this->samlService->isEnabled();
        $samlProviders = $samlEnabled ? $this->samlService->getAvailableProviders() : [];

        $this->render('login.html', [
            'login_token' => $loginToken,
            'msg' => $msg,
            'type' => $type,
            'recaptcha_enabled' => $this->config->get('security', 'recaptcha.enabled', false),
            'recaptcha_site_key' => $this->config->get('security', 'recaptcha.site_key', ''),
            'recaptcha_version' => $this->config->get('security', 'recaptcha.version', 'v2'),
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
