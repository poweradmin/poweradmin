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
 * Validates RRSIG records according to:
 * - RFC 4034: Resource Records for the DNS Security Extensions
 * - RFC 4035: Protocol Modifications for the DNS Security Extensions
 * - RFC 6781: DNSSEC Operational Practices, Version 2
 *
 * RRSIG records contain signatures for DNS record sets (RRsets) and are a critical
 * component of DNSSEC. These signatures allow validators to authenticate DNS data.
 *
 * Format: <covered-type> <algorithm> <labels> <orig-ttl> <sig-expiration> <sig-inception> <key-tag> <signer's-name> <signature>
 *
 * Example: A 8 2 86400 20230515130000 20230415130000 12345 example.com. AQPeAHj...
 *
 * Where:
 * - covered-type: The type of RRset covered by this signature (e.g., A, AAAA, MX)
 * - algorithm: DNSSEC algorithm used for the signature
 *   - 1 = RSA/MD5 (deprecated)
 *   - 3 = DSA/SHA1 (insecure)
 *   - 5 = RSA/SHA-1 (insecure)
 *   - 7 = RSASHA1-NSEC3-SHA1
 *   - 8 = RSA/SHA-256 (recommended)
 *   - 10 = RSA/SHA-512
 *   - 13 = ECDSA P-256 with SHA-256
 *   - 14 = ECDSA P-384 with SHA-384
 *   - 15 = ED25519
 *   - 16 = ED448
 * - labels: Number of labels in the original name (excluding leftmost wildcard)
 * - orig-ttl: The TTL of the covered RRset as it appears in the authoritative zone
 * - sig-expiration: Signature expiration time (YYYYMMDDHHmmSS format)
 * - sig-inception: Signature inception time (YYYYMMDDHHmmSS format)
 * - key-tag: Key tag identifying the DNSKEY that validates this signature
 * - signer's-name: Domain name of the zone containing the RRset (must end with a dot)
 * - signature: Cryptographic signature data (base64 encoded)
 *
 * Security considerations:
 * - RRSIG validity period should be limited (typically 30 days)
 * - Regular key rollovers are recommended (ZSK: 1-3 months, KSK: 1-2 years)
 * - RSA/SHA-256 (algorithm 8) or newer algorithms are recommended for security
 * - Signatures should be renewed before expiration to avoid validation failures
 * - TTL value of RRSIG record must match the TTL of the RRset it covers
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
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

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

        // Collect warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
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

        // Add general DNSSEC recommendations
        $warnings[] = _('RRSIG records should only be created by your DNS server when signing a zone. Manual manipulation is not recommended.');
        $warnings[] = _('Regular key rollovers are recommended security practice: ZSK every 1-3 months, KSK every 1-2 years.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
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
        $warnings = [];

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

        // Add warnings about algorithm security
        $algorithmNum = (int)$algorithm;
        if ($algorithmNum === 1) {
            $warnings[] = _('Algorithm 1 (RSA/MD5) is deprecated and should NOT be used due to cryptographic weaknesses.');
        } elseif ($algorithmNum === 3) {
            $warnings[] = _('Algorithm 3 (DSA/SHA1) is considered insecure and should be replaced with stronger algorithms.');
        } elseif ($algorithmNum === 5) {
            $warnings[] = _('Algorithm 5 (RSA/SHA-1) is considered insecure and should be replaced with stronger algorithms.');
        } elseif ($algorithmNum === 7) {
            $warnings[] = _('Algorithm 7 (RSASHA1-NSEC3-SHA1) uses SHA-1 which is no longer considered secure. Consider using RSA/SHA-256 or newer algorithms.');
        } elseif ($algorithmNum === 8) {
            $warnings[] = _('Algorithm 8 (RSA/SHA-256) is currently recommended for DNSSEC deployments.');
        } elseif ($algorithmNum === 10) {
            $warnings[] = _('Algorithm 10 (RSA/SHA-512) provides strong security but may have interoperability issues with some validators.');
        } elseif ($algorithmNum === 13) {
            $warnings[] = _('Algorithm 13 (ECDSA P-256 with SHA-256) provides good security with smaller signatures than RSA.');
        } elseif ($algorithmNum === 14) {
            $warnings[] = _('Algorithm 14 (ECDSA P-384 with SHA-384) provides strong security with smaller signatures than RSA.');
        } elseif ($algorithmNum === 15) {
            $warnings[] = _('Algorithm 15 (ED25519) offers modern security but may have compatibility issues with older DNSSEC validators.');
        } elseif ($algorithmNum === 16) {
            $warnings[] = _('Algorithm 16 (ED448) offers strong modern security but may have compatibility issues with older DNSSEC validators.');
        } else {
            $warnings[] = _('Unknown algorithm value. Only algorithms defined in RFCs should be used.');
        }

        if ($algorithmNum < 8 && $algorithmNum != 7) {
            $warnings[] = _('CRITICAL: This algorithm is considered insecure. Use algorithm 8 (RSA/SHA-256) or later.');
        }

        // Validate labels (must be numeric)
        if (!is_numeric($labels)) {
            return ValidationResult::failure(_('RRSIG labels field must be a numeric value.'));
        }

        // Validate that labels value is reasonable
        $labelsNum = (int)$labels;
        if ($labelsNum < 0 || $labelsNum > 128) {
            return ValidationResult::failure(_('RRSIG labels field must be a reasonable value (0-128).'));
        }

        // Validate original TTL (must be numeric)
        if (!is_numeric($origTtl)) {
            return ValidationResult::failure(_('RRSIG original TTL field must be a numeric value.'));
        }

        $origTtlNum = (int)$origTtl;
        if ($origTtlNum < 0 || $origTtlNum > 2147483647) {
            return ValidationResult::failure(_('RRSIG original TTL must be a valid positive integer.'));
        }

        if ($origTtlNum > 86400) {
            $warnings[] = _('Original TTL is greater than 1 day (86400). For DNSSEC records, shorter TTLs are generally preferred for faster recovery in case of key compromise.');
        }

        // Validate expiration time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $expiration)) {
            return ValidationResult::failure(_('RRSIG expiration must be in YYYYMMDDHHmmSS format.'));
        }

        // Validate inception time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $inception)) {
            return ValidationResult::failure(_('RRSIG inception must be in YYYYMMDDHHmmSS format.'));
        }

        // Check time validity - try to parse the timestamps and compare
        try {
            $expirationTime = \DateTime::createFromFormat('YmdHis', $expiration);
            $inceptionTime = \DateTime::createFromFormat('YmdHis', $inception);
            $currentTime = new \DateTime();

            if ($expirationTime && $inceptionTime) {
                // Ensure expiration time is after inception time
                if ($expirationTime <= $inceptionTime) {
                    $warnings[] = _('The expiration time must be later than the inception time. This signature appears to have expired before it became valid.');
                }

                // Check if the signature is already expired or about to expire
                if ($expirationTime < $currentTime) {
                    $warnings[] = _('CRITICAL: This signature has already expired. It will not be validated by DNSSEC resolvers.');
                } elseif ($expirationTime < $currentTime->modify('+7 days')) {
                    $warnings[] = _('WARNING: This signature will expire within the next 7 days. Consider renewing it soon.');
                } elseif ($expirationTime < $currentTime->modify('+30 days')) {
                    $warnings[] = _('This signature will expire within the next 30 days. Plan for renewal.');
                }

                // Check if the signature is not yet valid
                if ($inceptionTime > $currentTime) {
                    $warnings[] = _('WARNING: This signature is not yet valid. It will not be validated by DNSSEC resolvers until the inception time is reached.');
                }

                // Check if signature validity period is too long (over 30 days)
                $interval = $inceptionTime->diff($expirationTime);
                $daysValid = $interval->days;
                if ($daysValid > 90) {
                    $warnings[] = _('The signature validity period is over 90 days, which is longer than recommended practices. Long validity periods increase vulnerability in case of key compromise.');
                }
            }
        } catch (\Exception $e) {
            $warnings[] = _('Could not parse signature timestamps to check validity period.');
        }

        // Validate key tag (must be numeric)
        if (!is_numeric($keyTag)) {
            return ValidationResult::failure(_('RRSIG key tag field must be a numeric value.'));
        }

        $keyTagNum = (int)$keyTag;
        if ($keyTagNum < 0 || $keyTagNum > 65535) {
            return ValidationResult::failure(_('RRSIG key tag must be a valid value between 0 and 65535.'));
        }

        // Validate signer's name (must be a valid domain name ending with a dot)
        if (!str_ends_with($signerName, '.')) {
            return ValidationResult::failure(_('RRSIG signer name must be a fully qualified domain name (end with a dot).'));
        }

        // Remove trailing dot for domain validation
        $signerNameNoDot = rtrim($signerName, '.');
        if (!StringValidator::isValidDomain($signerNameNoDot)) {
            return ValidationResult::failure(_('RRSIG signer name must be a valid domain name.'));
        }

        // Validate signature (must not be empty)
        if (empty(trim($signature))) {
            return ValidationResult::failure(_('RRSIG signature cannot be empty.'));
        }

        // Check if signature looks like base64 encoded data
        if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', trim($signature))) {
            $warnings[] = _('The signature does not appear to be valid base64 encoded data. RRSIG signatures should be base64 encoded.');
        }

        // Add general DNSSEC warnings and information
        $warnings[] = _('RRSIG records are part of DNSSEC and provide cryptographic signatures for DNS records. They should be managed automatically by the DNS server.');
        $warnings[] = _('Remember that RRSIG records must be regenerated before their expiration date to maintain DNSSEC validation.');

        return ValidationResult::success(true, $warnings);
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
