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
 * Validator for PTR (Pointer) DNS records
 *
 * Validates PTR records according to:
 * - RFC 1035: Domain Names - Implementation and Specification
 * - RFC 2181: Clarifications to the DNS Specification
 * - RFC 1912: Common DNS Operational and Configuration Errors
 *
 * PTR records are used for reverse DNS lookups, mapping IP addresses to hostnames.
 * These records are the inverse of A/AAAA records and are stored in special
 * reverse delegation domains: in-addr.arpa for IPv4 and ip6.arpa for IPv6.
 *
 * Format: <hostname>
 *
 * Example: host.example.com
 *
 * Where:
 * - hostname: A domain name which points to the canonical name for the record's name
 *   (which should be an IP address in reverse notation)
 *
 * IPv4 example:
 * - Record name: 1.0.168.192.in-addr.arpa (for IP 192.168.0.1)
 * - Record content: host.example.com
 *
 * IPv6 example:
 * - Record name: 1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa (for IP 2001:db8::1)
 * - Record content: host.example.com
 *
 * Important notes:
 * - Type code: 12 (defined in RFC 1035)
 * - PTR records should have corresponding forward (A/AAAA) records to avoid problems
 * - Many services (especially email) require properly configured reverse DNS
 * - PTR records don't imply any special processing like CNAME records
 * - No additional section processing is required for PTR records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class PTRRecordValidator implements DnsRecordValidatorInterface
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
     * Validate PTR record
     *
     * @param string $content Hostname that this IP address points to
     * @param string $name IP address in reverse notation (e.g., 1.0.168.192.in-addr.arpa)
     * @param mixed $prio Priority (not used for PTR records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate content as hostname
        $contentHostnameResult = $this->hostnameValidator->validate($content, false);
        if (!$contentHostnameResult->isValid()) {
            return $contentHostnameResult;
        }
        $contentData = $contentHostnameResult->getData();
        $content = $contentData['hostname'];

        // Validate name as hostname
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Check if this appears to be a proper reverse DNS name
        if (!$this->isReverseDnsName($name)) {
            $warnings[] = _('The record name does not appear to be a standard reverse DNS zone name (should end with .in-addr.arpa for IPv4 or .ip6.arpa for IPv6).');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for PTR records)
        if (!empty($prio) && $prio != 0) {
            $warnings[] = _('Priority field for PTR records should be 0 or empty. The specified value will be ignored.');
        }

        // Add general PTR record recommendations
        $warnings[] = _('PTR records should have corresponding forward (A/AAAA) records to avoid problems with services that check reverse DNS.');
        $warnings[] = _('Email servers often require properly configured reverse DNS to accept mail from your server.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Checks if a domain name appears to be a reverse DNS name
     *
     * @param string $name Domain name to check
     * @return bool True if it looks like a reverse DNS name, false otherwise
     */
    private function isReverseDnsName(string $name): bool
    {
        // Check for IPv4 reverse DNS (.in-addr.arpa)
        if (str_ends_with($name, '.in-addr.arpa') || $name === 'in-addr.arpa') {
            // Ideally we would validate the IPv4 octet structure, but we'll keep it simple
            return true;
        }

        // Check for IPv6 reverse DNS (.ip6.arpa)
        if (str_ends_with($name, '.ip6.arpa') || $name === 'ip6.arpa') {
            // Ideally we would validate the IPv6 nibble structure, but we'll keep it simple
            return true;
        }

        return false;
    }
}
