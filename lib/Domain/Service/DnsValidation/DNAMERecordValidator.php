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
 * DNAME record validator
 *
 * DNAME records are defined in RFC 6672 as a mechanism for DNS domain name redirection.
 * They provide a way to make all names under one domain be aliases for names under
 * another domain, essentially creating an alias for an entire subtree of the DNS.
 *
 * Format: <target-domain>
 *
 * Key constraints from RFC 6672:
 * - DNAME records must be unique at a given owner name (singleton)
 * - DNAME and CNAME records MUST NOT coexist at the same owner name
 * - DNAME records at zone apex require special handling for NS records
 * - DNAME records create aliases for all subdomains of their owner name
 * - DNAME target must be a fully-qualified domain name
 * - DNAME cannot point to itself or create loops
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6672 RFC 6672: DNAME Redirection in the DNS
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DNAMERecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates DNAME record content
     *
     * @param string $content The content of the DNAME record (target domain)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for DNAME records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check for zone apex warnings
        $nameParts = explode('.', $name);
        $isZoneApex = false;
        if (count($nameParts) <= 2 || $nameParts[0] === '@') {
            $isZoneApex = true;
            $warnings[] = _('This DNAME record appears to be at zone apex. According to RFC 6672, using DNAME at the zone apex requires special handling for NS and related records.');
        }

        // Validate content - DNAME target should be a valid domain name
        $contentResult = $this->hostnameValidator->validate($content, true);
        if (!$contentResult->isValid()) {
            return ValidationResult::failure(_('DNAME target must be a valid fully-qualified domain name.'));
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // DNAME can't point to the same name (direct self-reference)
        if (strtolower($name) === strtolower($content)) {
            return ValidationResult::failure(_('DNAME record cannot point to itself.'));
        }

        // Check for potential DNAME loops or circular references
        if (str_ends_with(strtolower($content), strtolower('.' . $name))) {
            $warnings[] = _('The DNAME target appears to be a subdomain of the owner name. This could create problematic circular references when resolving subdomains.');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Priority for DNAME records should be 0
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for DNAME records must be 0 or empty.'));
        }

        // Add RFC 6672 specific warnings
        $warnings[] = _('According to RFC 6672, DNAME records must be unique at a given owner name (singleton rule).');
        $warnings[] = _('DNAME and CNAME records MUST NOT coexist at the same owner name (RFC 6672 Section 2.3).');

        if (!$isZoneApex) {
            $warnings[] = _('DNAME records MUST NOT appear at the same owner name as NS records, unless the owner name is the zone apex.');
        }

        $warnings[] = _('DNAME creates synthetic aliases for all subdomains of its owner name. Consider this carefully when planning your zone structure.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }
}
