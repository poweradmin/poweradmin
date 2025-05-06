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
 * NSEC3PARAM record validator
 *
 * NSEC3PARAM records provide the parameters for authenticated denial of existence using NSEC3.
 * Format: [hash-algorithm] [flags] [iterations] [salt]
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSEC3PARAMRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->ttlValidator = new TTLValidator($config);
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate an NSEC3PARAM record
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for NSEC3PARAM records)
     * @param int|string $ttl The TTL value
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

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('NSEC3PARAM record content cannot be empty.'));
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('NSEC3PARAM record contains invalid characters.'));
        }

        // Check NSEC3PARAM record format
        $contentResult = $this->validateNsec3ParamContent($content);
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

        // NSEC3PARAM records don't use priority, so it's always 0
        $priority = 0;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'ttl' => $validatedTtl,
            'priority' => $priority
        ]);
    }

    /**
     * Validate NSEC3PARAM record content format
     *
     * NSEC3PARAM content should have proper format with required fields
     *
     * @param string $content The NSEC3PARAM record content
     * @return ValidationResult ValidationResult object
     */
    private function validateNsec3ParamContent(string $content): ValidationResult
    {
        $parts = preg_split('/\s+/', trim($content));

        // NSEC3PARAM record should have exactly 4 parts:
        // 1. Hash algorithm (1 = SHA-1)
        // 2. Flags (0-255)
        // 3. Iterations (0-2500)
        // 4. Salt (- for empty or hex value)

        if (count($parts) !== 4) {
            return ValidationResult::failure(_('NSEC3PARAM record must contain exactly hash algorithm, flags, iterations, and salt.'));
        }

        // Validate hash algorithm (should be 1 for SHA-1)
        $algorithm = (int)$parts[0];
        if ($algorithm !== 1) {
            return ValidationResult::failure(_('NSEC3PARAM hash algorithm must be 1 (SHA-1).'));
        }

        // Validate flags (0-255, typically 0 or 1)
        $flags = (int)$parts[1];
        if ($flags < 0 || $flags > 255) {
            return ValidationResult::failure(_('NSEC3PARAM flags must be between 0 and 255.'));
        }

        // Validate iterations (0-2500, RFC recommends max of 150)
        $iterations = (int)$parts[2];
        if ($iterations < 0 || $iterations > 2500) {
            return ValidationResult::failure(_('NSEC3PARAM iterations must be between 0 and 2500.'));
        }

        // Validate salt (- for empty or hex value)
        $salt = $parts[3];
        if ($salt !== '-' && !preg_match('/^[0-9A-Fa-f]+$/', $salt)) {
            return ValidationResult::failure(_('NSEC3PARAM salt must be - (for empty) or a hexadecimal value.'));
        }

        return ValidationResult::success([
            'algorithm' => $algorithm,
            'flags' => $flags,
            'iterations' => $iterations,
            'salt' => $salt
        ]);
    }
}
