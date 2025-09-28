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

use Poweradmin\Application\Presenter\LocalePresenter;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LocaleService;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Logger\LoggerHandlerFactory;
use Poweradmin\Infrastructure\Utility\LanguageCode;

class LoginController extends BaseController
{
    private LocaleService $localeService;
    private LocalePresenter $localePresenter;
    private CsrfTokenService $csrfTokenService;
    private SamlService $samlService;

    public function __construct(array $request)
    {
        // Only authenticate on POST requests (when form is submitted)
        $authenticate = $_SERVER['REQUEST_METHOD'] === 'POST';
        parent::__construct($request, $authenticate);

        $this->localeService = new LocaleService();
        $this->localePresenter = new LocalePresenter();
        $this->csrfTokenService = new CsrfTokenService();

        // Initialize external auth services
        $logHandler = LoggerHandlerFactory::create($this->config->getAll());
        $logLevel = $this->config->get('logging', 'level', 'info');
        $logger = new Logger($logHandler, $logLevel);

        $samlConfigService = new SamlConfigurationService($this->config, $logger);
        $userProvisioningService = new UserProvisioningService($this->db, $this->config, $logger);

        $this->samlService = new SamlService(
            $this->config,
            $samlConfigService,
            $userProvisioningService,
            $logger
        );
    }

    public function run(): void
    {
        // If user is already authenticated, redirect to index
        if (isset($_SESSION['userid'])) {
            $this->redirect('/');
            return;
        }


        $localesData = $this->getLocalesData();
        $preparedLocales = $this->localeService->prepareLocales($localesData, $this->config->get('interface', 'language', 'en_EN'));

        list($msg, $type) = $this->getSessionMessages();

        if (file_exists('install')) {
            $this->render('empty.html', []);
        } else {
            $this->renderLogin($preparedLocales, $msg, $type);
        }
    }

    private function getSessionMessages(): array
    {
        $msg = $_SESSION['message'] ?? '';
        $type = $_SESSION['type'] ?? '';
        unset($_SESSION['message'], $_SESSION['type']);
        return [$msg, $type];
    }

    private function renderLogin(array $preparedLocales, string $msg, string $type): void
    {
        $locales = explode(',', $this->config->get('interface', 'enabled_languages', 'en_EN') ?? 'en_EN');
        $showLanguageSelector = count($locales) > 1;

        $loginToken = $this->csrfTokenService->generateToken();
        $_SESSION['login_token'] = $loginToken;

        // Get available external auth providers
        $oidcProviders = [];
        $oidcEnabled = false;

        // Check OIDC status but don't initialize the service here
        // OIDC authentication is handled by dedicated OidcLoginController
        $oidcEnabled = $this->config->get('oidc', 'enabled', false);
        if ($oidcEnabled) {
            // For template display purposes, get provider info from config
            $providersConfig = $this->config->get('oidc', 'providers', []);
            foreach ($providersConfig as $id => $config) {
                // Default to enabled if flag not set, or if provider has required credentials
                $isEnabled = !isset($config['enabled']) || $config['enabled'];
                $hasCredentials = !empty($config['client_id']) && !empty($config['client_secret']);

                if ($isEnabled && $hasCredentials) {
                    $oidcProviders[$id] = [
                        'id' => $id,
                        'display_name' => $config['display_name'] ?? ucfirst($id)
                    ];
                }
            }
        }

        $samlProviders = [];
        $samlEnabled = false;
        if ($this->samlService->isEnabled()) {
            $samlEnabled = true;
            $samlProviders = $this->samlService->getAvailableProviders();
        }

        $this->render('login.html', [
            'login_token' => $loginToken,
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'locale_options' => $this->localePresenter->generateLocaleOptions($preparedLocales),
            'show_language_selector' => $showLanguageSelector,
            'msg' => $msg,
            'type' => $type,
            'recaptcha_enabled' => $this->config->get('security', 'recaptcha.enabled', false),
            'recaptcha_site_key' => $this->config->get('security', 'recaptcha.site_key', ''),
            'recaptcha_version' => $this->config->get('security', 'recaptcha.version', 'v2'),
            'password_reset_enabled' => $this->config->get('security', 'password_reset.enabled', false),
            'oidc_enabled' => $oidcEnabled,
            'oidc_providers' => $oidcProviders,
            'saml_enabled' => $samlEnabled,
            'saml_providers' => $samlProviders,
        ]);
    }

    private function getLocalesData(): array
    {
        $enabledLanguages = $this->config->get('interface', 'enabled_languages', 'en_EN') ?? 'en_EN';
        $locales = explode(',', $enabledLanguages);
        $localesData = [];
        foreach ($locales as $locale) {
            $localesData[$locale] = LanguageCode::getByLocale($locale);
        }
        asort($localesData);

        return $localesData;
    }


    private function validateSamlProvider(string $provider): bool
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
