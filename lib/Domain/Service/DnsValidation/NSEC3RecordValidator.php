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
 * NSEC3 record validator
 *
 * NSEC3 records are an evolution of NSEC records for authenticated denial of existence in DNSSEC.
 * Format: [hash-algorithm] [flags] [iterations] [salt] [next-hashed-owner-name] [type-bit-maps]
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NSEC3RecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator($config);
    }

    /**
     * Validate an NSEC3 record
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for NSEC3 records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('NSEC3 record content cannot be empty.'));
            return false;
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Check NSEC3 record format
        if (!$this->isValidNsec3Content($content)) {
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // NSEC3 records don't use priority, so it's always 0
        $priority = 0;

        return [
            'content' => $content,
            'ttl' => $validatedTtl,
            'priority' => $priority
        ];
    }

    /**
     * Validate NSEC3 record content format
     *
     * NSEC3 content should have proper format with required fields
     *
     * @param string $content The NSEC3 record content
     * @return bool True if format is valid, false otherwise
     */
    private function isValidNsec3Content(string $content): bool
    {
        $parts = preg_split('/\s+/', trim($content));

        // NSEC3 record should have at least 5 parts:
        // 1. Hash algorithm (1 = SHA-1)
        // 2. Flags (0 or 1)
        // 3. Iterations (0-2500)
        // 4. Salt (- for empty or hex value)
        // 5. Next hashed owner name (Base32hex encoding)
        // 6+ Optional type bit maps

        if (count($parts) < 5) {
            $this->messageService->addSystemError(_('NSEC3 record must contain at least hash algorithm, flags, iterations, salt, and next hashed owner name.'));
            return false;
        }

        // Validate hash algorithm (should be 1 for SHA-1)
        $algorithm = (int)$parts[0];
        if ($algorithm !== 1) {
            $this->messageService->addSystemError(_('NSEC3 hash algorithm must be 1 (SHA-1).'));
            return false;
        }

        // Validate flags (0 or 1)
        $flags = (int)$parts[1];
        if ($flags !== 0 && $flags !== 1) {
            $this->messageService->addSystemError(_('NSEC3 flags must be 0 or 1.'));
            return false;
        }

        // Validate iterations (0-2500, RFC recommends max of 150)
        $iterations = (int)$parts[2];
        if ($iterations < 0 || $iterations > 2500) {
            $this->messageService->addSystemError(_('NSEC3 iterations must be between 0 and 2500.'));
            return false;
        }

        // Validate salt (- for empty or hex value)
        $salt = $parts[3];
        if ($salt !== '-' && !preg_match('/^[0-9A-Fa-f]+$/', $salt)) {
            $this->messageService->addSystemError(_('NSEC3 salt must be - (for empty) or a hexadecimal value.'));
            return false;
        }

        // Validate next hashed owner name (Base32hex encoding)
        $nextHashedOwner = $parts[4];
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $nextHashedOwner)) {
            $this->messageService->addSystemError(_('NSEC3 next hashed owner name must be a valid Base32hex encoded value.'));
            return false;
        }

        // If type bit maps are present, validate them
        if (count($parts) > 5) {
            $typeBitMaps = implode(' ', array_slice($parts, 5));
            if (!$this->validateTypeBitMaps($typeBitMaps)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the type bit maps part of an NSEC3 record
     *
     * @param string $typeBitMaps The type bit maps part of the NSEC3 record
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
                $this->messageService->addSystemError(sprintf(_('NSEC3 record contains an invalid record type: %s'), $type));
                return false;
            }
        }

        return true;
    }
}
