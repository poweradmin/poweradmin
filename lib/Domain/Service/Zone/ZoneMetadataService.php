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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The zone metadata rules the editor and the API share: what a kind accepts,
 * who may change it, what the active backend can store, and how a change is
 * persisted and logged. Callers parse their input and word the refusals.
 */
class ZoneMetadataService
{
    public const SUPPORT_SUPPORTED = ZoneMetadataStoreInterface::SUPPORT_SUPPORTED;
    public const SUPPORT_UNSUPPORTED_KNOWN = ZoneMetadataStoreInterface::SUPPORT_UNSUPPORTED_KNOWN;
    public const SUPPORT_UNKNOWN = ZoneMetadataStoreInterface::SUPPORT_UNKNOWN;
    public const MAX_KIND_LENGTH = 32;

    private LoggerInterface $logger;

    public function __construct(
        private readonly ZoneMetadataStoreInterface $store,
        private readonly ConfigurationInterface $config,
        private readonly PermissionService $permissions,
        private readonly AuditLoggerInterface $audit,
        private readonly RecordChangeWriterInterface $changeLogger,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function normalizeKind(string $kind): string
    {
        return strtoupper(trim($kind));
    }

    /**
     * Trim the submitted rows and drop the ones with no kind or no content, so an
     * add/remove-row editor never produces an empty write.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{kind: string, content: string}>
     */
    public static function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $kind = self::normalizeKind((string)($row['kind'] ?? ''));
            $content = trim((string)($row['content'] ?? ''));
            if ($kind === '' || $content === '') {
                continue;
            }
            $normalized[] = ['kind' => $kind, 'content' => $content];
        }

