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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

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
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates RRSIG record content
     *
     * @param string $content The content of the RRSIG record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RRSIG records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate the hostname format
        if (!StringValidator::isValidPrintable($name)) {
            return false;
        }

        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidRRSIGContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => 0, // RRSIG records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of an RRSIG record
     * Format: <covered-type> <algorithm> <labels> <orig-ttl> <sig-expiration> <sig-inception> <key-tag> <signer's-name> <signature>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidRRSIGContent(string $content): bool
    {
        // Check if empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('RRSIG record content cannot be empty.'));
            return false;
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 9) {
            $this->messageService->addSystemError(_('RRSIG record must contain covered-type, algorithm, labels, original TTL, expiration, inception, key tag, signer name and signature.'));
            return false;
        }

        [$coveredType, $algorithm, $labels, $origTtl, $expiration, $inception, $keyTag, $signerName] = array_slice($parts, 0, 8);
        $signature = implode(' ', array_slice($parts, 8));

        // Validate covered type (should be a valid DNS record type)
        if (!$this->isValidDnsRecordType($coveredType)) {
            $this->messageService->addSystemError(_('RRSIG covered type must be a valid DNS record type.'));
            return false;
        }

        // Validate algorithm (must be numeric)
        if (!is_numeric($algorithm)) {
            $this->messageService->addSystemError(_('RRSIG algorithm field must be a numeric value.'));
            return false;
        }

        // Validate labels (must be numeric)
        if (!is_numeric($labels)) {
            $this->messageService->addSystemError(_('RRSIG labels field must be a numeric value.'));
            return false;
        }

        // Validate original TTL (must be numeric)
        if (!is_numeric($origTtl)) {
            $this->messageService->addSystemError(_('RRSIG original TTL field must be a numeric value.'));
            return false;
        }

        // Validate expiration time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $expiration)) {
            $this->messageService->addSystemError(_('RRSIG expiration must be in YYYYMMDDHHmmSS format.'));
            return false;
        }

        // Validate inception time (must be a timestamp in YYYYMMDDHHmmSS format)
        if (!preg_match('/^\d{14}$/', $inception)) {
            $this->messageService->addSystemError(_('RRSIG inception must be in YYYYMMDDHHmmSS format.'));
            return false;
        }

        // Validate key tag (must be numeric)
        if (!is_numeric($keyTag)) {
            $this->messageService->addSystemError(_('RRSIG key tag field must be a numeric value.'));
            return false;
        }

        // Validate signer's name (must be a valid domain name ending with a dot)
        if (!str_ends_with($signerName, '.')) {
            $this->messageService->addSystemError(_('RRSIG signer name must be a fully qualified domain name (end with a dot).'));
            return false;
        }

        // Validate signature (must not be empty)
        if (empty(trim($signature))) {
            $this->messageService->addSystemError(_('RRSIG signature cannot be empty.'));
            return false;
        }

        return true;
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
