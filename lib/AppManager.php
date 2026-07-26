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

namespace Poweradmin;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\LocaleResolver;
use Poweradmin\Application\Service\StatsDisplayService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\TemplateCacheResolver;
use Poweradmin\Domain\Utility\MemoryUsage;
use Poweradmin\Domain\Utility\Timer;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;
use Poweradmin\Infrastructure\Configuration\ThemePathResolver;
use Poweradmin\Infrastructure\Utility\SimpleSizeFormatter;
use Poweradmin\Infrastructure\Web\BadgeTwigExtension;
use Poweradmin\Module\ModuleRegistry;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Translator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Extension\DebugExtension;
use Twig\Extension\ExtensionInterface;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Class AppManager
 *
 * Manages the application configuration, template rendering, and statistics display.
 */
class AppManager
{
    /** @var Environment $templateRenderer The Twig template renderer */
    protected Environment $templateRenderer;

    /** @var ConfigurationManager $configuration The application configuration */
    protected ConfigurationManager $configuration;

    /** @var StatsDisplayService|null $statsDisplayService The service for displaying statistics */
    protected ?StatsDisplayService $statsDisplayService = null;

    private LoggerInterface $logger;

    /** @var string $themeName The theme name after any fallback to 'default' */
    private string $themeName;

    /** @var string $themeBasePath The theme base path in relative/URL form, after any fallback */
    private string $themeBasePath;

    /** @var string $themePath The filesystem path of the resolved theme template directory */
    private string $themePath;

    /** @var string $interfaceLocale The locale the translator was built with */
    private string $interfaceLocale;

    /** @var list<string> $supportedLocales The enabled locales, trimmed */
    private array $supportedLocales;

    /**
     * AppManager constructor.
     * Initializes the template renderer, configuration, and optional statistics display service.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->configuration = ConfigurationManager::getInstance();
        $this->configuration->initialize();

        // Fail fast on broken configuration before any template setup work
        $this->assertDefaultsFileLoaded();
        $this->showValidationErrors(new ConfigValidator($this->configuration->getAll()));

        $registry = new ModuleRegistry($this->configuration);
        $registry->loadModules();

        $this->resolveTheme();
        $loader = $this->createTemplateLoader($registry);
        $this->templateRenderer = new Environment($loader, $this->buildTwigOptions());
        $this->statsDisplayService = $this->createStatsDisplayService();

        $this->setupTranslator($registry);

        $this->templateRenderer->addExtension(new BadgeTwigExtension());
        $this->templateRenderer->addExtension(new IntlExtension());

        if ($this->templateRenderer->isDebug()) {
            $this->templateRenderer->addExtension(new DebugExtension());
        }
    }

    /**
     * Exits with an error message if the bundled defaults file could not be
     * loaded.
     */
    private function assertDefaultsFileLoaded(): void
    {
        if ($this->configuration->isDefaultsFileLoaded()) {
            return;
        }

        $messageService = new MessageService();
        $messageService->displayDirectSystemError(sprintf(
            'Default settings file is missing or unreadable: %s. Please restore it from the Poweradmin distribution before continuing.',
            $this->configuration->getDefaultsFilePath()
        ));
    }

    /**
     * Resolves the configured theme to an existing template directory,
     * falling back to the default theme when the configured one is missing.
     * Sets the themeName, themeBasePath, and themePath properties.
     */
    private function resolveTheme(): void
    {
        $theme_base_path = $this->configuration->get('interface', 'theme_base_path', 'templates');
        $theme = $this->configuration->get('interface', 'theme', 'default');

        // Resolve against the app root for the on-disk existence check only; the
        // config value stays relative for use as a URL in templates.
        $fs_base_path = ThemePathResolver::toFilesystemPath($theme_base_path);
        $theme_path = $fs_base_path . '/' . $theme;

        if (!is_dir($theme_path)) {
            $this->logger->warning('Theme directory {path} does not exist. Falling back to default theme.', ['path' => $theme_path]);

            $removedThemes = ['spark', 'ignite', 'mobile'];
            if (in_array($theme, $removedThemes)) {
                $this->logger->warning('The {theme} theme was removed in Poweradmin 4.0. Please update your configuration to use theme: default.', ['theme' => $theme]);
            }

            $theme = 'default';
            $theme_path = $fs_base_path . '/default';

            // Custom base paths without a default theme fall back to the
            // bundled one so pages still render
            if (!is_dir($theme_path)) {
                $theme_base_path = 'templates';
                $theme_path = ThemePathResolver::toFilesystemPath('templates') . '/default';
            }

            if (!is_dir($theme_path)) {
                $messageService = new MessageService();
                $messageService->displayDirectSystemError(
                    "Critical error: Default theme directory '$theme_path' does not exist. " .
                    "Please ensure Poweradmin is properly installed."
                );
            }
        }

        $this->themeName = $theme;
        $this->themeBasePath = $theme_base_path;
        $this->themePath = $theme_path;
    }

