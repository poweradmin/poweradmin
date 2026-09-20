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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;

/**
 * SQL persistence for zone_change_requests, a Poweradmin-native table that is
 * never prefixed and is shared by the SQL and API backends.
 */
class DbZoneChangeRequestRepository implements ZoneChangeRequestRepositoryInterface
{
    private const COLUMNS = 'id, zone_id, zone_name, kind, status, requester_id, requester_name, request_comment,
        base_serial, payload, reviewer_id, reviewer_name, review_comment, created_at, reviewed_at, applied_at, error, snapshot';

    public function __construct(private readonly PDO $db)
    {
    }

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
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO zone_change_requests
                (zone_id, zone_name, kind, status, requester_id, requester_name, request_comment, base_serial, payload)
             VALUES
                (:zone_id, :zone_name, :kind, :status, :requester_id, :requester_name, :request_comment, :base_serial, :payload)'
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_name', $zoneName, PDO::PARAM_STR);
        $stmt->bindValue(':kind', $kind, PDO::PARAM_STR);
        $stmt->bindValue(':status', ZoneChangeRequest::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':requester_id', $requesterId, $requesterId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':requester_name', $requesterName, PDO::PARAM_STR);
        $stmt->bindValue(':request_comment', $requestComment, $requestComment === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':base_serial', $baseSerial, $baseSerial === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':payload', ZoneChangeRequest::encodePayload($actions, $zoneComment), PDO::PARAM_STR);
        $stmt->execute();

