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

namespace Poweradmin\Infrastructure\Logger;

use PDO;
use Poweradmin\Domain\Service\UserContextService;

class RecordChangeLogger
{
    public const ACTION_RECORD_CREATE = 'record_create';
    public const ACTION_RECORD_EDIT = 'record_edit';
    public const ACTION_RECORD_DELETE = 'record_delete';
    public const ACTION_ZONE_CREATE = 'zone_create';
    public const ACTION_ZONE_DELETE = 'zone_delete';
    public const ACTION_ZONE_METADATA_EDIT = 'zone_metadata_edit';

    private PDO $db;
    private UserContextService $userContext;

    public function __construct(PDO $db, ?UserContextService $userContext = null)
    {
        $this->db = $db;
        $this->userContext = $userContext ?? new UserContextService();
    }

    public function logRecordCreate(array $afterRecord, ?int $zoneId): void
    {
        $this->insert(
            self::ACTION_RECORD_CREATE,
            $zoneId,
            $afterRecord['id'] ?? null,
            null,
            $this->encodeRecordSnapshot($afterRecord)
        );
    }

    public function logRecordEdit(array $beforeRecord, array $afterRecord, int $zoneId): void
    {
        if ($this->recordsEqual($beforeRecord, $afterRecord)) {
            return;
        }

        $this->insert(
            self::ACTION_RECORD_EDIT,
            $zoneId,
            $afterRecord['id'] ?? $beforeRecord['id'] ?? null,
            $this->encodeRecordSnapshot($beforeRecord),
            $this->encodeRecordSnapshot($afterRecord)
        );
    }

    public function logRecordDelete(array $beforeRecord, ?int $zoneId): void
    {
        $this->insert(
            self::ACTION_RECORD_DELETE,
            $zoneId,
            $beforeRecord['id'] ?? null,
            $this->encodeRecordSnapshot($beforeRecord),
            null
        );
    }

    public function logZoneCreate(array $zoneData): void
    {
        $this->insert(
            self::ACTION_ZONE_CREATE,
            $zoneData['id'] ?? null,
            null,
            null,
            $this->encodeZoneSnapshot($zoneData)
        );
    }

    public function logZoneDelete(array $zoneData, int $recordCount): void
    {
        $snapshot = $this->encodeZoneSnapshot($zoneData);
        $snapshot = $this->mergeRecordCount($snapshot, $recordCount);

        $this->insert(
            self::ACTION_ZONE_DELETE,
            $zoneData['id'] ?? null,
            null,
            $snapshot,
            null
        );
    }

    public function logZoneMetadataEdit(array $beforeZone, array $afterZone): void
    {
        if ($this->zonesEqual($beforeZone, $afterZone)) {
            return;
        }

        $zoneId = $afterZone['id'] ?? $beforeZone['id'] ?? null;

        $this->insert(
            self::ACTION_ZONE_METADATA_EDIT,
            $zoneId,
            null,
            $this->encodeZoneSnapshot($beforeZone),
            $this->encodeZoneSnapshot($afterZone)
        );
    }

    private function insert(
        string $action,
        ?int $zoneId,
        ?int $recordId,
        ?string $beforeState,
        ?string $afterState
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO log_record_changes
                (zone_id, record_id, action, user_id, username, before_state, after_state, client_ip)
             VALUES
                (:zone_id, :record_id, :action, :user_id, :username, :before_state, :after_state, :client_ip)'
        );

        $userId = $this->userContext->getLoggedInUserId();
        $username = $this->userContext->getLoggedInUsername() ?? 'system';

