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
 * OPENPGPKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class OPENPGPKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates OPENPGPKEY record content
     *
     * @param string $content The content of the OPENPGPKEY record (base64 encoded PGP public key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for OPENPGPKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate the hostname format
        $printableResult = StringValidator::validatePrintable($name);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in hostname.'));
        }

        // Hostname validation for OPENPGPKEY records
        // OPENPGPKEY records are typically of the form: <hash-of-localpart>._openpgpkey.<domain>
        // But we'll allow regular FQDNs too
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateOpenPGPKeyContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for OPENPGPKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for OPENPGPKEY records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // OPENPGPKEY records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an OPENPGPKEY record
     * Content should be base64 encoded data
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateOpenPGPKeyContent(string $content): ValidationResult
    {
        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('OPENPGPKEY record content cannot be empty.'));
        }

        // Check for valid printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in OPENPGPKEY record content.'));
        }

        // OPENPGPKEY records store data in base64 format
        // We'll do a basic validation that it consists of valid base64 characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $content)) {
            return ValidationResult::failure(_('OPENPGPKEY records must contain valid base64-encoded data.'));
        }

        // Optionally verify that it's valid base64
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('OPENPGPKEY record contains invalid base64 data.'));
        }

        return ValidationResult::success(true);
    }
}
