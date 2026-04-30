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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class RecordChangeLogger
{
    public const ACTION_RECORD_CREATE = 'record_create';
    public const ACTION_RECORD_EDIT = 'record_edit';
    public const ACTION_RECORD_DELETE = 'record_delete';
    public const ACTION_ZONE_CREATE = 'zone_create';
    public const ACTION_ZONE_DELETE = 'zone_delete';
    public const ACTION_ZONE_METADATA_EDIT = 'zone_metadata_edit';

    // Per-field cap on long string values (TXT content, comments). Long values
    // get truncated in-place with a `_*_truncated` marker so the snapshot stays
    // valid JSON. 4 KiB comfortably fits the longest reasonable record content.
    private const MAX_FIELD_LENGTH = 4096;

    // Final safety net for the serialized snapshot. MySQL TEXT holds 65 535
    // bytes; staying under this leaves room for row metadata. If a payload
    // somehow still exceeds this after field truncation, we fall back to a
    // minimal marker payload rather than emit invalid JSON.
    private const MAX_SNAPSHOT_LENGTH = 60000;

    private PDO $db;
    private UserContextService $userContext;
    private ?ConfigurationManager $config;

    public function __construct(
        PDO $db,
        ?UserContextService $userContext = null,
        ?ConfigurationManager $config = null
    ) {
        $this->db = $db;
        $this->userContext = $userContext ?? new UserContextService();
        $this->config = $config;
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

    /**
     * @param int|string|null $recordId Numeric PK in SQL mode, or the
     *   base64url-encoded RecordIdentifier in API mode.
     */
    private function insert(
        string $action,
        ?int $zoneId,
        int|string|null $recordId,
        ?string $beforeState,
        ?string $afterState
    ): void {
        // Honor the same opt-out admins use for the legacy log_users / log_zones
        // / log_groups tables. When database audit logging is disabled, skip the
        // change log too rather than retain audit data the operator opted out of.
        if (!$this->isDatabaseLoggingEnabled()) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO log_record_changes
                (zone_id, record_id, action, user_id, username, before_state, after_state, client_ip)
             VALUES
                (:zone_id, :record_id, :action, :user_id, :username, :before_state, :after_state, :client_ip)'
        );

        $userId = $this->userContext->getLoggedInUserId();
        $username = $this->userContext->getLoggedInUsername() ?? 'system';

        $stmt->bindValue(':zone_id', $zoneId, $zoneId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        if ($recordId === null) {
            $stmt->bindValue(':record_id', null, PDO::PARAM_NULL);
        } elseif (is_int($recordId)) {
            $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':record_id', $recordId, PDO::PARAM_STR);
        }
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

    private function isDatabaseLoggingEnabled(): bool
    {
        $config = $this->config ?? ConfigurationManager::getInstance();
        return (bool) $config->get('logging', 'database_enabled', false);
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

        $this->truncateField($snapshot, 'content');
        $this->truncateField($snapshot, 'comment');

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

        // Optional pdns metadata payload, used by EditZoneMetadataController and
        // /api/v2/zones/{id}/metadata endpoints. Stored as a kind => sorted-values
        // map so order-only differences don't trigger spurious diff rows.
        if (isset($zone['metadata']) && is_array($zone['metadata'])) {
            $snapshot['metadata'] = self::normalizeMetadataRows($zone['metadata']);
        }

        return $this->jsonEncode($snapshot);
    }

    /**
     * @param array<int, array{kind?: string, content?: string}> $rows
     * @return array<string, list<string>>
     */
    public static function normalizeMetadataRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = isset($row['kind']) && is_string($row['kind']) ? $row['kind'] : '';
            if ($kind === '') {
                continue;
            }
            $content = isset($row['content']) ? (string) $row['content'] : '';
            $grouped[$kind][] = $content;
        }
        ksort($grouped);
        foreach ($grouped as &$values) {
            sort($values);
        }
        return $grouped;
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

        if (strlen($encoded) > self::MAX_SNAPSHOT_LENGTH) {
            // Per-field truncation already capped content/comment, so reaching
            // this branch means the rest of the payload is itself oversized
            // (e.g. unusually large name or zone_name). Drop to a minimal
            // marker rather than persist invalid JSON.
            $marker = json_encode([
                '_oversized' => true,
                'id' => $payload['id'] ?? null,
                'type' => $payload['type'] ?? null,
                'name' => isset($payload['name']) && is_string($payload['name'])
                    ? substr($payload['name'], 0, 255)
                    : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $marker === false ? null : $marker;
        }

        return $encoded;
    }

    private function truncateField(array &$snapshot, string $key): void
    {
        if (!isset($snapshot[$key]) || !is_string($snapshot[$key])) {
            return;
        }
        if (strlen($snapshot[$key]) <= self::MAX_FIELD_LENGTH) {
            return;
        }
        $snapshot[$key] = substr($snapshot[$key], 0, self::MAX_FIELD_LENGTH);
        $snapshot['_' . $key . '_truncated'] = true;
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

        $aMeta = isset($a['metadata']) && is_array($a['metadata']) ? self::normalizeMetadataRows($a['metadata']) : null;
        $bMeta = isset($b['metadata']) && is_array($b['metadata']) ? self::normalizeMetadataRows($b['metadata']) : null;
        if ($aMeta !== $bMeta) {
            return false;
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
