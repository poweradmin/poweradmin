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
 * CERT records store certificates in the DNS as defined in RFC 4398.
 * The format is: <type> <key-tag> <algorithm> <certificate-data>
 *
 * - type: Certificate type (1-PKIX, 2-SPKI, 3-PGP, 4-IPKIX, 5-ISPKI, 6-IPGP, 7-ACPKIX, 8-IACPKIX, 253-URI, 254-OID)
 * - key-tag: 16-bit key tag computed from the certificate's key (0-65535)
 * - algorithm: Cryptographic algorithm number (1-16 as defined in RFC 8624)
 * - certificate-data: Base64-encoded certificate data or URI when type indicates a URL format
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4398 RFC 4398: Storing Certificates in the Domain Name System (DNS)
 * @see https://datatracker.ietf.org/doc/html/rfc8624 RFC 8624: Algorithm Implementation Requirements and Usage Guidance for DNSSEC
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
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

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

        // Collect warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
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

        // Add general security advice
        $warnings[] = _('Per RFC 4398, consider DNS record size limitations when using CERT records. Large certificates may cause DNS message fragmentation.');

        if (strlen($content) > 400) {
            $warnings[] = _('This CERT record content is quite large (' . strlen($content) . ' characters). Consider alternatives if DNS transmission issues occur.');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of a CERT record
     * Format: <type> <key-tag> <algorithm> <certificate-data>
     *
     * Based on current standards and algorithm recommendations.
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateCERTContent(string $content): ValidationResult
    {
        $warnings = [];

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
            'PKIX' => 1,    // X.509 as per PKIX
            'SPKI' => 2,    // SPKI certificate
            'PGP' => 3,     // OpenPGP packet
            'IPKIX' => 4,   // URL of an X.509 data object
            'ISPKI' => 5,   // URL of an SPKI certificate
            'IPGP' => 6,    // Fingerprint and URL of OpenPGP packet
            'ACPKIX' => 7,  // Attribute Certificate
            'IACPKIX' => 8, // URL of an Attribute Certificate
            'URI' => 253,   // URI private
            'OID' => 254    // OID private
        ];

        // Type validation and specific type handling
        $typeValue = 0;
        $isUrlType = false;

        if (is_numeric($type)) {
            $typeValue = (int)$type;
            if ($typeValue < 0 || $typeValue > 65535) {
                return ValidationResult::failure(_('CERT type must be a number between 0 and 65535 or a valid mnemonic.'));
            }

            // Check reserved values
            if ($typeValue === 0 || $typeValue === 255 || $typeValue === 65535) {
                $warnings[] = _('CERT type value ' . $typeValue . ' is reserved per RFC 4398. This may not be recognized by all systems.');
            }

            // Check experimental range
            if ($typeValue >= 65280 && $typeValue <= 65534) {
                $warnings[] = _('CERT type value ' . $typeValue . ' is in the experimental range (65280-65534) per RFC 4398.');
            }
        } elseif (isset($validTypes[strtoupper($type)])) {
            // Type is a valid mnemonic, convert it to a value
            $typeValue = $validTypes[strtoupper($type)];
        } else {
            return ValidationResult::failure(_('CERT type must be a number between 0 and 65535 or a valid mnemonic (PKIX, SPKI, PGP, etc.).'));
        }

        // Check if this is a URL-based type (affects certificate data validation)
        if (in_array($typeValue, [4, 5, 6, 8])) {
            $isUrlType = true;
            $warnings[] = _('URL-based certificate types (IPKIX, ISPKI, IPGP, IACPKIX) introduce additional indirection and potential security concerns.');
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

        $algorithmValue = 0;

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

        // Add warning based on algorithm security recommendations in RFC 8624
        $mustImplement = [13, 8]; // ECDSAP256SHA256, RSASHA256
        $recommended = [15, 16];  // ED25519, ED448
        $optional = [14];         // ECDSAP384SHA384
        $notRecommended = [5, 6, 7, 12]; // RSASHA1, DSA-NSEC3-SHA1, RSASHA1-NSEC3-SHA1, ECC-GOST
        $mustNotImplement = [1, 3]; // RSAMD5, DSA

        if (in_array($algorithmValue, $mustNotImplement)) {
            $warnings[] = _('Algorithm ' . $algorithmValue . ' MUST NOT be used according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif (in_array($algorithmValue, $notRecommended)) {
            $warnings[] = _('Algorithm ' . $algorithmValue . ' is NOT RECOMMENDED for use according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif (in_array($algorithmValue, $recommended)) {
            $warnings[] = _('Algorithm ' . $algorithmValue . ' is RECOMMENDED per RFC 8624. This is a good choice.');
        }

        // Validate certificate data
        if ($isUrlType) {
            // For URL types, validate as URL rather than base64
            if (!filter_var($certData, FILTER_VALIDATE_URL)) {
                return ValidationResult::failure(_('For URL-based CERT types, the certificate data must be a valid URL.'));
            }
        } else {
            // Regular base64 validation for certificate data
            $base64Result = $this->validateBase64($certData);
            if (!$base64Result->isValid()) {
                return $base64Result;
            }

            // Check certificate size
            $decodedSize = strlen(base64_decode($certData));
            if ($decodedSize > 1024) {
                $warnings[] = _('Certificate data is ' . $decodedSize . ' bytes, which may be too large for efficient DNS usage. RFC 4398 recommends minimizing certificate size.');
            }
        }

        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * RFC 4398 requires that certificate data be in base64 encoding.
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Check for valid base64 characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('CERT certificate data must contain only valid base64 characters (A-Z, a-z, 0-9, +, /, =).'));
        }

        // Check if padding is correct (if present)
        if (strpos($data, '=') !== false && !preg_match('/=*$/', $data)) {
            return ValidationResult::failure(_('CERT certificate data has invalid base64 padding. Equals signs (=) should only appear at the end of the data.'));
        }

        // Verify the length is a multiple of 4 (possibly with padding)
        $unpadded = rtrim($data, '=');
        $padding = strlen($data) - strlen($unpadded);

        if (($padding > 2) || ((strlen($unpadded) + $padding) % 4 !== 0)) {
            return ValidationResult::failure(_('CERT certificate data has invalid base64 length. Base64 data must be a multiple of 4 characters (with padding).'));
        }

        // Try to decode the base64 data for final validation
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('CERT certificate data must be valid base64-encoded data.'));
        }

        // Certificate data should be binary and non-empty
        if (empty($decoded)) {
            return ValidationResult::failure(_('CERT certificate data must not be empty after base64 decoding.'));
        }

        return ValidationResult::success(true);
    }
}
