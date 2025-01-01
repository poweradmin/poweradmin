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

namespace PoweradminInstall\Validators;

use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\InstallationSteps;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;

class CreateLimitedRightsUserValidator extends AbstractStepValidator
{
    public function validate(): array
    {
        $constraints = new Assert\Collection([
            'install_token' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => CsrfTokenService::TOKEN_LENGTH, 'max' => CsrfTokenService::TOKEN_LENGTH]),
            ],
            'submit' => [
                new Assert\NotBlank(),
            ],
            'step' => [
                new Assert\NotBlank(),
                new Assert\EqualTo([
                    'value' => InstallationSteps::STEP_CREATE_CONFIGURATION_FILE,
                    'message' => 'The step must be equal to ' . InstallationSteps::STEP_CREATE_CONFIGURATION_FILE
                ])
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
            ],
            'db_pass' => [
                new Assert\NotBlank(),
            ],
            'db_host' => [
                new Assert\NotBlank(),
            ],
            'db_name' => [
                new Assert\NotBlank(),
            ],
            'db_port' => [
                new Assert\NotBlank(),
            ],
            'db_user' => [
                new Assert\NotBlank(),
            ],
            'db_type' => [
                new Assert\NotBlank(),
            ],
            'db_charset' => [
                new Assert\Optional(),
            ],
            'pa_db_user' => [
                new Assert\NotBlank(),
            ],
            'pa_db_pass' => [
                new Assert\NotBlank(),
            ],
            'pa_pass' => [
                new Assert\NotBlank(),
            ],
            'dns_hostmaster' => [
                new Assert\NotBlank(),
            ],
            'dns_ns1' => [
                new Assert\NotBlank(),
            ],
            'dns_ns2' => [
                new Assert\NotBlank(),
            ],
            'dns_ns3' => [
                new Assert\Optional()
            ],
            'dns_ns4' => [
                new Assert\Optional()
            ],
        ]);

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }
}
