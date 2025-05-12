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
 * NSEC record validator
 *
 * Validates NSEC (Next SECure) records according to:
 * - RFC 4034: Resource Records for the DNS Security Extensions
 * - RFC 3845: DNS Security (DNSSEC) NextSECure (NSEC) RDATA Format
 * - RFC 4035: Protocol Modifications for the DNS Security Extensions
 *
 * NSEC records are used in DNSSEC to provide authenticated denial of existence.
 * They form a chain of all domain names in a zone, proving which names exist and
 * which do not. Each NSEC record contains:
 *
 * 1. Next Domain Name: The next owner name in canonical ordering of the zone
 * 2. Type Bit Maps: The set of RR types present at the NSEC RR's owner name
 *
 * Format: next-domain-name [type-bit-maps]
 * Example: example.com. A NS SOA MX TXT AAAA
 *
 * Security considerations:
 * - NSEC records enable "zone walking" (discovering all names in a zone)
 * - For privacy concerns, NSEC3 (RFC 5155) can be used as an alternative
 * - NSEC records should have the same TTL as the SOA minimum TTL
 *
 * Type code: 47
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSECRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate an NSEC record
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for NSEC records)
     * @param int|string $ttl The TTL value
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

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('NSEC record content cannot be empty.'));
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('NSEC record contains invalid characters.'));
        }

        // Check NSEC record format
        $contentResult = $this->validateNsecContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // NSEC records don't use priority, so it's always 0
        $priority = 0;

        // RFC 4034 recommends that NSEC records have the same TTL as the SOA minimum TTL
        $warnings[] = _('According to RFC 4034, NSEC records should have the same TTL as the SOA minimum TTL field.');

        // Security warnings about NSEC records
        $warnings[] = _('NSEC records enable "zone walking", allowing enumeration of all names in a zone, which may be a privacy concern.');
        $warnings[] = _('If zone enumeration is a concern, consider using NSEC3 records (RFC 5155) instead, which use hashed owner names.');
        $warnings[] = _('NSEC records are part of DNSSEC and should only be managed alongside other DNSSEC records (DNSKEY, RRSIG, etc.).');
        $warnings[] = _('Manually editing NSEC records is not recommended as they are typically generated automatically by DNSSEC-aware nameservers.');

        // Check for potential wildcard handling
        if (strpos($name, '*') !== false || strpos($content, '*') !== false) {
            $warnings[] = _('Wildcard names in NSEC records require special handling. According to RFC 4035, wildcard owner names appear in NSEC records without expansion.');
        }

        // Collect warnings from content validation if any
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate NSEC record content format according to RFC 4034
     *
     * NSEC content should have:
     * 1. A valid next domain name (in canonical ordering of the zone)
     * 2. Optionally followed by type bit maps (list of RR types present at the NSEC owner name)
     *
     * @param string $content The NSEC record content
     * @return ValidationResult ValidationResult object
     */
    private function validateNsecContent(string $content): ValidationResult
    {
        $warnings = [];
        $parts = preg_split('/\s+/', trim($content), 2);

        // Check that next domain name is valid
        $nextDomainName = $parts[0];
        $hostnameResult = $this->hostnameValidator->validate($nextDomainName, true);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::failure(_('NSEC record must contain a valid next domain name.'));
        }

        // As per RFC 4034, the next domain name should be in canonical ordering of the zone
        // We can't validate this in isolation, but we can add a warning
        $warnings[] = _('The next domain name field must be the next owner name in canonical ordering of the zone. Canonical ordering is case-insensitive and sorts by labels from right to left.');

        // Special handling of the last NSEC record in the zone
        if (isset($parts[1]) && strpos($parts[1], 'SOA') !== false) {
            $warnings[] = _('If this is the last NSEC record in the zone, the next domain name should be the zone apex (the owner name of the zone\'s SOA RR).');
        }

        // If type bit maps are present, validate them
        if (isset($parts[1])) {
            $typeBitMaps = $parts[1];
            $typeBitMapsResult = $this->validateTypeBitMaps($typeBitMaps);
            if (!$typeBitMapsResult->isValid()) {
                return $typeBitMapsResult;
            }

            // Collect warnings from type bit maps validation
            if ($typeBitMapsResult->hasWarnings()) {
                $warnings = array_merge($warnings, $typeBitMapsResult->getWarnings());
            }
        } else {
            // Type bit maps should be present to indicate which types exist
            $warnings[] = _('Type bit maps should be present to indicate which RR types exist at the NSEC owner name. An empty type bitmap is unusual.');
        }

        return ValidationResult::success([
            'next_domain' => $nextDomainName,
            'type_maps' => $parts[1] ?? ''
        ], $warnings);
    }

    /**
     * Validate the type bit maps part of an NSEC record according to RFC 4034
     *
     * In the presentation format, the Type Bit Maps field is represented as a sequence of RR type mnemonics.
     * When the mnemonic is not known, the TYPE representation described in RFC 3597 should be used.
     *
     * @param string $typeBitMaps The type bit maps part of the NSEC record
     * @return ValidationResult ValidationResult object
     */
    private function validateTypeBitMaps(string $typeBitMaps): ValidationResult
    {
        $warnings = [];

        // Type bit maps should contain valid record types
        $validRecordTypes = [
            'A', 'AAAA', 'AFSDB', 'APL', 'CAA', 'CDNSKEY', 'CDS', 'CERT', 'CNAME', 'DHCID',
            'DLV', 'DNAME', 'DNSKEY', 'DS', 'EUI48', 'EUI64', 'HINFO', 'HTTPS', 'IPSECKEY',
            'KEY', 'KX', 'LOC', 'MX', 'NAPTR', 'NS', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'OPENPGPKEY',
            'PTR', 'RRSIG', 'SOA', 'SPF', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI'
        ];

        $types = preg_split('/\s+/', trim($typeBitMaps));

        // Check for common NSEC record type combinations
        $hasNsec = false;
        $hasRrsig = false;
        $hasSoa = false;
        $hasNs = false;

        foreach ($types as $type) {
            // Skip if the type is numeric (some representations use numeric type codes)
            if (is_numeric($type)) {
                // RFC 3597 format is TYPE### for unknown types
                if (!preg_match('/^TYPE\d+$/i', $type)) {
                    $warnings[] = sprintf(_('Numeric type "%s" should be represented using the RFC 3597 format (TYPE###).'), $type);
                }
                continue;
            }

            // If type has additional parameters in parentheses, extract just the type
            if (str_contains($type, '(')) {
                $type = trim(substr($type, 0, strpos($type, '(')));
            }

            $upperType = strtoupper($type);

            // Track specific types for validation
            if ($upperType === 'NSEC') {
                $hasNsec = true;
            } elseif ($upperType === 'RRSIG') {
                $hasRrsig = true;
            } elseif ($upperType === 'SOA') {
                $hasSoa = true;
            } elseif ($upperType === 'NS') {
                $hasNs = true;
            }

            if (!in_array($upperType, $validRecordTypes)) {
                // Check if it follows RFC 3597 format (TYPE###)
                if (preg_match('/^TYPE\d+$/i', $upperType)) {
                    // Valid TYPE format
                    continue;
                }
                return ValidationResult::failure(sprintf(_('NSEC record contains an invalid record type: %s'), $type));
            }
        }

        // DNSSEC-specific type checks
        if (!$hasNsec) {
            $warnings[] = _('NSEC type should typically be present in its own type bit map, as each name in a signed zone should have an NSEC record.');
        }

        if (!$hasRrsig) {
            $warnings[] = _('RRSIG type should typically be present in NSEC type bit maps for DNSSEC-signed zones.');
        }

        // Zone apex checks
        if ($hasSoa && $hasNs) {
            $warnings[] = _('This NSEC record contains both SOA and NS types, indicating it might be for a zone apex.');
        }

        return ValidationResult::success([
            'types' => $types
        ], $warnings);
    }
}
