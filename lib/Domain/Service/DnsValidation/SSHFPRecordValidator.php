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
 * SSHFP (SSH Fingerprint) record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SSHFPRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SSHFP record content
     *
     * @param string $content The content of the SSHFP record (algorithm fingerprint-type fingerprint)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SSHFP records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateSSHFPContent($content);
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

        // Validate priority (should be 0 for SSHFP records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for SSHFP records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // SSHFP records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an SSHFP record
     * Format: <algorithm> <fp-type> <fingerprint>
     *
     * Algorithm values:
     * 1 = RSA
     * 2 = DSA
     * 3 = ECDSA
     * 4 = Ed25519
     *
     * Fingerprint type values:
     * 1 = SHA-1
     * 2 = SHA-256
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateSSHFPContent(string $content): ValidationResult
    {
        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            return ValidationResult::failure(_('SSHFP record must contain algorithm, fingerprint-type and fingerprint separated by spaces.'));
        }

        [$algorithm, $fpType, $fingerprint] = $parts;

        // Validate algorithm (must be 1-4)
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, [1, 2, 3, 4])) {
            return ValidationResult::failure(_('SSHFP algorithm must be 1 (RSA), 2 (DSA), 3 (ECDSA), or 4 (Ed25519).'));
        }

        // Validate fingerprint type (must be 1 or 2)
        if (!is_numeric($fpType) || !in_array((int)$fpType, [1, 2])) {
            return ValidationResult::failure(_('SSHFP fingerprint type must be 1 (SHA-1) or 2 (SHA-256).'));
        }

        // Validate fingerprint (must be hexadecimal)
        if (!preg_match('/^[0-9a-fA-F]+$/', $fingerprint)) {
            return ValidationResult::failure(_('SSHFP fingerprint must be a hexadecimal string.'));
        }

        // Validate fingerprint length based on type
        $fpLength = strlen($fingerprint);
        if ((int)$fpType === 1 && $fpLength !== 40) { // SHA-1 is 40 hex chars
            return ValidationResult::failure(_('SSHFP SHA-1 fingerprint must be 40 characters long.'));
        } elseif ((int)$fpType === 2 && $fpLength !== 64) { // SHA-256 is 64 hex chars
            return ValidationResult::failure(_('SSHFP SHA-256 fingerprint must be 64 characters long.'));
        }

        return ValidationResult::success(true);
    }
}
