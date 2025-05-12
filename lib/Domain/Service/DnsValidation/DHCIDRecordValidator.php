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
 * DHCID (Dynamic Host Configuration Identifier) record validator
 *
 * DHCID records are defined in RFC 4701 and used to associate DNS names with DHCP clients.
 * They help to prevent conflicts when multiple DHCP clients attempt to update the same name.
 *
 * The structure of a DHCID record (in its binary form) consists of:
 * - A 2-octet identifier type code (in network byte order)
 * - A 1-octet digest type code
 * - The digest data (varies in length, typically a 32 octet SHA-256 hash)
 *
 * When represented in DNS master files, the binary RDATA is encoded using base64.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4701 RFC 4701: A DNS Resource Record for Encoding DHCP Information
 * @see https://datatracker.ietf.org/doc/html/rfc4703 RFC 4703: Resolution of FQDN Conflicts among DHCP Clients
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DHCIDRecordValidator implements DnsRecordValidatorInterface
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
     * Validates DHCID record content according to RFC 4701
     *
     * @param string $content The content of the DHCID record (base64-encoded data)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for DHCID records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
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

        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in DHCID record content.'));
        }

        // Validate content - DHCID should be a base64-encoded string with specific structure
        $base64Result = $this->validateBase64($content);
        if (!$base64Result->isValid()) {
            return $base64Result;
        }

        // Collect warnings from base64 validation
        if ($base64Result->hasWarnings()) {
            $warnings = array_merge($warnings, $base64Result->getWarnings());
        }

        // Check if the record name might be an A/AAAA record name
        // DHCID records should be placed at the same name as the A/AAAA records they protect
        $nameParts = explode('.', $name);
        if (count($nameParts) > 2 && !is_numeric($nameParts[0])) {
            // This looks like a hostname, add a specific note about placement
            $warnings[] = _('As per RFC 4701 and RFC 4703, DHCID records should be placed at the same name as the A or AAAA records they correspond to.');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for DHCID records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DHCID records must be 0 or empty.'));
        }

        // Add warning about collision detection
        $warnings[] = _('Per RFC 4703, DHCID records are used for collision detection when multiple DHCP clients claim the same name. The presence of a DHCID record indicates the name is in use by a DHCP client.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // DHCID records don't use priority
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates DHCID record content according to RFC 4701
     *
     * DHCID record content must be base64-encoded binary data with specific structure:
     * - 2 octets: identifier type code (0x0000, 0x0001, or 0x0002)
     * - 1 octet: digest type code (1 for SHA-256)
     * - Remaining octets: digest data (32 octets for SHA-256)
     *
     * @param string $data The base64-encoded DHCID content
     * @return ValidationResult ValidationResult with errors or success, and warnings if applicable
     */
    private function validateBase64(string $data): ValidationResult
    {
        $warnings = [];

        // Check for valid base64 characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ValidationResult::failure(_('DHCID record must contain only valid base64 characters (A-Z, a-z, 0-9, +, /, =).'));
        }

        // Check if padding is correct (if present)
        if (strpos($data, '=') !== false) {
            if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $data)) {
                return ValidationResult::failure(_('DHCID record has invalid base64 padding. Equals signs (=) should only appear at the end of the data.'));
            }
        }

        // Verify the length is a multiple of 4 (possibly with padding)
        $unpadded = rtrim($data, '=');
        $padding = strlen($data) - strlen($unpadded);

        if (($padding > 2) || ((strlen($unpadded) + $padding) % 4 !== 0)) {
            return ValidationResult::failure(_('DHCID record has invalid base64 length. Base64 data must be a multiple of 4 characters (with padding).'));
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ValidationResult::failure(_('DHCID record must contain valid base64-encoded data.'));
        }

        // According to RFC 4701, the minimum length is 35 bytes:
        // - 2 bytes for identifier type
        // - 1 byte for digest type
        // - 32 bytes for SHA-256 hash (minimum)
        $decodedLength = strlen($decoded);
        if ($decodedLength < 3) {
            return ValidationResult::failure(_('DHCID record is too short. The decoded data must contain at least 3 bytes (type codes and digest).'));
        }

        // Get the identifier type code (first 2 bytes)
        $identifierType = unpack('n', substr($decoded, 0, 2))[1]; // 'n' = unsigned short (16 bit), big-endian (network) byte order

        // Validate identifier type code (RFC 4701 defines 0x0000, 0x0001, and 0x0002)
        $validIdentifierTypes = [0x0000, 0x0001, 0x0002];
        if (!in_array($identifierType, $validIdentifierTypes)) {
            $warnings[] = sprintf(_('DHCID identifier type 0x%04X is not one of the standard types defined in RFC 4701 (0x0000, 0x0001, 0x0002).'), $identifierType);
        }

        // Get the digest type code (3rd byte)
        $digestType = ord($decoded[2]);

        // RFC 4701 only specifies digest type 1 (SHA-256)
        if ($digestType !== 1) {
            $warnings[] = sprintf(_('DHCID digest type %d is not the standard type (1 for SHA-256) defined in RFC 4701.'), $digestType);
        }

        // If digest type is 1 (SHA-256), then we should have 32 bytes of digest data
        if ($digestType === 1 && $decodedLength < 35) {
            return ValidationResult::failure(_('DHCID record with SHA-256 digest (type 1) must be at least 35 bytes when decoded (2 for identifier type, 1 for digest type, 32 for SHA-256 digest).'));
        }

        // Add general security warnings
        $warnings[] = _('DHCID records should be updated only by authorized DHCP clients or servers to prevent conflicts and unauthorized DNS updates.');
        $warnings[] = _('Consider using DNSSEC or TSIG authentication (RFC 3007) for secure DHCID updates as recommended by RFC 4701.');

        return ValidationResult::success(true, $warnings);
    }
}
