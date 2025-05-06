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
 * DNSKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DNSKEYRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates DNSKEY record content
     *
     * @param string $content The content of the DNSKEY record (flags protocol algorithm public-key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for DNSKEY records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
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
        $contentResult = $this->validateDNSKEYContent($content);
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

        // Priority for DNSKEY records should be 0
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DNSKEY records must be 0 or empty.'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // DNSKEY records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a DNSKEY record
     * Format: <flags> <protocol> <algorithm> <public-key>
     *
     * @param string $content The content to validate
     * @return ValidationResult Validation result with success or error message
     */
    private function validateDNSKEYContent(string $content): ValidationResult
    {
        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('DNSKEY record must contain flags, protocol, algorithm and public-key separated by spaces.'));
        }

        [$flags, $protocol, $algorithm, $publicKey] = $parts;

        // Validate flags (must be 0, 256, or 257)
        if (!is_numeric($flags) || !in_array((int)$flags, [0, 256, 257])) {
            return ValidationResult::failure(_('DNSKEY flags must be 0, 256, or 257.'));
        }

        // Validate protocol (must be 3)
        if (!is_numeric($protocol) || (int)$protocol !== 3) {
            return ValidationResult::failure(_('DNSKEY protocol must be 3.'));
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('DNSKEY algorithm must be a number between 1 and 16.'));
        }

        // Validate public key (must be valid base64-encoded data)
        $base64Result = $this->validateBase64($publicKey);
        if (!$base64Result->isValid()) {
            return ValidationResult::failure(_('DNSKEY public key must be valid base64-encoded data.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return ValidationResult Validation result with success or error message
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Basic pattern for base64-encoded data
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('Invalid base64 characters detected.'));
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('Invalid base64 encoding.'));
        }

        return ValidationResult::success(true);
    }
}
