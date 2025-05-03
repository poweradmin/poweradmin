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
    private MessageService $messageService;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator($config);
    }

    /**
     * Validate a LUA record
     *
     * @param string $content The content part of the record (Lua script code)
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (not used for LUA records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('LUA record content cannot be empty.'));
            return false;
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Check if the content follows LUA format pattern
        if (!$this->isValidLuaContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // LUA records don't use priority, so it's always 0
        $priority = 0;

        return [
            'content' => $content,
            'ttl' => $validatedTtl,
            'priority' => $priority
        ];
    }

    /**
     * Validate LUA record content format
     *
     * LUA record content should typically start with a recognized pattern
     *
     * @param string $content The LUA record content
     * @return bool True if format is valid, false otherwise
     */
    private function isValidLuaContent(string $content): bool
    {
        // Basic check that content appears to be a Lua script
        // PowerDNS typically expects LUA records to have a format like:
        // "function x(dname, ip) ... end" or similar pattern

        $trimmedContent = trim($content);

        // Check if it contains basic Lua keywords/patterns
        $hasFunction = str_contains($trimmedContent, 'function');
        $hasEnd = str_contains($trimmedContent, 'end');

        if (!$hasFunction || !$hasEnd) {
            $this->messageService->addSystemError(_('LUA record should contain a valid Lua function with "function" and "end" keywords.'));
            return false;
        }

        return true;
    }
}
