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
 * Validator for A DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ARecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private IPAddressValidator $ipAddressValidator;
    private TTLValidator $ttlValidator;
    private MessageService $messageService;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ipAddressValidator = new IPAddressValidator();
        $this->ttlValidator = new TTLValidator();
        $this->messageService = new MessageService();
    }

    /**
     * Validate A record
     *
     * @param string $content IPv4 address
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for A records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate IPv4 address
        if (!$this->ipAddressValidator->isValidIPv4($content)) {
            return false;
        }

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // Validate priority (should be 0 for A records)
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
     * Validate priority for A records
     * A records don't use priority, so it should be 0
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

        // If provided, ensure it's 0 for A records
        if (is_numeric($prio) && intval($prio) === 0) {
            return 0;
        }

        return false;
    }
}
