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
 * RKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class RKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates RKEY record content
     *
     * @param string $content The content of the RKEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RKEY records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate the hostname format
        if (!StringValidator::isValidPrintable($name)) {
            return false;
        }

        // Hostname validation for RKEY records
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidRKEYContent($content)) {
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
            'prio' => 0, // RKEY records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of an RKEY record
     * Format: <flags> <protocol> <algorithm> <public key>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidRKEYContent(string $content): bool
    {
        // Check if empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('RKEY record content cannot be empty.'));
            return false;
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 4) {
            $this->messageService->addSystemError(_('RKEY record must contain flags, protocol, algorithm, and public key data.'));
            return false;
        }

        [$flags, $protocol, $algorithm, $publicKey] = [$parts[0], $parts[1], $parts[2], implode(' ', array_slice($parts, 3))];

        // Validate flags field (must be a number)
        if (!is_numeric($flags)) {
            $this->messageService->addSystemError(_('RKEY flags field must be a numeric value.'));
            return false;
        }

        // Validate protocol field (must be a number)
        if (!is_numeric($protocol)) {
            $this->messageService->addSystemError(_('RKEY protocol field must be a numeric value.'));
            return false;
        }

        // Validate algorithm field (must be a number)
        if (!is_numeric($algorithm)) {
            $this->messageService->addSystemError(_('RKEY algorithm field must be a numeric value.'));
            return false;
        }

        // Minimal validation for the public key part
        if (empty(trim($publicKey))) {
            $this->messageService->addSystemError(_('RKEY public key data cannot be empty.'));
            return false;
        }

        return true;
    }
}
