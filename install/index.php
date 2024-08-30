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

use PoweradminInstall\TemplateUtils;

require dirname(__DIR__) . '/vendor/autoload.php';

require_once 'helpers/locale_handler.php';
require_once 'helpers/database_structure.php';
require_once 'helpers/install_helpers.php';
require_once 'helpers/permissions.php';

$local_config_file = dirname(__DIR__) . '/inc/config.inc.php';
$default_config_file = dirname(__DIR__) . '/inc/config-defaults.inc.php';
const SESSION_KEY_LENGTH = 46;

// Main
$language = getLanguageFromRequest();
setLanguage($language);

$twig = TemplateUtils::initializeTwigEnvironment($language);
$current_step = TemplateUtils::getCurrentStep($_POST);
TemplateUtils::renderHeader($twig, $current_step);
checkConfigFile($current_step, $local_config_file, $twig);

switch ($current_step) {

    case 1:
        step1ChooseLanguage($twig, $current_step);
        break;

    case 2:
        step2GettingReady($twig, $current_step, $language);
        break;

    case 3:
        step3ConfiguringDatabase($twig, $current_step, $language);
        break;

    case 4:
        step4SetupAccountAndNameServers($twig, $current_step, $default_config_file);
        break;

    case 5:
        step5CreateLimitedRightsUser($twig, $current_step, $language);
        break;

    case 6:
        step6CreateConfigurationFile($twig, $current_step, $language, $default_config_file, $local_config_file);
        break;

    case 7:
        step7InstallationComplete($twig);
        break;

    default:
        break;
}

TemplateUtils::renderFooter($twig);
