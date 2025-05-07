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
 * TSIG (Transaction SIGnature) record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class TSIGRecordValidator implements DnsRecordValidatorInterface
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
     * Validates TSIG record content
     *
     * @param string $content The content of the TSIG record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for TSIG records)
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
        $contentResult = StringValidator::validatePrintable($content);
        if (!$contentResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        $validationResult = $this->isValidTSIGContent($content);
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
            'prio' => 0, // TSIG records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a TSIG record
     * Format: <algorithm-name> <timestamp> <fudge> <mac> <original-id> <error> <other-len> [<other-data>]
     *
     * @param string $content The content to validate
     * @return array Array with 'isValid' (bool) and 'errors' (array) keys
     */
    private function isValidTSIGContent(string $content): array
    {
        $errors = [];

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 8);
        if (count($parts) < 7) {
            $errors[] = _('TSIG record must contain at least algorithm-name, timestamp, fudge, mac, original-id, error, and other-len separated by spaces.');
            return ['isValid' => false, 'errors' => $errors];
        }

        [$algorithmName, $timestamp, $fudge, $mac, $originalId, $error, $otherLen, $otherData] = $parts + array_fill(0, 8, '');

        // Validate algorithm name (must be a valid domain name ending with a dot)
        if (!$this->isValidAlgorithmName($algorithmName)) {
            $errors[] = _('TSIG algorithm name must be a valid domain name ending with a dot (e.g., hmac-sha256.).');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate timestamp (must be a positive integer)
        if (!is_numeric($timestamp) || (int)$timestamp < 0) {
            $errors[] = _('TSIG timestamp must be a non-negative integer.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate fudge (must be a positive integer, usually small like 300)
        if (!is_numeric($fudge) || (int)$fudge < 0) {
            $errors[] = _('TSIG fudge must be a non-negative integer.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate MAC (must be a base64 string or hexadecimal)
        if (!$this->isValidMac($mac)) {
            $errors[] = _('TSIG MAC must be a valid base64-encoded string or hexadecimal string.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate original ID (must be a positive integer between 0 and 65535)
        if (!is_numeric($originalId) || (int)$originalId < 0 || (int)$originalId > 65535) {
            $errors[] = _('TSIG original ID must be a number between 0 and 65535.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate error (must be a valid DNS RCODE number between 0 and 23)
        if (!is_numeric($error) || (int)$error < 0 || (int)$error > 23) {
            $errors[] = _('TSIG error must be a valid DNS RCODE number between 0 and 23.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate other-len (must be a positive integer)
        if (!is_numeric($otherLen) || (int)$otherLen < 0) {
            $errors[] = _('TSIG other-len must be a non-negative integer.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate other-data if present (must be a base64 string or hexadecimal)
        if ((int)$otherLen > 0 && $otherData !== '' && !$this->isValidOtherData($otherData)) {
            $errors[] = _('TSIG other-data must be a valid base64-encoded string or hexadecimal string.');
            return ['isValid' => false, 'errors' => $errors];
        }

        return ['isValid' => true, 'errors' => []];
    }

    /**
     * Validates algorithm name (must be a valid domain name ending with a dot)
     *
     * @param string $algorithmName
     * @return bool
     */
    private function isValidAlgorithmName(string $algorithmName): bool
    {
        // Common TSIG algorithm names
        $validAlgorithms = [
            'hmac-md5.',
            'hmac-md5.sig-alg.reg.int.',
            'hmac-sha1.',
            'hmac-sha224.',
            'hmac-sha256.',
            'hmac-sha384.',
            'hmac-sha512.'
        ];

        // Check against common algorithm names
        if (in_array(strtolower($algorithmName), $validAlgorithms)) {
            return true;
        }

        // Otherwise, must be a valid domain name ending with a dot
        if (!str_ends_with($algorithmName, '.')) {
            return false;
        }

        // Remove trailing dot and check if it's a valid hostname
        $domainName = substr($algorithmName, 0, -1);
        $result = $this->hostnameValidator->validate($domainName, false);
        return $result->isValid();
    }

    /**
     * Validates MAC (base64 or hex string)
     *
     * @param string $mac
     * @return bool
     */
    private function isValidMac(string $mac): bool
    {
        // Check if it's valid base64-encoded data
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $mac)) {
            $decoded = base64_decode($mac, true);
            if ($decoded !== false) {
                return true;
            }
        }

        // Check if it's a valid hexadecimal string
        if (preg_match('/^[0-9a-fA-F]+$/', $mac)) {
            return true;
        }

        return false;
    }

    /**
     * Validates other-data (base64 or hex string)
     *
     * @param string $otherData
     * @return bool
     */
    private function isValidOtherData(string $otherData): bool
    {
        return $this->isValidMac($otherData); // Same validation as MAC
    }
}
