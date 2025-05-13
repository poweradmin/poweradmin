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
 * NSEC3 record validator
 *
 * Validates NSEC3 (Next SECure version 3) records according to:
 * - RFC 5155: DNS Security (DNSSEC) Hashed Authenticated Denial of Existence
 * - RFC 9276: Guidance for NSEC3 Parameter Settings (Best Current Practice)
 * - RFC 9077: NSEC and NSEC3: TTLs and Aggressive Use
 *
 * NSEC3 records provide authenticated denial of existence in DNSSEC with additional
 * protection against zone enumeration. Each NSEC3 record contains:
 *
 * Format: [hash-algorithm] [flags] [iterations] [salt] [next-hashed-owner-name] [type-bit-maps]
 * Example: 1 0 0 - B4Q3JBMLEL2C7EMPGKUDAMPIP4DI4C2L A NS SOA MX RRSIG DNSKEY NSEC3PARAM
 *
 * Field descriptions:
 * 1. Hash Algorithm: The algorithm used for hashing (1 = SHA-1, the only defined value)
 * 2. Flags: The Opt-Out flag (bit 0) indicates whether NSEC3 covers unsigned delegations
 * 3. Iterations: Number of additional hash iterations (RFC 9276 recommends 0)
 * 4. Salt: Random value to defend against pre-calculated attacks (RFC 9276 recommends "-")
 * 5. Next Hashed Owner Name: Base32hex encoded next hashed owner name in hash order
 * 6. Type Bit Maps: Set of RR types present at the original owner name
 *
 * Security considerations:
 * - While more resistant than NSEC, NSEC3 records can still be susceptible to zone enumeration
 * - RFC 9276 recommends using 0 iterations as additional iterations provide minimal security benefit
 * - RFC 9276 recommends not using a salt (indicated by "-") to simplify operation
 * - NSEC3 records should have the same TTL as the SOA minimum TTL field
 * - Validating resolvers may reject zones with high iteration values (>100)
 *
 * Type code: 50
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSEC3RecordValidator implements DnsRecordValidatorInterface
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
     * Validate an NSEC3 record according to RFC 5155 and RFC 9276
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for NSEC3 records)
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

        // Validate NSEC3 hostname format (should be Base32hex encoded hash + zone name)
        if (!$this->isValidNsec3Name($name)) {
            $warnings[] = _('NSEC3 record owner names should typically be a Base32hex encoded hash followed by the zone name (e.g., "B4Q3JBMLEL2C7EMPGKUDAMPIP4DI4C2L.example.com").');
        }

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('NSEC3 record content cannot be empty.'));
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('NSEC3 record contains invalid characters.'));
        }

        // Check NSEC3 record format
        $contentResult = $this->validateNsec3Content($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get the parsed content data
        $contentData = $contentResult->getData();

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

        // NSEC3 records don't use priority, so it's always 0
        $priority = 0;

        // RFC recommendations for TTL
        $warnings[] = _('According to RFC 5155 and RFC 9077, NSEC3 records should have the same TTL as the SOA minimum TTL field.');

        // General NSEC3 warnings
        $warnings[] = _('NSEC3 records are part of DNSSEC and should only be managed alongside other DNSSEC records (DNSKEY, RRSIG, etc.).');
        $warnings[] = _('Manually editing NSEC3 records is not recommended as they are typically generated automatically by DNSSEC-aware nameservers.');
        $warnings[] = _('NSEC3 records require a corresponding NSEC3PARAM record at the zone apex to indicate the NSEC3 parameters in use.');

        return ValidationResult::success(['content' => $content,
            'name' => $name,
            'ttl' => $validatedTtl,
            'priority' => $priority,
            'algorithm' => $contentData['algorithm'],
            'flags' => $contentData['flags'],
            'iterations' => $contentData['iterations'],
            'salt' => $contentData['salt'],
            'next_hashed_owner' => $contentData['next_hashed_owner']], $warnings);
    }

    /**
     * Check if a name appears to be a valid NSEC3 record owner name
     * NSEC3 owner names are Base32hex encoded hashes prepended to the zone name
     *
     * @param string $name The name to check
     * @return bool True if the name looks like a valid NSEC3 owner name
     */
    private function isValidNsec3Name(string $name): bool
    {
        // NSEC3 record owner names should be a Base32hex encoded hash followed by the zone name
        // Example: B4Q3JBMLEL2C7EMPGKUDAMPIP4DI4C2L.example.com
        // Base32hex characters: 0-9, A-V (case insensitive)
        $parts = explode('.', $name, 2);

        if (count($parts) >= 2) {
            $hash = $parts[0];
            // Check if the first label looks like a Base32hex encoded hash
            if (preg_match('/^[A-V0-9]+$/i', $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate NSEC3 record content format according to RFC 5155 and RFC 9276
     *
     * NSEC3 content should have proper format with required fields
     *
     * @param string $content The NSEC3 record content
     * @return ValidationResult ValidationResult object
     */
    private function validateNsec3Content(string $content): ValidationResult
    {
        $warnings = [];
        $parts = preg_split('/\s+/', trim($content));

        // NSEC3 record should have at least 5 parts:
        // 1. Hash algorithm (1 = SHA-1)
        // 2. Flags (0 or 1)
        // 3. Iterations (0-2500)
        // 4. Salt (- for empty or hex value)
        // 5. Next hashed owner name (Base32hex encoding)
        // 6+ Optional type bit maps

        if (count($parts) < 5) {
            return ValidationResult::failure(_('NSEC3 record must contain at least hash algorithm, flags, iterations, salt, and next hashed owner name.'));
        }

        // Validate hash algorithm (should be 1 for SHA-1)
        $algorithm = (int)$parts[0];
        if ($algorithm !== 1) {
            return ValidationResult::failure(_('NSEC3 hash algorithm must be 1 (SHA-1).'));
        }

        // Validate flags (0 or 1)
        $flags = (int)$parts[1];
        if ($flags !== 0 && $flags !== 1) {
            return ValidationResult::failure(_('NSEC3 flags must be 0 or 1.'));
        }

        // Flag value explanation
        if ($flags === 1) {
            $warnings[] = _('Flag value 1 indicates Opt-Out is in use. This means NSEC3 records may cover unsigned delegations.') . ' ' .
                _('RFC 9276 recommends using Opt-Out only for very large and sparsely signed zones where the majority of records are insecure delegations.');
        }

        // Validate iterations (0-2500, RFC 9276 recommends 0)
        $iterations = (int)$parts[2];
        if ($iterations < 0 || $iterations > 2500) {
            return ValidationResult::failure(_('NSEC3 iterations must be between 0 and 2500.'));
        }

        // Iteration value warnings according to RFC 9276
        if ($iterations > 0) {
            $warnings[] = _('RFC 9276 recommends using 0 iterations. Additional iterations add computational cost without enhancing security.');

            if ($iterations > 100) {
                $warnings[] = _('High iteration values (>100) may cause validating resolvers to reject your zones. RFC 9276 STRONGLY recommends using 0 iterations.');
            } elseif ($iterations > 10) {
                $warnings[] = _('Iteration values >10 create unnecessary computational load without security benefits. RFC 9276 recommends using 0 iterations.');
            }
        }

        // Validate salt (- for empty or hex value)
        $salt = $parts[3];
        if ($salt !== '-' && !preg_match('/^[0-9A-Fa-f]+$/', $salt)) {
            return ValidationResult::failure(_('NSEC3 salt must be - (for empty) or a hexadecimal value.'));
        }

        // Salt warnings according to RFC 9276
        if ($salt !== '-') {
            $warnings[] = _('RFC 9276 recommends NOT using a salt (indicated by "-") to simplify operation without reducing security.');

            if (strlen($salt) > 16) {
                $warnings[] = _('Long salts provide no additional security benefit. Consider using a shorter salt or no salt (-).');
            }
        }

        // Validate next hashed owner name (Base32hex encoding)
        $nextHashedOwner = $parts[4];
        // Base32hex alphabet: 0-9, A-V (case insensitive) per RFC 4648
        if (!preg_match('/^[A-V0-9]+$/i', $nextHashedOwner)) {
            return ValidationResult::failure(_('NSEC3 next hashed owner name must be a valid Base32hex encoded value (using characters 0-9 and A-V).'));
        }

        // If type bit maps are present, validate them
        if (count($parts) > 5) {
            $typeBitMaps = implode(' ', array_slice($parts, 5));
            $typeBitMapsResult = $this->validateTypeBitMaps($typeBitMaps);
            if (!$typeBitMapsResult->isValid()) {
                return $typeBitMapsResult;
            }

            // Collect warnings from type bit maps validation
            if ($typeBitMapsResult->hasWarnings()) {
                $warnings = array_merge($warnings, $typeBitMapsResult->getWarnings());
            }
        } else {
            // Type bit maps should be present
            $warnings[] = _('Type bit maps should be present to indicate which RR types exist at the original owner name.');
        }

        return ValidationResult::success(['algorithm' => $algorithm,
            'flags' => $flags,
            'iterations' => $iterations,
            'salt' => $salt,
            'next_hashed_owner' => $nextHashedOwner], $warnings);
    }

    /**
     * Validate the type bit maps part of an NSEC3 record
     * According to RFC 5155, the Type Bit Maps field has the same format as the one used in NSEC records
     *
     * @param string $typeBitMaps The type bit maps part of the NSEC3 record
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

        // Check for common DNSSEC-related record types
        $hasRrsig = false;
        $hasSoa = false;
        $hasNs = false;
        $hasDnskey = false;
        $hasNsec3param = false;

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
            if ($upperType === 'RRSIG') {
                $hasRrsig = true;
            } elseif ($upperType === 'SOA') {
                $hasSoa = true;
            } elseif ($upperType === 'NS') {
                $hasNs = true;
            } elseif ($upperType === 'DNSKEY') {
                $hasDnskey = true;
            } elseif ($upperType === 'NSEC3PARAM') {
                $hasNsec3param = true;
            }

            if (!in_array($upperType, $validRecordTypes)) {
                // Check if it follows RFC 3597 format (TYPE###)
                if (preg_match('/^TYPE\d+$/i', $upperType)) {
                    // Valid TYPE format
                    continue;
                }
                return ValidationResult::failure(sprintf(_('NSEC3 record contains an invalid record type: %s'), $type));
            }
        }

        // DNSSEC-specific type checks
        if (!$hasRrsig) {
            $warnings[] = _('RRSIG type should typically be present in NSEC3 type bit maps for DNSSEC-signed zones.');
        }

        // Zone apex checks
        if ($hasSoa && $hasNs) {
            $warnings[] = _('This NSEC3 record contains both SOA and NS types, indicating it might be for a zone apex.');

            if ($hasDnskey && !$hasNsec3param) {
                $warnings[] = _('For a zone apex using NSEC3, the NSEC3PARAM type should typically be present along with DNSKEY.');
            }
        }

        return ValidationResult::success(['types' => $types], $warnings);
    }
}
