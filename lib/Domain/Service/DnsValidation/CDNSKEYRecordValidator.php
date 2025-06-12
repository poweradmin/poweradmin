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
 * CDNSKEY record validator
 *
 * The CDNSKEY (Child DNSKEY) record is defined in RFC 7344 for automating DNSSEC
 * delegation trust maintenance. It allows a child zone to signal to its parent what
 * DS records it would like the parent to publish.
 *
 * Format: <flags> <protocol> <algorithm> <public-key>
 *
 * Where:
 * - flags: 0, 256, or 257 (determines if the key is a Zone Key and/or Secure Entry Point)
 * - protocol: Must be 3 as per RFC 4034
 * - algorithm: DNSSEC algorithm number (1-16 currently defined in RFC 8624)
 * - public-key: Base64 encoded public key
 *
 * Special case: For deletion, the content "0 3 0 AA==" is used as per RFC 8078.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7344 - Automating DNSSEC Delegation Trust Maintenance
 * @see https://datatracker.ietf.org/doc/html/rfc8078 - Managing DS Records from the Parent via CDS/CDNSKEY
 * @see https://datatracker.ietf.org/doc/html/rfc8624 - Algorithm Implementation Requirements and Usage Guidance for DNSSEC
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CDNSKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CDNSKEY record content
     *
     * @param string $content The content of the CDNSKEY record (flags protocol algorithm public-key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CDNSKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Check if the record is used in a non-standard location
        // CDNSKEY records are normally published at the zone apex
        $nameParts = explode('.', $name);
        if (count($nameParts) > 2 && !in_array($nameParts[0], ['_dnskey', 'cdnskey'])) {
            $warnings[] = _('CDNSKEY records are typically published at the zone apex, not in subdomains.');
        }

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateCDNSKEYContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Check for warnings from content validation
        if ($contentResult->isValid() && $contentResult->hasWarnings()) {
            $contentWarnings = $contentResult->getWarnings();
            if (!empty($contentWarnings)) {
                $warnings = array_merge($warnings, $contentWarnings);
            }
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for CDNSKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for CDNSKEY records must be 0 or empty'));
        }

        // Add RFC 7344 recommendation for CDS records
        $warnings[] = _('RFC 7344 recommends that if you publish a CDNSKEY record, you should also publish a corresponding CDS record.');

        $result = [
            'content' => $content,
            'name' => $name,
            'prio' => 0, // CDNSKEY records don't use priority
            'ttl' => $validatedTtl
        ];

        return ValidationResult::success($result, $warnings);
    }

    /**
     * Validates the content of a CDNSKEY record
     * Format: <flags> <protocol> <algorithm> <public-key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateCDNSKEYContent(string $content): ValidationResult
    {
        $warnings = [];

        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in CDNSKEY record content.'));
        }

        // Special case for delete CDNSKEY record (RFC 8078)
        if (trim($content) === '0 3 0 AA==') {
            // This is the standard deletion format
            return ValidationResult::success(true);
        }

        // Check for erratum format for delete CDNSKEY record
        if (trim($content) === '0 3 0 0') {
            // This is the format from RFC 8078 erratum
            return ValidationResult::success(
                true,
                [_('Using "0 3 0 0" format from RFC 8078 erratum, consider using the standard "0 3 0 AA==" format.')]
            );
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('CDNSKEY record must contain flags, protocol, algorithm and public-key separated by spaces.'));
        }

        [$flags, $protocol, $algorithm, $publicKey] = $parts;

        // Validate flags (must be 0, 256, or 257)
        if (!is_numeric($flags) || !in_array((int)$flags, [0, 256, 257])) {
            return ValidationResult::failure(_('CDNSKEY flags must be 0, 256, or 257.'));
        }

        // Add warning for KSK (flag 257) as per RFC 8080
        if ((int)$flags === 257) {
            $warnings[] = _('Flag 257 indicates a Key Signing Key (KSK). Ensure this key is properly managed according to your DNSSEC key rollover policy.');
        }

        // Validate protocol (must be 3)
        if (!is_numeric($protocol) || (int)$protocol !== 3) {
            return ValidationResult::failure(_('CDNSKEY protocol must be 3.'));
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('CDNSKEY algorithm must be a number between 1 and 16.'));
        }

        // Add warnings based on algorithm recommendations in RFC 8624
        $algorithmInt = (int)$algorithm;
        if (in_array($algorithmInt, [1, 3, 5, 6, 7, 12])) {
            // These are deprecated or not recommended algorithms
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is deprecated or not recommended according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif ($algorithmInt === 8) {
            // RSA/SHA-256 is being replaced
            $warnings[] = _('Algorithm 8 (RSASHA256) is being replaced with ECDSAP256SHA256 (13) due to shorter key and signature size, resulting in smaller DNS packets.');
        } elseif ($algorithmInt === 10) {
            // RSA/SHA-512 is not recommended
            $warnings[] = _('Algorithm 10 (RSASHA512) is NOT RECOMMENDED for signing although it must be supported for validation.');
        }

        // Validate public key (must be valid base64-encoded data)
        $base64Result = $this->validateBase64($publicKey);
        if (!$base64Result->isValid()) {
            return $base64Result;
        }

        // Check for base64 validity
        if (substr($publicKey, -1) !== '=' && strlen($publicKey) % 4 !== 0) {
            $warnings[] = _('Base64 encoded data should be padded to a multiple of 4 bytes using "=" characters.');
        }

        return ValidationResult::success(['isValid' => true], $warnings);
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateBase64(string $data): ValidationResult
    {
        // Basic pattern for base64-encoded data
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('CDNSKEY public key must contain only valid base64 characters.'));
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('CDNSKEY public key must be valid base64-encoded data.'));
        }

        return ValidationResult::success(true);
    }
}
