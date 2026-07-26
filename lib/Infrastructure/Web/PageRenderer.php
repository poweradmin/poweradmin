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

namespace Poweradmin\Infrastructure\Web;

use Closure;
use Poweradmin\AppManager;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\ApiStatusService;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\LocaleResolver;
use Poweradmin\Application\Service\PdnsVersionService;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\UserAvatarService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\ThemePathResolver;
use Poweradmin\Infrastructure\Utility\LanguageCode;
use Poweradmin\Infrastructure\Service\StyleManager;
use Poweradmin\Module\ModuleRegistry;
use Poweradmin\Version;

/**
 * Renders the shared page chrome (header, footer, Twig globals) around
 * controller-rendered templates.
 *
 * The permission and debug-query lookups arrive as closures so they stay
 * deferred until a template actually needs them, and so this class never
 * triggers authentication or database work on construction.
 */
class PageRenderer
{
    private AppManager $app;
    private ConfigurationManager $config;
    private CsrfTokenService $csrfTokenService;
    private UserContextService $userContextService;
    private Closure $hasPermission;
    private Closure $getDebugQueries;

    private bool $twigEnvironmentReady = false;
    private ?string $activeLocale = null;
    private ?array $languageVars = null;

    public function __construct(
        AppManager $app,
        ConfigurationManager $config,
        CsrfTokenService $csrfTokenService,
        UserContextService $userContextService,
        Closure $hasPermission,
        Closure $getDebugQueries
    ) {
        $this->app = $app;
        $this->config = $config;
        $this->csrfTokenService = $csrfTokenService;
        $this->userContextService = $userContextService;
        $this->hasPermission = $hasPermission;
        $this->getDebugQueries = $getDebugQueries;
    }

    private function hasPermission(string $permission): bool
    {
        return ($this->hasPermission)($permission);
    }

    /**
     * Registers Twig extensions and globals that need controller-owned
     * services. Called on the first render of the request (Twig locks
     * extensions and new globals after that), so API requests that never
     * render a template skip the cost entirely.
     */
    private function setupTwigEnvironment(): void
    {
        if ($this->twigEnvironmentReady) {
            return;
        }
        $this->twigEnvironmentReady = true;

        // The closure defers the permission lookup until a template calls can()
        $this->app->addTwigExtension(new PermissionTwigExtension($this->hasPermission));

        // Always-on context shared by header, page, and footer templates
        $this->csrfTokenService->ensureTokenExists();
        $this->app->addTwigGlobal('csrf_token', $this->csrfTokenService->getToken());
        $this->app->addTwigGlobal('base_url_prefix', $this->config->get('interface', 'base_url_prefix', ''));
        $pdnsInfo = PdnsVersionService::getCachedInfo();
        $this->app->addTwigGlobal('pdns_caps', PdnsCapabilities::fromVersion($pdnsInfo['version'] ?? null));
        $this->app->addTwigGlobal('pdns_server_info', $pdnsInfo);
        $this->app->addTwigGlobal('user_logged_in', $this->userContextService->isAuthenticated());
        $this->app->addTwigGlobal('file_version', $this->getAssetVersion());
    }

    /**
     * Opaque cache-busting token for static asset URLs: stable within a
     * release so browsers can cache, changes on upgrade, and does not
     * reveal the release number to unauthenticated visitors.
     */
    private function getAssetVersion(): string
    {
        $key = (string)$this->config->get('security', 'session_key', '');
        if ($key === '' || in_array($key, ['p0w3r4dm1n', 'change_this_key'], true)) {
            // Placeholder keys are publicly known, so mix in a per-install
            // value to keep the token unpredictable from the release alone
            $configFile = getenv('PA_CONFIG_PATH') ?: dirname(__DIR__, 3) . '/config/settings.php';
            $key .= (string)@filemtime($configFile);
        }
        return substr(hash_hmac('sha256', Version::VERSION, $key), 0, 12);
    }

