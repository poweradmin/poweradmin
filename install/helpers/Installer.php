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

use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\Validators\AbstractStepValidator;
use PoweradminInstall\Validators\ChooseLanguageValidator;
use PoweradminInstall\Validators\ConfiguringDatabaseValidator;
use PoweradminInstall\Validators\CreateConfigurationFileValidator;
use PoweradminInstall\Validators\CreateLimitedRightsUserValidator;
use PoweradminInstall\Validators\EmptyValidator;
use PoweradminInstall\Validators\GettingReadyValidator;
use PoweradminInstall\Validators\SetupAccountAndNameServersValidator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class Installer
{
    private Request $request;
    private LocaleHandler $localeHandler;
    private StepValidator $stepValidator;
    private InstallStepHandler $installStepHandler;
    private CsrfTokenService $csrfTokenService;
    private InstallSecurityService $securityService;
    private string $localConfigFile;
    private string $defaultConfigFile;
    private string $installConfigFile;
    private const LOCAL_CONFIG_FILE_PATH = '/inc/config.inc.php';
    private const DEFAULT_CONFIG_FILE_PATH = '/inc/config-defaults.inc.php';
    private const INSTALL_CONFIG_PATH = '/config.php';
    private array $config;

    public function __construct(Request $request)
    {
        $this->localConfigFile = dirname(__DIR__, 2) . self::LOCAL_CONFIG_FILE_PATH;
        $this->defaultConfigFile = dirname(__DIR__, 2) . self::DEFAULT_CONFIG_FILE_PATH;
        $this->installConfigFile = dirname(__DIR__) . self::INSTALL_CONFIG_PATH;

        $this->request = $request;
        $this->localeHandler = new LocaleHandler();
        $this->stepValidator = new StepValidator();
        $this->csrfTokenService = new CsrfTokenService();
        $this->config = $this->loadConfig($this->installConfigFile);
        $this->securityService = new InstallSecurityService(
            $this->config,
            $this->csrfTokenService
        );
    }

    public function initialize(): void
    {
        $rawStep = $this->request->get('step', InstallationSteps::STEP_CHOOSE_LANGUAGE);
        $currentStep = $this->stepValidator->getCurrentStep($rawStep);

        if (file_exists($this->localConfigFile)) {
            // Only allow viewing the final step if installation is complete
            if ($currentStep !== InstallationSteps::STEP_INSTALLATION_COMPLETE) {
                echo 'There is already a configuration file in place, so the installation will be skipped.';
                exit;
            }
        }

        $securityErrors = $this->securityService->validateRequest($this->request);
        if (!empty($securityErrors)) {
            $this->handleSecurityErrors($securityErrors);
            return;
        }

        $errors = $this->validatePreviousStep($currentStep - 1);

        if ($this->hasLanguageError($errors)) {
            echo 'Please select a language to proceed with the installation.';
            $currentStep = InstallationSteps::STEP_CHOOSE_LANGUAGE;
        }

        // If there are errors, go back to the previous step
        if (!empty($errors)) {
            $currentStep--;
        }

        $installToken = $this->csrfTokenService->generateToken();
        $_SESSION['install_token'] = $installToken;

        $currentLanguage = $this->initializeLocaleHandler();
        $twigEnvironment = $this->initializeTwigEnvironment($currentLanguage);

        $this->installStepHandler = new InstallStepHandler(
            $this->request,
            $twigEnvironment,
            $currentStep,
            $currentLanguage
        );

        $this->handleStep($currentStep, $errors);
    }

    private function handleSecurityErrors(array $errors): void
    {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain');
        echo implode("\n", $errors);
        exit();
    }

    private function handleStep(int $currentStep, array $errors): void
    {
        switch ($currentStep) {
            case InstallationSteps::STEP_CHOOSE_LANGUAGE:
                $this->installStepHandler->step1ChooseLanguage($errors);
                break;

            case InstallationSteps::STEP_GETTING_READY:
                $this->installStepHandler->step2GettingReady($errors);
                break;

            case InstallationSteps::STEP_CONFIGURING_DATABASE:
                $this->installStepHandler->step3ConfiguringDatabase($errors);
                break;

            case InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS:
                $this->installStepHandler->step4SetupAccountAndNameServers($errors, $this->defaultConfigFile);
                break;

            case InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER:
                $this->installStepHandler->step5CreateLimitedRightsUser($errors);
                break;

            case InstallationSteps::STEP_CREATE_CONFIGURATION_FILE:
                $this->installStepHandler->step6CreateConfigurationFile(
                    $errors,
                    $this->defaultConfigFile,
                    $this->localConfigFile
                );
                break;

            case InstallationSteps::STEP_INSTALLATION_COMPLETE:
                $this->installStepHandler->step7InstallationComplete();
                break;

            default:
                break;
        }
    }

    private function getStepValidator($step): AbstractStepValidator
    {
        return match ($step) {
            InstallationSteps::STEP_CHOOSE_LANGUAGE => new ChooseLanguageValidator($this->request, $this->config),
            InstallationSteps::STEP_GETTING_READY => new GettingReadyValidator($this->request, $this->config),
            InstallationSteps::STEP_CONFIGURING_DATABASE => new ConfiguringDatabaseValidator($this->request, $this->config),
            InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS => new SetupAccountAndNameServersValidator($this->request, $this->config),
            InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER => new CreateLimitedRightsUserValidator($this->request, $this->config),
            InstallationSteps::STEP_CREATE_CONFIGURATION_FILE => new CreateConfigurationFileValidator($this->request, $this->config),
            default => new EmptyValidator($this->request, $this->config),
        };
    }

    private function validatePreviousStep(int $previousStep): array
    {
        $validator = $this->getStepValidator($previousStep);
        return $validator->validate();
    }

    private function hasLanguageError(array $errors): bool
    {
        if (isset($errors['language'])) {
            return true;
        }
        return false;
    }

    private function initializeLocaleHandler(): string
    {
        $language = $this->request->get('language', LocaleHandler::DEFAULT_LANGUAGE);
        $currentLanguage = $this->localeHandler->getCurrentLanguage($language);
        $this->localeHandler->setLanguage($currentLanguage);
        return $currentLanguage;
    }

    private function initializeTwigEnvironment(string $currentLanguage): Environment
    {
        $twigEnvironmentInitializer = new TwigEnvironmentInitializer($this->localeHandler);
        $environment = $twigEnvironmentInitializer->initialize($currentLanguage);

        $environment->addGlobal('install_token', $_SESSION['install_token'] ?? '');

        return $environment;
    }


    private function loadConfig(string $configPath): array
    {
        if (!file_exists($configPath)) {
            throw new RuntimeException("Configuration file not found: $configPath");
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException("Invalid configuration format");
        }

        return $config;
    }
}
