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
 * Validator for PTR DNS records
 */
class PTRRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
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
        $this->ttlValidator = new TTLValidator();
        $this->messageService = new MessageService();
    }

    /**
     * Validate PTR record
     *
     * @param string $content Hostname
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for PTR records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate content as hostname
        $contentHostnameResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
        if ($contentHostnameResult === false) {
            return false;
        }
        $content = $contentHostnameResult['hostname'];

        // Validate name as hostname
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

        // PTR records don't use priority
        $validatedPrio = 0;

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }
}
