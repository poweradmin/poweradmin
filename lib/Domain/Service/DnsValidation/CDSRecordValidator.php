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
 * CDS record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CDSRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CDS record content
     *
     * @param string $content The content of the CDS record (key-tag algorithm digest-type digest)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CDS records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
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
        $contentResult = $this->validateCDSContent($content);
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

        // Validate priority (should be 0 for CDS records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for CDS records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // CDS records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a CDS record
     * Format: <key-tag> <algorithm> <digest-type> <digest>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateCDSContent(string $content): ValidationResult
    {
        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in CDS record content.'));
        }

        // Special case for CDS deletion record
        if (trim($content) === '0 0 0 00') {
            return ValidationResult::success(true);
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('CDS record must contain key-tag, algorithm, digest-type and digest separated by spaces.'));
        }

        [$keyTag, $algorithm, $digestType, $digest] = $parts;

        // Validate key tag (must be a number between 0 and 65535)
        if (!is_numeric($keyTag) || (int)$keyTag < 0 || (int)$keyTag > 65535) {
            return ValidationResult::failure(_('CDS key tag must be a number between 0 and 65535.'));
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('CDS algorithm must be a number between 1 and 16.'));
        }

        // Validate digest type (must be 1, 2, or 4)
        $validDigestTypes = [1, 2, 4];
        if (!is_numeric($digestType) || !in_array((int)$digestType, $validDigestTypes)) {
            return ValidationResult::failure(_('CDS digest type must be 1 (SHA-1), 2 (SHA-256), or 4 (SHA-384).'));
        }

        // Validate digest (hex string)
        $expectedLength = 0;

        // Set expected length based on digest type
        if ((int)$digestType === 1) {
            // SHA-1: 40 hex chars
            $expectedLength = 40;
        } elseif ((int)$digestType === 2) {
            // SHA-256: 64 hex chars
            $expectedLength = 64;
        } elseif ((int)$digestType === 4) {
            // SHA-384: 96 hex chars
            $expectedLength = 96;
        }

        // Check if digest is a valid hex string of the expected length
        if (!ctype_xdigit($digest) || strlen($digest) !== $expectedLength) {
            return ValidationResult::failure(sprintf(_('CDS digest must be a valid hex string of length %d for the selected digest type.'), $expectedLength));
        }

        return ValidationResult::success(true);
    }
}
