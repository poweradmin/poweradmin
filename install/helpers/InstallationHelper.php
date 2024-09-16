<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

class InstallationHelper
{
    private Environment $twig;
    private int $currentStep;
    private string $language;
    private const SESSION_KEY_LENGTH = 46;

    public function __construct(Environment $twig, int $currentStep, string $language)
    {
        $this->twig = $twig;
        $this->currentStep = $currentStep;
        $this->language = $language;
    }

    public function checkConfigFile(string $local_config_file): void
    {
        if (file_exists($local_config_file)) {
            if ($this->currentStep == InstallationSteps::STEP_INSTALLATION_COMPLETE) {
                return; // Allow last step to be shown
            } else {
                echo "<p class='alert alert-danger'>" . _('There is already a configuration file in place, so the installation will be skipped.') . "</p>";
                exit;
            }
        }
    }

    private function renderTemplate(string $templateName, array $data): void
    {
        $data['next_step'] = filter_var($data['current_step'], FILTER_VALIDATE_INT) ?: 0;
        $data['next_step'] += 1;
        $data['file_version'] = time();
        echo $this->twig->render($templateName, $data);
    }

    public function step1ChooseLanguage(): void
    {
        $this->renderTemplate('step1.html.twig', array(
            'current_step' => $this->currentStep
        ));
    }

    public function step2GettingReady(): void
    {
        $this->renderTemplate('step2.html.twig', array(
            'current_step' => $this->currentStep,
            'language' => $this->language
        ));
    }

    public function step3ConfiguringDatabase(): void
    {
        $this->renderTemplate('step3.html.twig', array(
            'current_step' => $this->currentStep,
            'language' => $this->language
        ));
    }

    public function step4SetupAccountAndNameServers(Request $request, string $default_config_file): void
    {
        echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";

        $credentials = $this->getDatabaseCredentials($request);
        $pa_pass = $request->get('pa_pass');

        foreach ($credentials as $key => $value) {
            $value = strip_tags(trim($value));
            $credentials[$key] = $value;
        }

        $databaseConnection = new PDODatabaseConnection();
        $databaseService = new DatabaseService($databaseConnection);
        $db = $databaseService->connect($credentials);

        $databaseHelper = new DatabaseHelper($db, $credentials);
        $databaseHelper->updateDatabase();
        $databaseHelper->createAdministratorUser($pa_pass, $default_config_file);

        echo _('done!') . "</p>";

        if ($credentials['db_type'] == 'sqlite') {
            $this->currentStep = InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER;
        }

        $this->renderTemplate('step4.html.twig', array_merge([
            'current_step' => $this->currentStep,
            'language' => $request->get('language'),
            'pa_pass' => $pa_pass,
        ], $credentials));
    }

    public function step5CreateLimitedRightsUser(Request $request): void
    {
        $this->currentStep++;

        $credentials = [
            'db_user' => $request->get('db_user'),
            'db_pass' => $request->get('db_pass'),
            'db_host' => $request->get('db_host'),
            'db_port' => $request->get('db_port'),
            'db_name' => $request->get('db_name'),
            'db_charset' => $request->get('db_charset'),
            'db_collation' => $request->get('db_collation'),
            'db_type' => $request->get('db_type'),
        ];

        if ($credentials['db_type'] == 'sqlite') {
            $credentials['db_file'] = $credentials['db_name'];
        } else {
            $credentials['pa_db_user'] = $request->get('pa_db_user');
            $credentials['pa_db_pass'] = $request->get('pa_db_pass');
        }

        $pa_pass = $request->get('pa_pass');
        $hostmaster = $request->get('dns_hostmaster');
        $dns_ns1 = $request->get('dns_ns1');
        $dns_ns2 = $request->get('dns_ns2');

        $databaseConnection = new PDODatabaseConnection();
        $databaseService = new DatabaseService($databaseConnection);
        $db = $databaseService->connect($credentials);

        $databaseHelper = new DatabaseHelper($db, $credentials);
        $instructions = $databaseHelper->generateDatabaseUserInstructions();

        $this->renderTemplate('step5.html.twig', array(
            'current_step' => $this->currentStep,
            'language' => $this->language,
            'db_host' => htmlspecialchars($credentials['db_host']),
            'db_name' => htmlspecialchars($credentials['db_name']),
            'db_port' => htmlspecialchars($credentials['db_port']),
            'db_type' => htmlspecialchars($credentials['db_type']),
            'db_user' => htmlspecialchars($credentials['db_user']),
            'db_pass' => htmlspecialchars($credentials['db_pass']),
            'db_charset' => htmlspecialchars($credentials['db_charset']),
            'pa_db_user' => isset($credentials['pa_db_user']) ? htmlspecialchars($credentials['pa_db_user']) : '',
            'pa_db_pass' => isset($credentials['pa_db_pass']) ? htmlspecialchars($credentials['pa_db_pass']) : '',
            'pa_pass' => htmlspecialchars($pa_pass),
            'dns_hostmaster' => htmlspecialchars($hostmaster),
            'dns_ns1' => htmlspecialchars($dns_ns1),
            'dns_ns2' => htmlspecialchars($dns_ns2),
            'instructions' => $instructions
        ));
    }