        return (int)$this->db->lastInsertId('zone_change_requests_id_seq');
    }

    public function find(int $id): ?ZoneChangeRequest
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM zone_change_requests WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function list(array $filters, int $offset, int $limit): array
    {
        return $this->select($filters, $offset, $limit);
    }

    public function count(array $filters): int
    {
        [$where, $bindings] = $this->whereClause($filters);
        if ($where === null) {
            return 0;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM zone_change_requests' . $where);
        $this->bindFilters($stmt, $bindings);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function listPending(?array $zoneIds, int $offset, int $limit): array
    {
        return $this->list(['status' => ZoneChangeRequest::STATUS_PENDING, 'zoneIds' => $zoneIds], $offset, $limit);
    }

    public function listPendingForZone(int $zoneId): array
    {
        return $this->select(['status' => ZoneChangeRequest::STATUS_PENDING, 'zoneIds' => [$zoneId]], null, null);
    }

    public function countPending(?array $zoneIds): int
    {
        return $this->count(['status' => ZoneChangeRequest::STATUS_PENDING, 'zoneIds' => $zoneIds]);
    }

    public function storeSnapshot(int $id, string $snapshot): void
    {
        $stmt = $this->db->prepare('UPDATE zone_change_requests SET snapshot = :snapshot WHERE id = :id');
        $stmt->bindValue(':snapshot', $snapshot, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countPendingByZone(array $zoneIds): array
    {
        if ($zoneIds === []) {
            return [];
        }
        $placeholders = [];
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $placeholders[] = ":zone_id_$i";
        }
        $stmt = $this->db->prepare(
            'SELECT zone_id, COUNT(*) AS pending FROM zone_change_requests
             WHERE status = :status AND zone_id IN (' . implode(', ', $placeholders) . ')
             GROUP BY zone_id'
        );
        $stmt->bindValue(':status', ZoneChangeRequest::STATUS_PENDING, PDO::PARAM_STR);
        foreach (array_values($zoneIds) as $i => $zoneId) {
            $stmt->bindValue(":zone_id_$i", (int)$zoneId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $counts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[(int)$row['zone_id']] = (int)$row['pending'];
        }

        return $counts;
    }

    public function markReviewed(int $id, string $status, int $reviewerId, string $reviewerName, ?string $comment, array $fromStatuses = [ZoneChangeRequest::STATUS_PENDING]): bool
    {
        // Only a row still in one of the given states moves, so two concurrent decisions cannot both win
        $placeholders = [];
        foreach (array_values($fromStatuses) as $i => $from) {
            $placeholders[] = ":from_$i";
        }
        $stmt = $this->db->prepare(
            'UPDATE zone_change_requests
             SET status = :status, reviewer_id = :reviewer_id, reviewer_name = :reviewer_name,
                 review_comment = :review_comment, reviewed_at = CURRENT_TIMESTAMP, error = NULL
             WHERE id = :id AND status IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':reviewer_id', $reviewerId, PDO::PARAM_INT);
        $stmt->bindValue(':reviewer_name', $reviewerName, PDO::PARAM_STR);
        $stmt->bindValue(':review_comment', $comment, $comment === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        foreach (array_values($fromStatuses) as $i => $from) {
            $stmt->bindValue(":from_$i", $from, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function markApplied(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE zone_change_requests SET status = :status, applied_at = CURRENT_TIMESTAMP, error = NULL WHERE id = :id'
        );
        $stmt->bindValue(':status', ZoneChangeRequest::STATUS_APPROVED, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->prepare('UPDATE zone_change_requests SET status = :status, error = :error WHERE id = :id');
        $stmt->bindValue(':status', ZoneChangeRequest::STATUS_FAILED, PDO::PARAM_STR);
        $stmt->bindValue(':error', $error, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function cancel(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE zone_change_requests SET status = :status, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :pending'
        );
        $stmt->bindValue(':status', ZoneChangeRequest::STATUS_CANCELLED, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':pending', ZoneChangeRequest::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array{status?: string, zoneIds?: list<int>|null, requesterId?: int, reviewableZoneIds?: list<int>|null, orRequesterId?: int} $filters
     * @return list<ZoneChangeRequest> Newest first; the whole match when no window is given
     */
    private function select(array $filters, ?int $offset, ?int $limit): array
    {
        [$where, $bindings] = $this->whereClause($filters);
        if ($where === null) {
            return [];
        }

        $sql = 'SELECT ' . self::COLUMNS . ' FROM zone_change_requests' . $where . ' ORDER BY created_at DESC, id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $stmt = $this->db->prepare($sql);
        $this->bindFilters($stmt, $bindings);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        }
        $stmt->execute();

        $requests = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $requests[] = $this->mapRow($row);
        }

        return $requests;
    }

    /**
     * @param array{status?: string, zoneIds?: list<int>|null, requesterId?: int, reviewableZoneIds?: list<int>|null, orRequesterId?: int} $filters
     * @return array{0: string|null, 1: array<string, array{0: int|string, 1: int}>} The WHERE clause (null when
     *         the zone filter matches nothing) and the bindings as name => [value, type]
     */
    private function whereClause(array $filters): array
    {
        $conditions = [];
        $bindings = [];

        if (isset($filters['status'])) {
            $conditions[] = 'status = :status';
            $bindings[':status'] = [$filters['status'], PDO::PARAM_STR];
        }
        if (isset($filters['requesterId'])) {
            $conditions[] = 'requester_id = :requester_id';
            $bindings[':requester_id'] = [(int)$filters['requesterId'], PDO::PARAM_INT];
        }
        if (array_key_exists('zoneIds', $filters) && $filters['zoneIds'] !== null) {
            if ($filters['zoneIds'] === []) {
                return [null, []];
            }
            $placeholders = [];
            foreach (array_values($filters['zoneIds']) as $i => $zoneId) {
                $placeholders[] = ":zone_id_$i";
                $bindings[":zone_id_$i"] = [(int)$zoneId, PDO::PARAM_INT];
            }
            $conditions[] = 'zone_id IN (' . implode(', ', $placeholders) . ')';
        }
        // What a reviewer sees: requests in the zones they review, or their own
        if (isset($filters['orRequesterId']) && array_key_exists('reviewableZoneIds', $filters) && $filters['reviewableZoneIds'] !== null) {
            $bindings[':or_requester_id'] = [(int)$filters['orRequesterId'], PDO::PARAM_INT];
            if ($filters['reviewableZoneIds'] === []) {
                $conditions[] = 'requester_id = :or_requester_id';
            } else {
                $placeholders = [];
                foreach (array_values($filters['reviewableZoneIds']) as $i => $zoneId) {
                    $placeholders[] = ":reviewable_zone_id_$i";
                    $bindings[":reviewable_zone_id_$i"] = [(int)$zoneId, PDO::PARAM_INT];
                }
                $conditions[] = '(zone_id IN (' . implode(', ', $placeholders) . ') OR requester_id = :or_requester_id)';
            }
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /** @param array<string, array{0: int|string, 1: int}> $bindings */
    private function bindFilters(\PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): ZoneChangeRequest
    {
        $payload = ZoneChangeRequest::decodePayload((string)$row['payload']);

        return new ZoneChangeRequest(
            (int)$row['id'],
            (int)$row['zone_id'],
            (string)$row['zone_name'],
            (string)$row['kind'],
            (string)$row['status'],
            $row['requester_id'] === null ? null : (int)$row['requester_id'],
            (string)$row['requester_name'],
            $row['request_comment'] === null ? null : (string)$row['request_comment'],
            $row['base_serial'] === null ? null : (string)$row['base_serial'],
            $payload['actions'],
            $payload['zone_comment'],
            $row['reviewer_id'] === null ? null : (int)$row['reviewer_id'],
            $row['reviewer_name'] === null ? null : (string)$row['reviewer_name'],
            $row['review_comment'] === null ? null : (string)$row['review_comment'],
            (string)$row['created_at'],
            $row['reviewed_at'] === null ? null : (string)$row['reviewed_at'],
            $row['applied_at'] === null ? null : (string)$row['applied_at'],
            $row['error'] === null ? null : (string)$row['error'],
            ($row['snapshot'] ?? null) === null ? null : (string)$row['snapshot']
        );
    }
}
