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
 * AFSDB record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class AFSDBRecordValidator implements DnsRecordValidatorInterface
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
     * Validates AFSDB record content
     *
     * @param string $content The content of the AFSDB record
     * @param string $name The name of the record
     * @param mixed $prio The subtype value for AFSDB record
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($nameResult === false) {
            return false;
        }
        $name = $nameResult['hostname'];

        // Validate AFSDB content (hostname)
        $contentResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
        if ($contentResult === false) {
            $this->messageService->addSystemError(_('Invalid AFSDB hostname.'));
            return false;
        }
        $content = $contentResult['hostname'];

        // Validate subtype (stored in priority field)
        $validatedSubtype = $this->validateSubtype($prio);
        if ($validatedSubtype === false) {
            $this->messageService->addSystemError(_('Invalid AFSDB subtype. Must be 1 (AFS cell database server) or 2 (DCE authenticated name server).'));
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedSubtype,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate subtype for AFSDB records
     * AFSDB records accept subtypes 1 (AFS cell database server) or 2 (DCE authenticated name server)
     *
     * @param mixed $subtype Subtype value
     *
     * @return int|bool The validated subtype value or false if invalid
     */
    private function validateSubtype(mixed $subtype): int|bool
    {
        // If subtype is not provided or empty, use default of 1
        if (!isset($subtype) || $subtype === "") {
            return 1;
        }

        // Subtype should be either 1 or 2 for AFSDB
        if (is_numeric($subtype) && ($subtype == 1 || $subtype == 2)) {
            return (int)$subtype;
        }

        return false;
    }
}
