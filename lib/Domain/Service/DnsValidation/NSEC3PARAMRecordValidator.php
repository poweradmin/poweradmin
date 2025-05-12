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
 * NSEC3PARAM record validator
 *
 * Validates NSEC3PARAM (NSEC3 Parameters) records according to:
 * - RFC 5155: DNS Security (DNSSEC) Hashed Authenticated Denial of Existence
 * - RFC 9276: Guidance for NSEC3 Parameter Settings (Best Current Practice)
 * - RFC 9077: NSEC and NSEC3: TTLs and Aggressive Use
 *
 * NSEC3PARAM records provide the parameters needed by authoritative servers to calculate
 * hashed owner names for NSEC3 records. The NSEC3PARAM RR is used by servers to select
 * the appropriate NSEC3 records for negative responses.
 *
 * Format: [hash-algorithm] [flags] [iterations] [salt]
 * Example: 1 0 0 -
 *
 * Field descriptions:
 * 1. Hash Algorithm: The algorithm used for hashing (1 = SHA-1, the only defined value)
 * 2. Flags: The Opt-Out flag (bit 0) indicates whether NSEC3 covers unsigned delegations
 * 3. Iterations: Number of additional hash iterations (RFC 9276 recommends 0)
 * 4. Salt: Random value to defend against pre-calculated attacks (RFC 9276 recommends "-")
 *
 * Security considerations:
 * - NSEC3PARAM records MUST only be present at the zone apex
 * - Unlike NSEC3 records, NSEC3PARAM records do not include the "next hashed owner name" or "type bit maps"
 * - RFC 9276 recommends using 0 iterations as additional iterations provide minimal security benefit
 * - RFC 9276 recommends not using a salt (indicated by "-") to simplify operation
 * - Validating resolvers may reject zones with high iteration values (>100)
 *
 * Type code: 51
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSEC3PARAMRecordValidator implements DnsRecordValidatorInterface
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
     * Validate an NSEC3PARAM record according to RFC 5155 and RFC 9276
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for NSEC3PARAM records)
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

        // Check if name is at zone apex (should be root domain, not subdomain)
        // Simple check: a subdomain will have at least one period before the TLD
        $parts = explode('.', $name);
        if (count($parts) > 2) {
            $warnings[] = _('NSEC3PARAM records MUST only be present at the zone apex (root domain, not subdomain).');
        }

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('NSEC3PARAM record content cannot be empty.'));
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('NSEC3PARAM record contains invalid characters.'));
        }

        // Check NSEC3PARAM record format
        $contentResult = $this->validateNsec3ParamContent($content);
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

        // NSEC3PARAM records don't use priority, so it's always 0
        $priority = 0;

        // RFC recommendations for TTL
        $warnings[] = _('According to RFC 5155 and RFC 9077, NSEC3PARAM records should have the same TTL as the SOA minimum TTL field.');

        // General NSEC3PARAM warnings
        $warnings[] = _('NSEC3PARAM records are part of DNSSEC and should only be managed alongside other DNSSEC records (DNSKEY, RRSIG, etc.).');
        $warnings[] = _('Manually editing NSEC3PARAM records is not recommended as they are typically generated automatically by DNSSEC-aware nameservers.');
        $warnings[] = _('NSEC3PARAM records indicate to authoritative servers which parameters to use for NSEC3-based authenticated denial of existence.');
        $warnings[] = _('When updating NSEC3 parameters, both NSEC3 and NSEC3PARAM records must be re-generated with the same parameters.');

        return ValidationResult::success(['content' => $content,
            'name' => $name,
            'ttl' => $validatedTtl,
            'priority' => $priority,
            'algorithm' => $contentData['algorithm'],
            'flags' => $contentData['flags'],
            'iterations' => $contentData['iterations'],
            'salt' => $contentData['salt']], $warnings);
    }

    /**
     * Validate NSEC3PARAM record content format according to RFC 5155 and RFC 9276
     *
     * NSEC3PARAM content should have proper format with required fields
     *
     * @param string $content The NSEC3PARAM record content
     * @return ValidationResult ValidationResult object
     */
    private function validateNsec3ParamContent(string $content): ValidationResult
    {
        $warnings = [];
        $parts = preg_split('/\s+/', trim($content));

        // NSEC3PARAM record should have exactly 4 parts:
        // 1. Hash algorithm (1 = SHA-1)
        // 2. Flags (0-255)
        // 3. Iterations (0-2500)
        // 4. Salt (- for empty or hex value)

        if (count($parts) !== 4) {
            return ValidationResult::failure(_('NSEC3PARAM record must contain exactly hash algorithm, flags, iterations, and salt.'));
        }

        // Validate hash algorithm (should be 1 for SHA-1)
        $algorithm = (int)$parts[0];
        if ($algorithm !== 1) {
            return ValidationResult::failure(_('NSEC3PARAM hash algorithm must be 1 (SHA-1).'));
        }

        // Validate flags (0-255, typically 0 or 1)
        $flags = (int)$parts[1];
        if ($flags < 0 || $flags > 255) {
            return ValidationResult::failure(_('NSEC3PARAM flags must be between 0 and 255.'));
        }

        // Flag value explanation
        if ($flags === 1) {
            $warnings[] = _('Flag value 1 indicates Opt-Out is in use. This means NSEC3 records may cover unsigned delegations.') . ' ' .
                _('RFC 9276 recommends using Opt-Out only for very large and sparsely signed zones where the majority of records are insecure delegations.');
        } elseif ($flags > 1) {
            $warnings[] = _('Flags values greater than 1 are reserved for future use. Current implementations may not handle these values correctly.');
        }

        // Validate iterations (0-2500, RFC 9276 recommends 0)
        $iterations = (int)$parts[2];
        if ($iterations < 0 || $iterations > 2500) {
            return ValidationResult::failure(_('NSEC3PARAM iterations must be between 0 and 2500.'));
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
            return ValidationResult::failure(_('NSEC3PARAM salt must be - (for empty) or a hexadecimal value.'));
        }

        // Salt warnings according to RFC 9276
        if ($salt !== '-') {
            $warnings[] = _('RFC 9276 recommends NOT using a salt (indicated by "-") to simplify operation without reducing security.');

            if (strlen($salt) > 16) {
                $warnings[] = _('Long salts provide no additional security benefit. Consider using a shorter salt or no salt (-).');
            }
        }

        return ValidationResult::success(['algorithm' => $algorithm,
            'flags' => $flags,
            'iterations' => $iterations,
            'salt' => $salt], $warnings);
    }
}
