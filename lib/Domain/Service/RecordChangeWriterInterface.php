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

namespace Poweradmin\Domain\Service;

/**
 * Records before/after snapshots of record and zone changes for the change log.
 */
interface RecordChangeWriterInterface
{
    /**
     * @param array<string, mixed> $afterRecord The record as stored
     */
    public function logRecordCreate(array $afterRecord, ?int $zoneId): void;

    /**
     * @param array<string, mixed> $beforeRecord
     * @param array<string, mixed> $afterRecord
     */
    public function logRecordEdit(array $beforeRecord, array $afterRecord, int $zoneId): void;

    /**
     * @param array<string, mixed> $beforeRecord The record as it was before deletion
     */
    public function logRecordDelete(array $beforeRecord, ?int $zoneId): void;

    /**
     * @param array<string, mixed> $zoneData
     */
    public function logZoneCreate(array $zoneData): void;

    /**
     * @param array<string, mixed> $zoneData
     */
    public function logZoneDelete(array $zoneData, int $recordCount): void;

    /**
     * @param array<string, mixed> $beforeZone
     * @param array<string, mixed> $afterZone
     */
    public function logZoneMetadataEdit(array $beforeZone, array $afterZone): void;
}
