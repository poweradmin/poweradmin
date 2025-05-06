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
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates HINFO record content
     *
     * @param string $content The content of the HINFO record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for HINFO records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate HINFO content
        $contentResult = $this->validateHinfoContent($content);
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

        // Validate priority
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
     * Validate priority for HINFO records
     * HINFO records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for HINFO records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Priority field for HINFO records must be 0 or empty'));
    }

    /**
     * Validate HINFO record content format
     *
     * @param string $content HINFO record content
     * @return ValidationResult ValidationResult containing validation success or error message
     */
    private function validateHinfoContent(string $content): ValidationResult
    {
        if (empty($content)) {
            return ValidationResult::failure(_('HINFO record must have CPU type and OS fields.'));
        }

        // Validate overall format first
        if (!preg_match('/^(?:"[^"]*"|[^\s"]+)\s+(?:"[^"]*"|[^\s"]+)$/', $content)) {
            return ValidationResult::failure(_('HINFO record must have exactly two fields: CPU type and OS.'));
        }

        // First split by space while respecting quotes
        preg_match_all('/(?:"[^"]*"|[^\s"]+)/', $content, $matches);
        $fields = $matches[0];

        // Must have exactly 2 fields
        if (count($fields) !== 2) {
            return ValidationResult::failure(_('HINFO record must have exactly two fields: CPU type and OS.'));
        }

        // Validate each field
        foreach ($fields as $field) {
            // Check for proper quoting
            if ($field[0] === '"') {
                // Field starts with quote must end with quote
                if (substr($field, -1) !== '"') {
                    return ValidationResult::failure(_('Invalid quoting in HINFO record field.'));
                }
                // Must have exactly two quotes (start and end)
                if (substr_count($field, '"') !== 2) {
                    return ValidationResult::failure(_('Invalid quoting in HINFO record field.'));
                }
            } elseif (strpos($field, '"') !== false) {
                // If not properly quoted, should not contain any quotes
                return ValidationResult::failure(_('Invalid quoting in HINFO record field.'));
            }

            // Remove quotes for length and content validation
            $value = trim($field, '"');

            // Check if field is empty or just whitespace
            if (empty($value) || trim($value) === '') {
                return ValidationResult::failure(_('HINFO record fields cannot be empty.'));
            }

            // Check field length (after removing quotes)
            if (strlen($value) > 1000) {
                return ValidationResult::failure(_('HINFO record field exceeds maximum length of 1000 characters.'));
            }

            // Check for unmatched quotes within the value
            if (strpos($value, '"') !== false) {
                return ValidationResult::failure(_('Invalid quote marks within HINFO record field.'));
            }
        }

        return ValidationResult::success(true);
    }
}
