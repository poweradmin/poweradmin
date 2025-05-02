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

namespace Poweradmin\Domain\Service\Dns;

/**
 * Interface for domain/zone management operations
 */
interface DomainManagerInterface
{
    /**
     * Add a domain to the database
     *
     * @param object $db Database connection
     * @param string $domain A domain name
     * @param int $owner Owner ID for domain
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
     * @param string $slave_master Master server hostname for domain
     * @param int|string $zone_template ID of zone template ['none' or int]
     *
     * @return boolean true on success
     */
    public function addDomain($db, string $domain, int $owner, string $type, string $slave_master, int|string $zone_template): bool;

    /**
     * Deletes a domain by a given id
     *
     * @param int $id Zone ID
     *
     * @return boolean true on success
     */
    public function deleteDomain(int $id): bool;

    /**
     * Delete array of domains
     *
     * @param int[] $domains Array of Domain IDs to delete
     *
     * @return boolean true on success
     */
    public function deleteDomains(array $domains): bool;

    /**
     * Change Zone Type
     *
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     */
    public function changeZoneType(string $type, int $id): void;

    /**
     * Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     */
    public function changeZoneSlaveMaster(int $zone_id, string $ip_slave_master);

    /**
     * Change owner of a domain
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true when succesful
     */
    public static function addOwnerToZone($db, int $zone_id, int $user_id): bool;

    /**
     * Delete owner from zone
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true on success
     */
    public static function deleteOwnerFromZone($db, int $zone_id, int $user_id): bool;

    /**
     * Update All Zone Records for Zone ID with Zone Template
     *
     * @param string $db_type Database type
     * @param int $dns_ttl Default TTL
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     */
    public function updateZoneRecords(string $db_type, int $dns_ttl, int $zone_id, int $zone_template_id);

    /**
     * Get Zone Template ID for Zone ID
     *
     * @param object $db Database connection
     * @param int $zone_id Zone ID
     *
     * @return int Zone Template ID
     */
    public static function getZoneTemplate($db, int $zone_id): int;
}
