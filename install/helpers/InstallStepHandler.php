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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
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
        $phpVersionOk = SystemRequirements::isPhpVersionSupported();

        // Get extension statuses from centralized requirements
        $requiredExtensions = SystemRequirements::getRequiredExtensionsStatus();
        $databaseExtensions = SystemRequirements::getDatabaseExtensionsStatus();
        $optionalExtensions = SystemRequirements::getOptionalExtensionsStatus();

        // Check if all required components are available
        $requiredExtensionsOk = SystemRequirements::areRequiredExtensionsLoaded();
        $dbExtensionOk = SystemRequirements::isDatabaseExtensionLoaded();
        $requirementsOk = SystemRequirements::areAllRequirementsMet();

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
            // PowerDNS database field
            'pdns_db_name' => $this->getPdnsDbName(),
        ];

        $this->renderTemplate('step4.html.twig', array_merge([
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'errors' => $errors,
            'charsets' => $charsets ?? [],
            'collations' => $collations ?? [],
        ], $inputData));
    }

    public function step5SetupAccountAndNameServers(array $errors, ?string $defaultConfigFile = null): void
    {
        $credentials = $this->getCredentials();

        if ($credentials['db_type'] == 'sqlite') {
            $credentials['db_file'] = $credentials['db_name'];
        }

        $pa_pass = $this->request->get('pa_pass');
        $messages = [];
        $dbError = null;

        try {
            $databaseConnection = new PDODatabaseConnection();
            $databaseService = new DatabaseService($databaseConnection);
            $db = $databaseService->connect($credentials);

            $databaseHelper = new DatabaseHelper($db, $credentials);

            // Check for PowerDNS tables before proceeding
            // Skip this check if using a separate PowerDNS database (it's already validated)
            $pdns_db_name = $this->getPdnsDbName();
            if (empty($pdns_db_name)) {
                // Only check PowerDNS tables if using the same database
                $missingTables = $databaseHelper->checkPowerDnsTables();
                if (!empty($missingTables)) {
                    $warningMsg = '<strong>' . _('Warning:') . '</strong> ';
                    $warningMsg .= _('Missing PowerDNS tables:') . ' <strong>' . implode(', ', $missingTables) . '</strong>';
                    $warningMsg .= ' - ' . _('Poweradmin requires these PowerDNS tables to function properly.');
                    $messages['pdns_warning'] = $warningMsg;
                }
            }

            $databaseHelper->updateDatabase();
            $databaseHelper->createAdministratorUser($pa_pass);

            $messages['db_success'] = _('Updating database... done!');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $suggestions = '';

            // Check for foreign key constraint issues
            if (
                stripos($errorMessage, 'foreign key') !== false ||
                stripos($errorMessage, 'constraint') !== false ||
                stripos($errorMessage, 'FK') !== false
            ) {
                $dbType = $credentials['db_type'];
                $disableConstraintsCommand = '';

                if ($dbType === 'mysql') {
                    $disableConstraintsCommand = "SET foreign_key_checks = 0;";
                } elseif ($dbType === 'pgsql') {
                    $disableConstraintsCommand = "-- PostgreSQL doesn't have a direct equivalent\n-- You may need to drop the tables manually with CASCADE option:\nDROP TABLE table_name CASCADE;";
                } elseif ($dbType === 'sqlite') {
                    $disableConstraintsCommand = "PRAGMA foreign_keys = OFF;";
                }

                $suggestions = "<p><strong>" . _('Foreign Key Constraint Issues:') . "</strong></p>" .
                    "<ul>" .
                    "<li>" . _('The installer could not remove existing tables due to foreign key constraints') . "</li>" .
                    "<li>" . _('This can happen during reinstallation if you have custom constraints or if tables are referenced by other tables') . "</li>" .
                    "<li>" . _('You can try manually disabling foreign key checks before running the installer:') . "</li>" .
                    "</ul>" .
                    "<pre class='bg-dark text-light p-2 my-2'>{$disableConstraintsCommand}</pre>";
            } elseif ($credentials['db_type'] == 'sqlite') {
                // SQLite-specific suggestions
                $suggestions = "<p><strong>" . _('Suggestions:') . "</strong></p>" .
                    "<ul>" .
                    "<li>" . _('Make sure the SQLite database file exists') . "</li>" .
                    "<li>" . _('Check that the web server has read and write permissions for the database file') . "</li>" .
                    "<li>" . _('Verify the full path to the database file is correct and accessible') . "</li>" .
                    "</ul>";
            } else {
                // General database error suggestions
                $suggestions = "<p><strong>" . _('Suggestions:') . "</strong></p>" .
                    "<ul>" .
                    "<li>" . _('Verify database credentials are correct') . "</li>" .
                    "<li>" . _('Check that the database user has sufficient privileges') . "</li>" .
                    "<li>" . _('Ensure the database server is running and accessible') . "</li>" .
                    "</ul>";
            }

            $dbError = [
                'title' => _('Database Error'),
                'message' => $errorMessage,
                'suggestions' => $suggestions
            ];

            // Skip rendering the rest of the template
            $this->renderTemplate('step5.html.twig', [
                'current_step' => $this->currentStep,
                'language' => $this->request->get('language'),
                'db_error' => $dbError,
                'errors' => $errors,
            ]);
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
            'pdns_db_name' => $this->request->get('pdns_db_name'),
        ];

        $this->renderTemplate('step5.html.twig', array_merge([
            'current_step' => $this->currentStep,
            'language' => $this->request->get('language'),
            'pa_pass' => $pa_pass,
            'errors' => $errors,
            'messages' => $messages,
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
        $instructionBlocks = $databaseHelper->generateDatabaseUserInstructions($this->request->get('pdns_db_name'));

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
            'pdns_db_name' => $this->request->get('pdns_db_name'),
            'instruction_blocks' => $instructionBlocks,
            'errors' => $errors,
        ));
    }

    public function step7CreateConfigurationFile(array $errors, ?string $defaultConfigFile = null): void
    {
        // No need to set database port if it's standard port for that db
        $db_port = ($this->request->get('db_type') == 'mysql' && $this->request->get('db_port') != 3306)
        || ($this->request->get('db_type') == 'pgsql' && $this->request->get('db_port') != 5432) ? $this->request->get('db_port') : '';

        // For SQLite we should provide path to db file
        $db_file = $this->request->get('db_type') == 'sqlite' ? $this->request->get('db_name') : '';

        $config = ConfigurationManager::getInstance();
        $config->initialize();

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
            $config->get('security', 'password_encryption'),
            $config->get('security', 'password_cost')
        );

        $sessionKey = $userAuthService->generateSalt(self::SESSION_KEY_LENGTH);

        // Format display paths for the UI (remove leading slashes)
        $displayNewConfigFile = 'config/settings.php';

        $pdns_db_name = $this->request->get('pdns_db_name');

        $this->renderTemplate('step7.html.twig', array(
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
            'pdns_db_name' => $pdns_db_name,
            'errors' => $errors,
        ));
    }

    public function step8InstallationComplete(): void
    {
        // Clear installation messages now that installation is complete
        SessionUtils::clearMessages();

        $this->renderTemplate('step8.html.twig', array(
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

    private function getPdnsDbName(): string
    {
        $dbType = $this->request->get('db_type');
        $pdnsDbName = $this->request->get('pdns_db_name');

        // Only allow pdns_db_name for MySQL
        return ($dbType === 'mysql') ? $pdnsDbName : '';
    }
}
