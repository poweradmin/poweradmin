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
 * NID record validator
 *
 * NID records are used for Node Identifier in Identifier-Locator Network Protocol (ILNP).
 * NID record format is a 16-bit preference followed by a 64-bit Node Identifier value.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NIDRecordValidator implements DnsRecordValidatorInterface
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
     * Validate an NID record
     *
     * @param string $content The content part of the record (Node Identifier value)
     * @param string $name The name part of the record
     * @param mixed $prio The preference value (0-65535)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('NID record content cannot be empty.'));
            return false;
        }

        // Validate that content has valid characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Validate the content format (64-bit hexadecimal value)
        if (!$this->isValidNodeIdentifier($content)) {
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // Validate priority (preference)
        $priority = $this->validatePreference($prio);
        if ($priority === false) {
            return false;
        }

        return [
            'content' => $content,
            'ttl' => $validatedTtl,
            'priority' => $priority
        ];
    }

    /**
     * Validate the Node Identifier value
     * It should be a 64-bit hexadecimal value
     *
     * @param string $content The Node Identifier value
     * @return bool True if valid, false otherwise
     */
    private function isValidNodeIdentifier(string $content): bool
    {
        // Check if the content is a valid 64-bit hexadecimal value (16 hex characters)
        if (!preg_match('/^[0-9a-fA-F]{16}$/', trim($content))) {
            $this->messageService->addSystemError(_('NID record content must be a 64-bit hexadecimal value (16 hex characters).'));
            return false;
        }

        return true;
    }

    /**
     * Validate and parse the preference value
     * It should be an integer between 0 and 65535
     *
     * @param mixed $prio The preference value
     * @return int|bool The validated preference or false if invalid
     */
    private function validatePreference(mixed $prio): int|bool
    {
        // If empty, use default of 10
        if ($prio === '' || $prio === null) {
            return 10;
        }

        // Must be numeric
        if (!is_numeric($prio)) {
            $this->messageService->addSystemError(_('NID record preference must be a number.'));
            return false;
        }

        $prioInt = (int)$prio;

        // Must be between 0 and 65535
        if ($prioInt < 0 || $prioInt > 65535) {
            $this->messageService->addSystemError(_('NID record preference must be between 0 and 65535.'));
            return false;
        }

        return $prioInt;
    }
}