    /**
     * Gets the theme name that templates are actually served from, which is
     * 'default' when the configured theme directory does not exist.
     *
     * @return string The resolved theme name
     */
    public function getThemeName(): string
    {
        return $this->themeName;
    }

    /**
     * Gets the theme base path matching the resolved theme, in the
     * relative/URL form used for asset paths in templates.
     *
     * @return string The resolved theme base path
     */
    public function getThemeBasePath(): string
    {
        return $this->themeBasePath;
    }

    /**
     * Creates the Twig template loader for the resolved theme, with default
     * theme fallbacks and module template namespaces. Requires resolveTheme()
     * to have run.
     *
     * @param ModuleRegistry $registry The registry of loaded modules
     * @return FilesystemLoader The configured template loader
     */
    private function createTemplateLoader(ModuleRegistry $registry): FilesystemLoader
    {
        // Look directly in the theme path for templates, not in subdirectories
        $loader = new FilesystemLoader([$this->themePath]);

        // Custom themes fall back to the default theme for templates they do
        // not provide (module templates rely on the shared _macros.html).
        // The bundled default is the last resort for custom theme base paths.
        $fallbacks = array_unique([
            ThemePathResolver::toFilesystemPath($this->themeBasePath) . '/default',
            ThemePathResolver::toFilesystemPath('templates') . '/default',
        ]);
        foreach ($fallbacks as $fallback) {
            if ($fallback !== $this->themePath && is_dir($fallback)) {
                $loader->addPath($fallback);
            }
        }

        // Register module template paths as Twig namespaces (@module_name/template.html)
        foreach ($registry->getEnabledModules() as $module) {
            $templatePath = $module->getTemplatePath();
            if (!empty($templatePath) && is_dir($templatePath)) {
                $loader->addPath($templatePath, $module->getName());
            }
        }

        return $loader;
    }

    /**
     * Creates the statistics display service when misc.display_stats is on.
     *
     * @return StatsDisplayService|null The service, or null when disabled
     */
    private function createStatsDisplayService(): ?StatsDisplayService
    {
        if (!$this->configuration->get('misc', 'display_stats', false)) {
            return null;
        }

        return new StatsDisplayService(new MemoryUsage(), new Timer(), new SimpleSizeFormatter());
    }

    /**
     * Resolves the interface language and registers the translator with the
     * template renderer, including module translations.
     *
     * @param ModuleRegistry $registry The registry of loaded modules
     */
    private function setupTranslator(ModuleRegistry $registry): void
    {
        $resolver = new LocaleResolver($this->configuration, new UserContextService(), new Request());
        $this->interfaceLocale = $resolver->resolve();
        $this->supportedLocales = $resolver->getSupportedLocales();
        $interfaceLang = $this->interfaceLocale;

        // ICU formatters (format_datetime etc.) read the default locale;
        // setlocale() in LocaleManager does not affect ICU
        \Locale::setDefault($interfaceLang);

        $translator = new Translator($interfaceLang);
        $translator->addLoader('po', new PoFileLoader());
        $translator->addResource('po', $this->getLocaleFile(), $interfaceLang);

        foreach ($registry->getEnabledModules() as $module) {
            $localePath = $module->getLocalePath();
            if (empty($localePath)) {
                continue;
            }

            $moduleLocaleFile = $localePath . '/' . $interfaceLang . '/messages.po';
            if (file_exists($moduleLocaleFile)) {
                $translator->addResource('po', $moduleLocaleFile, $interfaceLang);
            }
        }

        $this->templateRenderer->addExtension(new TranslationExtension($translator));
    }

