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
 * Note: The KEY record type has been obsoleted by RFC 4034 and replaced by the DNSKEY
 * record type for DNS Security Extensions (DNSSEC). New deployments should use DNSKEY instead.
 *
 * The KEY record was originally designed to store public keys for use with various security
 * protocols. Its structure allows for storing different types of keys with associated
 * protocol information.
 *
 * Format: <flags> <protocol> <algorithm> <public key>
 *
 * Where:
 * - flags: A 16-bit unsigned integer (0-65535) containing various bit flags
 *   Common flag values:
 *   - 0: Use of the key is prohibited for authentication
 *   - 256 (0x0100): Key is not for use with DNSSEC (i.e., a user key)
 *   - 257 (0x0101): Key is a DNSSEC zone key
 * - protocol: An 8-bit integer (0-255) indicating the protocol for which the key is used
 *   - 3: DNSSEC (most common)
 * - algorithm: An 8-bit integer (0-255) identifying the public key's cryptographic algorithm
 *   Common algorithm values:
 *   - 1: RSA/MD5
 *   - 2: Diffie-Hellman
 *   - 3: DSA/SHA1
 *   - 5: RSA/SHA-1
 *   - 8: RSA/SHA-256
 * - public key: Base64 encoded key material
 *
 * Example: 256 3 5 AQPSKmynfzW4kyBv015MUG2DeIQ3Cbl+BBZH4b/0PY1kxkmvHjcZc8nocffttoalYz93wXFSYqO0mx8LoMQ3XDHLcuq5K2bNiLFuhz5ty9d/GSDUDtl74bQBrUu/zW5tOQ==
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2535 RFC 2535: Domain Name System Security Extensions (Original KEY Record Definition)
 * @see https://datatracker.ietf.org/doc/html/rfc3445 RFC 3445: Limiting the Scope of the KEY Resource Record
 * @see https://datatracker.ietf.org/doc/html/rfc4034 RFC 4034: Resource Records for the DNS Security Extensions (Obsoletes KEY with DNSKEY)
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
        $contentResult = $this->validateKEYContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get warnings from content validation
        $contentData = $contentResult->getData();
        $contentWarnings = [];
        if (isset($contentData['warnings']) && is_array($contentData['warnings'])) {
            $contentWarnings = $contentData['warnings'];
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

        // Create warnings about the obsolete status of KEY records
        $warnings = [
            _('KEY records have been obsoleted by RFC 4034. For DNSSEC, use DNSKEY records instead.'),
            _('KEY records may not be supported by all DNS servers and resolvers.')
        ];

        // Extract key information for specific warnings
        $parts = preg_split('/\s+/', trim($content));
        $flags = (int)($parts[0] ?? 0);
        $protocol = (int)($parts[1] ?? 0);
        $algorithm = (int)($parts[2] ?? 0);

        // Add algorithm-specific warnings
        if ($algorithm === 1) { // RSA/MD5
            $warnings[] = _('Algorithm 1 (RSA/MD5) is considered cryptographically weak and should not be used.');
        }

        // Add flag-specific warnings
        if ($flags === 257 && $protocol === 3) {
            $warnings[] = _('For DNSSEC zone keys (flags=257, protocol=3), DNSKEY records should be used instead of KEY records.');
        }

        // Add warnings from content validation
        $warnings = array_merge($warnings, $contentWarnings);

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of a KEY record according to RFC 2535 and RFC 3445
     * Format: <flags> <protocol> <algorithm> <public key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2535 RFC 2535
     * @see https://datatracker.ietf.org/doc/html/rfc3445 RFC 3445
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
        $warnings = [];

        // Validate flags (0-65535) - 16-bit unsigned integer per RFC 2535
        if (!is_numeric($flags) || (int)$flags < 0 || (int)$flags > 65535) {
            return ValidationResult::failure(_('KEY flags must be a number between 0 and 65535.'));
        }

        // Add flag-specific validation and warnings
        $flagsInt = (int)$flags;
        // Check for specific flag values defined in RFC 2535 and RFC 3445
        if ($flagsInt !== 0 && $flagsInt !== 256 && $flagsInt !== 257) {
            $warnings[] = _('Unusual flag value. Common values are 0 (authentication prohibited), 256 (user key), or 257 (zone key).');
        }

        // Validate protocol (0-255) - 8-bit unsigned integer per RFC 2535
        if (!is_numeric($protocol) || (int)$protocol < 0 || (int)$protocol > 255) {
            return ValidationResult::failure(_('KEY protocol must be a number between 0 and 255.'));
        }

        // Add protocol-specific validation and warnings
        $protocolInt = (int)$protocol;
        if ($protocolInt !== 3) {
            $warnings[] = _('Unusual protocol value. Protocol 3 (DNSSEC) is most common for KEY records.');
        }

        // Validate algorithm (0-255) - 8-bit unsigned integer per RFC 2535 and updates
        if (!is_numeric($algorithm) || (int)$algorithm < 0 || (int)$algorithm > 255) {
            return ValidationResult::failure(_('KEY algorithm must be a number between 0 and 255.'));
        }

        // Add algorithm-specific warnings
        $algorithmInt = (int)$algorithm;
        if (!in_array($algorithmInt, [1, 2, 3, 5, 8, 10])) {
            $warnings[] = _('Unusual algorithm value. Common values are 1 (RSA/MD5), 2 (DH), 3 (DSA), 5 (RSA/SHA-1), 8 (RSA/SHA-256).');
        }

        // Validate public key (base64 format)
        if (empty($publicKey)) {
            return ValidationResult::failure(_('KEY public key is required.'));
        }

        // Validate that the public key is properly base64 encoded
        // Remove spaces first, as they are allowed in KEY public keys
        $cleanPublicKey = str_replace(' ', '', $publicKey);
        if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $cleanPublicKey)) {
            return ValidationResult::failure(_('KEY public key must be properly Base64 encoded.'));
        }

        return ValidationResult::success([
            'valid' => true,
            'warnings' => $warnings
        ]);
    }
}
