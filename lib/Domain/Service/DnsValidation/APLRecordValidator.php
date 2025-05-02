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
 * Validator for APL (Address Prefix List) DNS records
 * RFC 3123: https://tools.ietf.org/html/rfc3123
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class APLRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;
    private MessageService $messageService;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = new IPAddressValidator();
        $this->messageService = new MessageService();
        $this->config = $config;
    }

    /**
     * Validate APL record
     *
     * @param string $content APL content in format "1:192.0.2.0/24 2:2001:db8::/32"
     * @param string $name Record hostname
     * @param mixed $prio Priority (not used for APL records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // 1. Validate hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // 2. Validate APL content
        if (!$this->isValidAPLContent($content)) {
            return false;
        }

        // 3. Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // 4. Validate priority (should be 0 for APL records)
        $validatedPrio = $this->validatePriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for prio field.'));
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate priority for APL records
     * APL records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return int|bool 0 if valid, false otherwise
     */
    private function validatePriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return 0;
        }

        // If provided, ensure it's 0 for APL records
        if (is_numeric($prio) && intval($prio) === 0) {
            return 0;
        }

        return false;
    }

    /**
     * Validate APL content format
     * Examples: "1:192.0.2.0/24" or "2:2001:db8::/32" or "1:192.0.2.0/24 !2:2001:db8::/32"
     *
     * @param string $content The APL content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidAPLContent(string $content): bool
    {
        // Handle empty content
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('APL record content cannot be empty.'));
            return false;
        }

        // Split content by whitespace to handle multiple address prefix elements
        $prefixElements = preg_split('/\s+/', trim($content));

        foreach ($prefixElements as $element) {
            if (!$this->isValidAPLElement($element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single APL element
     * Format: [!]afi:address/prefix
     *
     * @param string $element The APL element to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidAPLElement(string $element): bool
    {
        // Check if element starts with negation
        $negation = false;
        if (str_starts_with($element, '!')) {
            $negation = true;
            $element = substr($element, 1);
        }

        // Check for afi:address/prefix format
        if (!preg_match('/^(\d+):([^\/]+)\/(\d+)$/', $element, $matches)) {
            $this->messageService->addSystemError(_('Invalid APL element format. Expected [!]afi:address/prefix.'));
            return false;
        }

        $afi = (int)$matches[1];
        $address = $matches[2];
        $prefix = (int)$matches[3];

        // Validate Address Family Identifier (AFI)
        // 1 = IPv4, 2 = IPv6 (as per RFC 3123)
        if ($afi !== 1 && $afi !== 2) {
            $this->messageService->addSystemError(_('Invalid Address Family Identifier (AFI). Must be 1 for IPv4 or 2 for IPv6.'));
            return false;
        }

        // Validate address and prefix based on AFI
        if ($afi === 1) {
            // IPv4
            if (!$this->ipValidator->isValidIPv4($address)) {
                return false;
            }

            // IPv4 prefix must be between 0 and 32
            if ($prefix < 0 || $prefix > 32) {
                $this->messageService->addSystemError(_('IPv4 prefix must be between 0 and 32.'));
                return false;
            }
        } else {
            // IPv6
            if (!$this->ipValidator->isValidIPv6($address)) {
                return false;
            }

            // IPv6 prefix must be between 0 and 128
            if ($prefix < 0 || $prefix > 128) {
                $this->messageService->addSystemError(_('IPv6 prefix must be between 0 and 128.'));
                return false;
            }
        }

        return true;
    }
}
