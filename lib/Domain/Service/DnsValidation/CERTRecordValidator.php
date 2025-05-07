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
 * CERT record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CERTRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CERT record content
     *
     * @param string $content The content of the CERT record (type key-tag algorithm cert-data)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CERT records)
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
        $contentResult = $this->validateCERTContent($content);
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

        // Validate priority (should be 0 for CERT records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for CERT records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // CERT records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a CERT record
     * Format: <type> <key-tag> <algorithm> <certificate-data>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateCERTContent(string $content): ValidationResult
    {
        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in CERT record content.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('CERT record must contain type, key-tag, algorithm and certificate-data separated by spaces.'));
        }

        [$type, $keyTag, $algorithm, $certData] = $parts;

        // Validate type (must be a number or a known type mnemonic)
        $validTypes = [
            'PKIX' => 1,   // X.509 as per PKIX
            'SPKI' => 2,   // SPKI certificate
            'PGP' => 3,    // OpenPGP packet
            'IPKIX' => 4,  // URL of an X.509 data object
            'ISPKI' => 5,  // URL of an SPKI certificate
            'IPGP' => 6,   // Fingerprint and URL of OpenPGP packet
            'ACPKIX' => 7, // Attribute Certificate
            'IACPKIX' => 8, // URL of an AC
            'URI' => 253,  // URI private
            'OID' => 254   // OID private
        ];

        if (is_numeric($type)) {
            $typeValue = (int)$type;
            if ($typeValue < 0 || $typeValue > 65535) {
                return ValidationResult::failure(_('CERT type must be a number between 0 and 65535 or a valid mnemonic.'));
            }
        } elseif (isset($validTypes[strtoupper($type)])) {
            // Type is a valid mnemonic, convert it to a value
            $typeValue = $validTypes[strtoupper($type)];
        } else {
            return ValidationResult::failure(_('CERT type must be a number between 0 and 65535 or a valid mnemonic (PKIX, SPKI, PGP, etc.).'));
        }

        // Validate key tag (must be a number between 0 and 65535)
        if (!is_numeric($keyTag) || (int)$keyTag < 0 || (int)$keyTag > 65535) {
            return ValidationResult::failure(_('CERT key tag must be a number between 0 and 65535.'));
        }

        // Validate algorithm (must be a number between 0 and 255 or valid algorithm mnemonic)
        $validAlgorithms = [
            'RSAMD5' => 1,
            'DH' => 2,
            'DSA' => 3,
            'ECC' => 4,
            'RSASHA1' => 5,
            'RSASHA256' => 8,
            'RSASHA512' => 10,
            'ECCGOST' => 12,
            'ECDSAP256SHA256' => 13,
            'ECDSAP384SHA384' => 14,
            'ED25519' => 15,
            'ED448' => 16
        ];

        if (is_numeric($algorithm)) {
            $algorithmValue = (int)$algorithm;
            if ($algorithmValue < 0 || $algorithmValue > 255) {
                return ValidationResult::failure(_('CERT algorithm must be a number between 0 and 255 or a valid mnemonic.'));
            }
        } elseif (isset($validAlgorithms[strtoupper($algorithm)])) {
            // Algorithm is a valid mnemonic, convert it to a value
            $algorithmValue = $validAlgorithms[strtoupper($algorithm)];
        } else {
            return ValidationResult::failure(_('CERT algorithm must be a number between 0 and 255 or a valid mnemonic (RSASHA1, DSA, etc.).'));
        }

        // Validate certificate data (must be base64-encoded data)
        $base64Result = $this->validateBase64($certData);
        if (!$base64Result->isValid()) {
            return $base64Result;
        }

        return ValidationResult::success(true);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Basic pattern for base64-encoded data (may allow some invalid base64, but is sufficient for basic validation)
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('CERT certificate data must contain only valid base64 characters.'));
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('CERT certificate data must be valid base64-encoded data.'));
        }

        return ValidationResult::success(true);
    }
}
