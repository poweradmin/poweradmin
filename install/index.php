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

use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use PoweradminInstall\StepValidator;
use PoweradminInstall\TwigEnvironmentInitializer;

require dirname(__DIR__) . '/vendor/autoload.php';

require_once 'helpers/database_structure.php';
require_once 'helpers/install_helpers.php';

$local_config_file = dirname(__DIR__) . '/inc/config.inc.php';
$default_config_file = dirname(__DIR__) . '/inc/config-defaults.inc.php';
const SESSION_KEY_LENGTH = 46;

// Main
$localeHandler = new LocaleHandler();
$language = $localeHandler->getLanguageFromRequest();
$localeHandler->setLanguage($language);

$twigEnvironmentInitializer = new TwigEnvironmentInitializer($localeHandler);
$twigEnvironment = $twigEnvironmentInitializer->initialize($language);

$stepValidator = new StepValidator();
$current_step = $stepValidator->getCurrentStep($_POST);
checkConfigFile($current_step, $local_config_file, $twigEnvironment);

switch ($current_step) {

    case InstallationSteps::STEP_CHOOSE_LANGUAGE:
        step1ChooseLanguage($twigEnvironment, $current_step);
        break;

    case InstallationSteps::STEP_GETTING_READY:
        step2GettingReady($twigEnvironment, $current_step, $language);
        break;

    case InstallationSteps::STEP_CONFIGURING_DATABASE:
        step3ConfiguringDatabase($twigEnvironment, $current_step, $language);
        break;

    case InstallationSteps::STEP_SETUP_ACCOUNT_AND_NAMESERVERS:
        step4SetupAccountAndNameServers($twigEnvironment, $current_step, $default_config_file);
        break;

    case InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER:
        step5CreateLimitedRightsUser($twigEnvironment, $current_step, $language);
        break;

    case InstallationSteps::STEP_CREATE_CONFIGURATION_FILE:
        step6CreateConfigurationFile($twigEnvironment, $current_step, $language, $default_config_file, $local_config_file);
        break;

    case InstallationSteps::STEP_INSTALLATION_COMPLETE:
        step7InstallationComplete($twigEnvironment);
        break;

    default:
        break;
}
