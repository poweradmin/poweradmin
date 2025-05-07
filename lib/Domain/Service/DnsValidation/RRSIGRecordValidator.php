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
 * RRSIG (Resource Record Signature) record validator for DNSSEC
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class RRSIGRecordValidator implements DnsRecordValidatorInterface
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
     * Validates RRSIG record content
     *
     * @param string $content The content of the RRSIG record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RRSIG records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate the hostname format
        if (!StringValidator::isValidPrintable($name)) {
            return ValidationResult::failure(_('Hostname contains invalid characters.'));
        }

        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateRRSIGContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for RRSIG records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for RRSIG records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // RRSIG records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an RRSIG record
     * Format: <covered-type> <algorithm> <labels> <orig-ttl> <sig-expiration> <sig-inception> <key-tag> <signer's-name> <signature>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateRRSIGContent(string $content): ValidationResult
    {
        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('RRSIG record content cannot be empty.'));
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('RRSIG record contains invalid characters.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 9) {
            return ValidationResult::failure(_('RRSIG record must contain covered-type, algorithm, labels, original TTL, expiration, inception, key tag, signer name and signature.'));
        }

        [$coveredType, $algorithm, $labels, $origTtl, $expiration, $inception, $keyTag, $signerName] = array_slice($parts, 0, 8);
        $signature = implode(' ', array_slice($parts, 8));

        // Validate covered type (should be a valid DNS record type)
        if (!$this->isValidDnsRecordType($coveredType)) {
            return ValidationResult::failure(_('RRSIG covered type must be a valid DNS record type.'));
        }

        // Validate algorithm (must be numeric)
        if (!is_numeric($algorithm)) {
            return ValidationResult::failure(_('RRSIG algorithm field must be a numeric value.'));
        }

        // Validate labels (must be numeric)
        if (!is_numeric($labels)) {
            return ValidationResult::failure(_('RRSIG labels field must be a numeric value.'));
        }

        // Validate original TTL (must be numeric)
        if (!is_numeric($origTtl)) {
            return ValidationResult::failure(_('RRSIG original TTL field must be a numeric value.'));
        }

        // Validate expiration time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $expiration)) {
            return ValidationResult::failure(_('RRSIG expiration must be in YYYYMMDDHHmmSS format.'));
        }

        // Validate inception time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $inception)) {
            return ValidationResult::failure(_('RRSIG inception must be in YYYYMMDDHHmmSS format.'));
        }

        // Validate key tag (must be numeric)
        if (!is_numeric($keyTag)) {
            return ValidationResult::failure(_('RRSIG key tag field must be a numeric value.'));
        }

        // Validate signer's name (must be a valid domain name ending with a dot)
        if (!str_ends_with($signerName, '.')) {
            return ValidationResult::failure(_('RRSIG signer name must be a fully qualified domain name (end with a dot).'));
        }

        // Validate signature (must not be empty)
        if (empty(trim($signature))) {
            return ValidationResult::failure(_('RRSIG signature cannot be empty.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Validates if a string is a valid DNS record type
     *
     * @param string $recordType The record type to check
     * @return bool True if valid, false otherwise
     */
    private function isValidDnsRecordType(string $recordType): bool
    {
        // List of common DNS record types
        $validTypes = [
            'A', 'AAAA', 'AFSDB', 'APL', 'CAA', 'CDNSKEY', 'CDS', 'CERT', 'CNAME', 'DHCID',
            'DLV', 'DNAME', 'DNSKEY', 'DS', 'EUI48', 'EUI64', 'HINFO', 'HTTPS', 'IPSECKEY',
            'KEY', 'KX', 'LOC', 'MX', 'NAPTR', 'NS', 'NSEC', 'NSEC3', 'NSEC3PARAM',
            'OPENPGPKEY', 'PTR', 'RKEY', 'RP', 'RRSIG', 'SMIMEA', 'SOA', 'SPF', 'SRV',
            'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI', 'ZONEMD'
        ];

        return in_array(strtoupper($recordType), $validTypes);
    }
}
