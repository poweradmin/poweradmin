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
 * RKEY (Resource KEY) record validator
 *
 * Validates RKEY records according to:
 * - Internet-Draft: draft-reid-dnsext-rkey-00 (The RKEY DNS Resource Record)
 * - RFC 4034: Format for the flags, protocol, and algorithm fields
 *
 * RKEY records are designed to store public keys used for encrypting DNS resource records,
 * primarily intended for encrypting NAPTR records, though they can be used more generally.
 * The format is borrowed from DNSKEY records defined in RFC 4034.
 *
 * Format: <flags> <protocol> <algorithm> <public-key>
 *
 * Example: 256 3 8 AwEAAbDIfjfFKkP0arI0+27YF8yJzt2+VM1NFRGMbl4dbExs+eK7
 *
 * Where:
 * - flags: 16-bit field (typically 256 or 257)
 *   - Bit 7 (Zone Key flag): If set, the key is a zone key
 *   - Bit 0 (SEP flag): If set, this is a secure entry point
 * - protocol: Must be 3 for backward compatibility
 * - algorithm: DNSSEC algorithm number (same as in RFC 4034)
 *   - 1 = RSA/MD5 (deprecated, RFC 6725)
 *   - 3 = DSA/SHA1 (insecure)
 *   - 5 = RSA/SHA-1 (insecure)
 *   - 7 = RSASHA1-NSEC3-SHA1
 *   - 8 = RSA/SHA-256 (recommended)
 *   - 10 = RSA/SHA-512
 *   - 13 = ECDSA Curve P-256 with SHA-256
 *   - 14 = ECDSA Curve P-384 with SHA-384
 *   - 15 = Ed25519
 *   - 16 = Ed448
 * - public-key: Base64 encoded public key data
 *
 * Important notes:
 * - This record was never formally standardized as an RFC
 * - Modern implementations rarely use RKEY records
 * - This record borrows format from DNSKEY but serves a different purpose
 * - Type code assignment was requested from IANA in the Internet-Draft
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class RKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates RKEY record content
     *
     * @param string $content The content of the RKEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // Validate the hostname format
        $nameResult = StringValidator::validatePrintable($name);
        if (!$nameResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in name field.'));
        }

        // Hostname validation for RKEY records
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

        // Validate the RKEY content
        $rkeyResult = $this->validateRKEYContent($content);
        if (!$rkeyResult->isValid()) {
            return $rkeyResult;
        }

        // Collect warnings from content validation
        $rkeyData = $rkeyResult->getData();
        if (is_array($rkeyData) && isset($rkeyData['warnings']) && is_array($rkeyData['warnings'])) {
            $warnings = array_merge($warnings, $rkeyData['warnings']);
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for RKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for RKEY records must be 0 or empty.'));
        }

        // Add general RKEY record usage warning
        $warnings[] = _('RKEY records should be used with caution as they are not widely supported by DNS servers and resolvers.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an RKEY record
     * Format: <flags> <protocol> <algorithm> <public key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors and warnings
     */
    private function validateRKEYContent(string $content): ValidationResult
    {
        $warnings = [];

        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('RKEY record content cannot be empty.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 4) {
            return ValidationResult::failure(_('RKEY record must contain flags, protocol, algorithm, and public key data.'));
        }

        [$flags, $protocol, $algorithm, $publicKey] = [$parts[0], $parts[1], $parts[2], implode(' ', array_slice($parts, 3))];

        // Validate flags field (must be a number)
        if (!is_numeric($flags)) {
            return ValidationResult::failure(_('RKEY flags field must be a numeric value.'));
        }

        // Check flags value and provide warnings
        $flagsValue = (int)$flags;

        // Common flag values are 256 (bit 7 set - Zone Key) and 257 (bits 7 and 0 set - Zone Key + SEP)
        if ($flagsValue !== 0 && $flagsValue !== 256 && $flagsValue !== 257) {
            $warnings[] = _('Unusual flags value. Common values are 0, 256 (Zone Key), or 257 (Zone Key + SEP).');
        }

        // Explain flag meaning
        if ($flagsValue & 256) { // Check if bit 7 is set (Zone Key flag)
            $warnings[] = _('Bit 7 (Zone Key flag) is set in the flags field, indicating this is a zone key.');
        }

        if ($flagsValue & 1) { // Check if bit 0 is set (SEP flag)
            $warnings[] = _('Bit 0 (SEP flag) is set in the flags field, indicating this is a secure entry point.');
        }

        // Validate protocol field (must be a number and should be 3)
        if (!is_numeric($protocol)) {
            return ValidationResult::failure(_('RKEY protocol field must be a numeric value.'));
        }

        $protocolValue = (int)$protocol;
        if ($protocolValue !== 3) {
            $warnings[] = _('Protocol field should be 3 for compliance with standards based on DNSKEY format.');
        }

        // Validate algorithm field (must be a number)
        if (!is_numeric($algorithm)) {
            return ValidationResult::failure(_('RKEY algorithm field must be a numeric value.'));
        }

        // Check algorithm value and provide warnings
        $algorithmValue = (int)$algorithm;
        if ($algorithmValue < 0 || $algorithmValue > 16) {
            return ValidationResult::failure(_('RKEY algorithm field must be a valid DNSSEC algorithm number (0-16).'));
        }

        // Add warnings about algorithm security
        if ($algorithmValue === 1) {
            $warnings[] = _('Algorithm 1 (RSA/MD5) is deprecated (RFC 6725) and should NOT be used.');
        } elseif ($algorithmValue === 3) {
            $warnings[] = _('Algorithm 3 (DSA/SHA1) is considered insecure and should be replaced with a stronger algorithm.');
        } elseif ($algorithmValue === 5) {
            $warnings[] = _('Algorithm 5 (RSA/SHA-1) is considered insecure and should be replaced with a stronger algorithm.');
        } elseif ($algorithmValue === 7) {
            $warnings[] = _('Algorithm 7 (RSASHA1-NSEC3-SHA1) uses SHA-1 which is no longer considered secure.');
        } elseif ($algorithmValue === 8) {
            $warnings[] = _('Algorithm 8 (RSA/SHA-256) is currently recommended for DNSSEC deployments.');
        } elseif ($algorithmValue === 10) {
            $warnings[] = _('Algorithm 10 (RSA/SHA-512) provides strong security.');
        } elseif ($algorithmValue === 13) {
            $warnings[] = _('Algorithm 13 (ECDSA Curve P-256 with SHA-256) provides good security with smaller signatures than RSA.');
        } elseif ($algorithmValue === 14) {
            $warnings[] = _('Algorithm 14 (ECDSA Curve P-384 with SHA-384) provides strong security with smaller signatures than RSA.');
        } elseif ($algorithmValue === 15) {
            $warnings[] = _('Algorithm 15 (Ed25519) offers modern security but may have compatibility issues with older validators.');
        } elseif ($algorithmValue === 16) {
            $warnings[] = _('Algorithm 16 (Ed448) offers strong modern security but may have compatibility issues with older validators.');
        }

        if ($algorithmValue < 8 && $algorithmValue != 7) {
            $warnings[] = _('CRITICAL: This algorithm is considered insecure. Use algorithm 8 (RSA/SHA-256) or later.');
        }

        // Validate the public key part
        if (empty(trim($publicKey))) {
            return ValidationResult::failure(_('RKEY public key data cannot be empty.'));
        }

        // Check if public key looks like Base64
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', trim($publicKey))) {
            $warnings[] = _('Public key data should be Base64 encoded according to RFC 4034.');
        }

        // Add general warnings about RKEY records
        $warnings[] = _('RKEY records were never formally standardized as an RFC, only proposed in draft-reid-dnsext-rkey-00.');
        $warnings[] = _('RKEY records are rarely used in modern DNS implementations.');
        $warnings[] = _('Consider using standardized record types for cryptographic purposes.');

        return ValidationResult::success([
            'result' => true,
            'warnings' => $warnings
        ]);
    }
}
