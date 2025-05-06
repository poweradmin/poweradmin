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
 * Validator for A DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ARecordValidator implements DnsRecordValidatorInterface
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
     * Validate A record
     *
     * @param string $content IPv4 address
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for A records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult<array> ValidationResult containing validated data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate IPv4 address
        $ipv4Result = $this->ipAddressValidator->validateIPv4($content);
        if (!$ipv4Result->isValid()) {
            $errors[] = _('Invalid IPv4 address format.');
        }

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $hostnameResult->getErrors()));
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $ttlResult->getErrors()));
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for A records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $prioResult->getErrors()));
        }
        $validatedPrio = $prioResult->getData();

        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for A records
     * A records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult<int> ValidationResult with validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for A records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. A records must have priority value of 0.'));
    }
}