        $stmt->bindValue(':zone_id', $zoneId, $zoneId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':record_id', $recordId, $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':before_state', $beforeState, $beforeState === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':after_state', $afterState, $afterState === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':client_ip', $this->getClientIp(), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function getClientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || $ip === '') {
            return null;
        }
        return substr($ip, 0, 64);
    }

    private function encodeRecordSnapshot(array $record): ?string
    {
        $snapshot = [
            'id' => $record['id'] ?? null,
            'name' => $record['name'] ?? null,
            'type' => $record['type'] ?? null,
            'content' => $record['content'] ?? null,
            'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
            'prio' => isset($record['prio']) ? (int) $record['prio'] : null,
            'disabled' => isset($record['disabled']) ? (bool) $record['disabled'] : null,
            'comment' => $record['comment'] ?? null,
            'zone_name' => $record['zone_name'] ?? null,
        ];

        if (isset($snapshot['type']) && in_array($snapshot['type'], ['SPF', 'TXT'], true) && is_string($snapshot['content'])) {
            $snapshot['content'] = trim($snapshot['content'], '"');
        }

        return $this->jsonEncode($snapshot);
    }

    private function encodeZoneSnapshot(array $zone): ?string
    {
        $snapshot = [
            'id' => $zone['id'] ?? null,
            'name' => $zone['name'] ?? $zone['zone_name'] ?? null,
            'type' => $zone['type'] ?? $zone['zone_type'] ?? null,
            'master' => $zone['master'] ?? $zone['zone_master'] ?? null,
            'template_id' => $zone['template_id'] ?? null,
            'owner' => $zone['owner'] ?? null,
        ];

        return $this->jsonEncode($snapshot);
    }

    private function mergeRecordCount(?string $snapshotJson, int $recordCount): ?string
    {
        if ($snapshotJson === null) {
            return $this->jsonEncode(['record_count' => $recordCount]);
        }

        $decoded = json_decode($snapshotJson, true);
        if (!is_array($decoded)) {
            return $snapshotJson;
        }
        $decoded['record_count'] = $recordCount;
        return $this->jsonEncode($decoded);
    }

    private function jsonEncode(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return null;
        }

        if (strlen($encoded) > 8192) {
            $encoded = substr($encoded, 0, 8189) . '...';
        }

        return $encoded;
    }

    private function recordsEqual(array $a, array $b): bool
    {
        $normalize = function (array $record): array {
            $type = $record['type'] ?? null;
            $content = $record['content'] ?? '';
            if (in_array($type, ['SPF', 'TXT'], true) && is_string($content)) {
                $content = trim($content, '"');
            }
            return [
                'name' => isset($record['name']) ? strtolower((string) $record['name']) : null,
                'type' => $type,
                'content' => $content,
                'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
                'prio' => isset($record['prio']) ? (int) $record['prio'] : null,
                'disabled' => isset($record['disabled']) ? (bool) $record['disabled'] : null,
                'comment' => $record['comment'] ?? null,
            ];
        };

        return $normalize($a) === $normalize($b);
    }

    private function zonesEqual(array $a, array $b): bool
    {
        $keys = ['name', 'zone_name', 'type', 'zone_type', 'master', 'zone_master', 'template_id', 'owner'];
        foreach ($keys as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT COUNT(*) AS total FROM log_record_changes' . $where;
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->execute();
        return (int) $stmt->fetch()['total'];
    }

    public function getFiltered(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT id, zone_id, record_id, action, user_id, username, before_state, after_state, client_ip, created_at
                FROM log_record_changes'
            . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value[0], $value[1]);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['before_state_decoded'] = $this->safeJsonDecode($row['before_state'] ?? null);
            $row['after_state_decoded'] = $this->safeJsonDecode($row['after_state'] ?? null);
            $row['changed_fields'] = $this->detectChangedFields(
                $row['before_state_decoded'],
                $row['after_state_decoded']
            );
            return $row;
        }, $rows);
    }

    public function getDistinctActions(): array
    {
        return [
            self::ACTION_RECORD_CREATE,
            self::ACTION_RECORD_EDIT,
            self::ACTION_RECORD_DELETE,
            self::ACTION_ZONE_CREATE,
            self::ACTION_ZONE_DELETE,
            self::ACTION_ZONE_METADATA_EDIT,
        ];
    }

    public function getDistinctUsers(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT username FROM log_record_changes WHERE username IS NOT NULL ORDER BY username');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['zone_id'])) {
            $conditions[] = 'zone_id = :zone_id';
            $params[':zone_id'] = [(int) $filters['zone_id'], PDO::PARAM_INT];
        }

        if (!empty($filters['action']) && in_array($filters['action'], $this->getDistinctActions(), true)) {
            $conditions[] = 'action = :action';
            $params[':action'] = [$filters['action'], PDO::PARAM_STR];
        }

        if (!empty($filters['user'])) {
            $conditions[] = 'username = :username';
            $params[':username'] = [$filters['user'], PDO::PARAM_STR];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'created_at >= :date_from';
            $params[':date_from'] = [$filters['date_from'], PDO::PARAM_STR];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'created_at <= :date_to';
            $params[':date_to'] = [$filters['date_to'], PDO::PARAM_STR];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    private function safeJsonDecode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function detectChangedFields(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return [];
        }

        $changed = [];
        foreach (['name', 'type', 'content', 'ttl', 'prio', 'disabled', 'comment'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        return $changed;
    }
}
