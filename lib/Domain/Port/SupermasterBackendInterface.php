<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Domain\Port;

/**
 * Supermaster (autoprimary) reads and writes against the DNS backend.
 */
interface SupermasterBackendInterface
{
    /**
     * Add a supermaster (autoprimary).
     *
     * @param string $masterIp Supermaster IP address
     * @param string $nsName Nameserver hostname
     * @param string $account Account name
     * @return bool
     */
    public function addSupermaster(string $masterIp, string $nsName, string $account): bool;

    /**
     * Delete a supermaster (autoprimary).
     *
     * @param string $masterIp Supermaster IP address
     * @param string $nsName Nameserver hostname
     * @return bool
     */
    public function deleteSupermaster(string $masterIp, string $nsName): bool;

    /**
     * Get all supermasters.
     *
     * @return array Array of supermaster records
     */
    public function getSupermasters(): array;

    /**
     * Update a supermaster.
     *
     * @param string $oldMasterIp Original IP
     * @param string $oldNsName Original nameserver
     * @param string $newMasterIp New IP
     * @param string $newNsName New nameserver
     * @param string $account Account name
     * @return bool
     */
    public function updateSupermaster(string $oldMasterIp, string $oldNsName, string $newMasterIp, string $newNsName, string $account): bool;
}
