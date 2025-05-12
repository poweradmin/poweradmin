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
 * Validator for DS (Delegation Signer) DNS records
 *
 * DS records are a critical component of DNSSEC and provide a secure delegation
 * mechanism from a parent zone to a child zone. They contain a digest of a DNSKEY
 * record in the child zone, allowing the parent zone to validate the child's keys.
 *
 * Format: <key-tag> <algorithm> <digest-type> <digest>
 *
 * - key-tag: A 16-bit numerical identifier (1-65535) for the referenced DNSKEY
 * - algorithm: DNSSEC algorithm number (1-16, same as DNSKEY record)
 * - digest-type: Hash algorithm used (1=SHA-1, 2=SHA-256, 4=SHA-384)
 * - digest: Hexadecimal representation of the hash with length based on digest type
 *   - SHA-1: 40 hex characters
 *   - SHA-256: 64 hex characters
 *   - SHA-384: 96 hex characters
 *
 * Special case for CDS records (RFC 8078):
 * - "0 0 0 00" is a special deletion record to signal removal of DS at parent
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4034 RFC 4034: Resource Records for DNS Security Extensions
 * @see https://datatracker.ietf.org/doc/html/rfc8624 RFC 8624: Algorithm Implementation Requirements and Usage Guidance for DNSSEC
 * @see https://datatracker.ietf.org/doc/html/rfc3658 RFC 3658: Delegation Signer Resource Record
 * @see https://datatracker.ietf.org/doc/html/rfc8078 RFC 8078: Managing DS Records from the Parent via CDS/CDNSKEY
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DSRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate DS record
     *
     * @param string $content Content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL value
     * @param int $defaultTTL Default TTL to use if TTL is empty
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // Validate the hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check for proper parent-child relationship
        // DS records should be placed at zone delegation points
        $nameParts = explode('.', $name);
        if (count($nameParts) <= 2) {
            $warnings[] = _('DS records should typically be placed at delegation points, not at the zone apex. Check if this is intentional.');
        }

        // Special check for CDS deletion record (RFC 8078)
        if (trim($content) === '0 0 0 00') {
            $warnings[] = _('This is a special CDS deletion record (RFC 8078) that signals the parent to remove the DS record.');

            // Validate TTL since we need it even for deletion records
            $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
            if (!$ttlResult->isValid()) {
                return $ttlResult;
            }
            $ttlData = $ttlResult->getData();
            $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

            return ValidationResult::success([
                'content' => $content,
                'name' => $name,
                'prio' => 0,
                'ttl' => $validatedTtl
            ], $warnings);
        }

        // Validate DS record content
        $contentResult = $this->validateDSContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Add any warnings from content validation
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

        // Priority for DS records should be 0
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DS records must be 0 or empty.'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate DS record content format
     *
     * @param string $content DS record content
     *
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateDSContent(string $content): ValidationResult
    {
        $warnings = [];

        // DS record format: <key-tag> <algorithm> <digest-type> <digest>
        if (!preg_match('/^([0-9]+) ([0-9]+) ([0-9]+) ([a-f0-9]+)$/i', $content)) {
            return ValidationResult::failure(_('DS record must be in the format: <key-tag> <algorithm> <digest-type> <digest>'));
        }

        // Split content into components
        $parts = explode(' ', $content);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('DS record must contain exactly 4 fields'));
        }

        list($keyTag, $algorithm, $digestType, $digest) = $parts;

        // Validate key tag (1-65535)
        if (!is_numeric($keyTag) || $keyTag < 1 || $keyTag > 65535) {
            return ValidationResult::failure(_('Key tag must be a number between 1 and 65535'));
        }

        // Validate algorithm (known DNSSEC algorithms 1-16)
        // Algorithm security categorization based on RFC 8624
        $validAlgorithms = range(1, 16);
        $currentRecommended = [13, 15, 16]; // ECDSAP256SHA256, ED25519, ED448
        $mustImplement = [8, 13]; // RSASHA256, ECDSAP256SHA256
        $optional = [14]; // ECDSAP384SHA384
        $notRecommended = [1, 3, 5, 6, 7, 12]; // RSAMD5, DSA, RSASHA1, DSA-NSEC3-SHA1, RSASHA1-NSEC3-SHA1, ECC-GOST

        $algorithmInt = (int)$algorithm;
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

        // Basic algorithm validation
        if (!is_numeric($algorithm) || !in_array($algorithmInt, $validAlgorithms)) {
            return ValidationResult::failure(_('Algorithm must be one of: 1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16'));
        }

        // Add algorithm recommendation warnings
        $algorithmName = $algorithmNames[$algorithmInt] ?? "Algorithm $algorithmInt";

        if (in_array($algorithmInt, $currentRecommended)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is a RECOMMENDED algorithm for use according to RFC 8624.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $mustImplement) && !in_array($algorithmInt, $currentRecommended)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is in common use, but newer algorithms like ECDSAP256SHA256 (13) or ED25519 (15) are preferred.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $optional)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is optional for implementation according to RFC 8624.'), $algorithmName, $algorithmInt);
        } elseif (in_array($algorithmInt, $notRecommended)) {
            $warnings[] = sprintf(_('%s (algorithm %d) is NOT RECOMMENDED for use according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.'), $algorithmName, $algorithmInt);
        }

        // Validate digest type and add warnings (1 = SHA-1, 2 = SHA-256, 4 = SHA-384)
        // Based on RFC 8624 recommendations
        $validDigestTypes = [1, 2, 4];
        $digestTypeInt = (int)$digestType;

        if (!in_array($digestTypeInt, $validDigestTypes)) {
            return ValidationResult::failure(_('Digest type must be one of: 1 (SHA-1), 2 (SHA-256), 4 (SHA-384)'));
        }

        // Add digest type recommendation warnings
        if ($digestTypeInt === 1) {
            $warnings[] = _('SHA-1 (digest type 1) is NOT RECOMMENDED for use according to RFC 8624. SHA-256 (digest type 2) is the recommended digest algorithm.');
        } elseif ($digestTypeInt === 2) {
            $warnings[] = _('SHA-256 (digest type 2) is the RECOMMENDED digest algorithm according to RFC 8624.');
        } elseif ($digestTypeInt === 4) {
            $warnings[] = _('SHA-384 (digest type 4) is a good choice for higher security requirements, but SHA-256 (digest type 2) is sufficient for most deployments and has wider support.');
        }

        // Validate digest length based on type
        $digestLength = strlen($digest);
        switch ($digestTypeInt) {
            case 1: // SHA-1
                if ($digestLength !== 40) {
                    return ValidationResult::failure(_('SHA-1 digest must be exactly 40 hexadecimal characters'));
                }
                break;
            case 2: // SHA-256
                if ($digestLength !== 64) {
                    return ValidationResult::failure(_('SHA-256 digest must be exactly 64 hexadecimal characters'));
                }
                break;
            case 4: // SHA-384
                if ($digestLength !== 96) {
                    return ValidationResult::failure(_('SHA-384 digest must be exactly 96 hexadecimal characters'));
                }
                break;
        }

        // Add additional guidance
        $warnings[] = _('DS records establish a chain of trust from parent to child zones. The parent zone must have this DS record, and the child zone must have the corresponding DNSKEY record.');

        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Validate DS record content format for public use
     *
     * @param string $content DS record content
     *
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    public function validateDSRecordContent(string $content): ValidationResult
    {
        // Special check for CDS deletion record (RFC 8078)
        if (trim($content) === '0 0 0 00') {
            return ValidationResult::success(['valid' => true], [_('This is a special CDS deletion record (RFC 8078) that signals the parent to remove the DS record.')]);
        }

        return $this->validateDSContent($content);
    }
}
