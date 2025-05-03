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
 * NSEC record validator
 *
 * NSEC records are used for authenticated denial of existence in DNSSEC.
 * Format: next-domain-name [type-bit-maps]
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSECRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator($config);
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
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('NSEC record content cannot be empty.'));
            return false;
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Check NSEC record format
        if (!$this->isValidNsecContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // NSEC records don't use priority, so it's always 0
        $priority = 0;

        return [
            'content' => $content,
            'ttl' => $validatedTtl,
            'priority' => $priority
        ];
    }

    /**
     * Validate NSEC record content format
     *
     * NSEC content should have:
     * 1. A valid next domain name
     * 2. Optionally followed by type bit maps
     *
     * @param string $content The NSEC record content
     * @return bool True if format is valid, false otherwise
     */
    private function isValidNsecContent(string $content): bool
    {
        $parts = preg_split('/\s+/', trim($content), 2);

        // Check that next domain name is valid
        $nextDomainName = $parts[0];
        if (!$this->hostnameValidator->isValidHostnameFqdn($nextDomainName, '0')) {
            $this->messageService->addSystemError(_('NSEC record must contain a valid next domain name.'));
            return false;
        }

        // If type bit maps are present, validate them
        if (isset($parts[1])) {
            $typeBitMaps = $parts[1];
            if (!$this->validateTypeBitMaps($typeBitMaps)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the type bit maps part of an NSEC record
     *
     * @param string $typeBitMaps The type bit maps part of the NSEC record
     * @return bool True if valid, false otherwise
     */
    private function validateTypeBitMaps(string $typeBitMaps): bool
    {
        // Type bit maps should contain valid record types
        $validRecordTypes = [
            'A', 'AAAA', 'AFSDB', 'APL', 'CAA', 'CDNSKEY', 'CDS', 'CERT', 'CNAME', 'DHCID',
            'DLV', 'DNAME', 'DNSKEY', 'DS', 'EUI48', 'EUI64', 'HINFO', 'HTTPS', 'IPSECKEY',
            'KEY', 'KX', 'LOC', 'MX', 'NAPTR', 'NS', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'OPENPGPKEY',
            'PTR', 'RRSIG', 'SOA', 'SPF', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI'
        ];

        $types = preg_split('/\s+/', trim($typeBitMaps));

        foreach ($types as $type) {
            // Skip if the type is numeric (some representations use numeric type codes)
            if (is_numeric($type)) {
                continue;
            }

            // If type has additional parameters in parentheses, extract just the type
            if (str_contains($type, '(')) {
                $type = trim(substr($type, 0, strpos($type, '(')));
            }

            if (!in_array(strtoupper($type), $validRecordTypes)) {
                $this->messageService->addSystemError(sprintf(_('NSEC record contains an invalid record type: %s'), $type));
                return false;
            }
        }

        return true;
    }
}