    /**
     * Renders the header of the page.
     *
     * @param array $requestData The controller's request data (current page, form state)
     * @param string $pageTitle Page title, or '' to fall back to the interface title
     * @param array|null $systemMessages System messages to be displayed
     */
    public function renderHeader(array $requestData, string $pageTitle, ?array $systemMessages = null, ?array $scriptMessages = null): void
    {
        $this->setupTwigEnvironment();

        if (!headers_sent()) {
            header('Content-type: text/html; charset=utf-8');
        }

        $style = $this->config->get('interface', 'style', 'light');
        // The resolved theme (not the raw config value) keeps asset URLs in
        // sync with the templates AppManager actually serves after fallback
        $themeBasePath = $this->app->getThemeBasePath();
        $theme = $this->app->getThemeName();
        $styleManager = new StyleManager($style, $themeBasePath, $theme);

        // Resolve against the app root for on-disk asset checks (the resolved
        // value stays relative for the template URL below).
        $fsThemeBasePath = ThemePathResolver::toFilesystemPath($themeBasePath);

        // Check for custom theme stylesheets
        $customLightExists = file_exists($fsThemeBasePath . '/' . $theme . '/style/custom_light.css');
        $customDarkExists = file_exists($fsThemeBasePath . '/' . $theme . '/style/custom_dark.css');
        $customThemeExists = file_exists($fsThemeBasePath . '/' . $theme . '/style/custom_' . $styleManager->getSelectedStyle() . '.css');

        $activeLocale = $this->resolveActiveLocale();

        $vars = array_merge([
            'iface_title' => $this->config->get('interface', 'title'),
            'iface_style' => $styleManager->getSelectedStyle(),
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
            'base_url_prefix' => $this->config->get('interface', 'base_url_prefix', ''),
            'custom_header' => file_exists($fsThemeBasePath . '/' . $theme . '/custom/header.html'),
            'custom_light_exists' => $customLightExists,
            'custom_dark_exists' => $customDarkExists,
            'custom_theme_exists' => $customThemeExists,
            'install_error' => file_exists('install') ? _('The install/ directory exists, you must remove it first before proceeding.') : false,
            'version' => Version::VERSION,
            'show_style_switcher' => true,
            // Defined on every render (incl. unauthenticated) so strict_variables holds
            'api_error' => false,
        ], LanguageCode::templateVars($activeLocale));

        $vars = array_merge($vars, $this->languageVars());

        $dblog_use = $this->config->get('logging', 'database_enabled');
        $session_key = $this->config->get('security', 'session_key');

        if ($this->userContextService->isAuthenticated()) {
            $perm_is_godlike = $this->hasPermission('user_is_ueberuser');

            $vars = array_merge($vars, [
                'user_name' => $this->userContextService->getDisplayName(),
                'user_username' => $this->userContextService->getLoggedInUsername(),
                'perm_search' => $this->hasPermission('search'),
                'perm_view_zone_own' => $this->hasPermission('zone_content_view_own'),
                'perm_view_zone_other' => $this->hasPermission('zone_content_view_others'),
                'perm_supermaster_view' => $this->hasPermission('supermaster_view'),
                'perm_zone_master_add' => $this->hasPermission('zone_master_add'),
                'perm_zone_slave_add' => $this->hasPermission('zone_slave_add'),
                'perm_zone_templ_add' => $this->hasPermission('zone_templ_add'),
                'perm_zone_templ_edit' => $this->hasPermission('zone_templ_edit'),
                'perm_supermaster_add' => $this->hasPermission('supermaster_add'),
                'perm_is_godlike' => $perm_is_godlike,
                'perm_templ_perm_edit' => $this->hasPermission('templ_perm_edit'),
                'perm_templ_perm_add' => $this->hasPermission('templ_perm_add'),
                'perm_add_new' => $this->hasPermission('user_add_new'),
                'perm_view_others' => $this->hasPermission('user_view_others'),
                'perm_edit_own' => $this->hasPermission('user_edit_own'),
                'perm_edit_others' => $this->hasPermission('user_edit_others'),
                'perm_api_manage_keys' => $this->hasPermission('api_manage_keys'),
                'perm_view_zone_logs_own' => $this->hasPermission('zone_logs_view_own'),
                'perm_view_zone_logs_others' => $this->hasPermission('zone_logs_view_others'),
                'perm_user_logs_view' => $this->hasPermission('user_logs_view'),
                'perm_group_logs_view' => $this->hasPermission('group_logs_view'),
                'session_key_error' => $perm_is_godlike && in_array($session_key, ['p0w3r4dm1n', 'change_this_key'], true) ? _('Default session encryption key is used, please set it in your configuration file.') : false,
                'auth_used' => $this->userContextService->getAuthMethod() !== "ldap",  // Legacy variable for backward compatibility
                'auth_method' => $this->userContextService->getAuthMethod() ?? 'internal',
                'can_change_password' => !in_array($this->userContextService->getAuthMethod(), ['ldap', 'oidc', 'saml']),
                'session_userid' => $this->userContextService->getLoggedInUserId() ?? 0,
                'user_avatar_url' => $this->getUserAvatarUrl(),
                'request' => $requestData,
                'dblog_use' => $dblog_use,
                'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
                'api_enabled' => $this->config->get('api', 'enabled', false),
                'mfa_enabled' => $this->config->get('security', 'mfa.enabled', false),
                'enable_consistency_checks' => $this->config->get('interface', 'enable_consistency_checks', false),
                'api_docs_enabled' => $this->config->get('api', 'docs_enabled', false),
                'module_nav_items' => $this->getModuleNavItems(),
                'show_user_access_templates' => $this->config->get('permissions', 'show_user_access_templates', true),
                'show_group_access_templates' => $this->config->get('permissions', 'show_group_access_templates', true),
            ]);

            // Surface PowerDNS API errors on every page, not just the dashboard.
            if ($perm_is_godlike && DnsBackendProviderFactory::isApiBackend($this->config)) {
                $vars['api_error'] = (new ApiStatusService())->getLastError();
            }
        }

        if ($systemMessages) {
            $vars['system_messages'] = $systemMessages;
        }
        if ($scriptMessages) {
            $vars['script_messages'] = $scriptMessages;
        }

        // Add the current page and page title to the header variables
        $currentPage = $requestData['page'] ?? 'index';
        $vars['current_page'] = $currentPage;
        $vars['page_title'] = $pageTitle !== '' ? $pageTitle : $vars['iface_title'];

        $this->app->render('header.html', $vars);
    }

