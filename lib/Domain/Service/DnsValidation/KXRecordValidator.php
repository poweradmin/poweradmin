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
 * KX Record Validator
 *
 * KX records (Key Exchanger) map a domain name to a mail server that will act
 * as a key exchanger for that domain.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class KXRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate KX record
     *
     * @param string $content Key exchanger hostname
     * @param string $name Domain name for the KX record
     * @param mixed $prio Priority value
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate content (key exchanger hostname)
        $contentResult = $this->hostnameValidator->validate($content, false);
        if (!$contentResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid key exchanger hostname.')], $contentResult->getErrors())
            );
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate priority
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            $errors[] = $prioResult->getFirstError();
            return ValidationResult::errors($errors);
        }
        $validatedPrio = $prioResult->getData();

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for KX records
     * KX records require a numeric priority between 0 and 65535
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, use default of 10
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(10);
        }

        // Priority must be a number between 0 and 65535
        if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
            return ValidationResult::success((int)$prio);
        }

        return ValidationResult::failure(_('Invalid value for KX priority field. Must be between 0 and 65535.'));
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param mixed $prio Priority value
     * @return int|bool The validated priority value or false if invalid
     */
    private function validatePriorityLegacy(mixed $prio): int|bool
    {
        $result = $this->validatePriority($prio);
        if (!$result->isValid()) {
            return false;
        }
        return $result->getData();
    }
}
