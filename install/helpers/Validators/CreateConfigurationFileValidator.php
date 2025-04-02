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

namespace PoweradminInstall\Validators;

use PoweradminInstall\InstallationSteps;
use Symfony\Component\Validator\Constraints as Assert;

class CreateConfigurationFileValidator extends BaseValidator
{
    public function validate(): array
    {
        $constraints = new Assert\Collection(array_merge(
            $this->getBaseConstraints(),
            [
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_INSTALLATION_COMPLETE,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_INSTALLATION_COMPLETE
                    ])
                ],
            ]
        ));

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        $errors = ValidationErrorHelper::formatErrors($violations);

        // Check if either configuration file exists (new format is preferred)
        $newConfigExists = file_exists(dirname(__DIR__, 3) . '/config/settings.php');
        $oldConfigExists = file_exists(dirname(__DIR__, 3) . '/inc/config.inc.php');

        if (!$newConfigExists && !$oldConfigExists) {
            $errors['configuration'] = _('No configuration file found. Please create the config/settings.php file before proceeding.');
        }

        return $errors;
    }
}