    /**
     * Renders the footer of the page.
     */
    public function renderFooter(): void
    {
        $style = $this->config->get('interface', 'style', 'light');
        $themeBasePath = $this->app->getThemeBasePath();
        $theme = $this->app->getThemeName();
        $styleManager = new StyleManager($style, $themeBasePath, $theme);
        $selected_style = $styleManager->getSelectedStyle();
        $fsThemeBasePath = ThemePathResolver::toFilesystemPath($themeBasePath);

        $display_stats = $this->config->get('misc', 'display_stats');
        $db_debug = $this->config->get('database', 'debug');

        $this->app->render('footer.html', [
            'version' => $this->userContextService->isAuthenticated() ? Version::VERSION : false,
            'custom_footer' => file_exists($fsThemeBasePath . '/' . $theme . '/custom/footer.html'),
            'display_stats' => $display_stats ? $this->app->displayStats() : false,
            'db_queries' => $db_debug ? ($this->getDebugQueries)() : false,
            'show_style_switcher' => in_array($selected_style, ['light', 'dark']),
            'iface_style' => $selected_style,
            'theme' => $theme,
            'theme_base_path' => $themeBasePath,
            'base_url_prefix' => $this->config->get('interface', 'base_url_prefix', ''),
            'is_rtl' => LanguageCode::isRtl($this->resolveActiveLocale()),
        ]);
    }

    /**
     * Language selector variables for the current request, built once and
     * shared by the header dropdown, the page body (login form's hidden
     * userlang field), and any later render call in the same request.
     *
     * @return array<string, mixed>
     */
    public function languageVars(): array
    {
        return $this->languageVars ??= $this->getLanguageSelectorVars($this->resolveActiveLocale());
    }

    /**
     * Build the login-page language selector variables: the active locale,
     * the dropdown options, and a visibility flag. Shared by the header
     * dropdown and the login form's hidden userlang field so the chosen
     * language survives form submission.
     *
     * @return array<string, mixed>
     */
    public function getLanguageSelectorVars(string $activeLocale): array
    {
        $vars = ['current_language' => $activeLocale];

        $enabledLanguages = $this->config->get('interface', 'enabled_languages', 'en_EN') ?? 'en_EN';
        $localeList = array_map('trim', explode(',', $enabledLanguages));
        if (count($localeList) > 1) {
            $preparedLocales = [];
            foreach ($localeList as $locale) {
                $preparedLocales[] = [
                    'locale' => $locale,
                    'language' => LanguageCode::getByLocale($locale),
                    'selected' => $locale === $activeLocale,
                ];
            }
            usort($preparedLocales, fn($a, $b) => strcmp($a['language'], $b['language']));
            $vars['locales'] = $preparedLocales;
            $vars['show_language_selector'] = true;
        }

        return $vars;
    }

    /**
     * Returns the locale active for the current request (GET override > session > config).
     * Memoized: the inputs are stable within a request and this runs on every
     * header and footer render.
     */
    public function resolveActiveLocale(): string
    {
        // Request is constructed here, not in the constructor: it snapshots
        // the superglobals, which must happen at first resolve time
        return $this->activeLocale ??= (new LocaleResolver(
            $this->config,
            $this->userContextService,
            new Request()
        ))->resolve();
    }

    /**
     * Gets navigation items from enabled modules.
     *
     * @return array<array<string, string>>
     */
    private function getModuleNavItems(): array
    {
        $registry = new ModuleRegistry($this->config);
        $registry->loadModules();

        $isAdmin = $this->hasPermission('user_is_ueberuser');
        $items = $registry->getNavItems($isAdmin);

        return array_values(array_filter($items, function (array $item): bool {
            if (!empty($item['permission'])) {
                return $this->hasPermission($item['permission']);
            }
            return true;
        }));
    }

    /**
     * Gets the user's avatar URL if avatar functionality is enabled
     *
     * @return string|null The avatar URL or null if not available/enabled
     */
    private function getUserAvatarUrl(): ?string
    {
        $userAvatarService = new UserAvatarService($this->userContextService, $this->config);
        return $userAvatarService->getCurrentUserAvatarUrl();
    }
}
