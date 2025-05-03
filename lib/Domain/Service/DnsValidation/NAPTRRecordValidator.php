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
 * NAPTR record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class NAPTRRecordValidator implements DnsRecordValidatorInterface
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
     * Validates NAPTR record content
     *
     * @param string $content The content of the NAPTR record (order pref flags service regexp replacement)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for NAPTR records, priority is part of content)
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
        if (!$this->isValidNAPTRContent($content)) {
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
            'prio' => 0, // Priority is included in the content for NAPTR records
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates NAPTR record content
     * Format: <order> <preference> <flags> <service> <regexp> <replacement>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidNAPTRContent(string $content): bool
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content), 6);

        // Must have all 6 parts
        if (count($parts) !== 6) {
            $this->messageService->addSystemError(_('NAPTR record must contain order, preference, flags, service, regexp, and replacement values.'));
            return false;
        }

        [$order, $preference, $flags, $service, $regexp, $replacement] = $parts;

        // Validate order (must be a number between 0 and 65535)
        if (!is_numeric($order) || (int)$order < 0 || (int)$order > 65535) {
            $this->messageService->addSystemError(_('NAPTR record order must be a number between 0 and 65535.'));
            return false;
        }

        // Validate preference (must be a number between 0 and 65535)
        if (!is_numeric($preference) || (int)$preference < 0 || (int)$preference > 65535) {
            $this->messageService->addSystemError(_('NAPTR record preference must be a number between 0 and 65535.'));
            return false;
        }

        // Validate flags (must be a quoted string with one of "A", "P", "S", "U", "")
        if (!$this->isValidQuotedString($flags)) {
            $this->messageService->addSystemError(_('NAPTR record flags must be a quoted string.'));
            return false;
        }

        $flagsValue = trim($flags, '"');
        if (!empty($flagsValue) && !preg_match('/^[APSU]+$/', $flagsValue)) {
            $this->messageService->addSystemError(_('NAPTR record flags must contain only A, P, S, or U.'));
            return false;
        }

        // Validate service (must be a quoted string)
        if (!$this->isValidQuotedString($service)) {
            $this->messageService->addSystemError(_('NAPTR record service must be a quoted string.'));
            return false;
        }

        // Validate regexp (must be a quoted string)
        if (!$this->isValidQuotedString($regexp)) {
            $this->messageService->addSystemError(_('NAPTR record regexp must be a quoted string.'));
            return false;
        }

        // Validate replacement (must be a valid domain name or ".")
        if ($replacement !== ".") {
            $replacementResult = $this->hostnameValidator->isValidHostnameFqdn($replacement, 1);
            if ($replacementResult === false) {
                $this->messageService->addSystemError(_('NAPTR record replacement must be either "." or a valid fully-qualified domain name.'));
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a string is a valid quoted string
     *
     * @param string $value The string to check
     * @return bool True if valid, false otherwise
     */
    private function isValidQuotedString(string $value): bool
    {
        return preg_match('/^".*"$/', $value) === 1;
    }
}
