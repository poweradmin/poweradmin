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
 * LUA records allow PowerDNS to execute Lua scripts for dynamic DNS content generation.
 * They are PowerDNS-specific and not standardized by IETF.
 *
 * Format: Lua script that returns the appropriate DNS record value
 *
 * The LUA record can be used in two ways:
 * 1. Implicit return mode: "functioncall(parameters)"
 * 2. Explicit return mode: ";if(condition) then return value else return othervalue end"
 *    (note the leading semicolon to indicate explicit return mode)
 *
 * Common patterns:
 * - IP selection based on client location: "pickclosest({'192.0.2.1', '198.51.100.1'})"
 * - Conditional resolution: ";if(continent('EU')) then return '192.0.2.1' else return '198.51.100.1' end"
 * - Availability checks: "ifportup(443, {'192.0.2.1', '198.51.100.1'})"
 *
 * SECURITY CONSIDERATIONS:
 * - LUA records must be explicitly enabled in PowerDNS configuration
 * - Never serve LUA records from untrusted sources
 * - LUA records can expose system information and potentially lead to system compromise
 *
 * @see https://doc.powerdns.com/authoritative/lua-records/ PowerDNS LUA Records documentation
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

        // Add warnings and informational messages
        $warnings = [];

        // Check for common security patterns and add specific warnings
        if (stripos($content, 'io.') !== false || stripos($content, 'file.') !== false || stripos($content, 'os.') !== false) {
            $warnings[] = _('WARNING: This LUA record contains potentially unsafe file or system operations. These may be blocked by PowerDNS security mechanisms.');
        }

        // Check for explicit return mode vs implicit return mode
        if (substr(trim($content), 0, 1) === ';') {
            $warnings[] = _('This LUA record uses explicit return mode (starts with semicolon). Ensure your Lua script explicitly returns the appropriate value.');
        } else {
            // Check if common PowerDNS Lua functions are used
            $commonFunctions = ['pickclosest', 'pickwhashed', 'ifportup', 'ifurlup', 'view', 'continent', 'country', 'latlonloc'];
            $foundFunction = false;

            foreach ($commonFunctions as $function) {
                if (stripos($content, $function . '(') !== false) {
                    $foundFunction = true;
                    break;
                }
            }

            if (!$foundFunction) {
                $warnings[] = _('This LUA record doesn\'t appear to use common PowerDNS Lua functions. Make sure it returns an appropriate value for the record type.');
            }
        }

        // Check for unbalanced quotes
        $singleQuotes = substr_count($content, "'");
        $doubleQuotes = substr_count($content, '"');
        if ($singleQuotes % 2 !== 0 || $doubleQuotes % 2 !== 0) {
            $warnings[] = _('WARNING: This LUA record appears to have unbalanced quotes, which may cause syntax errors.');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate LUA record content format
     *
     * PowerDNS supports two patterns for LUA records:
     * 1. Implicit return mode: A function call that returns a value (e.g., pickclosest({'192.0.2.1','198.51.100.1'}))
     * 2. Explicit return mode: A Lua script starting with ';' that explicitly returns a value
     *
     * @param string $content The LUA record content
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateLuaContent(string $content): ValidationResult
    {
        $trimmedContent = trim($content);

        // Empty content check (though this should be caught earlier)
        if (empty($trimmedContent)) {
            return ValidationResult::failure(_('LUA record content cannot be empty.'));
        }

        // Check for mismatched parentheses
        $openParentheses = substr_count($trimmedContent, '(');
        $closeParentheses = substr_count($trimmedContent, ')');
        if ($openParentheses !== $closeParentheses) {
            return ValidationResult::failure(_('LUA record has mismatched parentheses. Check your syntax.'));
        }

        // Check for mismatched braces
        $openBraces = substr_count($trimmedContent, '{');
        $closeBraces = substr_count($trimmedContent, '}');
        if ($openBraces !== $closeBraces) {
            return ValidationResult::failure(_('LUA record has mismatched braces. Check your syntax.'));
        }

        // Check if this is explicit return mode (starts with semicolon)
        if (substr($trimmedContent, 0, 1) === ';') {
            // In explicit return mode, we should see "return" statements
            if (!str_contains($trimmedContent, 'return')) {
                return ValidationResult::failure(_('LUA record in explicit return mode (starts with semicolon) must contain at least one "return" statement.'));
            }
        } else {
            // In implicit return mode, we should have a valid function call structure
            // Common patterns:
            // 1. Direct function call: functionname(...)
            // 2. Function definition: function name(...) ... end
            // 3. Record type prefix: RECORDTYPE "function(...)" or RECORDTYPE function(...)
            $validPattern = false;

            // Check for record type prefix (A, AAAA, CNAME, etc.) followed by function call
            // Pattern: RECORDTYPE "function(...)" or RECORDTYPE function(...)
            // Be specific about common DNS record types to avoid false matches
            $recordTypes = 'A|AAAA|CNAME|MX|NS|PTR|SOA|SRV|TXT|CAA|DS|DNSKEY|NSEC|NSEC3|RRSIG|TLSA|URI|LOC|HINFO|' .
                          'RP|AFSDB|ISDN|RT|X25|PX|GPOS|NAPTR|KX|CERT|DNAME|SINK|OPT|APL|SSHFP|IPSECKEY|DHCID|' .
                          'NSEC3PARAM|HIP|CDS|CDNSKEY|OPENPGPKEY|CSYNC|ZONEMD|SVCB|HTTPS';
            if (preg_match('/^(' . $recordTypes . ')\s+["\']?[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $trimmedContent)) {
                $validPattern = true;
            }

            // Direct function call: functionname(...) - check for function name followed by open parenthesis
            // But exclude 'function' keyword as that should be handled by the function definition check
            if (preg_match('/^(?!function\s)[a-zA-Z_][a-zA-Z0-9_]*\s*\(/i', $trimmedContent)) {
                $validPattern = true;
            }

            // Function definition: function name(...) ... end
            $hasFunction = str_contains($trimmedContent, 'function');
            $hasEnd = str_contains($trimmedContent, 'end');
            if ($hasFunction && $hasEnd) {
                $validPattern = true;
            }

            if (!$validPattern) {
                return ValidationResult::failure(_('LUA record in implicit return mode should be a valid function call or function definition with "function" and "end" keywords.'));
            }
        }

        // Check for potentially dangerous Lua constructs
        if (preg_match('/\b(os\.|io\.|file\.|loadfile|dofile)\b/i', $trimmedContent)) {
            return ValidationResult::failure(_('LUA record contains potentially dangerous system access functions. These are likely to be blocked by PowerDNS security restrictions.'));
        }

        return ValidationResult::success(true);
    }
}
