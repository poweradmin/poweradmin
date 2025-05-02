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

use Exception;

/**
 * Interface for DNS record management operations
 */
interface RecordManagerInterface
{
    /**
     * Add a record
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     *
     * @return boolean true if successful
     * @throws Exception
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool;

    /**
     * Edit a record
     *
     * @param array $record Record structure to update
     *
     * @return boolean true if successful
     */
    public function editRecord(array $record): bool;

    /**
     * Delete a record by a given record id
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public function deleteRecord(int $rid): bool;

    /**
     * Delete record reference to zone template
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function deleteRecordZoneTempl($db, int $rid): bool;

    /**
     * Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function getZoneComment($db, int $zone_id): string;

    /**
     * Edit the zone comment
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return boolean true on success
     */
    public function editZoneComment(int $zone_id, string $comment): bool;
}
