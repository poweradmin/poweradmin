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
 * SMIMEA (S/MIME Certificate Association) record validator
 *
 * Validates SMIMEA records according to:
 * - RFC 8162: Using Secure DNS to Associate Certificates with Domain Names for S/MIME
 *
 * SMIMEA records enable secure discovery and verification of S/MIME certificates
 * through DNS, with DNSSEC providing the trust mechanism.
 *
 * Format: <usage> <selector> <matching-type> <certificate-data>
 *
 * Example: 3 1 1 a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74
 *
 * Where:
 * - usage: Certificate usage type
 *   - 0 = PKIX-TA: CA constraint (trust anchor cert must be in validation path)
 *   - 1 = PKIX-EE: End entity cert constraint (cert must pass PKIX validation)
 *   - 2 = DANE-TA: Trust anchor assertion (trust anchor to be used for validation)
 *   - 3 = DANE-EE: Domain-issued certificate (cert used directly, not validated)
 *
 * - selector: Which part of the cert is matched
 *   - 0 = Full certificate (entire cert is matched)
 *   - 1 = SubjectPublicKeyInfo (only the public key is matched)
 *
 * - matching-type: How the cert data is matched
 *   - 0 = Exact match (no hash, full data)
 *   - 1 = SHA-256 hash (recommended)
 *   - 2 = SHA-512 hash
 *
 * - certificate-data: Hexadecimal representation of the data to match
 *
 * Domain name format:
 * - <SHA-256 hash of local-part>._smimecert.<domain part of email>
 * - Example: c93f1e400f26708f98cb19d936620da35eec8f72e57f9eec01c1afd6._smimecert.example.com
 *
 * Security considerations:
 * - SMIMEA records REQUIRE DNSSEC for any security benefit
 * - Without DNSSEC validation, SMIMEA offers no security advantage
 * - Usage type 3 (DANE-EE) with selector 1 (SubjectPublicKeyInfo) is recommended
 * - Use SHA-256 (matching type 1) for the best balance of security and compatibility
 * - IMPORTANT: RFC 8162 is currently an EXPERIMENTAL protocol
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SMIMEARecordValidator implements DnsRecordValidatorInterface
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
     * Validates SMIMEA record content
     *
     * @param string $content The content of the SMIMEA record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SMIMEA records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // For SMIMEA records with special format
        // Validate printable characters at minimum
        if (!StringValidator::isValidPrintable($name)) {
            return ValidationResult::failure(_('Invalid characters in hostname.'));
        }

        // SMIMEA records are typically of the form: <hash-of-localpart>._smimecert.<domain>
        // The local-part hash is the SHA-256 hash (hex encoded) of the local part of the email address
        if (strpos($name, '._smimecert.') !== false) {
            // This is the SMIMEA format, let's validate the hash portion
            $parts = explode('._smimecert.', $name, 2);
            $localPartHash = $parts[0];

            // Validate that the local part hash is properly formed (64 hex characters for SHA-256)
            if (!preg_match('/^[0-9a-fA-F]{64}$/', $localPartHash)) {
                $warnings[] = _('The local-part hash in SMIMEA records should be a 64-character SHA-256 hex hash. The format should be: SHA256(local-part)._smimecert.domain.tld');
            }

            // Add information about the format
            $warnings[] = _('SMIMEA record name is in the format: SHA256(local-part)._smimecert.domain.tld for email addresses like local-part@domain.tld');
        } else {
            // For non-SMIMEA format names, use regular hostname validation but add a warning
            $hostnameResult = $this->hostnameValidator->validate($name, true);
            if (!$hostnameResult->isValid()) {
                return $hostnameResult;
            }
            $hostnameData = $hostnameResult->getData();
            $name = $hostnameData['hostname'];

            $warnings[] = _('This does not use the standard SMIMEA name format. SMIMEA records should typically use: SHA256(local-part)._smimecert.domain.tld');
        }

        // Validate content
        $contentResult = $this->validateSMIMEAContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Include any warnings from content validation
        $contentData = $contentResult->getData();
        if (is_array($contentData) && isset($contentData['warnings']) && is_array($contentData['warnings'])) {
            $warnings = array_merge($warnings, $contentData['warnings']);
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // SMIMEA records don't use priority, so it should be 0
        if (isset($prio) && $prio !== "" && (!is_numeric($prio) || intval($prio) !== 0)) {
            return ValidationResult::failure(_('Invalid value for priority field. SMIMEA records must have priority value of 0.'));
        }

        // Add email security considerations warning
        $warnings[] = _('SMIMEA makes email addresses visible in DNS. Consider privacy implications when publishing SMIMEA records.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of a SMIMEA record
     * Format: <usage> <selector> <matching-type> <certificate-data>
     * Very similar to TLSA records
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateSMIMEAContent(string $content): ValidationResult
    {
        $warnings = [];

        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('SMIMEA record content cannot be empty.'));
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('SMIMEA record contains invalid characters.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('SMIMEA record must contain usage, selector, matching-type, and certificate-data separated by spaces.'));
        }

        [$usage, $selector, $matchingType, $certificateData] = $parts;

        // Validate usage field (0-3)
        // 0 = PKIX-TA: CA constraint
        // 1 = PKIX-EE: Service certificate constraint
        // 2 = DANE-TA: Trust anchor assertion
        // 3 = DANE-EE: Domain-issued certificate
        if (!is_numeric($usage) || !in_array((int)$usage, range(0, 3))) {
            return ValidationResult::failure(_('SMIMEA usage field must be a number between 0 and 3.'));
        }

        // Add warnings about usage types
        $usageInt = (int)$usage;
        if ($usageInt === 0) {
            $warnings[] = _('PKIX-TA usage (0) requires the CA certificate to be in the certificate validation path. Ensure this CA is publicly known and trusted.');
        } elseif ($usageInt === 1) {
            $warnings[] = _('PKIX-EE usage (1) requires the end entity certificate to be validated using PKIX validation rules. Ensure proper intermediate certificates are available.');
        } elseif ($usageInt === 2) {
            $warnings[] = _('DANE-TA usage (2) designates this certificate as a trust anchor for validating the end entity certificate. Ensure this certificate is properly secured.');
        } elseif ($usageInt === 3) {
            $warnings[] = _('DANE-EE usage (3) is recommended as it provides the simplest deployment with direct certificate usage without additional validation.');
        }

        // Validate selector field (0-1)
        // 0 = Full certificate
        // 1 = SubjectPublicKeyInfo
        if (!is_numeric($selector) || !in_array((int)$selector, range(0, 1))) {
            return ValidationResult::failure(_('SMIMEA selector field must be 0 (Full certificate) or 1 (SubjectPublicKeyInfo).'));
        }

        // Add recommendations for selector
        $selectorInt = (int)$selector;
        if ($selectorInt === 0) {
            $warnings[] = _('Selector 0 (Full certificate) means the entire certificate is matched. Using selector 1 (SubjectPublicKeyInfo) is recommended as it offers more flexibility for certificate renewals.');
        } elseif ($selectorInt === 1) {
            $warnings[] = _('Selector 1 (SubjectPublicKeyInfo) is recommended as it allows certificate renewal without SMIMEA record updates, as long as the public key stays the same.');
        }

        // Validate matching type field (0-2)
        // 0 = Exact match
        // 1 = SHA-256 hash
        // 2 = SHA-512 hash
        if (!is_numeric($matchingType) || !in_array((int)$matchingType, range(0, 2))) {
            return ValidationResult::failure(_('SMIMEA matching type field must be 0 (Exact match), 1 (SHA-256), or 2 (SHA-512).'));
        }

        // Add recommendations for matching type
        $matchingTypeInt = (int)$matchingType;
        if ($matchingTypeInt === 0) {
            $warnings[] = _('Matching type 0 (Exact match) uses raw certificate data. SHA-256 (type 1) is recommended for better performance and compatibility.');
        } elseif ($matchingTypeInt === 1) {
            $warnings[] = _('Matching type 1 (SHA-256) provides a good balance of security and efficiency.');
        } elseif ($matchingTypeInt === 2) {
            $warnings[] = _('Matching type 2 (SHA-512) provides the highest security but may have compatibility issues with some implementations.');
        }

        // Validate certificate data (must be a hexadecimal string)
        if (!preg_match('/^[0-9a-fA-F]+$/', $certificateData)) {
            return ValidationResult::failure(_('SMIMEA certificate data must be a hexadecimal string.'));
        }

        // Additional validation based on the matching type
        $length = strlen($certificateData);
        if ((int)$matchingType === 1 && $length !== 64) { // SHA-256 is 32 bytes (64 hex chars)
            return ValidationResult::failure(_('SMIMEA SHA-256 certificate data must be 64 characters long.'));
        } elseif ((int)$matchingType === 2 && $length !== 128) { // SHA-512 is 64 bytes (128 hex chars)
            return ValidationResult::failure(_('SMIMEA SHA-512 certificate data must be 128 characters long.'));
        }

        // Add recommended configuration warning
        if (!($usageInt === 3 && $selectorInt === 1 && $matchingTypeInt === 1)) {
            $warnings[] = _('Recommended SMIMEA configuration is usage=3 (DANE-EE), selector=1 (SubjectPublicKeyInfo), and matching-type=1 (SHA-256).');
        }

        // Add security warning that SMIMEA is experimental
        $warnings[] = _('WARNING: SMIMEA (RFC 8162) is currently an EXPERIMENTAL protocol and may not be widely supported.');

        // Add security warning about DNSSEC requirement
        $warnings[] = _('CRITICAL: SMIMEA records REQUIRE DNSSEC to provide security. Without DNSSEC, SMIMEA offers NO security benefit.');

        return ValidationResult::success([
            'result' => true,
            'warnings' => $warnings
        ]);
    }
}
