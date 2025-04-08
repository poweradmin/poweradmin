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

namespace PoweradminInstall;

use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Poweradmin\AppConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class InstallStepHandler
{
    private Request $request;
    private Environment $twig;
    private int $currentStep;
    private string $language;
    private const SESSION_KEY_LENGTH = 46;

    public function __construct(Request $request, Environment $twig, int $currentStep, string $language)
    {
        $this->request = $request;
        $this->twig = $twig;
        $this->currentStep = $currentStep;
        $this->language = $language;
    }

    private function renderTemplate(string $templateName, array $data): void
    {
        $data['next_step'] = filter_var($data['current_step'], FILTER_VALIDATE_INT) ?: 0;
        $data['next_step'] += 1;
        $data['file_version'] = time();
        try {
            echo $this->twig->render($templateName, $data);
        } catch (LoaderError | RuntimeError | SyntaxError $e) {
            error_log($e->getMessage());
            echo "An error occurred while rendering the template.";
        }
    }

    public function step1ChooseLanguage(array $errors): void
    {
        $this->renderTemplate('step1.html.twig', array(
            'current_step' => $this->currentStep,
            'errors' => $errors,
        ));
    }

    public function step2CheckRequirements(array $errors): void
    {
        // PHP version check
        $phpVersion = PHP_VERSION;
        $phpVersionOk = version_compare($phpVersion, '8.1.0', '>=');

        // Required PHP extensions
        $requiredExtensions = [
            'intl' => extension_loaded('intl'),
            'gettext' => extension_loaded('gettext'),
            'openssl' => extension_loaded('openssl'),
            'filter' => extension_loaded('filter'),
            'tokenizer' => extension_loaded('tokenizer'),
            'pdo' => extension_loaded('pdo'),
        ];

        // Database extensions
        $databaseExtensions = [
            'pdo-mysql' => extension_loaded('pdo_mysql'),
            'pdo-pgsql' => extension_loaded('pdo_pgsql'),
            'pdo-sqlite' => extension_loaded('pdo_sqlite'),
        ];
        $dbExtensionOk = in_array(true, $databaseExtensions, true);

        // Optional extensions
        $optionalExtensions = [
            'ldap' => extension_loaded('ldap'),
        ];

        // Check if all required components are available
        $requiredExtensionsOk = !in_array(false, $requiredExtensions, true);
        $requirementsOk = $phpVersionOk && $requiredExtensionsOk && $dbExtensionOk;

        $this->renderTemplate('step2.html.twig', [
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'errors' => $errors,
            'php_version' => $phpVersion,
            'php_version_ok' => $phpVersionOk,
            'required_extensions' => $requiredExtensions,
            'database_extensions' => $databaseExtensions,
            'db_extension_ok' => $dbExtensionOk,
            'optional_extensions' => $optionalExtensions,
            'requirements_ok' => $requirementsOk,
        ]);
    }

    public function step3GettingReady(array $errors): void
    {
        $this->renderTemplate('step3.html.twig', [
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'errors' => $errors,
        ]);
    }

    public function step4ConfiguringDatabase(array $errors): void
    {
        $charsets = require __DIR__ . '/../charsets.php';
        $collations = require __DIR__ . '/../collations.php';

        $inputData = [
            'db_user' => $this->request->get('db_user'),
            'db_pass' => $this->request->get('db_pass'),
            'db_host' => $this->request->get('db_host'),
            'db_port' => $this->request->get('db_port'),
            'db_name' => $this->request->get('db_name'),
            'db_charset' => $this->request->get('db_charset'),
            'db_collation' => $this->request->get('db_collation'),
            'db_type' => $this->request->get('db_type'),
            'pa_pass' => $this->request->get('pa_pass'),
        ];

        $this->renderTemplate('step4.html.twig', array_merge([
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'errors' => $errors,
            'charsets' => $charsets ?? [],
            'collations' => $collations ?? [],
        ], $inputData));
    }

    public function step5SetupAccountAndNameServers(array $errors, string $default_config_file): void
    {
        $credentials = $this->getCredentials();

        if ($credentials['db_type'] == 'sqlite') {
            $credentials['db_file'] = $credentials['db_name'];
        }

        $pa_pass = $this->request->get('pa_pass');

        try {
            $databaseConnection = new PDODatabaseConnection();
            $databaseService = new DatabaseService($databaseConnection);
            $db = $databaseService->connect($credentials);

            echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";

            $databaseHelper = new DatabaseHelper($db, $credentials);
            $databaseHelper->updateDatabase();
            $databaseHelper->createAdministratorUser($pa_pass, $default_config_file);

            echo _('done!') . "</p>";
        } catch (\Exception $e) {
            // Display the error in a user-friendly way
            echo "<div class='alert alert-danger'>";
            echo "<h5>" . _('Database Error') . "</h5>";
            echo "<p>" . $e->getMessage() . "</p>";

            if ($credentials['db_type'] == 'sqlite') {
                echo "<p><strong>" . _('Suggestions:') . "</strong></p>";
                echo "<ul>";
                echo "<li>" . _('Make sure the SQLite database file exists') . "</li>";
                echo "<li>" . _('Check that the web server has read and write permissions for the database file') . "</li>";
                echo "<li>" . _('Verify the full path to the database file is correct and accessible') . "</li>";
                echo "</ul>";
            }

            echo "</div>";

            // Return so we don't continue with the form rendering
            return;
        }

        $inputData = [
            'pa_db_user' => $this->request->get('pa_db_user'),
            'pa_db_pass' => $this->request->get('pa_db_pass'),
            'dns_hostmaster' => $this->request->get('dns_hostmaster'),
            'dns_ns1' => $this->request->get('dns_ns1'),
            'dns_ns2' => $this->request->get('dns_ns2'),
            'dns_ns3' => $this->request->get('dns_ns3'),
            'dns_ns4' => $this->request->get('dns_ns4'),
        ];

        $this->renderTemplate('step5.html.twig', array_merge([
            'current_step' => $this->currentStep,
            'language' => $this->request->get('language'),
            'pa_pass' => $pa_pass,
            'errors' => $errors,
        ], $credentials, $inputData));
    }

    public function step6CreateLimitedRightsUser(array $errors): void
    {
        $credentials = $this->getCredentials();

        if ($credentials['db_type'] == 'sqlite') {
            $credentials['db_file'] = $credentials['db_name'];
        } else {
            $credentials['pa_db_user'] = $this->request->get('pa_db_user');
            $credentials['pa_db_pass'] = $this->request->get('pa_db_pass');
        }

        $pa_pass = $this->request->get('pa_pass');
        $hostmaster = $this->request->get('dns_hostmaster');
        $dns_ns1 = $this->request->get('dns_ns1');
        $dns_ns2 = $this->request->get('dns_ns2');
        $dns_ns3 = $this->request->get('dns_ns3');
        $dns_ns4 = $this->request->get('dns_ns4');

        $databaseConnection = new PDODatabaseConnection();
        $databaseService = new DatabaseService($databaseConnection);
        $db = $databaseService->connect($credentials);
        $databaseHelper = new DatabaseHelper($db, $credentials);
        $instructions = $databaseHelper->generateDatabaseUserInstructions();

        $this->renderTemplate('step6.html.twig', array(
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'db_host' => $credentials['db_host'],
            'db_name' => $credentials['db_name'],
            'db_port' => $credentials['db_port'],
            'db_type' => $credentials['db_type'],
            'db_user' => $credentials['db_user'],
            'db_pass' => $credentials['db_pass'],
            'db_charset' => $credentials['db_charset'],
            'db_collation' => $credentials['db_collation'],
            'pa_db_user' => $credentials['pa_db_user'] ?? '',
            'pa_db_pass' => $credentials['pa_db_pass'] ?? '',
            'pa_pass' => $pa_pass,
            'dns_hostmaster' => $hostmaster,
            'dns_ns1' => $dns_ns1,
            'dns_ns2' => $dns_ns2,
            'dns_ns3' => $dns_ns3,
            'dns_ns4' => $dns_ns4,
            'instructions' => $instructions,
            'errors' => $errors,
        ));
    }

    public function step7CreateConfigurationFile(array $errors, string $default_config_file): void
    {
        // No need to set database port if it's standard port for that db
        $db_port = ($this->request->get('db_type') == 'mysql' && $this->request->get('db_port') != 3306)
        || ($this->request->get('db_type') == 'pgsql' && $this->request->get('db_port') != 5432) ? $this->request->get('db_port') : '';

        // For SQLite we should provide path to db file
        $db_file = $this->request->get('db_type') == 'sqlite' ? $this->request->get('db_name') : '';

        $config = new AppConfiguration($default_config_file);

        $dns_hostmaster = $this->request->get('dns_hostmaster');
        $dns_ns1 = $this->request->get('dns_ns1');
        $dns_ns2 = $this->request->get('dns_ns2');
        $dns_ns3 = $this->request->get('dns_ns3');
        $dns_ns4 = $this->request->get('dns_ns4');
        $db_host = $this->request->get('db_host');
        $db_user = $this->request->get('pa_db_user') ?? '';
        $db_pass = $this->request->get('pa_db_pass') ?? '';
        $db_name = $this->request->get('db_name');
        $db_type = $this->request->get('db_type');
        $db_charset = $this->request->get('db_charset');
        $db_collation = $this->request->get('db_collation');

        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        $sessionKey = $userAuthService->generateSalt(self::SESSION_KEY_LENGTH);

        // Format display paths for the UI (remove leading slashes)
        $displayNewConfigFile = 'config/settings.php';

        $this->renderTemplate('step6.html.twig', array(
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'new_config_file' => $displayNewConfigFile,
            'session_key' => $sessionKey,
            'dns_hostmaster' => $dns_hostmaster,
            'dns_ns1' => $dns_ns1,
            'dns_ns2' => $dns_ns2,
            'dns_ns3' => $dns_ns3,
            'dns_ns4' => $dns_ns4,
            'db_host' => $db_host,
            'db_user' => $db_user,
            'db_pass' => $db_pass,
            'db_name' => $db_name,
            'db_file' => $db_file,
            'db_type' => $db_type,
            'db_port' => $db_port,
            'db_charset' => $db_charset,
            'db_collation' => $db_collation,
            'errors' => $errors,
        ));
    }

    public function step8InstallationComplete(): void
    {
        $this->renderTemplate('step7.html.twig', array(
            'current_step' => InstallationSteps::STEP_INSTALLATION_COMPLETE,
        ));
    }

    public function getCredentials(): array
    {
        return [
            'db_user' => $this->request->get('db_user'),
            'db_pass' => $this->request->get('db_pass'),
            'db_host' => $this->request->get('db_host'),
            'db_port' => $this->request->get('db_port'),
            'db_name' => $this->request->get('db_name'),
            'db_charset' => $this->request->get('db_charset'),
            'db_collation' => $this->request->get('db_collation'),
            'db_type' => $this->request->get('db_type'),
        ];
    }
}
