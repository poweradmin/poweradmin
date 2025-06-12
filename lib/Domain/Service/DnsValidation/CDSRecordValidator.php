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
 * CDS (Child DS) record validator
 *
 * CDS records are defined in RFC 7344 and RFC 8078 to facilitate automated
 * DNSSEC delegation trust maintenance. These records allow child zones to
 * signal to parent zones which DS records should be published.
 *
 * Format: <key-tag> <algorithm> <digest-type> <digest>
 *
 * - key-tag: 16-bit numerical identifier (0-65535)
 * - algorithm: DNSSEC algorithm number (1-16 as defined in RFC 8624)
 * - digest-type: Hash algorithm used (1=SHA-1, 2=SHA-256, 4=SHA-384)
 * - digest: Hexadecimal representation of the hash with length based on digest type
 *   - SHA-1: 40 hex characters
 *   - SHA-256: 64 hex characters
 *   - SHA-384: 96 hex characters
 *
 * Special case for deletion: "0 0 0 00" (RFC 8078)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7344 RFC 7344: Automating DNSSEC Delegation Trust Maintenance
 * @see https://datatracker.ietf.org/doc/html/rfc8078 RFC 8078: Managing DS Records from the Parent via CDS/CDNSKEY
 * @see https://datatracker.ietf.org/doc/html/rfc8624 RFC 8624: Algorithm Implementation Requirements and Usage Guidance for DNSSEC
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

        // Check if record is at zone apex (required by RFC 7344)
        $nameParts = explode('.', $name);
        if (count($nameParts) > 2 && $nameParts[0] !== '@') {
            $warnings[] = _('CDS records should only be placed at the zone apex, not on subdomains, as required by RFC 7344.');
        }

        // Validate content
        $contentResult = $this->validateCDSContent($content);
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

        // Validate priority (should be 0 for CDS records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for CDS records must be 0 or empty'));
        }

        // In RFC 8078, parent zone operators should check for stability of CDS records
        $warnings[] = _('According to RFC 8078, CDS records should be stable for some time before parent zones accept them.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of a CDS record
     * Format: <key-tag> <algorithm> <digest-type> <digest>
     *
     * Based on RFC 7344 and RFC 8078 specifications.
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

        // Special case for CDS deletion record (RFC 8078 Section 4)
        if (trim($content) === '0 0 0 00') {
            return ValidationResult::success(
                ['valid' => true],
                [_('This is a CDS deletion record as defined in RFC 8078. It signals that the corresponding DS records should be removed from the parent.')]
            );
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('CDS record must contain key-tag, algorithm, digest-type and digest separated by spaces.'));
        }

        [$keyTag, $algorithm, $digestType, $digest] = $parts;
        $warnings = [];

        // Validate key tag (must be a number between 0 and 65535)
        if (!is_numeric($keyTag) || (int)$keyTag < 0 || (int)$keyTag > 65535) {
            return ValidationResult::failure(_('CDS key tag must be a number between 0 and 65535.'));
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('CDS algorithm must be a number between 1 and 16.'));
        }

        // Add warnings for algorithms based on RFC 8624 security recommendations
        $algorithmInt = (int)$algorithm;

        // Algorithm security categorization based on RFC 8624
        $mustImplement = [13, 8]; // ECDSAP256SHA256, RSASHA256
        $recommended = [15, 16];  // ED25519, ED448
        $optional = [14];         // ECDSAP384SHA384
        $notRecommended = [3, 5, 6, 7, 12]; // DSA+SHA1, RSASHA1, DSA-NSEC3-SHA1, RSASHA1-NSEC3-SHA1, ECC-GOST
        $deprecated = [1];        // RSAMD5
        $mustNotImplement = [0, 2, 4, 9, 10, 11]; // Reserved, DH, Reserved, Reserved, Reserved, Reserved

        if (in_array($algorithmInt, $mustImplement)) {
            // These are good, no warnings needed
        } elseif (in_array($algorithmInt, $recommended)) {
            // These are preferred
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is recommended for use according to RFC 8624. This is a good choice.');
        } elseif (in_array($algorithmInt, $optional)) {
            // Optional but still secure
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is optional for implementation according to RFC 8624.');
        } elseif (in_array($algorithmInt, $notRecommended)) {
            // Not recommended
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is NOT RECOMMENDED for use according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif (in_array($algorithmInt, $deprecated)) {
            // Deprecated
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is DEPRECATED according to RFC 8624. Do not use this algorithm for new deployments.');
        } elseif (in_array($algorithmInt, $mustNotImplement)) {
            // Must not implement
            $warnings[] = _('Algorithm ' . $algorithmInt . ' MUST NOT be implemented according to RFC 8624. This value should not be used.');
        }

        // Validate digest type (must be 1, 2, or 4)
        $validDigestTypes = [1, 2, 4];
        if (!is_numeric($digestType) || !in_array((int)$digestType, $validDigestTypes)) {
            return ValidationResult::failure(_('CDS digest type must be 1 (SHA-1), 2 (SHA-256), or 4 (SHA-384).'));
        }

        // Add warning for SHA-1 usage
        if ((int)$digestType === 1) {
            $warnings[] = _('SHA-1 (digest type 1) is deprecated for security reasons. Consider using SHA-256 (digest type 2) or SHA-384 (digest type 4) instead.');
        }

        // Validate digest (hex string)
        $expectedLength = 0;
        $digestTypeInt = (int)$digestType;

        // Set expected length based on digest type
        if ($digestTypeInt === 1) {
            // SHA-1: 40 hex chars
            $expectedLength = 40;
        } elseif ($digestTypeInt === 2) {
            // SHA-256: 64 hex chars
            $expectedLength = 64;
        } elseif ($digestTypeInt === 4) {
            // SHA-384: 96 hex chars
            $expectedLength = 96;
        }

        // Check if digest is a valid hex string of the expected length
        if (!ctype_xdigit($digest) || strlen($digest) !== $expectedLength) {
            return ValidationResult::failure(sprintf(_('CDS digest must be a valid hex string of length %d for the selected digest type.'), $expectedLength));
        }

        // Add warning about proper parent and child zone coordination
        $warnings[] = _('CDS records must be accompanied by matching CDNSKEY records as recommended by RFC 7344.');
        $warnings[] = _('CDS records should only be placed at the zone apex (not on subdomains).');

        return ValidationResult::success(['valid' => true], $warnings);
    }
}
