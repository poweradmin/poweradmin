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
 * The audit events domain services record; the Application layer owns the line format and the actor context.
 */
interface AuditLoggerInterface
{
    public function logRecordAdd(int $zoneId, string $type, string $name, string $content, int|string $ttl, int|string $prio): void;

    /**
     * @param array<string, mixed> $before Record row before the edit (type, name, content, ttl, prio)
     * @param array<string, mixed> $after Record row after the edit
     */
    public function logRecordEdit(?int $zoneId, array $before, array $after): void;

    public function logBatchPtrRecordAdd(int $zoneId, string $name, string $content, int|string $ttl, int|string $prio): void;

    /**
     * Written twice: logNotice() carries no zone id and only reaches the user log, so the
     * line is repeated against the zone to show in that zone's history.
     *
     * @param string $username The dyndns2 or API principal, which has no session
     */
    public function logDynamicDnsUpdate(string $username, string $hostname, int $zoneId, string $ip): void;

    /**
     * Logged against the member zone: that is the zone whose stored catalog changed.
     */
    public function logZoneCatalogAssign(int $zoneId, string $zoneName, string $catalog): void;

    public function logZoneCatalogClear(int $zoneId, string $zoneName, string $catalog): void;

    /**
     * @param list<string> $kinds The kinds the zone carries after the edit
     */
    public function logZoneMetadataEdit(int $zoneId, string $zoneName, array $kinds): void;

    public function logDnssecSignZone(int $zoneId, string $zoneName): void;

    public function logDnssecUnsignZone(int $zoneId, string $zoneName): void;
}
