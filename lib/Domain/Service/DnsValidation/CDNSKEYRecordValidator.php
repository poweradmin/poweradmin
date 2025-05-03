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
 * CDNSKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CDNSKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CDNSKEY record content
     *
     * @param string $content The content of the CDNSKEY record (flags protocol algorithm public-key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CDNSKEY records)
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
        if (!$this->isValidCDNSKEYContent($content)) {
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
            'prio' => 0, // CDNSKEY records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of a CDNSKEY record
     * Format: <flags> <protocol> <algorithm> <public-key>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidCDNSKEYContent(string $content): bool
    {
        // Special case for delete CDNSKEY record
        if (trim($content) === '0 3 0 AA==') {
            return true;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            $this->messageService->addSystemError(_('CDNSKEY record must contain flags, protocol, algorithm and public-key separated by spaces.'));
            return false;
        }

        [$flags, $protocol, $algorithm, $publicKey] = $parts;

        // Validate flags (must be 0 or 256, 257)
        if (!is_numeric($flags) || !in_array((int)$flags, [0, 256, 257])) {
            $this->messageService->addSystemError(_('CDNSKEY flags must be 0, 256, or 257.'));
            return false;
        }

        // Validate protocol (must be 3)
        if (!is_numeric($protocol) || (int)$protocol !== 3) {
            $this->messageService->addSystemError(_('CDNSKEY protocol must be 3.'));
            return false;
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            $this->messageService->addSystemError(_('CDNSKEY algorithm must be a number between 1 and 16.'));
            return false;
        }

        // Validate public key (must be valid base64-encoded data)
        if (!$this->isValidBase64($publicKey)) {
            $this->messageService->addSystemError(_('CDNSKEY public key must be valid base64-encoded data.'));
            return false;
        }

        return true;
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return bool True if valid base64, false otherwise
     */
    private function isValidBase64(string $data): bool
    {
        // Basic pattern for base64-encoded data
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return false;
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        return true;
    }
}
