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
 * HINFO record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class HINFORecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates HINFO record content
     *
     * @param string $content The content of the HINFO record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for HINFO records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate HINFO content
        if (!$this->isValidHinfoContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => 0, // HINFO records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validate HINFO record content format
     *
     * @param string $content HINFO record content
     *
     * @return bool True if valid, false otherwise
     */    private function isValidHinfoContent(string $content): bool
    {
        if (empty($content)) {
            $this->messageService->addSystemError(_('HINFO record must have CPU type and OS fields.'));
            return false;
        }

        // Validate overall format first
        if (!preg_match('/^(?:"[^"]*"|[^\s"]+)\s+(?:"[^"]*"|[^\s"]+)$/', $content)) {
            $this->messageService->addSystemError(_('HINFO record must have exactly two fields: CPU type and OS.'));
            return false;
        }

        // First split by space while respecting quotes
        preg_match_all('/(?:"[^"]*"|[^\s"]+)/', $content, $matches);
        $fields = $matches[0];

        // Must have exactly 2 fields
        if (count($fields) !== 2) {
            $this->messageService->addSystemError(_('HINFO record must have exactly two fields: CPU type and OS.'));
            return false;
        }

        // Validate each field
        foreach ($fields as $field) {
            // Check for proper quoting
            if ($field[0] === '"') {
                // Field starts with quote must end with quote
                if (substr($field, -1) !== '"') {
                    $this->messageService->addSystemError(_('Invalid quoting in HINFO record field.'));
                    return false;
                }
                // Must have exactly two quotes (start and end)
                if (substr_count($field, '"') !== 2) {
                    $this->messageService->addSystemError(_('Invalid quoting in HINFO record field.'));
                    return false;
                }
            } elseif (strpos($field, '"') !== false) {
                // If not properly quoted, should not contain any quotes
                $this->messageService->addSystemError(_('Invalid quoting in HINFO record field.'));
                return false;
            }
            if ($field[0] === '"') {
                if (substr($field, -1) !== '"' || substr_count($field, '"') !== 2) {
                    $this->messageService->addSystemError(_('Invalid quoting in HINFO record field.'));
                    return false;
                }
            }

            // Remove quotes for length and content validation
            $value = trim($field, '"');
            
            // Check if field is empty or just whitespace
            if (empty($value) || trim($value) === '') {
                $this->messageService->addSystemError(_('HINFO record fields cannot be empty.'));
                return false;
            }

            // Check field length (after removing quotes)
            if (strlen($value) > 1000) {
                $this->messageService->addSystemError(_('HINFO record field exceeds maximum length of 1000 characters.'));
                return false;
            }

            // Check for unmatched quotes within the value
            if (strpos($value, '"') !== false) {
                $this->messageService->addSystemError(_('Invalid quote marks within HINFO record field.'));
                return false;
            }
        }

        return true;
    }
}
