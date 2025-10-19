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
 * NAPTR (Naming Authority Pointer) Record Validator
 *
 * Validates NAPTR records according to:
 * - RFC 3403: Dynamic Delegation Discovery System (DDDS) Part Three: The DNS Database
 * - RFC 6116: The E.164 to Uniform Resource Identifiers (URI) Dynamic Delegation Discovery System (DDDS) Application (ENUM)
 * - ETSI TS 123 003: Numbering, addressing and identification (3GPP TS 23.003)
 * - 3GPP standards for telecommunications service discovery
 *
 * NAPTR records are used for translating one string into another or for replacing
 * one type of string with an entirely different one through regular expressions
 * and DNS lookups.
 *
 * NAPTR format: <order> <preference> <flags> <service> <regexp> <replacement>
 *
 * Example (terminal NAPTR with regexp):
 *   10 100 "u" "SIP+D2U" "!^.*$!sip:customer-service@example.com!" .
 *
 * Example (non-terminal NAPTR with replacement, per ETSI TS 123 003):
 *   10 100 "s" "x-3gpp-pgw:x-gn" "" next-lookup.example.com.
 *
 * Where:
 * - order: 16-bit unsigned integer specifying the order in which records MUST be processed
 * - preference: 16-bit unsigned integer specifying the order for processing records with same order
 * - flags: a character string containing flags to control DDDS processing (A, P, S, U, or "")
 * - service: a string specifying the service available
 *   * RFC format: [protocol]+[feature] (alphanumeric)
 *   * 3GPP format: allows hyphens, colons, and plus signs (e.g., "x-3gpp-pgw:x-gn")
 * - regexp: a string containing a substitution expression applied to the original string
 *   * Can be empty ("") when using replacement field (non-terminal NAPTR)
 * - replacement: a domain-name for the next rule in lookup or "." for terminal rule
 *   * Must be "." when regexp is non-empty
 *   * Can be a domain name when regexp is empty (non-terminal NAPTR)
 *
 * Common applications:
 * - ENUM (E.164 phone number to URI mapping)
 * - SIP (Session Initiation Protocol) service location
 * - XMPP service discovery
 * - S-NAPTR (Service-NAPTR) as defined in RFC 3958
 * - 3GPP/LTE network element discovery (PGW, SGW, etc.)
 *
 * Security considerations:
 * - NAPTR records with regexp fields can potentially be used for various injection attacks
 * - The validator includes security checks to prevent misuse of regexp patterns
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NAPTRRecordValidator implements DnsRecordValidatorInterface
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
     * Validates NAPTR record content
     *
     * @param string $content The content of the NAPTR record (order pref flags service regexp replacement)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for NAPTR records, priority is part of content)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Check for potential ENUM domain
        $warnings = [];
        if (strpos($name, 'e164.arpa') !== false || preg_match('/\d+\.\d+\.\d+\.in-addr\.arpa$/', $name)) {
            $warnings[] = _('This appears to be an ENUM domain. NAPTR records should use E2U service field and follow RFC 6116.');
        }

        // Validate content
        $contentResult = $this->validateNAPTRContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get content validation data and check for warnings
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

        // Validate priority (should be 0 for NAPTR records as it's included in content)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        // Add warnings for complex regexp pattern
        $data = $contentResult->getData();
        if (isset($data['regexp']) && strlen($data['regexp']) > 50) {
            $warnings[] = _('Complex regexp patterns in NAPTR records can be difficult to debug and maintain.');
        }

        // Add RFC version warning
        $warnings[] = _('This record follows RFC 3403. Be aware that some DNS servers may not fully support all NAPTR features.');

        // Include parsed flag for test compatibility
        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl,
            'parsed' => true
        ], $warnings);
    }

    /**
     * Validate priority for NAPTR records
     * NAPTR records don't use the prio field directly (priority is in content)
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

        // If provided, ensure it's 0 for NAPTR records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. NAPTR records have priority within the content field and must have a priority value of 0.'));
    }

    /**
     * Validates NAPTR record content
     * Format: <order> <preference> <flags> <service> <regexp> <replacement>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation success or error messages
     */
    private function validateNAPTRContent(string $content): ValidationResult
    {
        $warnings = [];

        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content), 6);

        // Must have all 6 parts
        if (count($parts) !== 6) {
            return ValidationResult::failure(_('NAPTR record must contain order, preference, flags, service, regexp, and replacement values.'));
        }

        [$order, $preference, $flags, $service, $regexp, $replacement] = $parts;

        // Validate order (must be a number between 0 and 65535)
        if (!is_numeric($order) || (int)$order < 0 || (int)$order > 65535) {
            return ValidationResult::failure(_('NAPTR record order must be a number between 0 and 65535.'));
        }

        // Validate preference (must be a number between 0 and 65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            return ValidationResult::failure(_('NAPTR record preference must be a number between 0 and 65535.'));
        }

        // Validate flags (must be a quoted string with one of "A", "P", "S", "U", "")
        $quotedStringResult = $this->validateQuotedString($flags);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record flags must be a quoted string.'));
        }

        $flagsValue = trim($flags, '"');
        // Valid flags are "a", "p", "s", "u" (case-insensitive) or empty
        if (!empty($flagsValue) && !preg_match('/^[APSUapsu]+$/', $flagsValue)) {
            return ValidationResult::failure(_('NAPTR record flags must contain only A, P, S, or U.'));
        }

        // Validate flags combinations
        $flagsCombinationResult = $this->validateFlagsCombination($flagsValue);
        if (!$flagsCombinationResult->isValid()) {
            return $flagsCombinationResult;
        }

        // Validate service (must be a quoted string)
        $quotedStringResult = $this->validateQuotedString($service);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record service must be a quoted string.'));
        }

        // Validate service format
        $serviceValue = trim($service, '"');
        $serviceFormatResult = $this->validateServiceFormat($serviceValue);
        if (!$serviceFormatResult->isValid()) {
            return $serviceFormatResult;
        }

        // Check if this might be an ENUM record
        $enumResult = $this->checkENUMRecord($serviceValue);
        if ($enumResult->isValid() && $enumResult->hasWarnings()) {
            $warnings = array_merge($warnings, $enumResult->getWarnings());
        }

        // Validate regexp (must be a quoted string)
        $quotedStringResult = $this->validateQuotedString($regexp);
        if (!$quotedStringResult->isValid()) {
            return ValidationResult::failure(_('NAPTR record regexp must be a quoted string.'));
        }

        // Validate regexp content
        $regexpValue = trim($regexp, '"');
        $regexpContentResult = $this->validateRegexpContent($regexpValue);
        if (!$regexpContentResult->isValid()) {
            return $regexpContentResult;
        }

        // Security check for regexp
        $securityCheckResult = $this->securityChecks($regexpValue);
        if (!$securityCheckResult->isValid()) {
            return $securityCheckResult;
        }

        // Validate replacement (must be a valid domain name or ".")
        if ($replacement !== ".") {
            $replacementResult = $this->hostnameValidator->validate($replacement, true);
            if (!$replacementResult->isValid()) {
                return ValidationResult::failure(_('NAPTR record replacement must be either "." or a valid fully-qualified domain name.'));
            }
        }

        // RFC 3403 and ETSI TS 123 003: Validate regexp and replacement mutual exclusivity
        // Terminal NAPTR (with regexp): Must have "." as replacement
        // Non-terminal NAPTR (without regexp): Can have domain name as replacement for next lookup
        // This is common in 3GPP networks where NAPTR chains are used for service discovery
        if (!empty($regexpValue) && $replacement !== ".") {
            return ValidationResult::failure(_('NAPTR record with a regexp must have "." as the replacement.'));
        }

        return ValidationResult::success([
            'order' => (int)$order,
            'preference' => (int)$preference,
            'flags' => $flagsValue,
            'service' => $serviceValue,
            'regexp' => $regexpValue,
            'replacement' => $replacement,
            'parsed' => true
        ], $warnings);
    }

    /**
     * Validates service field format according to RFC 3403 and ETSI TS 123 003
     *
     * RFC 3403 format: [protocol] *("+" rs)
     *   protocol = ALPHA *31ALPHANUM
     *   rs = ALPHA *31ALPHANUM
     *
     * ETSI TS 123 003 / 3GPP extensions:
     *   - Allows hyphens (-) for node type prefixes (e.g., "x-3gpp-pgw")
     *   - Allows colons (:) to separate service and protocol (e.g., "x-3gpp-pgw:x-gn")
     *   - Maintains plus (+) for resolution service concatenation
     *
     * Examples:
     *   - "SIP+D2U" (RFC 3403)
     *   - "x-3gpp-sgw" (3GPP with hyphens)
     *   - "x-3gpp-pgw:x-gn" (3GPP with colon separator)
     *   - "aaa+ap6:diameter.sctp" (Diameter with plus and colon)
     *
     * @param string $service The service string without quotes
     * @return ValidationResult ValidationResult with success or error message
     */
    private function validateServiceFormat(string $service): ValidationResult
    {
        // Empty service is valid
        if (empty($service)) {
            return ValidationResult::success(true);
        }

        // Service field format with 3GPP extensions
        // Must start with a letter, can contain alphanumeric, hyphens, colons, plus signs
        // Maximum 32 characters per segment when split by plus signs
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9:+\-]{0,31})(\+[a-zA-Z][a-zA-Z0-9:+\-]{0,31})*$/', $service)) {
            return ValidationResult::failure(_('NAPTR service must follow the format: [protocol][+rs][+rs]... where protocol and rs start with a letter and contain alphanumeric characters, hyphens, colons, or plus signs (max 32 chars each).'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Validates that flags combinations are valid according to RFC 3403
     *
     * @param string $flags The flags string without quotes
     * @return ValidationResult ValidationResult with success or error message
     */
    private function validateFlagsCombination(string $flags): ValidationResult
    {
        // Empty flags are valid
        if (empty($flags)) {
            return ValidationResult::success(true);
        }

        // Convert to uppercase for consistency
        $flags = strtoupper($flags);

        // "S", "A", "U" and "P" are terminal flags and should not be combined
        // They each indicate a different terminal lookup service
        if (
            strlen($flags) > 1 && (strpos($flags, 'S') !== false ||
                                 strpos($flags, 'A') !== false ||
                                 strpos($flags, 'P') !== false ||
                                 strpos($flags, 'U') !== false)
        ) {
            return ValidationResult::failure(_('Terminal flags "S", "A", "U", and "P" should not be combined with other flags.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Validates regexp field content follows valid substitution expression format
     *
     * @param string $regexp The regexp string without quotes
     * @return ValidationResult ValidationResult with success or error message
     */
    private function validateRegexpContent(string $regexp): ValidationResult
    {
        // Empty regexp is valid if replacement is "."
        if (empty($regexp)) {
            return ValidationResult::success(true);
        }

        // Regexp must have the format delimiter+pattern+delimiter+replacement+delimiter+flags
        $delimiter = $regexp[0] ?? '';
        if (empty($delimiter)) {
            return ValidationResult::failure(_('NAPTR regexp must start with a delimiter character.'));
        }

        // Count occurrences of delimiter (need at least 3)
        $delimCount = substr_count($regexp, $delimiter);
        if ($delimCount < 3) {
            return ValidationResult::failure(_('NAPTR regexp must have the format delimiter+pattern+delimiter+replacement+delimiter+flags.'));
        }

        // Validate flags at the end (after third delimiter) - valid flags for NAPTR substitution are "i"
        $parts = explode($delimiter, $regexp);
        $flags = end($parts);
        if (!empty($flags) && !preg_match('/^[i]*$/', $flags)) {
            return ValidationResult::failure(_('NAPTR regexp flags (after third delimiter) should only contain "i" or be empty.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Checks if this is potentially an ENUM record (RFC 6116) and provides appropriate warnings
     *
     * @param string $service The service field without quotes
     * @return ValidationResult ValidationResult with warnings if applicable
     */
    private function checkENUMRecord(string $service): ValidationResult
    {
        $warnings = [];

        // Look for E2U or E2U+ pattern which indicates ENUM
        if (preg_match('/^E2U(\+[a-zA-Z][a-zA-Z0-9]{0,31})*$/i', $service)) {
            $warnings[] = _('This appears to be an ENUM NAPTR record (RFC 6116). Ensure the domain is under e164.arpa or a private ENUM tree.');
            $warnings[] = _('ENUM records should have the "U" flag to indicate URI output.');
        }

        // Return success with warnings directly rather than nesting them in data
        return ValidationResult::success(['valid' => true], $warnings);
    }

    /**
     * Perform security checks on the NAPTR record, particularly the regexp field
     *
     * @param string $regexp The regexp string without quotes
     * @return ValidationResult ValidationResult with success or error message
     */
    private function securityChecks(string $regexp): ValidationResult
    {
        // Check for potentially dangerous patterns in regexp
        $dangerousPatterns = [
            '/\(\?\{/' => 'Perl code execution in regexp',
            '/\(\?[<>]/' => 'Named backreferences',
            '/\(\?[#|=!]/' => 'Conditional or comment expressions',
            '/\\\\\$[\$&`\']/' => 'Potentially dangerous backreferences',
            '/\$\$/' => 'Double dollar sign backreference'
        ];

        foreach ($dangerousPatterns as $pattern => $description) {
            if (preg_match($pattern, $regexp)) {
                return ValidationResult::failure(
                    _('NAPTR regexp contains potentially dangerous pattern: ') . $description
                );
            }
        }

        // Check for extremely long patterns that could cause DoS
        if (strlen($regexp) > 1000) {
            return ValidationResult::failure(_('NAPTR regexp is too long. Maximum length is 1000 characters.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param string $content The content to validate
     * @return array Array with 'isValid' (bool) and 'errors' (array) keys
     */
    private function isValidNAPTRContent(string $content): array
    {
        $result = $this->validateNAPTRContent($content);
        if (!$result->isValid()) {
            return [
                'isValid' => false,
                'errors' => [$result->getFirstError()]
            ];
        }
        return [
            'isValid' => true,
            'errors' => []
        ];
    }

    /**
     * Validate if a string is a valid quoted string
     *
     * @param string $value The string to check
     * @return ValidationResult ValidationResult containing validation success or error message
     */
    private function validateQuotedString(string $value): ValidationResult
    {
        if (preg_match('/^".*"$/', $value) === 1) {
            return ValidationResult::success(true);
        }
        return ValidationResult::failure(_('Value must be enclosed in double quotes.'));
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param string $value The string to check
     * @return bool True if valid, false otherwise
     */
    private function isValidQuotedString(string $value): bool
    {
        $result = $this->validateQuotedString($value);
        return $result->isValid();
    }
}
