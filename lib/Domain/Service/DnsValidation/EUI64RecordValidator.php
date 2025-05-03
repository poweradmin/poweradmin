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
 * EUI64 record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class EUI64RecordValidator implements DnsRecordValidatorInterface
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
     * Validates EUI64 record content
     *
     * @param string $content The content of the EUI64 record (EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for EUI64 records)
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

        // Validate content - should be a valid EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format
        if (!$this->isValidEUI64($content)) {
            $this->messageService->addSystemError(_('EUI64 record must be a valid EUI-64 address in xx-xx-xx-xx-xx-xx-xx-xx format (where x is a hexadecimal digit).'));
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
            'prio' => 0, // EUI64 records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Check if a string is a valid EUI-64 address
     *
     * @param string $data The data to check
     * @return bool True if valid EUI-64, false otherwise
     */
    private function isValidEUI64(string $data): bool
    {
        // EUI-64 format: xx-xx-xx-xx-xx-xx-xx-xx where x is a hexadecimal digit
        return (bool) preg_match('/^([0-9a-fA-F]{2}-){7}[0-9a-fA-F]{2}$/', $data);
    }
}
