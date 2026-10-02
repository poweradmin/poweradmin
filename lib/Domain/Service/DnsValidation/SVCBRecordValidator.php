<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

use Closure;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * SVCB record validator
 *
 * Validates SVCB (Service Binding) records according to:
 * - RFC 9460: Service Binding and Parameter Specification via the DNS (DNS SVCB and HTTPS Resource Records)
 *
 * SVCB records are the general variant of HTTPS records, allowing service binding for any protocol.
 * Format: <priority> <target> [param1=value1 param2=value2 ...]
 */
class SVCBRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;
    /** @var (Closure(string): bool)|null */
    private ?Closure $svcParamSupported;

    /**
     * @param (Closure(string): bool)|null $svcParamSupported Whether the connected PowerDNS knows a parameter name
     */
    public function __construct(HostnameValidator $hostnameValidator, ?IPAddressValidator $ipValidator = null, ?Closure $svcParamSupported = null)
    {
        $this->hostnameValidator = $hostnameValidator;
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->svcParamSupported = $svcParamSupported;
    }

    /**
     * Validates SVCB record content
     *
     * SVCB records have the format: <priority> <target> [<params>...]
     * Example: 1 . alpn=h2,h3 ipv4hint=192.0.2.1 ipv6hint=2001:db8::1
     * Example: 0 svc.example.com.
     *
     * @param string $content The content of the SVCB record
     * @param string $name The name of the record
     * @param mixed $prio The priority value
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     * @param mixed ...$args Additional validator-specific parameters
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        // Validate hostname/name
        if (!StringValidator::validatePrintable($name)->isValid()) {
            return ValidationResult::failure(_('Invalid characters in name field.'));
        }

        // Validate content
        if (!StringValidator::validatePrintable($content)->isValid()) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        // Parse SVCB record parts: <priority> <target> [<params>...]
        $formatResult = $this->validateSVCBRecordFormat($content);
        if (!$formatResult->isValid()) {
            return $formatResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Use the provided priority if available, otherwise extract from the content
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : $this->extractPriorityFromContent($content);

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Check if content follows SVCB record format according to RFC 9460
     * Format: <priority> <target> [<params>...]
     *
     * The record has two modes (RFC 9460 Section 2.4.1):
     * - Alias Mode (priority = 0): Points to another SVCB or other record
     * - Service Mode (priority > 0): Provides connection information for the service
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateSVCBRecordFormat(string $content): ValidationResult
    {
        // Basic regex to match SVCB record format with at least priority and target
        if (!preg_match('/^(\d+)\s+([^\s]+)(\s+.*)?$/', $content, $matches)) {
            return ValidationResult::failure(_('SVCB record must start with a priority and target: <priority> <target> [<params>...]'));
        }

        $priority = (int)$matches[1];
        $target = $matches[2];
        $params = $matches[3] ?? '';

        // Validate priority (0-65535)
        if ($priority < 0 || $priority > 65535) {
            return ValidationResult::failure(_('SVCB priority must be between 0 and 65535.'));
        }

        // Validate target (either "." or a valid hostname)
        if ($target !== '.') {
            $hostnameResult = $this->hostnameValidator->validate($target, false);
            if (!$hostnameResult->isValid()) {
                return ValidationResult::failure(_('SVCB target must be either "." or a valid hostname.'));
            }
        }

        // Apply specific rules based on mode (Alias Mode vs Service Mode) - RFC 9460 Section 2.4.1
        if ($priority === 0) {
            // Alias Mode - priority is 0

            // In Alias Mode, the target cannot be "."
            if ($target === ".") {
                return ValidationResult::failure(_('In Alias Mode (priority = 0), the target cannot be ".".'));
            }

            // In Alias Mode, there must not be any parameters
            if (trim($params) !== '') {
                return ValidationResult::failure(_('In Alias Mode (priority = 0), no SvcParams are allowed.'));
            }
        } else {
            // Service Mode - priority > 0

            // In Service Mode with "." as the target, there must be at least one parameter
            if ($target === "." && trim($params) === '') {
                return ValidationResult::failure(_('In Service Mode (priority > 0) with "." target, at least one SvcParam is required.'));
            }
        }

        // If parameters are present, validate them
        if (trim($params) !== '') {
            $paramResult = $this->validateSVCBParameters(trim($params));
            if (!$paramResult->isValid()) {
                return $paramResult;
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate SVCB parameters according to RFC 9460
     *
     * @param string $params The parameters to validate
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateSVCBParameters(string $params): ValidationResult
    {
        // Split the params string by space
        $paramsList = preg_split('/\s+/', trim($params));

        // Define valid parameter keys according to RFC 9460 section 7
        $validKeys = [
            'alpn' => true,            // 1: Alt-Svc Alternative Protocol Negotiation
            'no-default-alpn' => true, // 2: No support for default protocol
            'port' => true,            // 3: Port for alternative endpoint
            'ipv4hint' => true,        // 4: IPv4 address hints
            'ech' => true,             // 5: Encrypted ClientHello
            'ipv6hint' => true,        // 6: IPv6 address hints
            'dohpath' => true,         // 7: DNS over HTTPS path template
            'ohttp' => true,           // 8: Oblivious HTTP gateway
            'tls-supported-groups' => true, // 9: TLS key exchange groups
            'mandatory' => true,       // 0: Parameters that must be understood
        ];

        // Track which parameters have been seen
        $seenParams = [];
        $errors = [];
        $mandatoryParams = [];

        foreach ($paramsList as $param) {
            // key=value, except the flag keys PowerDNS only reads bare
            if (!preg_match('/^([a-z0-9_-]+)(=(.*))?$/i', $param, $matches)) {
                return ValidationResult::failure(_('SVCB parameters must be in key=value format separated by spaces.'));
            }

            $key = strtolower($matches[1]);
            $value = $matches[3] ?? '';
            $hasEquals = isset($matches[2]) && $matches[2] !== '';
            if (in_array($key, ['no-default-alpn', 'ohttp'], true) === $hasEquals) {
                if (!$hasEquals) {
                    return ValidationResult::failure(_('SVCB parameters must be in key=value format separated by spaces.'));
                }
                return ValidationResult::failure(sprintf(_('The %s parameter is written without a value or "=".'), $key));
            }

            // Check if the parameter key is allowed
            if (preg_match('/^key[0-6]$/', $key)) {
                $errors[] = sprintf(_('Use the parameter name instead of "%s".'), $key);
            } elseif (!isset($validKeys[$key]) && !self::isGenericKey($key)) {
                $errors[] = sprintf(_('Unknown SVCB parameter key: "%s".'), $key);
            } elseif ($this->svcParamSupported !== null && !($this->svcParamSupported)($key)) {
                $errors[] = sprintf(_('The connected PowerDNS version cannot load the %s parameter.'), $key);
            }

            // Check for duplicate keys (not allowed as per RFC)
            if (isset($seenParams[$key])) {
                return ValidationResult::failure(sprintf(_('Duplicate SVCB parameter key: "%s". Each key can only appear once.'), $key));
            }
            $seenParams[$key] = true;

            // Validate specific parameters according to their format
            switch ($key) {
                case 'alpn':
                    // ALPN values should be a comma-separated list of protocol identifiers
                    if (!preg_match('/^[a-z0-9,_-]+$/i', $value)) {
                        $errors[] = _('ALPN value must be a comma-separated list of protocol identifiers.');
                    }
                    break;

                case 'port':
                    // Must be a valid port number (1-65535)
                    if (!is_numeric($value) || (int)$value < 1 || (int)$value > 65535) {
                        $errors[] = _('Port value must be a number between 1 and 65535.');
                    }
                    break;

                case 'ipv4hint':
                    // Must be valid IPv4 addresses separated by commas
                    $ipAddresses = explode(',', $value);
                    foreach ($ipAddresses as $ip) {
                        if (!$this->ipValidator->isValidIPv4(trim($ip))) {
                            $errors[] = sprintf(_('Invalid IPv4 address in ipv4hint: "%s".'), $ip);
                        }
                    }
                    break;

                case 'ipv6hint':
                    // Must be valid IPv6 addresses separated by commas
                    $ipAddresses = explode(',', $value);
                    foreach ($ipAddresses as $ip) {
                        if (!$this->ipValidator->isValidIPv6(trim($ip))) {
                            $errors[] = sprintf(_('Invalid IPv6 address in ipv6hint: "%s".'), $ip);
                        }
                    }
                    break;

                case 'dohpath':
                    // DNS over HTTPS path should start with a slash and contain valid URL path characters
                    if (!str_starts_with($value, '/')) {
                        $errors[] = _('DoH path must start with a forward slash (/).');
                    }
                    break;

                case 'ech':
                    if (!self::isBase64($value)) {
                        $errors[] = _('The ech value must be base64 encoded.');
                    }
                    break;

                case 'tls-supported-groups':
                    if (!self::isUint16List($value)) {
                        $errors[] = _('The tls-supported-groups value must be a comma-separated list of numbers between 0 and 65535.');
                    }
                    break;

                case 'mandatory':
                    $mandatoryParams = explode(',', $value);
                    break;
            }
        }

        foreach ($mandatoryParams as $mandatoryParam) {
            $mandatoryParam = strtolower(trim($mandatoryParam));
            if ($mandatoryParam === 'mandatory') {
                $errors[] = _('The mandatory parameter cannot list itself.');
            } elseif ($mandatoryParam === '' || !isset($seenParams[$mandatoryParam])) {
                $errors[] = sprintf(_('Parameter "%s" is marked as mandatory but not included in the record.'), $mandatoryParam);
            }
        }

        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success(true);
    }

    /**
     * keyNNNNN form of a SvcParam key; the number is 16 bits. key0 to key6 have names in every
     * supported PowerDNS, which reads those numbers in wire format, so they must be written by name.
     */
    public static function isGenericKey(string $key): bool
    {
        return preg_match('/^key(\d{1,5})$/', $key, $matches) === 1 && (int)$matches[1] >= 7 && (int)$matches[1] <= 65535;
    }

    /**
     * Comma-separated 16-bit numbers, the form PowerDNS reads tls-supported-groups in.
     */
    public static function isUint16List(string $value): bool
    {
        foreach (explode(',', $value) as $part) {
            if (!preg_match('/^\d{1,5}$/', $part) || (int)$part > 65535) {
                return false;
            }
        }

        return true;
    }

    /**
     * PowerDNS cannot load a record whose ech value does not decode as base64.
     */
    public static function isBase64(string $value): bool
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value !== '' && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) === 1 && base64_decode($value, true) !== false;
    }

    /**
     * Extract priority value from SVCB record content
     *
     * @param string $content The SVCB record content
     * @return int The priority value
     */
    private function extractPriorityFromContent(string $content): int
    {
        preg_match('/^(\d+)\s+/', $content, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
}
