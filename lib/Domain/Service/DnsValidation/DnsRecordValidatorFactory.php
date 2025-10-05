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

namespace Poweradmin\Domain\Service\DnsValidation;

/**
 * Factory for creating DNS record validators
 *
 * Manages the mapping of DNS record types to their appropriate validators
 */
class DnsRecordValidatorFactory
{
    /** @var array<string, DnsRecordValidatorInterface> */
    private static array $validators = [];

    /** @var array<string, string> Mapping of record types to validator classes */
    private static array $typeToValidatorMap = [];

    /**
     * Initialize the factory with all available validators
     */
    private static function initialize(): void
    {
        if (!empty(self::$validators)) {
            return;
        }

        $validatorClasses = [
            CoreRecordValidator::class,
            DnssecRecordValidator::class,
            SecurityRecordValidator::class,
            NetworkRecordValidator::class,
            ServiceRecordValidator::class,
            MailRecordValidator::class,
            SpecialRecordValidator::class,
            ExtendedRecordValidator::class,
        ];

        foreach ($validatorClasses as $validatorClass) {
            /** @var DnsRecordValidatorInterface $validator */
            $validator = new $validatorClass();
            $className = $validatorClass;
            self::$validators[$className] = $validator;

            foreach ($validator->getSupportedTypes() as $type) {
                self::$typeToValidatorMap[$type] = $className;
            }
        }
    }

    /**
     * Get the appropriate validator for a given DNS record type
     *
     * @param string $type The DNS record type
     * @return DnsRecordValidatorInterface|null The validator or null if not found
     */
    public static function getValidator(string $type): ?DnsRecordValidatorInterface
    {
        self::initialize();

        $validatorClass = self::$typeToValidatorMap[$type] ?? null;
        if ($validatorClass === null) {
            return null;
        }

        return self::$validators[$validatorClass];
    }

    /**
     * Check if a validator exists for the given record type
     *
     * @param string $type The DNS record type
     * @return bool True if a validator exists
     */
    public static function hasValidator(string $type): bool
    {
        self::initialize();
        return isset(self::$typeToValidatorMap[$type]);
    }

    /**
     * Get all supported record types
     *
     * @return array<string> Array of supported DNS record types
     */
    public static function getSupportedTypes(): array
    {
        self::initialize();
        return array_keys(self::$typeToValidatorMap);
    }
}
