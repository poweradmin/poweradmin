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

namespace Poweradmin\Application\Boot;

use PDO;
use Poweradmin\Application\Bootstrap;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\Auth\SessionAuthenticator;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\Backend\DatabaseService;
use Poweradmin\Application\Service\Web\LocaleResolver;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Configuration\ConfigValidator;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Utility\DependencyCheck;

/**
 * The one place a process is booted: index.php, dynamic_update.php,
 * install/index.php and bin/poweradmin all call boot() and work from the
 * BootContext it returns. Nothing below the entry points reaches for the
 * configuration singleton.
 */
final class Kernel
{
    private const DEFAULT_CONFIGURATION_FILE = 'config/settings.php';

    /**
     * Loads the configuration once, applies the timezone and session policy,
     * builds the logger and the module registry, and opens the database when the
     * entry point wants it up front.
     *
     * @param BootOptions|bool $web true boots as BootOptions::Web, false as BootOptions::Script
     */
    public static function boot(BootOptions|bool $web): BootContext
    {
        $options = is_bool($web) ? ($web ? BootOptions::Web : BootOptions::Script) : $web;

        $config = ConfigurationManager::getInstance();
        $config->initialize();
        Bootstrap::initializeTimezone($config);

        if ($options->startsSession() && !self::sessionIsPointless($options, $config)) {
            Bootstrap::initializeSession($config);
        }

        $logger = Logger::fromConfig($config);
        $config->setLogger($logger);

        if ($options->guardsConfiguration()) {
            self::assertConfigurationUsable($config);
        }

        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        $context = new BootContext($config, $logger, $registry);
        if ($options->connectsDatabase()) {
            $context->database();
        }

        return $context;
    }

    /**
     * What a web controller needs beyond the booted context: the PHP extensions
     * and the configuration file present, the gettext locale set, the database
     * open and, unless the controller serves anonymous requests, the session
     * authenticated. Runs once per controller; the checks are idempotent.
     */
    public static function controllerEnvironment(BootContext $context, bool $authenticate): ControllerEnvironment
    {
        DependencyCheck::verifyExtensions();
        self::assertConfigurationFileExists();
        self::loadLocale($context->config);

        $db = $context->database();
        if ($authenticate) {
            (new SessionAuthenticator($db, $context->config, new HttpRequest()))->authenticate();
        }

        return new ControllerEnvironment($context->config, $db, $context->logger, $context->moduleRegistry);
    }

    /**
     * Opens a PDO connection from mapped credentials (DatabaseCredentialMapper
     * or the installer's form), wrapping failures in a readable message.
     *
     * @param array<string, mixed> $credentials
     */
    public static function connect(array $credentials): PDO
    {
        return (new DatabaseService(new PDODatabaseConnection()))->connect($credentials);
    }

    /**
     * The settings file the process reads: PA_CONFIG_PATH when set, otherwise
     * config/settings.php relative to the working directory.
     */
    public static function configurationFile(): string
    {
        $customConfigPath = getenv('PA_CONFIG_PATH');

        return $customConfigPath !== false && $customConfigPath !== ''
            ? $customConfigPath
            : self::DEFAULT_CONFIGURATION_FILE;
    }

    private static function sessionIsPointless(BootOptions $options, ConfigurationInterface $config): bool
    {
        if (!$options->skipsSessionWhenHeadless()) {
            return false;
        }

        return !$config->get('interface', 'web_enabled', true)
            || RequestContext::isHealthProbeRequest((string) $config->get('interface', 'base_url_prefix', ''));
    }

    /**
     * Fails fast on a broken configuration, before any page or API handler runs.
     */
    private static function assertConfigurationUsable(ConfigurationManager $configuration): void
    {
        if (!$configuration->isDefaultsFileLoaded()) {
            (new MessageService())->displayDirectSystemError(sprintf(
                'Default settings file is missing or unreadable: %s. Please restore it from the Poweradmin distribution before continuing.',
                $configuration->getDefaultsFilePath()
            ));
        }

        $validator = new ConfigValidator($configuration->getAll());
        if ($validator->validate()) {
            return;
        }

        // MessageService escapes the message itself, so pass plain text only
        (new MessageService())->displayDirectSystemError(
            'Invalid configuration: ' . implode('; ', $validator->getErrors())
        );
    }

    private static function assertConfigurationFileExists(): void
    {
        $configFile = self::configurationFile();
        if (file_exists($configFile)) {
            return;
        }

        (new MessageService())->displayDirectSystemError(
            sprintf(
                _('No configuration file found at %s. Please run the installer at install/ to create one, or create the configuration file manually.'),
                $configFile
            )
        );
    }

    /**
     * Sets the gettext locale from the configuration, the session or the request.
     */
    private static function loadLocale(ConfigurationInterface $config): void
    {
        $resolver = new LocaleResolver($config, new UserContextService(), new HttpRequest());
        $localeManager = new LocaleManager($resolver->getSupportedLocales(), './locale');
        $localeManager->setLocale($resolver->resolve());
    }
}
