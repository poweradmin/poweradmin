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
 * NID (Node Identifier) Record Validator
 *
 * Validates NID records according to RFC 6742 (ILNP DNS Resource Records).
 *
 * NID records are used for Node Identifier in Identifier-Locator Network Protocol (ILNP).
 * The NID record contains a 16-bit preference value followed by a 64-bit Node Identifier value
 * in the EUI-64 format.
 *
 * Format:
 * - Preference: 16-bit unsigned integer (0-65535)
 * - NodeID: 64-bit value in EUI-64 format, represented as 4 groups of 4 hex digits
 *   separated by colons (xxxx:xxxx:xxxx:xxxx)
 *
 * According to RFC 6742:
 * - The NodeID MUST be in the modified EUI-64 format
 * - The NodeID MUST NOT be in the compressed format
 * - The u/l bit (universal/local bit, 7th bit of first byte) indicates if the ID is globally unique
 * - The g bit (Group bit, least significant bit of first byte) MUST be 0 in ILNP
 *
 * Example NID record: 10 1000:0000:0000:0001
 *
 * Note: ILNP is an experimental protocol (RFC 6740) that provides identifier-locator
 * separation to enhance multihoming capabilities.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NIDRecordValidator implements DnsRecordValidatorInterface
{
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $_config)
    {
        // ConfigurationManager parameter is kept for interface consistency
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate an NID record according to RFC 6742
     *
     * @param string $content The content part of the record (Node Identifier value)
     * @param string $name The name part of the record
     * @param mixed $prio The preference value (0-65535)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Add warning about ILNP being experimental
        $warnings[] = _('Note: NID records are used for the ILNP protocol, which is experimental (RFC 6740, 6742).');

        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('NID record content cannot be empty.'));
        }

        // Validate that content has valid characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return $printableResult;
        }

        // Validate the content format (according to RFC 6742)
        $nodeIdResult = $this->validateNodeIdentifier($content);
        if (!$nodeIdResult->isValid()) {
            return $nodeIdResult;
        }

        $nodeIdData = $nodeIdResult->getData();

        // Get the formatted NodeID in the RFC presentation format
        $formattedNodeId = $nodeIdData['node_id'];

        // Add any warnings from the NodeID validation
        if ($nodeIdResult->hasWarnings()) {
            $warnings = array_merge($warnings, $nodeIdResult->getWarnings());
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (preference)
        $priorityResult = $this->validatePreference($prio);
        if (!$priorityResult->isValid()) {
            return $priorityResult;
        }
        $priority = $priorityResult->getData();

        return ValidationResult::success([
            'content' => $formattedNodeId,
            'ttl' => $validatedTtl,
            'priority' => $priority,
            'name' => $name,
            'raw_node_id' => $nodeIdData['raw_hex']
        ], $warnings);
    }

    /**
     * Validate the Node Identifier value
     * According to RFC 6742, it should be a 64-bit hexadecimal value in EUI-64 format
     * represented as 4 groups of 4 hex digits separated by colons
     *
     * @param string $content The Node Identifier value
     * @return ValidationResult Validation result with validated and formatted NodeID or error
     */
    private function validateNodeIdentifier(string $content): ValidationResult
    {
        $content = trim($content);
        $rawHex = '';
        $warnings = [];

        // Handle colon-separated format (RFC 6742 presentation format)
        if (strpos($content, ':') !== false) {
            // Check format xxxx:xxxx:xxxx:xxxx (4 groups of 4 hex digits with colons)
            if (!preg_match('/^[0-9a-fA-F]{1,4}(:[0-9a-fA-F]{1,4}){3}$/', $content)) {
                return ValidationResult::failure(_('NID record content must be in format xxxx:xxxx:xxxx:xxxx with 4 groups of 4 hexadecimal digits.'));
            }

            // Split by colon and validate each group has 4 digits
            $groups = explode(':', $content);
            if (count($groups) !== 4) {
                return ValidationResult::failure(_('NID record content must have exactly 4 groups of hexadecimal digits.'));
            }

            // Zero-pad each group to 4 digits and build raw hex value
            foreach ($groups as $index => $group) {
                $groups[$index] = str_pad($group, 4, '0', STR_PAD_LEFT);
            }

            $rawHex = implode('', $groups);
        } else {
            // Check if the content is a valid 64-bit hexadecimal value (16 hex characters)
            if (!preg_match('/^[0-9a-fA-F]{16}$/', $content)) {
                return ValidationResult::failure(_(
                    'NID record content must be a 64-bit hexadecimal value (16 hex characters or xxxx:xxxx:xxxx:xxxx format).'
                ));
            }

            $rawHex = $content;

            // If using raw format, add a warning about RFC 6742 presentation format
            $warnings[] = _('NID records should use the RFC 6742 presentation format (xxxx:xxxx:xxxx:xxxx) with colons for better readability.');
        }

        // Validate EUI-64 format requirements (RFC 6742)
        $eui64Result = $this->validateEUI64Format($rawHex);
        if (!$eui64Result->isValid()) {
            return $eui64Result;
        }

        // Merge any EUI-64 validation warnings
        if ($eui64Result->hasWarnings()) {
            $warnings = array_merge($warnings, $eui64Result->getWarnings());
        }

        // Format for storage according to RFC presentation format
        $formattedHex = $this->formatNodeIdentifier($rawHex);

        return ValidationResult::success([
            'node_id' => $formattedHex,
            'raw_hex' => $rawHex], $warnings);
    }

    /**
     * Validate that the NodeID complies with EUI-64 format requirements per RFC 6742
     *
     * @param string $hexString Raw 16-character hex string
     * @return ValidationResult Validation result
     */
    private function validateEUI64Format(string $hexString): ValidationResult
    {
        $warnings = [];

        // Convert first byte to binary to check bit values
        $firstByte = hexdec(substr($hexString, 0, 2));

        // Check the Group bit (RFC 6740 states it should be 0 for ILNP)
        // The Group bit is the least significant bit of the first octet
        if (($firstByte & 0x01) !== 0) {
            return ValidationResult::failure(_(
                'Invalid NID record: The Group bit (g bit, least significant bit of first byte) MUST be 0 in ILNP as specified in RFC 6742.'
            ));
        }

        // Check the universal/local bit (7th bit of first byte)
        // This is informational only, so we add a warning if it's set to local
        if (($firstByte & 0x02) === 0) {
            $warnings[] = _('The universal/local bit (u/l bit) is set to universal (0), indicating this NID is based on a globally unique identifier.');
        } else {
            $warnings[] = _('The universal/local bit (u/l bit) is set to local (1), indicating this NID is locally assigned and not globally unique.');
        }

        // Check for all zeros, which is technically valid but unusual
        if ($hexString === '0000000000000000') {
            $warnings[] = _('A zero NodeID value is unusual and may indicate a configuration error.');
        }

        // Check for all ones, which is technically valid but unusual
        if ($hexString === 'FFFFFFFFFFFFFFFF') {
            $warnings[] = _('An all-ones NodeID value is unusual and may indicate a configuration error.');
        }

        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Format a raw hex string into RFC 6742 presentation format (with colons)
     *
     * @param string $rawHex 16-character hex string
     * @return string Formatted hex string with colons (xxxx:xxxx:xxxx:xxxx)
     */
    private function formatNodeIdentifier(string $rawHex): string
    {
        // Insert colons after every 4 characters
        return implode(':', str_split($rawHex, 4));
    }

    /**
     * Validate and parse the preference value
     * It should be an integer between 0 and 65535
     *
     * @param mixed $prio The preference value
     * @return ValidationResult Validation result with the validated preference or error
     */
    private function validatePreference(mixed $prio): ValidationResult
    {
        // If empty, use default of 10
        if ($prio === '' || $prio === null) {
            return ValidationResult::success(10);
        }

        // Must be numeric
        if (!is_numeric($prio)) {
            return ValidationResult::failure(_('NID record preference must be a number.'));
        }

        $prioInt = (int)$prio;

        // Must be between 0 and 65535
        if ($prioInt < 0 || $prioInt > 65535) {
            return ValidationResult::failure(_('NID record preference must be between 0 and 65535.'));
        }

        return ValidationResult::success($prioInt);
    }
}
