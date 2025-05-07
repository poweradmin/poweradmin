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
 * Interface for PowerDNS supermaster operations
 */
interface SupermasterManagerInterface
{
    /**
     * Add Supermaster
     *
     * Add a trusted supermaster to the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermasterfound in NS records for domain
     * @param string $account Account name used for tracking
     *
     * @return boolean true on success
     */
    public function addSupermaster(string $master_ip, string $ns_name, string $account): bool;

    /**
     * Delete Supermaster
     *
     * Delete a supermaster from the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermaster
     *
     * @return boolean true on success
     */
    public function deleteSupermaster(string $master_ip, string $ns_name): bool;

    /**
     * Get All Supermasters
     *
     * Gets an array of arrays of supermaster details
     *
     * @return array[] supermasters detail [master_ip,ns_name,account]s
     */
    public function getSupermasters(): array;

    /**
     * Get Supermaster Info from IP
     *
     * Retrieve supermaster details from supermaster IP address
     *
     * @param string $master_ip Supermaster IP address
     *
     * @return array array of supermaster details
     */
    public function getSupermasterInfoFromIp(string $master_ip): array;

    /**
     * Check if Supermaster IP address exists
     *
     * @param string $master_ip Supermaster IP
     *
     * @return boolean true if exists, otherwise false
     */
    public function supermasterExists(string $master_ip): bool;

    /**
     * Check if Supermaster IP Address and NS Name combo exists
     *
     * @param string $master_ip Supermaster IP Address
     * @param string $ns_name Supermaster NS Name
     *
     * @return boolean true if exists, false otherwise
     */
    public function supermasterIpNameExists(string $master_ip, string $ns_name): bool;

    /**
     * Update Supermaster
     *
     * Update a trusted supermaster in the global supermasters table
     *
     * @param string $old_master_ip Original supermaster IP address
     * @param string $old_ns_name Original hostname of supermaster
     * @param string $new_master_ip New supermaster IP address
     * @param string $new_ns_name New hostname of supermaster
     * @param string $account Account name used for tracking
     *
     * @return boolean true on success
     */
    public function updateSupermaster(string $old_master_ip, string $old_ns_name, string $new_master_ip, string $new_ns_name, string $account): bool;

    /**
     * Validate Account is valid string
     *
     * @param string $account Account name alphanumeric and ._-
     *
     * @return boolean true is valid, false otherwise
     */
    public static function validateAccount(string $account): bool;
}
