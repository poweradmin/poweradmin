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
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname/name
        if (!StringValidator::isValidPrintable($name)) {
            $this->messageService->addSystemError(_('Invalid characters in name field.'));
            return false;
        }

        // Validate content
        if (!StringValidator::isValidPrintable($content)) {
            $this->messageService->addSystemError(_('Invalid characters in content field.'));
            return false;
        }

        // Parse SVCB record parts: <priority> <target> [<params>...]
        if (!$this->isValidSVCBRecordFormat($content)) {
            return false;
        }

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        // Use the provided priority if available, otherwise extract from the content
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : $this->extractPriorityFromContent($content);

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Check if content follows SVCB record format: <priority> <target> [<params>...]
     *
     * @param string $content The content to validate
     * @return bool True if valid format, false otherwise
     */
    private function isValidSVCBRecordFormat(string $content): bool
    {
        // Basic regex to match SVCB record format with at least priority and target
        if (!preg_match('/^(\d+)\s+([^\s]+)(\s+.*)?$/', $content, $matches)) {
            $this->messageService->addSystemError(_('SVCB record must start with a priority and target: <priority> <target> [<params>...]'));
            return false;
        }

        $priority = (int)$matches[1];
        $target = $matches[2];
        $params = $matches[3] ?? '';

        // Validate priority (0-65535)
        if ($priority < 0 || $priority > 65535) {
            $this->messageService->addSystemError(_('SVCB priority must be between 0 and 65535.'));
            return false;
        }

        // Validate target (either "." for AliasMode or a valid hostname)
        if ($target !== '.') {
            $result = $this->hostnameValidator->isValidHostnameFqdn($target, '0');
            if (!$result) {
                $this->messageService->addSystemError(_('SVCB target must be either "." (for AliasMode) or a valid hostname.'));
                return false;
            }
        }

        // If parameters are present, validate them
        if (trim($params) !== '') {
            if (!$this->validateSVCBParameters(trim($params))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate SVCB parameters
     *
     * @param string $params The parameters to validate
     * @return bool True if parameters are valid, false otherwise
     */
    private function validateSVCBParameters(string $params): bool
    {
        // Split parameters by space
        $paramsList = preg_split('/\s+/', $params);

        // Check each parameter
        foreach ($paramsList as $param) {
            // Check if parameter follows "key=value" format
            if (!preg_match('/^([a-z0-9]+)=(.+)$/', $param, $paramMatches)) {
                $this->messageService->addSystemError(_('SVCB parameters must be in "key=value" format.'));
                return false;
            }

            $key = $paramMatches[1];
            $value = $paramMatches[2];

            // Validate known parameters
            if ($key === 'alpn') {
                // Check alpn format: comma-separated values
                if (!preg_match('/^[a-z0-9,\-]+$/', $value)) {
                    $this->messageService->addSystemError(_('SVCB alpn parameter must be a comma-separated list of protocol names.'));
                    return false;
                }
            } elseif ($key === 'ipv4hint') {
                // Check IPv4 hint (comma-separated IPv4 addresses)
                $ipv4s = explode(',', $value);
                foreach ($ipv4s as $ip) {
                    if (!filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $this->messageService->addSystemError(_('SVCB ipv4hint must contain valid IPv4 addresses.'));
                        return false;
                    }
                }
            } elseif ($key === 'ipv6hint') {
                // Check IPv6 hint (comma-separated IPv6 addresses)
                $ipv6s = explode(',', $value);
                foreach ($ipv6s as $ip) {
                    if (!filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $this->messageService->addSystemError(_('SVCB ipv6hint must contain valid IPv6 addresses.'));
                        return false;
                    }
                }
            } elseif ($key === 'port') {
                // Check port (1-65535)
                if (!is_numeric($value) || (int)$value < 1 || (int)$value > 65535) {
                    $this->messageService->addSystemError(_('SVCB port must be between 1 and 65535.'));
                    return false;
                }
            }
            // Other parameters are allowed and passed through without specific validation
        }

        return true;
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
