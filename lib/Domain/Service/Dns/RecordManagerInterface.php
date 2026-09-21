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

namespace Poweradmin\Domain\Service\Dns;

/**
 * Record creation, update and deletion.
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
     * @return boolean true if successful; addRecordGetId() carries the reason for a refusal
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool;

    /**
     * Add a record and return its ID
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     * @param int $disabled Whether the record is created in disabled state (0 or 1)
     * @param bool $finalizeZone Bump the serial and rectify; a batch caller does that once itself
     * @param array|null $comment RRset comment ['content' => string, 'account' => string]; the API
     *                            backend writes it in the same PATCH as the record, SQL ignores it
     *
     * @return RecordWriteResult The new record id, or the reason the write was refused
     */
    public function addRecordGetId(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio, int $disabled = 0, bool $finalizeZone = true, ?array $comment = null): RecordWriteResult;

    /**
     * Edit a record. An unchanged save is skipped entirely when
     * dns.bump_serial_on_unchanged_save is off, so nothing bumps the serial then.
     *
     * @param array $record Record structure to update
     * @param bool $finalizeZone Bump the serial and rectify; a batch caller does that once itself
     * @param array|null $comment RRset comment ['content' => string, 'account' => string] to
     *                            store with the record where the backend keeps them together
     */
    public function editRecord(array $record, bool $finalizeZone = true, ?array $comment = null): RecordWriteResult;

    /**
     * What every single write ends with: bump the serial (unless told not to) and
     * rectify. Batch callers pass finalizeZone=false to the writes and call this
     * once when they are done; one holding a transaction bumps the serial inside
     * it and calls this with bumpSerial=false after the commit, since PowerDNS
     * rectifies from committed rows.
     */
    public function finalizeZone(int $zoneId, bool $bumpSerial = true): void;

    /**
     * Delete a record and everything that pointed at it (template link, comments)
     *
     * @param int|string $rid Record ID
     * @param bool $finalizeZone Bump the serial and rectify; a batch caller does that once itself
     */
    public function deleteRecord(int|string $rid, bool $finalizeZone = true): RecordWriteResult;

    /**
     * Edit the zone comment
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return RecordWriteResult Success, or the reason the write was refused
     */
    public function editZoneComment(int $zone_id, string $comment): RecordWriteResult;
}
