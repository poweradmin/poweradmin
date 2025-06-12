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
 * The HINFO (Host Information) record is used to acquire general information about a host.
 * It provides CPU type and operating system information which can be used by application
 * protocols to use special procedures when communicating with specific computer architectures.
 *
 * Format: <CPU> <OS>
 * Where:
 * - CPU: A character-string which specifies the CPU type
 * - OS: A character-string which specifies the operating system type
 *
 * Both fields must be present. If a field contains spaces, it must be enclosed in double quotes.
 *
 * Examples:
 * - "Intel Core i7" "Windows 10"
 * - INTEL LINUX
 * - "AMD Ryzen" "Ubuntu 22.04"
 *
 * Note: For security reasons, HINFO records are not recommended for public-facing DNS as they
 * can reveal potentially sensitive information about the host's hardware and software configuration
 * that could be useful to attackers.
 *
 * @see https://www.ietf.org/rfc/rfc1035.txt RFC 1035: Domain Names - Implementation and Specification
 * @see https://www.rfc-editor.org/rfc/rfc1700 RFC 1700: Assigned Numbers (for standard CPU and OS types)
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
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
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

        // Collect warnings from content validation
        $warnings = $contentResult->hasWarnings() ? $contentResult->getWarnings() : [];

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

        // Add warning about security implications of HINFO records
        $warnings[] = _('Warning: HINFO records reveal potentially sensitive information about host hardware and software that could be useful to attackers. Consider security implications before using in public-facing DNS.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
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
     * Validate HINFO record content format according to RFC 1035
     *
     * @param string $content HINFO record content
     * @return ValidationResult ValidationResult containing validation success or error message
     */
    private function validateHinfoContent(string $content): ValidationResult
    {
        if (empty($content)) {
            return ValidationResult::failure(_('HINFO record must have CPU type and OS fields.'));
        }

        $warnings = [];

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

        // Extract CPU and OS fields for validation
        $cpu = $fields[0];
        $os = $fields[1];
        $cpuValue = '';
        $osValue = '';

        // Validate each field
        foreach ([$cpu, $os] as $index => $field) {
            $fieldName = $index === 0 ? 'CPU' : 'OS';

            // Check for proper quoting
            if ($field[0] === '"') {
                // Field starts with quote must end with quote
                if (substr($field, -1) !== '"') {
                    return ValidationResult::failure(_('Invalid quoting in HINFO ' . $fieldName . ' field. Double quotes must appear at the beginning and end of the string.'));
                }
                // Must have exactly two quotes (start and end)
                if (substr_count($field, '"') !== 2) {
                    return ValidationResult::failure(_('Invalid quoting in HINFO ' . $fieldName . ' field. Found unbalanced quotes.'));
                }
            } elseif (strpos($field, '"') !== false) {
                // If not properly quoted, should not contain any quotes
                return ValidationResult::failure(_('Invalid quoting in HINFO ' . $fieldName . ' field. If using quotes, the entire string must be quoted.'));
            }

            // Remove quotes for length and content validation
            $value = trim($field, '"');
            if ($index === 0) {
                $cpuValue = $value;
            } else {
                $osValue = $value;
            }

            // Check if field is empty or just whitespace
            if (empty($value) || trim($value) === '') {
                return ValidationResult::failure(_('HINFO ' . $fieldName . ' field cannot be empty.'));
            }

            // Check field length (after removing quotes)
            // RFC 1035 specifies <character-string> can be up to 255 octets
            if (strlen($value) > 255) {
                return ValidationResult::failure(_('HINFO ' . $fieldName . ' field exceeds maximum length of 255 characters.'));
            }

            // Check for unmatched quotes within the value
            if (strpos($value, '"') !== false) {
                return ValidationResult::failure(_('Invalid quote marks within HINFO ' . $fieldName . ' field.'));
            }

            // Check for control characters (not allowed in DNS character strings)
            if (preg_match('/[\x00-\x1F]/', $value)) {
                return ValidationResult::failure(_('HINFO ' . $fieldName . ' field contains control characters, which are not allowed.'));
            }
        }

        // Recommend standard values as per RFC 1700 if non-standard values are used
        $standardCPUs = ['VAX', 'ALPHA', 'PENTIUM', 'INTEL-386', 'INTEL-486', 'INTEL', 'AMD64', 'ARM', 'SPARC', 'MIPS', 'PPC', 'IBM370', 'IBM-PC', 'PC', 'PC/AT', 'CRAY'];
        $standardOSs = ['UNIX', 'LINUX', 'WIN32', 'WINDOWS', 'MACOS', 'IOS', 'ANDROID', 'DOS', 'PLAN9', 'BSD', 'OS/2', 'OSX', 'VMS', 'VM/CMS', 'MVS'];

        $cpuUppercase = strtoupper($cpuValue);
        $osUppercase = strtoupper($osValue);

        $foundStandardCPU = false;
        $foundStandardOS = false;

        foreach ($standardCPUs as $stdCPU) {
            if (strpos($cpuUppercase, $stdCPU) !== false) {
                $foundStandardCPU = true;
                break;
            }
        }

        foreach ($standardOSs as $stdOS) {
            if (strpos($osUppercase, $stdOS) !== false) {
                $foundStandardOS = true;
                break;
            }
        }

        if (!$foundStandardCPU) {
            $warnings[] = _('CPU type does not contain any standard values. Consider using a standard CPU type for better interoperability.');
        }

        if (!$foundStandardOS) {
            $warnings[] = _('OS type does not contain any standard values. Consider using a standard OS type for better interoperability.');
        }

        return ValidationResult::success(true, $warnings);
    }
}
