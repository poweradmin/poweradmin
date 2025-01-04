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

class SetupAccountAndNameServersValidator extends BaseValidator
{
    use DatabaseValidationTrait;
    use UserPasswordValidationTrait;
    use DnsValidationTrait;
    use DbUserValidationTrait;

    public function validate(): array
    {
        $constraints = new Assert\Collection(array_merge(
            $this->getBaseConstraints(),
            $this->getDatabaseConstraints(),
            $this->getPasswordValidationConstraints(),
            $this->getDnsValidationConstraints(),
            $this->getUserConstraints(),
            [
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_CREATE_LIMITED_RIGHTS_USER
                    ])
                ],
            ]
        ));

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }
}
