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
 * Validator for ALIAS DNS records
 *
 * ALIAS is a PowerDNS-specific record type (65401) that provides CNAME-like functionality
 * at zone apex (root domain). Unlike CNAME records, ALIAS records can coexist with other
 * record types for the same name.
 *
 * Format: <name> [<ttl>] IN ALIAS <target>
 *
 * When a resolver asks for an A or AAAA record for a name with an ALIAS record, PowerDNS
 * will resolve the target's A/AAAA records and return them as if they belonged to the name.
 *
 * Note that for proper functionality in PowerDNS, the expand-alias setting must be enabled
 * and a resolver must be configured. These settings are beyond the scope of this validator.
 *
 * @see https://doc.powerdns.com/authoritative/guides/alias.html
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ALIASRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->config = $config;
    }

    /**
     * Validate ALIAS record
     *
     * @param string $content Target hostname that the ALIAS points to
     * @param string $name ALIAS hostname (source name being aliased)
     * @param mixed $prio Priority (not used for ALIAS records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // 1. Validate ALIAS hostname
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // 2. Validate target hostname
        $contentResult = $this->hostnameValidator->validate($content, false);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // PowerDNS requires target hostnames to be fully qualified domain names
        if (strpos($content, '.') === false) {
            return ValidationResult::failure(_('ALIAS target must be a fully qualified domain name (FQDN).'));
        }

        // Self-referential ALIAS records can cause resolution loops
        if ($content === $name) {
            return ValidationResult::failure(_('ALIAS target cannot point to itself, as this would create a resolution loop.'));
        }

        // 3. Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // 4. Validate priority (should be 0 for ALIAS records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();


        if (count($errors) > 0) {
            return ValidationResult::errors($errors);
        }

        $result = [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];

        return ValidationResult::success($result, $warnings);
    }

    /**
     * Validate priority for ALIAS records
     * ALIAS records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult with validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for ALIAS records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. ALIAS records must have priority value of 0.'));
    }
}
