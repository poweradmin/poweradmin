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

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;
use Poweradmin\Infrastructure\Utility\DependencyCheck;

/**
 * Class AppInitializer
 *
 * Initializes the application by checking dependencies, loading configuration,
 * setting locale, connecting to the database, and optionally authenticating the user.
 */
class AppInitializer
{
    /** @var ConfigurationManager $configManager Configuration manager */
    private ConfigurationManager $configManager;

    /** @var PDOCommon $db Database connection layer */
    private PDOCommon $db;

    /**
     * AppInitializer constructor.
     *
     * @param bool $authenticate Whether to authenticate the user
     */
    public function __construct(bool $authenticate)
    {
        $this->checkDependencies();
        $this->checkConfigurationFile();
        $this->loadConfiguration();
        $this->loadLocale();
        $this->connectToDatabase();
        if ($authenticate) {
            $this->authenticateUser();
        }
    }

    /**
     * Checks if the configuration file exists.
     * If not, presents an error message and exits the application.
     */
    private function checkConfigurationFile(): void
    {
        // Check for new-style configuration file (preferred)
        $newConfigExists = file_exists('config/settings.php');

        // Check for old-style configuration file (deprecated)
        $oldConfigExists = file_exists('inc/config.inc.php');

        if (!$newConfigExists && !$oldConfigExists) {
            $messageService = new MessageService();
            $messageService->displayHtmlError(
                _('No configuration file found. Please use the <a href="install/">installer</a> to create one, or create a config/settings.php file manually.')
            );
        }
    }

    /**
     * Checks if all required dependencies are installed.
     */
    private function checkDependencies(): void
    {
        DependencyCheck::verifyExtensions();
    }

    /**
     * Loads the application configuration.
     */
    private function loadConfiguration(): void
    {
        $this->configManager = ConfigurationManager::getInstance();
        $this->configManager->initialize();
    }

    /**
     * Loads and sets the locale based on the configuration or user session.
     */
    private function loadLocale(): void
    {
        $enabledLanguages = $this->configManager->get('interface', 'enabled_languages');
        if (!$enabledLanguages) {
            // Fallback to legacy config key
            $enabledLanguages = $this->configManager->get('interface', 'enabled_languages', 'en_EN');
        }

        $supportedLocales = explode(',', $enabledLanguages);
        $locale = new LocaleManager($supportedLocales, './locale');

        $defaultLanguage = $this->configManager->get('interface', 'language', 'en_EN');
        $userLang = $_SESSION["userlang"] ?? $defaultLanguage;
        $locale->setLocale($userLang);
    }

    /**
     * Connects to the database using the configuration settings.
     */
    private function connectToDatabase(): void
    {
        // Get database configuration from the database section
        $dbConfig = $this->configManager->getGroup('database');

        // Map database configuration to the credentials expected by PDODatabaseConnection
        $credentials = [
            'db_host' => $dbConfig['host'] ?? '',
            'db_port' => $dbConfig['port'] ?? '',
            'db_user' => $dbConfig['user'] ?? '',
            'db_pass' => $dbConfig['password'] ?? '',
            'db_name' => $dbConfig['name'] ?? '',
            'db_charset' => $dbConfig['charset'] ?? '',
            'db_collation' => $dbConfig['collation'] ?? '',
            'db_type' => $dbConfig['type'] ?? '',
            'db_file' => $dbConfig['file'] ?? '',
        ];

        $databaseConnection = new PDODatabaseConnection();
        $databaseService = new DatabaseService($databaseConnection);
        $this->db = $databaseService->connect($credentials);
    }

    /**
     * Authenticates the user using session data.
     */
    private function authenticateUser(): void
    {
        $sessionAuthenticator = new SessionAuthenticator($this->db, $this->configManager);
        $sessionAuthenticator->authenticate();
    }

    /**
     * Gets the database connection.
     *
     * @return PDOCommon The database connection
     */
    public function getDb(): PDOCommon
    {
        return $this->db;
    }
}
