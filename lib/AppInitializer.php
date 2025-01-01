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

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\Infrastructure\Database\PDOLayer;
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
    /** @var AppConfiguration $config Application configuration */
    private AppConfiguration $config;

    /** @var PDOLayer $db Database connection layer */
    private PDOLayer $db;

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
        if (!file_exists('inc/config.inc.php')) {
            $error = new ErrorMessage(_('The configuration file (config.inc.php) does not exist. Please use the <a href="install/">installer</a> to create it.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
            exit();
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
        $this->config = new AppConfiguration();
    }

    /**
     * Loads and sets the locale based on the configuration or user session.
     */
    private function loadLocale(): void
    {
        $supportedLocales = explode(',', $this->config->get('iface_enabled_languages'));
        $locale = new LocaleManager($supportedLocales, './locale');

        $userLang = $_SESSION["userlang"] ?? $this->config->get('iface_lang');
        $locale->setLocale($userLang);
    }

    /**
     * Connects to the database using the configuration settings.
     */
    private function connectToDatabase(): void
    {
        $credentials = [
            'db_host' => $this->config->get('db_host'),
            'db_port' => $this->config->get('db_port'),
            'db_user' => $this->config->get('db_user'),
            'db_pass' => $this->config->get('db_pass'),
            'db_name' => $this->config->get('db_name'),
            'db_charset' => $this->config->get('db_charset'),
            'db_collation' => $this->config->get('db_collation'),
            'db_type' => $this->config->get('db_type'),
            'db_file' => $this->config->get('db_file'),
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
        $legacyAuthenticateSession = new SessionAuthenticator($this->db, $this->config);
        $legacyAuthenticateSession->authenticate();
    }

    /**
     * Gets the database connection.
     *
     * @return PDOLayer The database connection
     */
    public function getDb(): PDOLayer
    {
        return $this->db;
    }
}
