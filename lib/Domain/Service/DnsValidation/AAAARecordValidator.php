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
 * Validator for AAAA DNS records
 *
 * Validates AAAA records according to:
 * - RFC 3596: DNS Extensions to Support IP Version 6
 * - RFC 4291: IP Version 6 Addressing Architecture
 * - RFC 5952: A Recommendation for IPv6 Address Text Representation
 *
 * AAAA records map domain names to IPv6 addresses.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class AAAARecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private IPAddressValidator $ipAddressValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ipAddressValidator = new IPAddressValidator();
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate AAAA record
     *
     * @param string $content IPv6 address
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for AAAA records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate IPv6 address according to RFC 3596, RFC 4291, and RFC 5952
        // Pass true for canonicalForm parameter to enforce RFC 5952 recommendations
        $ipv6Result = $this->ipAddressValidator->validateIPv6($content, true);
        if (!$ipv6Result->isValid()) {
            return $ipv6Result; // Return the detailed error messages from the validator
        }

        // Check for IPv6 addresses that shouldn't be used in DNS
        if ($content === '::' || $content === '::1') {
            return ValidationResult::failure(_('Unspecified (::) or loopback (::1) IPv6 addresses should not be used in AAAA records.'));
        }

        // Check for site-local deprecated addresses (fec0::/10)
        if (preg_match('/^fe[c-f][0-9a-f]:/i', $content)) {
            return ValidationResult::failure(_('Site-local IPv6 addresses (fec0::/10) are deprecated and should not be used.'));
        }

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for AAAA records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for AAAA records
     * AAAA records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult with validated priority or error
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for AAAA records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. AAAA records must have priority value of 0.'));
    }
}
