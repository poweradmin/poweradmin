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
 * AFSDB record validator
 *
 * Validates AFSDB (AFS Database) records according to:
 * - RFC 1183 Section 1: AFS Data Base location (AFSDB RR)
 * - RFC 5864: DNS SRV Resource Records for AFS (updates RFC 1183)
 *
 * AFSDB RRs map from a domain name to the name of an AFS cell database server
 * or Authenticated Name Server for DCE/NCA cell.
 *
 * Format: <domain-name> [<ttl>] [<class>] AFSDB <subtype> <hostname>
 *
 * Where:
 * - <subtype> 1: AFS cell database server
 * - <subtype> 2: DCE authenticated name server
 *
 * Note: RFC 5864 deprecates the use of AFSDB RR to locate AFS cell database servers
 * in favor of SRV records.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class AFSDBRecordValidator implements DnsRecordValidatorInterface
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
     * Validates AFSDB record content
     *
     * @param string $content The content of the AFSDB record (hostname of the server)
     * @param string $name The name of the record (domain-name being mapped)
     * @param mixed $prio The subtype value for AFSDB record (1 or 2)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Add deprecation warning according to RFC 5864
        $warnings[] = _('AFSDB records are deprecated by RFC 5864 in favor of SRV records for AFS service location.');

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate AFSDB content (hostname) - RFC 1183 requires this to be a fully qualified domain name
        $contentResult = $this->hostnameValidator->validate($content, false);
        if (!$contentResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid AFSDB hostname.')], $contentResult->getErrors())
            );
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // Validate that content is a fully qualified domain name as required by RFC 1183
        // Check that it has at least one dot to indicate it's a multi-part domain
        if (strpos($content, '.') === false) {
            return ValidationResult::failure(_('AFSDB server hostname must be a fully qualified domain name (FQDN).'));
        }

        // Count the number of hostname labels (parts separated by dots)
        $parts = explode('.', $content);
        if (count($parts) < 2) {
            return ValidationResult::failure(_('AFSDB server hostname must be a fully qualified domain name with at least two parts.'));
        }

        // Validate subtype (stored in priority field)
        $subtypeResult = $this->validateSubtype($prio);
        if (!$subtypeResult->isValid()) {
            return $subtypeResult;
        }
        $validatedSubtype = $subtypeResult->getData();

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $ttlResult->getErrors()));
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        if (count($errors) > 0) {
            return ValidationResult::errors($errors);
        }

        // Return success with data and any warnings
        $result = [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedSubtype,
            'ttl' => $validatedTtl
        ];

        return ValidationResult::success($result, $warnings);
    }

    /**
     * Validate subtype for AFSDB records
     * AFSDB records accept subtypes 1 (AFS cell database server) or 2 (DCE authenticated name server)
     *
     * @param mixed $subtype Subtype value
     *
     * @return ValidationResult ValidationResult with validated subtype or error message
     */
    private function validateSubtype(mixed $subtype): ValidationResult
    {
        // If subtype is not provided or empty, use default of 1
        if (!isset($subtype) || $subtype === "") {
            return ValidationResult::success(1);
        }

        // Subtype should be either 1 or 2 for AFSDB
        if (is_numeric($subtype) && ($subtype == 1 || $subtype == 2)) {
            return ValidationResult::success((int)$subtype);
        }

        return ValidationResult::failure(_('Invalid AFSDB subtype. Must be 1 (AFS cell database server) or 2 (DCE authenticated name server).'));
    }
}
