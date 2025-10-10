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
use PoweradminInstall\SystemRequirements;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validator for the system requirements check step
 */
class CheckRequirementsValidator extends BaseValidator
{

    /**
     * Check requirements and validate the step
     */
    public function validate(): array
    {
        $constraints = new Assert\Collection(array_merge(
            $this->getBaseConstraints(),
            [
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_GETTING_READY,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_GETTING_READY
                    ])
                ],
            ]
        ));

        $input = $this->input->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }

    /**
     * @inheritDoc
     */
    public function validateStep(array $data): array
    {
        // Check PHP version
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

        // Prepare template data
        $templateData = [
            'php_version' => $phpVersion,
            'php_version_ok' => $phpVersionOk,
            'required_extensions' => $requiredExtensions,
            'database_extensions' => $databaseExtensions,
            'db_extension_ok' => $dbExtensionOk,
            'optional_extensions' => $optionalExtensions,
            'requirements_ok' => $requirementsOk,
            'next_step' => InstallationSteps::STEP_GETTING_READY,
        ];

        return [
            'success' => true,
            'template_data' => $templateData,
        ];
    }
}