        return $normalized;
    }

    /**
     * The kinds whose value set differs between two snapshots.
     *
     * @param array<int, array{kind: string, content: string}> $submitted
     * @param array<int, array{kind: string, content: string}> $current
     * @return list<string>
     */
    public static function changedKinds(array $submitted, array $current): array
    {
        $collect = static function (array $rows): array {
            $byKind = [];
            foreach ($rows as $row) {
                $kind = (string)($row['kind'] ?? '');
                if ($kind !== '') {
                    $byKind[$kind][] = (string)($row['content'] ?? '');
                }
            }
            foreach ($byKind as $kind => $values) {
                sort($values);
                $byKind[$kind] = $values;
            }
            return $byKind;
        };

        $before = $collect($current);
        $after = $collect($submitted);

        $changed = [];
        foreach (array_keys($before + $after) as $kind) {
            if (($before[$kind] ?? []) !== ($after[$kind] ?? [])) {
                $changed[] = (string)$kind;
            }
        }

        return $changed;
    }

    /**
     * Why the active store cannot hold this kind (a MetadataDefinitions::REJECT_* code), or null.
     */
    public function writeRejection(string $kind): ?string
    {
        if (MetadataDefinitions::isServerManaged($kind)) {
            return MetadataDefinitions::REJECT_SERVER_MANAGED;
        }

        return $this->store->writeRejection($kind);
    }

    /**
     * Whether the active store is known to take a version-gated kind: SUPPORT_* constants.
     *
     * @param array<string, mixed> $definition A MetadataDefinitions entry
     * @param callable(): PdnsCapabilities $capabilities Resolved only when support is version-gated
     */
    public function kindSupport(array $definition, callable $capabilities): string
    {
        return $this->store->kindSupport($definition, $capabilities);
    }

    /**
     * The zone's metadata rows, sorted by kind. In API mode the zone-object
     * properties that stand in for metadata kinds are folded in.
     *
     * @return list<array{kind: string, content: string}>
     */
    public function load(int $zoneId, string $zoneName): array
    {
        return $this->store->load($zoneId, $zoneName);
    }

    /**
     * Replace the zone's whole metadata set, as the editor does. Every row is
     * checked first, then only the kinds that actually change are gated, so a
     * value set out of band does not lock the editor out of the rest.
     *
     * @param list<array{kind: string, content: string}> $rows Already normalised
     */
    public function replaceAll(int $zoneId, string $zoneName, array $rows, int $actingUserId): ZoneMetadataResult
    {
        $refusal = $this->validateSet($rows);
        if ($refusal !== null) {
            return $refusal;
        }

        $before = $this->load($zoneId, $zoneName);
        foreach (self::changedKinds($rows, $before) as $kind) {
            $refusal = $this->kindRefusal($kind, $actingUserId);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        if (!$this->store->replaceAll($zoneId, $zoneName, $rows, $before)) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED);
        }
        $this->log($zoneId, $zoneName, $before, $rows);

        return ZoneMetadataResult::ok();
    }

    /**
     * Set every value of one kind, as the API does.
     *
     * @param list<string> $values
     */
    public function replaceKind(int $zoneId, string $zoneName, string $kind, array $values, int $actingUserId): ZoneMetadataResult
    {
        $kind = self::normalizeKind($kind);
        $refusal = $this->kindRefusal($kind, $actingUserId);
        if ($refusal !== null) {
            return $refusal;
        }

        $values = array_values(array_filter(array_map(fn($value): string => trim((string)$value), $values), fn(string $value): bool => $value !== ''));
        if ($values === []) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::EMPTY_VALUES, $kind);
        }
        $rows = array_map(fn(string $value): array => ['kind' => $kind, 'content' => $value], $values);
        $refusal = $this->validateValues($rows);
        if ($refusal !== null) {
            return $refusal;
        }

        $before = $this->load($zoneId, $zoneName);
        $after = self::replaceKindIn($before, $kind, $values);
        $refusal = $this->validateCompanions($rows, $after);
        if ($refusal !== null) {
            return $refusal;
        }

        if (!$this->store->replaceKind($zoneId, $zoneName, $kind, $values, $before)) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED, $kind);
        }
        $this->log($zoneId, $zoneName, $before, $after);

        return ZoneMetadataResult::ok();
    }

    /**
     * Remove every value of one kind, as the API does.
     */
    public function deleteKind(int $zoneId, string $zoneName, string $kind, int $actingUserId): ZoneMetadataResult
    {
        $kind = self::normalizeKind($kind);
        $refusal = $this->kindRefusal($kind, $actingUserId);
        if ($refusal !== null) {
            return $refusal;
        }

        $before = $this->load($zoneId, $zoneName);
        $after = self::replaceKindIn($before, $kind, []);
        // A kind another one depends on cannot go while the dependant stays; rows
        // already inconsistent for other reasons are not this delete's concern
        foreach ($after as $row) {
            if (MetadataDefinitions::requiredCompanionKind($row['kind'], $row['content']) === $kind) {
                return new ZoneMetadataResult(ZoneMetadataOutcome::COMPANION_REQUIRED, $row['kind'], null, $kind);
            }
        }

        if (!$this->store->replaceKind($zoneId, $zoneName, $kind, [], $before)) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::WRITE_FAILED, $kind);
        }
        $this->log($zoneId, $zoneName, $before, $after);

        return ZoneMetadataResult::ok();
    }

    /**
     * Single-value, vocabulary and companion rules over a whole set.
     *
     * @param list<array{kind: string, content: string}> $rows
     */
    private function validateSet(array $rows): ?ZoneMetadataResult
    {
        foreach ($rows as $row) {
            $refusal = self::kindShapeRefusal($row['kind']);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return $this->validateValues($rows) ?? $this->validateCompanions($rows, $rows);
    }

    /** domainmetadata.kind is VARCHAR(32), so a longer kind cannot be stored as typed */
    private static function kindShapeRefusal(string $kind): ?ZoneMetadataResult
    {
        if ($kind === '' || strlen($kind) > self::MAX_KIND_LENGTH) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::INVALID_KIND, $kind);
        }

        return null;
    }

    /**
     * @param list<array{kind: string, content: string}> $rows
     */
    private function validateValues(array $rows): ?ZoneMetadataResult
    {
        $countsByKind = [];
        foreach ($rows as $row) {
            $kind = $row['kind'];
            $countsByKind[$kind] = ($countsByKind[$kind] ?? 0) + 1;
            if (!MetadataDefinitions::isMultiValue($kind) && $countsByKind[$kind] > 1) {
                return new ZoneMetadataResult(ZoneMetadataOutcome::SINGLE_VALUE_ONLY, $kind);
            }

            // PowerDNS stores an unknown policy string without complaint and then
            // ignores it, so the vocabulary has to be enforced here.
            $options = MetadataDefinitions::getAllowedValues($kind, $this->config);
            if ($options !== null && !in_array($row['content'], $options, true)) {
                return new ZoneMetadataResult(ZoneMetadataOutcome::INVALID_VALUE, $kind, $options);
            }
        }

        return null;
    }

    /**
     * A kind that only takes effect next to another needs that one present in
     * the resulting set.
     *
     * @param list<array{kind: string, content: string}> $rows The rows to check
     * @param list<array{kind: string, content: string}> $resulting The set after the write
     */
    private function validateCompanions(array $rows, array $resulting): ?ZoneMetadataResult
    {
        $present = array_flip(array_column($resulting, 'kind'));
        foreach ($rows as $row) {
            $companion = MetadataDefinitions::requiredCompanionKind($row['kind'], $row['content']);
            if ($companion !== null && !isset($present[$companion])) {
                return new ZoneMetadataResult(ZoneMetadataOutcome::COMPANION_REQUIRED, $row['kind'], null, $companion);
            }
        }

        return null;
    }

    /**
     * Whether this kind may be written at all, and by this user: the backend must
     * be able to store it, and operator-only kinds (Lua reaching every zone the
     * server hosts) are for superusers.
     */
    private function kindRefusal(string $kind, int $actingUserId): ?ZoneMetadataResult
    {
        $refusal = self::kindShapeRefusal($kind);
        if ($refusal !== null) {
            return $refusal;
        }

        $rejection = match ($this->writeRejection($kind)) {
            MetadataDefinitions::REJECT_SERVER_MANAGED => ZoneMetadataOutcome::SERVER_MANAGED,
            MetadataDefinitions::REJECT_NO_API_ROUTE => ZoneMetadataOutcome::NO_API_ROUTE,
            MetadataDefinitions::REJECT_CUSTOM_PREFIX => ZoneMetadataOutcome::CUSTOM_PREFIX,
            default => null,
        };
        if ($rejection !== null) {
            return new ZoneMetadataResult($rejection, $kind);
        }

        if (MetadataDefinitions::isOperatorOnly($kind) && !$this->permissions->isAdmin($actingUserId)) {
            return new ZoneMetadataResult(ZoneMetadataOutcome::OPERATOR_ONLY, $kind);
        }

        return null;
    }

    /**
     * The set with every row of one kind replaced by the given values.
     *
     * @param list<array{kind: string, content: string}> $rows
     * @param list<string> $values
     * @return list<array{kind: string, content: string}>
     */
    public static function replaceKindIn(array $rows, string $kind, array $values): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (self::normalizeKind($row['kind']) !== $kind) {
                $kept[] = $row;
            }
        }
        foreach ($values as $value) {
            $kept[] = ['kind' => $kind, 'content' => (string)$value];
        }

        return $kept;
    }

    /**
     * @param list<array{kind: string, content: string}> $before
     * @param list<array{kind: string, content: string}> $after
     */
    private function log(int $zoneId, string $zoneName, array $before, array $after): void
    {
        try {
            $this->audit->logZoneMetadataEdit($zoneId, $zoneName, array_values(array_unique(array_column($after, 'kind'))));
            $this->changeLogger->logZoneMetadataEdit(
                ['id' => $zoneId, 'name' => $zoneName, 'metadata' => $before],
                ['id' => $zoneId, 'name' => $zoneName, 'metadata' => $after]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write zone metadata edit log: {error}', ['error' => $e->getMessage()]);
        }
    }
}
