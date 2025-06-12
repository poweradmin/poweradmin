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
 * Validator for DLV (DNSSEC Lookaside Validation) DNS records
 *
 * IMPORTANT: DLV records have been obsoleted by RFC 8749 (March 2020).
 * Both RFC 4431 and RFC 5074 that defined DLV are now classified as Historic.
 * Major DNS resolvers like BIND 9.16+ and Unbound have removed or deprecated DLV support.
 *
 * This validator is maintained for backward compatibility with older systems that might
 * still use DLV records. However, DLV records should not be used for new deployments.
 *
 * DLV records have the same format as DS records: <key-tag> <algorithm> <digest-type> <digest>
 * but were used for validating DNSSEC outside the standard delegation hierarchy.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8749 RFC 8749: Moving DNSSEC Lookaside Validation (DLV) to Historic Status
 * @see https://datatracker.ietf.org/doc/html/rfc5074 RFC 5074: DNSSEC Lookaside Validation (DLV)
 * @see https://datatracker.ietf.org/doc/html/rfc4431 RFC 4431: The DNSSEC Lookaside Validation (DLV) DNS Resource Record
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DLVRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate DLV record
     *
     * Note that DLV records are obsolete according to RFC 8749, but this validator
     * is maintained for backward compatibility.
     *
     * @param string $content Content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL value
     * @param int $defaultTTL Default TTL to use if TTL is empty
     *
     * @return ValidationResult Validation result with data, errors, or warnings
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Add obsolescence warning
        $warnings[] = _('IMPORTANT: DLV records have been obsoleted by RFC 8749 (March 2020). Major DNS resolvers have removed or deprecated DLV support. Consider using standard DNSSEC validation with DS records instead.');

        // Validate the hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check if the name resembles a DLV lookup domain
        if (strpos($name, 'dlv.') !== false) {
            $warnings[] = _('This name appears to be in a DLV lookup domain. Note that dlv.isc.org, the main DLV registry, was decommissioned in 2017.');
        }

        // Validate DLV record content (same format as DS)
        $contentResult = $this->validateDLVContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Collect warnings from content validation if any
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

        // Priority for DLV records should be 0
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DLV records must be 0 or empty.'));
        }

        // Placement warning
        $warnings[] = _('DLV records were typically placed in special DLV registry domains, not in the same domain as the DNSKEY records they reference.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate DLV record content format
     * DLV has the same format as DS records: <key-tag> <algorithm> <digest-type> <digest>
     * as defined in RFC 4431 and RFC 5074.
     *
     * @param string $content DLV record content
     * @return ValidationResult Validation result with success or error message
     */
    public function validateDLVContent(string $content): ValidationResult
    {
        $warnings = [];

        // DLV record format: <key-tag> <algorithm> <digest-type> <digest>
        if (!preg_match('/^([0-9]+) ([0-9]+) ([0-9]+) ([a-f0-9]+)$/i', $content)) {
            return ValidationResult::failure(_('Invalid DLV record format. Expected: <key-tag> <algorithm> <digest-type> <digest>.'));
        }

        // Split content into components
        $parts = explode(' ', $content);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('Invalid DLV record format. Should contain exactly 4 parts.'));
        }

        list($keyTag, $algorithm, $digestType, $digest) = $parts;

        // Validate key tag (1-65535)
        if (!is_numeric($keyTag) || $keyTag < 1 || $keyTag > 65535) {
            return ValidationResult::failure(_('Invalid key tag. Must be a number between 1 and 65535.'));
        }

        // Warning about key tag usage
        $warnings[] = _('The key tag field does not uniquely identify a DNSKEY. More than one key can have the same key tag.');

        // Algorithm security categorization according to RFC 8624
        $mustImplement = [13, 8]; // ECDSAP256SHA256, RSASHA256
        $recommended = [15, 16];  // ED25519, ED448
        $optional = [14];         // ECDSAP384SHA384
        $notRecommended = [5, 6, 7, 12]; // RSASHA1, DSA-NSEC3-SHA1, RSASHA1-NSEC3-SHA1, ECC-GOST
        $mustNotImplement = [1, 3]; // RSAMD5, DSA

        // Obsolete algorithms (not even listed in current RFCs)
        $obsoleteAlgorithms = [2, 4, 9, 11];

        // Validate algorithm (known DNSSEC algorithms 1-16)
        $algorithmInt = (int)$algorithm;
        $validAlgorithms = array_merge($mustImplement, $recommended, $optional, $notRecommended, $mustNotImplement);

        if (!in_array($algorithmInt, $validAlgorithms) && !in_array($algorithmInt, $obsoleteAlgorithms)) {
            return ValidationResult::failure(_('Invalid algorithm. Must be one of the valid DNSSEC algorithms (1-16).'));
        }

        // Add algorithm warnings
        if (in_array($algorithmInt, $mustNotImplement)) {
            $warnings[] = _('Algorithm ' . $algorithmInt . ' MUST NOT be used according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif (in_array($algorithmInt, $notRecommended)) {
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is NOT RECOMMENDED for use according to RFC 8624. Consider using ECDSAP256SHA256 (13) or ED25519 (15) instead.');
        } elseif (in_array($algorithmInt, $obsoleteAlgorithms)) {
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is obsolete and not mentioned in current RFCs.');
        } elseif (in_array($algorithmInt, $recommended)) {
            $warnings[] = _('Algorithm ' . $algorithmInt . ' is RECOMMENDED per RFC 8624. Good choice.');
        }

        // Validate digest type (1 = SHA-1, 2 = SHA-256, 4 = SHA-384)
        $validDigestTypes = [1, 2, 4];
        $digestTypeInt = (int)$digestType;

        if (!in_array($digestTypeInt, $validDigestTypes)) {
            return ValidationResult::failure(_('Invalid digest type. Must be 1 (SHA-1), 2 (SHA-256), or 4 (SHA-384).'));
        }

        // Add warning for SHA-1
        if ($digestTypeInt === 1) {
            $warnings[] = _('SHA-1 (digest type 1) is considered weak for cryptographic use. Consider using SHA-256 (type 2) or SHA-384 (type 4) instead.');
        }

        // Validate digest length based on type
        $digestLength = strlen($digest);
        switch ($digestTypeInt) {
            case 1: // SHA-1
                if ($digestLength !== 40) {
                    return ValidationResult::failure(_('Invalid digest length for SHA-1. Should be 40 hexadecimal characters.'));
                }
                break;
            case 2: // SHA-256
                if ($digestLength !== 64) {
                    return ValidationResult::failure(_('Invalid digest length for SHA-256. Should be 64 hexadecimal characters.'));
                }
                break;
            case 4: // SHA-384
                if ($digestLength !== 96) {
                    return ValidationResult::failure(_('Invalid digest length for SHA-384. Should be 96 hexadecimal characters.'));
                }
                break;
        }

        // Verify content is valid hex characters
        if (!ctype_xdigit($digest)) {
            return ValidationResult::failure(_('Digest must contain only hexadecimal characters (0-9, a-f).'));
        }

        return ValidationResult::success(true, $warnings);
    }
}
