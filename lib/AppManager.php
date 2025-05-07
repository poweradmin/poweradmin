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

namespace Poweradmin;

use Poweradmin\Application\Service\StatsDisplayService;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Utility\MemoryUsage;
use Poweradmin\Domain\Utility\Timer;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;
use Poweradmin\Infrastructure\Utility\SimpleSizeFormatter;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Error\Error;
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

    /**
     * AppManager constructor.
     * Initializes the template renderer, configuration, and optional statistics display service.
     */
    public function __construct()
    {
        $this->configuration = ConfigurationManager::getInstance();
        $this->configuration->initialize();

        $theme_base_path = $this->configuration->get('interface', 'theme_base_path', 'templates');
        $theme = $this->configuration->get('interface', 'theme', 'default');
        $theme_path = $theme_base_path . '/' . $theme;

        // Look directly in the theme path for templates, not in subdirectories
        $loader = new FilesystemLoader([$theme_path]);
        $this->templateRenderer = new Environment($loader, ['debug' => false]);

        if ($this->configuration->get('misc', 'display_stats', false)) {
            $memoryUsage = new MemoryUsage();
            $timer = new Timer();
            $sizeFormatter = new SimpleSizeFormatter();
            $this->statsDisplayService = new StatsDisplayService($memoryUsage, $timer, $sizeFormatter);
        }

        $validator = new ConfigValidator($this->configuration->getAll());
        $this->showValidationErrors($validator);

        $iface_lang = $this->configuration->get('interface', 'language', 'en_EN');
        if (isset($_SESSION["userlang"])) {
            $iface_lang = $_SESSION["userlang"];
        }

        $translator = new Translator($iface_lang);
        $translator->addLoader('po', new PoFileLoader());
        $translator->addResource('po', $this->getLocaleFile($iface_lang), $iface_lang);

        $this->templateRenderer->addExtension(new TranslationExtension($translator));
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
            error_log($e->getMessage());
            $messageService = new MessageService();
            $messageService->displayDirectSystemError('An error occurred while rendering the template: ' . $e->getMessage());
        }
    }

    /**
     * Gets the application configuration.
     *
     * @return ConfigurationManager The application configuration
     */
    public function getConfig(): ConfigurationManager
    {
        return $this->configuration;
    }


    /**
     * Gets the locale file path for the given interface language.
     *
     * @param string $iface_lang The interface language
     * @return string The path to the locale file
     */
    public function getLocaleFile(string $iface_lang): string
    {
        $supportedLocales = explode(',', $this->configuration->get('interface', 'enabled_languages', 'en_EN'));
        if (in_array($iface_lang, $supportedLocales)) {
            return "locale/$iface_lang/LC_MESSAGES/messages.po";
        }
        return "locale/en_EN/LC_MESSAGES/messages.po";
    }

    /**
     * Displays validation errors if the configuration is invalid.
     *
     * @param ConfigValidator $validator The configuration validator
     */
    public function showValidationErrors(ConfigValidator $validator): void
    {
        if (!$validator->validate()) {
            $errors = $validator->getErrors();
            $messageService = new MessageService();

            // If there's only one error, display it directly
            if (count($errors) === 1) {
                $firstKey = array_key_first($errors);
                $messageService->displayDirectSystemError("Invalid configuration: " . $errors[$firstKey]);
            } elseif (count($errors) > 1) {
                $errorMessage = "Invalid configuration:<ul>";
                foreach ($errors as $error) {
                    $errorMessage .= "<li>" . htmlspecialchars($error, ENT_QUOTES) . "</li>";
                }
                $errorMessage .= "</ul>";
                $messageService->displayDirectSystemError($errorMessage);
            }
        }
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
