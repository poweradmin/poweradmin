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

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * NAPTR record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NAPTRRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates NAPTR record content
     *
     * @param string $content The content of the NAPTR record (order pref flags service regexp replacement)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for NAPTR records, priority is part of content)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateNAPTRContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for NAPTR records as it's included in content)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for NAPTR records
     * NAPTR records don't use the prio field directly (priority is in content)
     *
     * @param mixed $prio Priority value
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for NAPTR records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. NAPTR records have priority within the content field and must have a priority value of 0.'));
    }

    /**
     * Validates NAPTR record content
     * Format: <order> <preference> <flags> <service> <regexp> <replacement>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation success or error messages
     */
    private function validateNAPTRContent(string $content): ValidationResult
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content), 6);

        // Must have all 6 parts
        if (count($parts) !== 6) {
            return ValidationResult::failure(_('NAPTR record must contain order, preference, flags, service, regexp, and replacement values.'));
        }

        [$order, $preference, $flags, $service, $regexp, $replacement] = $parts;

        // Validate order (must be a number between 0 and 65535)
        if (!is_numeric($order) || (int)$order < 0 || (int)$order > 65535) {
            return ValidationResult::failure(_('NAPTR record order must be a number between 0 and 65535.'));
        }

        // Validate preference (must be a number between 0 and 65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            return ValidationResult::failure(_('NAPTR record preference must be a number between 0 and 65535.'));
        }

        // Validate flags (must be a quoted string with one of "A", "P", "S", "U", "")
        $quotedStringResult = $this->validateQuotedString($flags);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record flags must be a quoted string.'));
        }

        $flagsValue = trim($flags, '"');
        // Valid flags are "a", "p", "s", "u" (case-insensitive) or empty
        if (!empty($flagsValue) && !preg_match('/^[APSUapsu]+$/', $flagsValue)) {
            return ValidationResult::failure(_('NAPTR record flags must contain only A, P, S, or U.'));
        }

        // Validate service (must be a quoted string)
        $quotedStringResult = $this->validateQuotedString($service);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record service must be a quoted string.'));
        }

        // Validate regexp (must be a quoted string)
        $quotedStringResult = $this->validateQuotedString($regexp);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record regexp must be a quoted string.'));
        }

        // Validate replacement (must be a valid domain name or ".")
        if ($replacement !== ".") {
            $replacementResult = $this->hostnameValidator->validate($replacement, true);
            if (!$replacementResult->isValid()) {
                return ValidationResult::failure(_('NAPTR record replacement must be either "." or a valid fully-qualified domain name.'));
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param string $content The content to validate
     * @param array &$errors Collection of validation errors
     * @return bool True if valid, false otherwise
     */
    private function isValidNAPTRContent(string $content, array &$errors): bool
    {
        $result = $this->validateNAPTRContent($content);
        if (!$result->isValid()) {
            $errors[] = $result->getFirstError();
            return false;
        }
        return true;
    }

    /**
     * Validate if a string is a valid quoted string
     *
     * @param string $value The string to check
     * @return ValidationResult ValidationResult containing validation success or error message
     */
    private function validateQuotedString(string $value): ValidationResult
    {
        if (preg_match('/^".*"$/', $value) === 1) {
            return ValidationResult::success(true);
        }
        return ValidationResult::failure(_('Value must be enclosed in double quotes.'));
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param string $value The string to check
     * @return bool True if valid, false otherwise
     */
    private function isValidQuotedString(string $value): bool
    {
        $result = $this->validateQuotedString($value);
        return $result->isValid();
    }
}
