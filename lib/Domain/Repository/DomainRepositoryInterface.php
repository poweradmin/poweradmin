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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\Constants;

/**
 * Interface for domain/zone repository operations
 */
interface DomainRepositoryInterface
{
    /**
     * Check if Zone ID exists
     *
     * @param int $zid Zone ID
     *
     * @return int Domain count or false on failure
     */
    public function zoneIdExists(int $zid): int;

    /**
     * Get Domain Name by domain ID
     *
     * @param int $id Domain ID
     *
     * @return bool|string Domain name
     */
    public function getDomainNameById(int $id): bool|string;

    /**
     * Get Domain ID by name
     *
     * @param string $name Domain name
     *
     * @return bool|int Domain ID or false if not found
     */
    public function getDomainIdByName(string $name): bool|int;

    /**
     * Get zone id from name
     *
     * @param string $zname Zone name
     * @return bool|int Zone ID
     */
    public function getZoneIdFromName(string $zname): bool|int;

    /**
     * Get Domain Type for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return string Domain Type [NATIVE,MASTER,SLAVE]
     */
    public function getDomainType(int $id): string;

    /**
     * Get Slave Domain's Master
     *
     * @param int $id Domain ID
     *
     * @return string|bool|null Master server
     */
    public function getDomainSlaveMaster(int $id): string|bool|null;

    /**
     * Check if a domain is already existing.
     *
     * @param string $domain Domain name
     * @return boolean true if existing, false if it doesn't exist.
     */
    public function domainExists(string $domain): bool;

    /**
     * Get Zones
     *
     * @param string $perm View Zone Permissions ['own','all','none']
     * @param int $userid Requesting User ID
     * @param string $letterstart Starting letters to match [default='all']
     * @param int $rowstart Start from row in set [default=0]
     * @param int $rowamount Max number of rows to fetch for this query when not 'all' [default=9999]
     * @param string $sortby Column to sort results by [default='name']
     * @param string $sortDirection Sort direction [default='ASC']
     *
     * @return boolean|array false or array of zone details [id,name,type,count_records]
     */
    public function getZones(string $perm, int $userid = 0, string $letterstart = 'all', int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name', string $sortDirection = 'ASC'): bool|array;

    /**
     * Get Zone details from Zone ID
     *
     * @param int $zid Zone ID
     * @return array array of zone details [type,name,master_ip,record_count]
     */
    public function getZoneInfoFromId(int $zid): array;

    /**
     * Get Zone(s) details from Zone IDs
     *
     * @param array $zones Zone IDs
     * @return array
     */
    public function getZoneInfoFromIds(array $zones): array;

    /**
     * Get Best Matching in-addr.arpa Zone ID from Domain Name
     *
     * @param string $domain Domain name
     *
     * @return int Zone ID
     */
    public function getBestMatchingZoneIdFromName(string $domain): int;
}
