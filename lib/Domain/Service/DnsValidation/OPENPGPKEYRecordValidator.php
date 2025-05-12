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
 * OPENPGPKEY record validator
 *
 * Validates OPENPGPKEY records according to:
 * - RFC 7929: DNS-Based Authentication of Named Entities (DANE) Bindings for OpenPGP
 * - RFC 9580: OpenPGP Message Format (obsoletes RFC 4880)
 *
 * OPENPGPKEY records store OpenPGP public keys in DNS for email address verification
 * and encryption. These records are part of the DANE (DNS-Based Authentication of
 * Named Entities) standard for publishing public keys in DNS.
 *
 * Format: <base64-encoded-public-key>
 *
 * Example: mDMEXEcE6RYJKwYBBAHaRw8BAQdArjWwk3FAqyiFbFBKT4TzXcVBqPTB3gmzlC...
 *
 * Domain name format:
 * - <SHA-256 hash of local-part>._openpgpkey.<domain part of email>
 * - Example: c93f1e400f26708f98cb19d936620da35eec8f72e57f9eec01c1afd6._openpgpkey.example.com
 *
 * Where:
 * - The local part of the email address (before @) is hashed with SHA-256
 * - The hash is truncated to 28 octets (56 hex characters)
 * - The result is prepended to the label "_openpgpkey"
 * - The domain part of the email follows
 *
 * Security considerations:
 * - OPENPGPKEY records REQUIRE DNSSEC for any security benefit
 * - Without DNSSEC validation, OPENPGPKEY offers no security advantage
 * - OPENPGPKEY records are not a replacement for the OpenPGP Web of Trust
 * - Applications should use "minimal key export" format to keep records small
 * - Type code: 61 (IANA-assigned)
 * - OPENPGPKEY records are EXPERIMENTAL per RFC 7929
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class OPENPGPKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates OPENPGPKEY record content
     *
     * @param string $content The content of the OPENPGPKEY record (base64 encoded PGP public key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for OPENPGPKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // Validate the hostname format
        $printableResult = StringValidator::validatePrintable($name);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in hostname.'));
        }

        // Hostname validation for OPENPGPKEY records
        // OPENPGPKEY records are typically of the form: <hash-of-localpart>._openpgpkey.<domain>
        // But we'll allow regular FQDNs too
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check if this follows the standard OPENPGPKEY format
        if (!$this->isStandardOpenpgpkeyFormat($name)) {
            $warnings[] = _('The record name does not follow the standard OPENPGPKEY format (<hash>._openpgpkey.<domain>). Standard format is recommended for interoperability.');
        }

        // Validate content
        $contentResult = $this->validateOpenPGPKeyContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Collect warnings from content validation
        $contentData = $contentResult->getData();
        if (is_array($contentData) && isset($contentData['warnings']) && is_array($contentData['warnings'])) {
            $warnings = array_merge($warnings, $contentData['warnings']);
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for OPENPGPKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for OPENPGPKEY records must be 0 or empty'));
        }

        // Add warning about creating these records manually
        $warnings[] = _('OPENPGPKEY records should typically be generated using specialized tools like GnuPG with the --print-dane-records option, rather than created manually.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Checks if a domain name follows the standard OPENPGPKEY format
     *
     * @param string $name Domain name to check
     * @return bool True if it follows the format, false otherwise
     */
    private function isStandardOpenpgpkeyFormat(string $name): bool
    {
        // RFC 7929 specifies the format: <hash>._openpgpkey.<domain>
        // The hash should be a 56-character hex string (28 bytes of SHA-256)
        if (preg_match('/^[0-9a-f]{56}\._openpgpkey\..+$/i', $name)) {
            return true;
        }

        // Check for the label even if the hash doesn't match exact length
        if (strpos($name, '._openpgpkey.') !== false) {
            // Extract the part before ._openpgpkey.
            $parts = explode('._openpgpkey.', $name);
            if (!empty($parts[0])) {
                $hashPart = $parts[0];
                // If hash part exists but doesn't match 56 chars, it might be non-standard
                if (strlen($hashPart) != 56 || !preg_match('/^[0-9a-f]+$/i', $hashPart)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Validates the content of an OPENPGPKEY record
     * Content should be base64 encoded data representing an OpenPGP public key
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors, warnings or success
     */
    private function validateOpenPGPKeyContent(string $content): ValidationResult
    {
        $warnings = [];

        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('OPENPGPKEY record content cannot be empty.'));
        }

        // Check for valid printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in OPENPGPKEY record content.'));
        }

        // OPENPGPKEY records store data in base64 format
        // We'll do a basic validation that it consists of valid base64 characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $content)) {
            return ValidationResult::failure(_('OPENPGPKEY records must contain valid base64-encoded data.'));
        }

        // Verify that it's valid base64
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('OPENPGPKEY record contains invalid base64 data.'));
        }

        // Check the size - large DNS records can cause issues
        // RFC 7929 section 5 recommends small keys for DNS transport
        $contentLength = strlen($content);
        if ($contentLength > 4096) {
            $warnings[] = _('OPENPGPKEY record exceeds 4KB in size. RFC 7929 recommends keeping DNS records as small as possible to ensure reliable transport. Consider using a minimal key export format.');
        } elseif ($contentLength < 100) {
            $warnings[] = _('OPENPGPKEY record is unusually small. Verify that this is a valid OpenPGP public key.');
        }

        // More comprehensive check for OpenPGP format (RFC 9580)
        if (strlen($decoded) > 3) {
            $firstByte = ord($decoded[0]);

            // OpenPGP packets must start with a byte that has the high bit (bit 7) set
            if (($firstByte & 0x80) != 0x80) {
                $warnings[] = _('The decoded content does not appear to be a valid OpenPGP packet. OpenPGP packets must start with a byte that has the high bit set (RFC 9580).');
            } else {
                // Check packet tag format (old or new)
                $isNewFormat = ($firstByte & 0x40) == 0x40;

                if ($isNewFormat) {
                    // New format packet (bits 5-0 are the packet tag)
                    $packetTag = $firstByte & 0x3F;

                    // Verify the packet tag is for a public key (tag 6) or public subkey (tag 14)
                    if ($packetTag != 6 && $packetTag != 14) {
                        $warnings[] = _('The decoded content does not appear to contain a public key packet (tag 6) or public subkey packet (tag 14) as its first packet.');
                    }
                } else {
                    // Old format packet (bits 5-2 are the packet tag)
                    $packetTag = ($firstByte & 0x3C) >> 2;

                    // Verify the packet tag is for a public key (tag 6) or public subkey (tag 14)
                    if ($packetTag != 6 && $packetTag != 14) {
                        $warnings[] = _('The decoded content does not appear to contain a public key packet (tag 6) or public subkey packet (tag 14) as its first packet.');
                    }
                }
            }
        }

        // Add general OPENPGPKEY record warnings
        $warnings[] = _('OPENPGPKEY records REQUIRE DNSSEC for any security benefit. Without DNSSEC validation, these records offer no security advantage.');
        $warnings[] = _('OPENPGPKEY records are EXPERIMENTAL as per RFC 7929 and may not be supported by all email clients or OpenPGP implementations.');
        $warnings[] = _('OPENPGPKEY records are not a replacement for the OpenPGP Web of Trust. Additional verification methods are still recommended.');
        $warnings[] = _('To create OPENPGPKEY records, use specialized tools like GnuPG with the --print-dane-records option to ensure correct format and content.');

        return ValidationResult::success([
            'result' => true,
            'warnings' => $warnings
        ]);
    }
}