    /**
     * Builds the Twig environment options, enabling the opt-in compiled
     * template cache when misc.template_cache is set. Cache failures degrade
     * to uncached rendering with a logged warning - never fatal.
     *
     * @return array The options for the Environment constructor
     */
    private function buildTwigOptions(): array
    {
        // Dev mode surfaces template typos and undefined params; production
        // keeps Twig's silent behavior so pages never break on missing vars.
        $displayErrors = (bool)$this->configuration->get('misc', 'display_errors', false);
        $options = ['debug' => $displayErrors, 'strict_variables' => $displayErrors];

        if (!$this->configuration->get('misc', 'template_cache', false)) {
            return $options;
        }

        $resolver = new TemplateCacheResolver($this->logger);
        $cachePath = $resolver->resolve((string)$this->configuration->get('misc', 'template_cache_path', ''));
        if ($cachePath !== null) {
            $options['cache'] = $cachePath;
            // Without this, debug=false would let upgrades serve stale compiled templates
            $options['auto_reload'] = true;
        }

        return $options;
    }

    /**
     * Registers an extension on the template renderer. Must be called before
     * the first render of the request - Twig locks extensions afterwards.
     *
     * @param ExtensionInterface $extension The Twig extension to register
     */
    public function addTwigExtension(ExtensionInterface $extension): void
    {
        $this->templateRenderer->addExtension($extension);
    }

    /**
     * Registers a global template variable. New names must be registered
     * before the first render of the request; re-registering an existing
     * name afterwards only updates its value.
     *
     * @param string $name The variable name available to all templates
     * @param mixed $value The value to expose
     */
    public function addTwigGlobal(string $name, mixed $value): void
    {
        $this->templateRenderer->addGlobal($name, $value);
    }

    /**
     * Renders a template with the given parameters.
     *
     * @param string $template The template file to render
     * @param array $params The parameters to pass to the template
     */
    public function render(string $template, array $params = []): void
    {
        try {
            echo $this->templateRenderer->render($template, $params);
        } catch (Error $e) {
            $this->logger->error('Template rendering failed: {error}', ['error' => $e->getMessage()]);
            $messageService = new MessageService();
            if ($this->templateRenderer->isDebug()) {
                // Twig messages carry the template name and line - essential in dev mode
                $messageService->displayDirectSystemError('Template rendering failed: ' . $e->getMessage());
            } else {
                $messageService->displayDirectSystemError('An error occurred while rendering the template. Please check the server logs for details.');
            }
        }
    }

    /**
     * Gets the locale file path for the resolved interface locale.
     *
     * @return string The path to the locale file
     */
    private function getLocaleFile(): string
    {
        if (in_array($this->interfaceLocale, $this->supportedLocales, true)) {
            return "locale/$this->interfaceLocale/LC_MESSAGES/messages.po";
        }
        return "locale/en_EN/LC_MESSAGES/messages.po";
    }

    /**
     * Gets the locale the translator was built with. Page chrome must use
     * this (not re-resolve) so rendered strings and lang metadata agree.
     *
     * @return string The resolved interface locale
     */
    public function getInterfaceLocale(): string
    {
        return $this->interfaceLocale;
    }

    /**
     * Gets the enabled locales as resolved for this request.
     *
     * @return list<string> The enabled locales, trimmed
     */
    public function getSupportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * Displays validation errors and exits if the configuration is invalid.
     *
     * @param ConfigValidator $validator The configuration validator
     */
    private function showValidationErrors(ConfigValidator $validator): void
    {
        if ($validator->validate()) {
            return;
        }

        // MessageService escapes the message itself, so pass plain text only
        $messageService = new MessageService();
        $messageService->displayDirectSystemError(
            'Invalid configuration: ' . implode('; ', $validator->getErrors())
        );
    }

    /**
     * Displays the application statistics.
     *
     * @return string The statistics display
     */
    public function displayStats(): string
    {
        if ($this->statsDisplayService !== null) {
            return $this->statsDisplayService->displayStats();
        }
        return '';
    }
}
