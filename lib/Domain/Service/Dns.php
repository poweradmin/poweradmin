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

namespace Poweradmin\Domain\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;

/**
 * DNS functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 * @deprecated Use DnsRecordValidationService instead
 */
class Dns
{
    private DnsRecordValidationServiceInterface $validationService;

    public function __construct(
        PDOLayer $db,
        ConfigurationManager $config
    ) {
        $this->validationService = DnsServiceFactory::createDnsRecordValidationService($db, $config);
    }

    /**
     * Validate DNS record input
     *
     * @param int $rid Record ID
     * @param int $zid Zone ID
     * @param string $type Record Type
     * @param string $content content part of record
     * @param string $name Name part of record
     * @param int|null $prio Priority
     * @param int|null $ttl TTL
     * @param string $dns_hostmaster DNS hostmaster email
     * @param int $dns_ttl Default TTL value
     *
     * @return array|bool Returns array with validated data on success, false otherwise
     * @deprecated Use DnsRecordValidationService::validateRecord() instead
     */
    public function validate_input(int $rid, int $zid, string $type, mixed $content, string $name, mixed $prio, mixed $ttl, $dns_hostmaster, $dns_ttl): array|bool
    {
        // Convert to proper types for the new service
        $result = $this->validationService->validateRecord(
            $rid,
            $zid,
            $type,
            (string)$content,
            $name,
            $prio === null ? null : (int)$prio,
            $ttl === null ? null : (int)$ttl,
            (string)$dns_hostmaster,
            (int)$dns_ttl
        );

        // Maintain backward compatibility: return false instead of null
        return $result ?? false;
    }
}
