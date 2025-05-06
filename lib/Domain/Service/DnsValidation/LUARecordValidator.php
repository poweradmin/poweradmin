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
 * LUA record validator
 *
 * LUA records allow PowerDNS to execute Lua scripts for dynamic content generation.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class LUARecordValidator implements DnsRecordValidatorInterface
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
     * Validate a LUA record
     *
     * @param string $content The content part of the record (Lua script code)
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for LUA records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('LUA record content cannot be empty.'));
        }

        // Validate that content has valid characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in LUA record content.'));
        }

        // Check if the content follows LUA format pattern
        $contentResult = $this->validateLuaContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for LUA records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for LUA records must be 0 or empty'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // LUA records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate LUA record content format
     *
     * LUA record content should typically start with a recognized pattern
     *
     * @param string $content The LUA record content
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateLuaContent(string $content): ValidationResult
    {
        // Basic check that content appears to be a Lua script
        // PowerDNS typically expects LUA records to have a format like:
        // "function x(dname, ip) ... end" or similar pattern

        $trimmedContent = trim($content);

        // Check if it contains basic Lua keywords/patterns
        $hasFunction = str_contains($trimmedContent, 'function');
        $hasEnd = str_contains($trimmedContent, 'end');

        if (!$hasFunction || !$hasEnd) {
            return ValidationResult::failure(_('LUA record should contain a valid Lua function with "function" and "end" keywords.'));
        }

        return ValidationResult::success(true);
    }
}
