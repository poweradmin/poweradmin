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

namespace Poweradmin\Domain\Repository;

use Poweradmin\Domain\Model\ZoneChangeRequest;

/**
 * Persistence for zone change requests. Lists come back newest first.
 *
 * Filters accepted by list() and count(): "status" (string), "zoneIds"
 * (list<int>|null, null means every zone and [] means none), "requesterId" (int),
 * and "reviewableZoneIds" with "orRequesterId" for rows in those zones or filed by that user.
 */
interface ZoneChangeRequestRepositoryInterface
{
    /**
     * @param list<array<string, mixed>> $actions
     * @return int The new request id
     */
    public function create(
        int $zoneId,
        string $zoneName,
        string $kind,
        ?int $requesterId,
        string $requesterName,
        ?string $requestComment,
        ?string $baseSerial,
        array $actions,
        ?string $zoneComment
    ): int;

    public function find(int $id): ?ZoneChangeRequest;

    /**
     * @param array{status?: string, zoneIds?: list<int>|null, requesterId?: int, reviewableZoneIds?: list<int>|null, orRequesterId?: int} $filters
     * @return list<ZoneChangeRequest>
     */
    public function list(array $filters, int $offset, int $limit): array;

    /**
     * @param array{status?: string, zoneIds?: list<int>|null, requesterId?: int, reviewableZoneIds?: list<int>|null, orRequesterId?: int} $filters
     */
    public function count(array $filters): int;

    /**
     * @param list<int>|null $zoneIds null for every zone, [] for none
     * @return list<ZoneChangeRequest>
     */
    public function listPending(?array $zoneIds, int $offset, int $limit): array;

    /** @return list<ZoneChangeRequest> */
    public function listPendingForZone(int $zoneId): array;

    /** @param list<int>|null $zoneIds null for every zone, [] for none */
    public function countPending(?array $zoneIds): int;

    /** @return bool False when the request was no longer pending, so nothing changed */
    public function markReviewed(int $id, string $status, int $reviewerId, string $reviewerName, ?string $comment): bool;

    public function markApplied(int $id): void;

    public function markFailed(int $id, string $error): void;

    /** @return bool False when the request was no longer pending, so nothing changed */
    public function cancel(int $id): bool;
}
