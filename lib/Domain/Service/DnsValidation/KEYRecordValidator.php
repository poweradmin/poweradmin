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
 * KEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class KEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates KEY record content
     *
     * KEY format: <flags> <protocol> <algorithm> <public key>
     * Example: 256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==
     *
     * @param string $content The content of the KEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for KEY records)
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
        $contentResult = $this->validateKEYContent($content);
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

        // Validate priority (should be 0 for KEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for KEY records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // KEY records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a KEY record
     * Format: <flags> <protocol> <algorithm> <public key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateKEYContent(string $content): ValidationResult
    {
        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in KEY record content.'));
        }

        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 4) {
            return ValidationResult::failure(_('KEY record must contain flags, protocol, algorithm, and public key.'));
        }

        [$flags, $protocol, $algorithm] = array_slice($parts, 0, 3);
        $publicKey = implode(' ', array_slice($parts, 3));

        // Validate flags (0-65535)
        if (!is_numeric($flags) || (int)$flags < 0 || (int)$flags > 65535) {
            return ValidationResult::failure(_('KEY flags must be a number between 0 and 65535.'));
        }

        // Validate protocol (0-255)
        // 3 is most common (DNSSEC), others are mostly historical
        if (!is_numeric($protocol) || (int)$protocol < 0 || (int)$protocol > 255) {
            return ValidationResult::failure(_('KEY protocol must be a number between 0 and 255.'));
        }

        // Validate algorithm (0-255)
        // Common values: 1 (RSA/MD5), 2 (Diffie-Hellman), 3 (DSA/SHA1), 5 (RSA/SHA-1), etc.
        if (!is_numeric($algorithm) || (int)$algorithm < 0 || (int)$algorithm > 255) {
            return ValidationResult::failure(_('KEY algorithm must be a number between 0 and 255.'));
        }

        // Validate public key (base64 format)
        if (empty($publicKey)) {
            return ValidationResult::failure(_('KEY public key is required.'));
        }

        return ValidationResult::success(true);
    }
}
