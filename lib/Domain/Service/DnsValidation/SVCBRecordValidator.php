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
 * SVCB record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SVCBRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SVCB record content
     *
     * SVCB records have the format: <priority> <target> [<params>...]
     * Example: 1 . alpn=h2,h3 ipv4hint=192.0.2.1 ipv6hint=2001:db8::1
     * Example: 0 svc.example.com.
     *
     * @param string $content The content of the SVCB record
     * @param string $name The name of the record
     * @param mixed $prio The priority value
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        if (!StringValidator::isValidPrintable($name)) {
            return ValidationResult::failure(_('Invalid characters in name field.'));
        }

        // Validate content
        if (!StringValidator::isValidPrintable($content)) {
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
     * Check if content follows SVCB record format: <priority> <target> [<params>...]
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

        // Validate target (either "." for AliasMode or a valid hostname)
        if ($target !== '.') {
            $hostnameResult = $this->hostnameValidator->validate($target, false);
            if (!$hostnameResult->isValid()) {
                return ValidationResult::failure(_('SVCB target must be either "." (for AliasMode) or a valid hostname.'));
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
     * Validate SVCB parameters
     *
     * @param string $params The parameters to validate
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateSVCBParameters(string $params): ValidationResult
    {
        // Split parameters by space
        $paramsList = preg_split('/\s+/', $params);

        // Check each parameter
        foreach ($paramsList as $param) {
            // Check if parameter follows "key=value" format
            if (!preg_match('/^([a-z0-9]+)=(.+)$/', $param, $paramMatches)) {
                return ValidationResult::failure(_('SVCB parameters must be in "key=value" format.'));
            }

            $key = $paramMatches[1];
            $value = $paramMatches[2];

            // Validate known parameters
            if ($key === 'alpn') {
                // Check alpn format: comma-separated values
                if (!preg_match('/^[a-z0-9,\-]+$/', $value)) {
                    return ValidationResult::failure(_('SVCB alpn parameter must be a comma-separated list of protocol names.'));
                }
            } elseif ($key === 'ipv4hint') {
                // Check IPv4 hint (comma-separated IPv4 addresses)
                $ipv4s = explode(',', $value);
                foreach ($ipv4s as $ip) {
                    if (!filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return ValidationResult::failure(_('SVCB ipv4hint must contain valid IPv4 addresses.'));
                    }
                }
            } elseif ($key === 'ipv6hint') {
                // Check IPv6 hint (comma-separated IPv6 addresses)
                $ipv6s = explode(',', $value);
                foreach ($ipv6s as $ip) {
                    if (!filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        return ValidationResult::failure(_('SVCB ipv6hint must contain valid IPv6 addresses.'));
                    }
                }
            } elseif ($key === 'port') {
                // Check port (1-65535)
                if (!is_numeric($value) || (int)$value < 1 || (int)$value > 65535) {
                    return ValidationResult::failure(_('SVCB port must be between 1 and 65535.'));
                }
            }
            // Other parameters are allowed and passed through without specific validation
        }

        return ValidationResult::success(true);
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
