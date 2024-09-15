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

declare(strict_types=1);

use PoweradminInstall\InstallationHelper;
use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use PoweradminInstall\StepValidator;
use PoweradminInstall\TwigEnvironmentInitializer;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL) ;

$localConfigFile = dirname(__DIR__) . '/inc/config.inc.php';
$defaultConfigFile = dirname(__DIR__) . '/inc/config-defaults.inc.php';

// Main
$request = Request::createFromGlobals();

$localeHandler = new LocaleHandler();
$language = $request->get('language', LocaleHandler::DEFAULT_LANGUAGE);
$currentLanguage = $localeHandler->getCurrentLanguage($language);
$localeHandler->setLanguage($currentLanguage);

$twigEnvironmentInitializer = new TwigEnvironmentInitializer($localeHandler);
$twigEnvironment = $twigEnvironmentInitializer->initialize($currentLanguage);

$stepValidator = new StepValidator();
$step = $request->get('step', InstallationSteps::STEP_CHOOSE_LANGUAGE);
$currentStep = $stepValidator->getCurrentStep($step);

$installationHelper = new InstallationHelper($twigEnvironment, $currentStep, $currentLanguage);
$installationHelper->checkConfigFile($localConfigFile);

switch ($currentStep) {
    case InstallationSteps::STEP_CHOOSE_LANGUAGE:
        $installationHelper->step1ChooseLanguage();
        break;

    case InstallationSteps::STEP_GETTING_READY:
        $installationHelper->step2GettingReady();
        break;

    case InstallationSteps::STEP_CONFIGURING_DATABASE:
        $installationHelper->step3ConfiguringDatabase();
        break;

    case InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS:
        $installationHelper->step4SetupAccountAndNameServers($defaultConfigFile);
        break;

    case InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER:
        $installationHelper->step5CreateLimitedRightsUser();
        break;

    case InstallationSteps::STEP_CREATE_CONFIGURATION_FILE:
        $installationHelper->step6CreateConfigurationFile(
            $defaultConfigFile,
            $localConfigFile
        );
        break;

    case InstallationSteps::STEP_INSTALLATION_COMPLETE:
        $installationHelper->step7InstallationComplete();
        break;

    default:
        break;
}
