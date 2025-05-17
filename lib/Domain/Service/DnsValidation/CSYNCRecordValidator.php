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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Validator for CSYNC DNS records
 *
 * CSYNC (Child-To-Parent Synchronization) records are used to synchronize DNS data
 * from a child zone to its parent zone as defined in RFC 7477.
 *
 * The format is: <SOA-Serial> <Flags> <Type-Bit-Map>
 *
 * - SOA-Serial: 32-bit unsigned integer (0 - 4,294,967,295) representing a minimum
 *   SOA serial number that a parental agent needs to see
 * - Flags: 16-bit field containing boolean flags:
 *    0x0000 (0): No flags set
 *    0x0001 (1): "immediate" - when set, parental agent may process record immediately
 *    0x0002 (2): "soaminimum" - when set, enforces SOA serial number validation
 *    0x0003 (3): Both flags set
 * - Type-Bit-Map: The set of record types to be synchronized (space-separated)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7477 RFC 7477: Child-To-Parent Synchronization in DNS
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CSYNCRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate CSYNC record
     *
     * @param string $content CSYNC record content in format: "SOA_SERIAL FLAGS TYPE1 [TYPE2...]"
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for CSYNC records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $warnings = [];

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // RFC 7477 requires CSYNC records to be placed at the apex of a zone
        // Check if name looks like a zone apex (no subdomain)
        $nameParts = explode('.', $name);
        if (count($nameParts) > 2 && $nameParts[0] !== '@') {
            $warnings[] = _('CSYNC records should only be placed at the zone apex (RFC 7477). This record may not be processed by parental agents.');
        }

        // Validate CSYNC content format
        $contentResult = $this->validateCSYNCContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Collect warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for CSYNC records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        // Add DNSSEC security warning
        $warnings[] = _('CSYNC records require DNSSEC signing to be processed by parental agents (RFC 7477). Ensure your zone is properly signed with DNSSEC.');

        // Add warning about single record requirement
        $warnings[] = _('Only one CSYNC record should exist per zone (RFC 7477). Multiple records may cause unexpected behavior.');

        // Parse flags and provide specific warnings
        $flagsValue = 0;
        $fields = preg_split("/\s+/", trim($content));
        if (isset($fields[1]) && is_numeric($fields[1])) {
            $flagsValue = (int)$fields[1];

            if ($flagsValue === 0) {
                $warnings[] = _('No flags are set in this CSYNC record. The record may not be processed immediately by parental agents.');
            }

            if (($flagsValue & 0x0001) === 0) {
                $warnings[] = _('The "immediate" flag (0x0001) is not set. Parental agents may delay processing this record.');
            }

            if (($flagsValue & 0x0002) === 0) {
                $warnings[] = _('The "soaminimum" flag (0x0002) is not set. SOA serial number validation may not be enforced.');
            }
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate priority for CSYNC records
     * CSYNC records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing the validated priority value or error
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for CSYNC records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. CSYNC records must have priority value of 0.'));
    }

    /**
     * Check if CSYNC content is valid
     *
     * Validates the CSYNC record content according to RFC 7477 requirements.
     *
     * @param string $content CSYNC record content
     * @return ValidationResult ValidationResult containing validation result
     */
    public function validateCSYNCContent(string $content): ValidationResult
    {
        $warnings = [];
        $fields = preg_split("/\s+/", trim($content));

        // Validate SOA Serial (first field)
        if (!isset($fields[0]) || !is_numeric($fields[0]) || (int)$fields[0] < 0 || (int)$fields[0] > 4294967295) {
            return ValidationResult::failure(_('Invalid SOA Serial in CSYNC record. It must be a 32-bit unsigned integer (0-4294967295).'));
        }

        // Validate Flags (second field)
        if (!isset($fields[1]) || !is_numeric($fields[1])) {
            return ValidationResult::failure(_('Invalid Flags in CSYNC record. Flags must be a numeric value.'));
        }

        $flagsValue = (int)$fields[1];

        // The Flags field is a 16-bit field, so values must be 0-65535
        if ($flagsValue < 0 || $flagsValue > 65535) {
            return ValidationResult::failure(_('Invalid Flags in CSYNC record. Flags must be between 0 and 65535.'));
        }

        // Check for unsupported flag values
        // RFC 7477 only defines bits 0 and 1, which mean:
        // Bit 0 (value 1): "immediate" flag
        // Bit 1 (value 2): "soaminimum" flag
        // Only bits 0-1 are defined, so valid values are 0, 1, 2, or 3
        if ($flagsValue > 3) {
            return ValidationResult::failure(_('Invalid Flags in CSYNC record. Only bits 0-1 are defined (values 0-3). Valid values are 0, 1, 2, or 3.'));
        }

        // Validate Type Bit Map (remaining fields)
        if (count($fields) <= 2) {
            // At least one type must be specified
            return ValidationResult::failure(_('CSYNC record must specify at least one record type in the Type Bit Map field.'));
        }

        // Record types that are ALLOWED to be synchronized (per RFC 7477)
        $validTypes = [
            RecordType::A, RecordType::AAAA, RecordType::CNAME, RecordType::DNAME, RecordType::MX, RecordType::NS,
            RecordType::PTR, RecordType::SRV, RecordType::TXT
        ];

        // Record types that are EXPLICITLY PROHIBITED from synchronization (per RFC 7477)
        $prohibitedTypes = [
            RecordType::DNSKEY, RecordType::DS, RecordType::CDS, RecordType::CDNSKEY, RecordType::CSYNC
        ];

        // Validate each specified record type
        $typesFound = [];
        for ($i = 2; $i < count($fields); $i++) {
            $currentType = strtoupper($fields[$i]);

            // Check for invalid types
            if (!in_array($currentType, $validTypes) && !in_array($currentType, $prohibitedTypes)) {
                return ValidationResult::failure(_('Invalid Type "' . $currentType . '" in CSYNC record Type Bit Map. Supported types include A, AAAA, NS, MX, etc.'));
            }

            // Check for prohibited types
            if (in_array($currentType, $prohibitedTypes)) {
                return ValidationResult::failure(_('Type "' . $currentType . '" is prohibited in CSYNC records. DNSSEC-related records (DS, DNSKEY, CDS, CDNSKEY) and CSYNC records must not be synchronized.'));
            }

            // Store found types to check for duplicates
            if (in_array($currentType, $typesFound)) {
                $warnings[] = _('Duplicate type "' . $currentType . '" specified in Type Bit Map. Each type should only be specified once.');
            } else {
                $typesFound[] = $currentType;
            }
        }

        // NS record specific warning
        if (in_array('NS', $typesFound)) {
            $warnings[] = _('NS record synchronization requires careful consideration. Parent zones may have specific NS record policies that can prevent successful synchronization.');
        }

        // Warning about RFC 4034 Type Bit Map format
        $warnings[] = _('Note: The true wire format for CSYNC Type Bit Map follows RFC 4034 Section 4.1.2 format. Poweradmin uses a simplified representation with space-separated record types.');

        // All or nothing processing warning
        $warnings[] = _('RFC 7477 requires an "all or nothing" approach to CSYNC processing. If any type cannot be synchronized, no changes will be made by the parent.');

        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Validate CSYNC content for public use
     *
     * Validates the CSYNC record content according to RFC 7477 requirements
     * and returns a ValidationResult with appropriate warnings.
     *
     * @param string $content CSYNC record content
     *
     * @return ValidationResult ValidationResult containing validation status, error message, or warnings
     */
    public function validateCSYNCRecordContent(string $content): ValidationResult
    {
        return $this->validateCSYNCContent($content);
    }
}
