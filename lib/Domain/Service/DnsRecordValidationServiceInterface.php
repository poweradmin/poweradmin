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

use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Interface for DNS Record Validation Service
 *
 * Defines the contract for DNS record validation functionality
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
interface DnsRecordValidationServiceInterface
{
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
     * @return ValidationResult<array> Returns ValidationResult with validated data or error messages
     */
    public function validateRecord(
        int $rid,
        int $zid,
        string $type,
        string $content,
        string $name,
        ?int $prio,
        ?int $ttl,
        string $dns_hostmaster,
        int $dns_ttl
    ): ValidationResult;
}