    public function step6CreateConfigurationFile(Request $request, string $default_config_file, string $local_config_file): void
    {
        // No need to set database port if it's standard port for that db
        $db_port = ($request->get('db_type') == 'mysql' && $request->get('db_port') != 3306)
        || ($request->get('db_type') == 'pgsql' && $request->get('db_port') != 5432) ? $request->get('db_port') : '';

        // For SQLite we should provide path to db file
        $db_file = $request->get('db_type') == 'sqlite' ? htmlspecialchars($request->get('db_name')) : '';

        $config = new AppConfiguration($default_config_file);

        $dns_hostmaster = $request->get('dns_hostmaster');
        $dns_ns1 = $request->get('dns_ns1');
        $dns_ns2 = $request->get('dns_ns2');
//        $dns_ns3 = $request->get('dns_ns3');
//        $dns_ns4 = $request->get('dns_ns4');
        $db_host = $request->get('db_host');
        $db_user = $request->get('pa_db_user') ?? '';
        $db_pass = $request->get('pa_db_pass') ?? '';
        $db_name = $request->get('db_name');
        $db_type = $request->get('db_type');
        $db_charset = $request->get('db_charset');
        $pa_pass = $request->get('pa_pass');

        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        $this->renderTemplate('step6.html.twig', array(
            'current_step' => (int)htmlspecialchars($this->currentStep),
            'language' => $this->language,
            'local_config_file' => $local_config_file,
            'session_key' => $userAuthService->generateSalt(self::SESSION_KEY_LENGTH),
            'iface_lang' => $this->language,
            'dns_hostmaster' => htmlspecialchars($dns_hostmaster),
            'dns_ns1' => htmlspecialchars($dns_ns1),
            'dns_ns2' => htmlspecialchars($dns_ns2),
            'db_host' => htmlspecialchars($db_host),
            'db_user' => htmlspecialchars($db_user),
            'db_pass' => htmlspecialchars($db_pass),
            'db_name' => htmlspecialchars($db_name),
            'db_file' => $db_file,
            'db_type' => htmlspecialchars($db_type),
            'db_port' => htmlspecialchars($db_port),
            'db_charset' => htmlspecialchars($db_charset),
            'pa_pass' => htmlspecialchars($pa_pass)
        ));
    }

    public function step7InstallationComplete(): void
    {
        $this->renderTemplate('step7.html.twig', array(
            'current_step' => InstallationSteps::STEP_INSTALLATION_COMPLETE,
        ));
    }

    public function getDatabaseCredentials(Request $request): array
    {
        $credentials = [
            'db_user' => $request->get('user'),
            'db_pass' => $request->get('pass'),
            'db_host' => $request->get('host'),
            'db_port' => $request->get('dbport'),
            'db_name' => $request->get('name'),
            'db_charset' => $request->get('charset'),
            'db_collation' => $request->get('collation'),
            'db_type' => $request->get('type'),
        ];

        if ($credentials['db_type'] == 'sqlite') {
            $credentials['db_file'] = $credentials['db_name'];
        }

        return $credentials;
    }
}
