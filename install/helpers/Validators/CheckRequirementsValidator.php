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
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validator for the system requirements check step
 */
class CheckRequirementsValidator extends BaseValidator
{
    /**
     * Minimum PHP version required for Poweradmin
     */
    private const MIN_PHP_VERSION = '8.1.0';

    /**
     * Required PHP extensions
     */
    private const REQUIRED_EXTENSIONS = [
        'intl',
        'gettext',
        'openssl',
        'filter',
        'tokenizer',
        'pdo',
    ];

    /**
     * Database extensions (at least one must be installed)
     */
    private const DATABASE_EXTENSIONS = [
        'pdo-mysql',
        'pdo-pgsql',
        'pdo-sqlite',
    ];

    /**
     * Optional extensions
     */
    private const OPTIONAL_EXTENSIONS = [
        'ldap',
    ];

    /**
     * Check requirements and validate the step
     */
    public function validate(): array
    {
        // For GET requests (like "Check Again"), we don't need to validate the same constraints
        if ($this->request->isMethod('GET')) {
            // Only validate language and step for GET requests
            $constraints = new Assert\Collection([
                'language' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
                ],
                'step' => [
                    new Assert\NotBlank(),
                    new Assert\EqualTo([
                        'value' => InstallationSteps::STEP_CHECK_REQUIREMENTS,
                        'message' => 'The step must be equal to ' . InstallationSteps::STEP_CHECK_REQUIREMENTS
                    ])
                ],
            ]);

            $input = $this->request->query->all();
            $violations = $this->validator->validate($input, $constraints);
            return ValidationErrorHelper::formatErrors($violations);
        }

        // For POST requests, use the full validation
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

        $input = $this->request->request->all();
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
        $phpVersionOk = version_compare($phpVersion, self::MIN_PHP_VERSION, '>=');

        // Check required extensions
        $requiredExtensions = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $extensionName = $this->getExtensionName($extension);
            $requiredExtensions[$extension] = extension_loaded($extensionName);
        }

        // Check database extensions
        $databaseExtensions = [];
        $dbExtensionOk = false;
        foreach (self::DATABASE_EXTENSIONS as $extension) {
            $extensionName = $this->getExtensionName($extension);
            $isLoaded = extension_loaded($extensionName);
            $databaseExtensions[$extension] = $isLoaded;
            if ($isLoaded) {
                $dbExtensionOk = true;
            }
        }

        // Check optional extensions
        $optionalExtensions = [];
        foreach (self::OPTIONAL_EXTENSIONS as $extension) {
            $extensionName = $this->getExtensionName($extension);
            $optionalExtensions[$extension] = extension_loaded($extensionName);
        }

        // Check if all required components are available
        $requiredExtensionsOk = !in_array(false, $requiredExtensions, true);
        $requirementsOk = $phpVersionOk && $requiredExtensionsOk && $dbExtensionOk;

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

    /**
     * Get the actual extension name from the friendly name
     *
     * @param string $extension The extension friendly name
     * @return string The actual extension name
     */
    private function getExtensionName(string $extension): string
    {
        // Map friendly names to actual extension names
        $extensionMap = [
            'pdo-mysql' => 'pdo_mysql',
            'pdo-pgsql' => 'pdo_pgsql',
            'pdo-sqlite' => 'pdo_sqlite',
        ];

        return $extensionMap[$extension] ?? $extension;
    }
}
