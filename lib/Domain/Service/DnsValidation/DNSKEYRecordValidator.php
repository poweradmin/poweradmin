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
 * DNSKEY records are defined in RFC 4034 and store public keys that are used in the
 * DNSSEC authentication process. These records contain the public key material that
 * verifiers need to authenticate DNSSEC signatures in RRSIG records.
 *
 * Format: <flags> <protocol> <algorithm> <public-key>
 *
 * - flags: 16-bit field represented as an unsigned decimal number (0, 256, or 257)
 *   - bit 7: Zone Key flag (1 for KSK/ZSK, 0 for other purposes)
 *   - bit 15: Secure Entry Point (SEP) flag (RFC 3757), often set for KSKs (1 for KSK)
 *   - 256 (0x0100): Zone Key flag set (ZSK)
 *   - 257 (0x0101): Zone Key flag + SEP flag set (KSK)
 *   - 0: Neither flag set (not for DNSSEC)
 * - protocol: Must be 3 (fixed value, retained for compatibility with KEY record)
 * - algorithm: DNSSEC algorithm number (1-16, with RFC 8624 recommendations)
 * - public-key: Base64 encoded public key material (format depends on algorithm)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4034 RFC 4034: Resource Records for DNS Security Extensions
 * @see https://datatracker.ietf.org/doc/html/rfc3757 RFC 3757: Domain Name System KEY (DNSKEY) RR Secure Entry Point (SEP) Flag
 * @see https://datatracker.ietf.org/doc/html/rfc8624 RFC 8624: Algorithm Implementation Requirements and Usage Guidance for DNSSEC
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
        $warnings = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check if record is at zone apex (recommended practice)
        $nameParts = explode('.', $name);
        $isZoneApex = false;
        if (count($nameParts) <= 2 || $nameParts[0] === '@') {
            $isZoneApex = true;
        } else {
            $warnings[] = _('DNSKEY records are typically placed at the zone apex. This record does not appear to be at a zone apex, which is unusual for DNSSEC deployments.');
        }

        // Validate content
        $contentResult = $this->validateDNSKEYContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Add any warnings from the content validation
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

        // Priority for DNSKEY records should be 0
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DNSKEY records must be 0 or empty.'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
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
        $warnings = [];

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

        // Provide contextual information based on flag values
        $flagValue = (int)$flags;
        if ($flagValue === 0) {
            $warnings[] = _('Flag value 0 indicates this key is NOT intended for use as a zone key in DNSSEC. Make sure this is intentional.');
        } elseif ($flagValue === 256) {
            $warnings[] = _('Flag value 256 indicates this is a Zone Signing Key (ZSK) with Zone Key bit set and SEP bit unset.');
        } elseif ($flagValue === 257) {
            $warnings[] = _('Flag value 257 indicates this is a Key Signing Key (KSK) with both Zone Key and SEP bits set.');
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

        // Add warnings based on RFC 8624 algorithm security recommendations
        $algorithmInt = (int)$algorithm;

        // Algorithm security categorization based on RFC 8624
        $mustImplement = [13, 8]; // ECDSAP256SHA256, RSASHA256
        $recommended = [15, 16];  // ED25519, ED448
        $optional = [14];         // ECDSAP384SHA384
        $notRecommended = [3, 5, 6, 7, 12]; // DSA+SHA1, RSASHA1, DSA-NSEC3-SHA1, RSASHA1-NSEC3-SHA1, ECC-GOST
        $deprecated = [1, 10];        // RSAMD5, RSASHA1-NSEC3-SHA1 (explicitly deprecated)
        $mustNotImplement = [0, 2, 4, 9, 11]; // Reserved, DH, Reserved, Reserved, Reserved

        // Algorithm names for better clarity in warnings
        $algorithmNames = [
            1 => 'RSAMD5',
            3 => 'DSA',
            5 => 'RSASHA1',
            6 => 'DSA-NSEC3-SHA1',
            7 => 'RSASHA1-NSEC3-SHA1',
            8 => 'RSASHA256',
            10 => 'RSASHA512',
            12 => 'ECC-GOST',
            13 => 'ECDSAP256SHA256',
            14 => 'ECDSAP384SHA384',
            15 => 'ED25519',
            16 => 'ED448'
        ];

        $algorithmName = $algorithmNames[$algorithmInt] ?? "Algorithm $algorithmInt";

        if (in_array($algorithmInt, $mustImplement)) {
            if ($algorithmInt === 13) { // ECDSAP256SHA256
                $warnings[] = _('ECDSAP256SHA256 (algorithm 13) is the RECOMMENDED algorithm for DNSSEC signing according to RFC 8624.');
            } else {
                $warnings[] = sprintf(_('%s (algorithm %d) is a MUST implement algorithm according to RFC 8624.'), $algorithmName, $algorithmInt);
            }
        } elseif (in_array($algorithmInt, $recommended)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is a RECOMMENDED algorithm for use according to RFC 8624.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $optional)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is specified as optional for implementation in RFC 8624.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $notRecommended)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is NOT RECOMMENDED for use according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $deprecated)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is DEPRECATED according to RFC 8624. Do not use this algorithm for new deployments.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $mustNotImplement)) {
            $warnings[] = sprintf(_('Algorithm %d MUST NOT be implemented according to RFC 8624. This value should not be used.'), $algorithmInt);
        }

        // Validate public key (must be valid base64-encoded data)
        $base64Result = $this->validateBase64($publicKey);
        if (!$base64Result->isValid()) {
            return ValidationResult::failure(_('DNSKEY public key must be valid base64-encoded data.'));
        }

        // Check minimal public key length for security
        $decoded = base64_decode($publicKey);
        $keyLength = strlen($decoded) * 8; // Length in bits

        // Provide warning if key size seems too small based on algorithm
        if ($algorithmInt == 1 || $algorithmInt == 5 || $algorithmInt == 7 || $algorithmInt == 8 || $algorithmInt == 10) {
            // RSA algorithms
            if ($keyLength < 2048) {
                $warnings[] = _('RSA key size is less than 2048 bits. RFC 8624 recommends a minimum of 2048 bits for RSA keys.');
            }
        }

        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return ValidationResult Validation result with success or error message
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Check for proper padding
        if (strlen($data) % 4 !== 0) {
            return ValidationResult::failure(_('Base64 encoding has incorrect padding.'));
        }

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
