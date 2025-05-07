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
 * RKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class RKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates RKEY record content
     *
     * @param string $content The content of the RKEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate the hostname format
        $nameResult = StringValidator::validatePrintable($name);
        if (!$nameResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in name field.'));
        }

        // Hostname validation for RKEY records
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = StringValidator::validatePrintable($content);
        if (!$contentResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        $validationResult = $this->isValidRKEYContent($content);
        if (!$validationResult['isValid']) {
            return ValidationResult::errors($validationResult['errors']);
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // RKEY records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an RKEY record
     * Format: <flags> <protocol> <algorithm> <public key>
     *
     * @param string $content The content to validate
     * @return array Array with 'isValid' (bool) and 'errors' (array) keys
     */
    private function isValidRKEYContent(string $content): array
    {
        $errors = [];

        // Check if empty
        if (empty(trim($content))) {
            $errors[] = _('RKEY record content cannot be empty.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 4) {
            $errors[] = _('RKEY record must contain flags, protocol, algorithm, and public key data.');
            return ['isValid' => false, 'errors' => $errors];
        }

        [$flags, $protocol, $algorithm, $publicKey] = [$parts[0], $parts[1], $parts[2], implode(' ', array_slice($parts, 3))];

        // Validate flags field (must be a number)
        if (!is_numeric($flags)) {
            $errors[] = _('RKEY flags field must be a numeric value.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate protocol field (must be a number)
        if (!is_numeric($protocol)) {
            $errors[] = _('RKEY protocol field must be a numeric value.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate algorithm field (must be a number)
        if (!is_numeric($algorithm)) {
            $errors[] = _('RKEY algorithm field must be a numeric value.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Minimal validation for the public key part
        if (empty(trim($publicKey))) {
            $errors[] = _('RKEY public key data cannot be empty.');
            return ['isValid' => false, 'errors' => $errors];
        }

        return ['isValid' => true, 'errors' => []];
    }
}
