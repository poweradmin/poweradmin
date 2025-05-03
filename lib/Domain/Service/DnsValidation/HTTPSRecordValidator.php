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
 * HTTPS record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class HTTPSRecordValidator implements DnsRecordValidatorInterface
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
     * Validates HTTPS record content
     *
     * @param string $content The content of the HTTPS record (priority target [key=value...])
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for HTTPS records, priority is part of content)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidHTTPSContent($content)) {
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
            'prio' => 0, // Priority is included in the content for HTTPS records
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates HTTPS record content
     * Format: <priority> <target> [key=value...]
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidHTTPSContent(string $content): bool
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content), 3);

        // Must have at least priority and target
        if (count($parts) < 2) {
            $this->messageService->addSystemError(_('HTTPS record must contain at least priority and target values.'));
            return false;
        }

        [$priority, $target] = $parts;

        // Validate priority (must be a number between 0 and 65535)
        if (!is_numeric($priority) || (int)$priority < 0 || (int)$priority > 65535) {
            $this->messageService->addSystemError(_('HTTPS record priority must be a number between 0 and 65535.'));
            return false;
        }

        // Validate target (must be either "." or a valid hostname)
        if ($target !== ".") {
            $targetResult = $this->hostnameValidator->isValidHostnameFqdn($target, 1);
            if ($targetResult === false) {
                $this->messageService->addSystemError(_('HTTPS record target must be either "." or a valid fully-qualified domain name.'));
                return false;
            }
        }

        // If there are key-value parameters, validate them
        if (count($parts) > 2) {
            $params = $parts[2];

            // Basic check for parameter format
            if (!$this->isValidHTTPSParams($params)) {
                $this->messageService->addSystemError(_('HTTPS record parameters must be in key=value format separated by spaces.'));
                return false;
            }
        }

        return true;
    }

    /**
     * Validate HTTPS parameters
     *
     * @param string $params The parameter string to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidHTTPSParams(string $params): bool
    {
        // Split the params string by space
        $paramsList = preg_split('/\s+/', trim($params));

        foreach ($paramsList as $param) {
            // Each parameter should be in key=value format
            if (!preg_match('/^[a-z0-9]+=[^=\s]+$/i', $param)) {
                return false;
            }
        }

        return true;
    }
}
