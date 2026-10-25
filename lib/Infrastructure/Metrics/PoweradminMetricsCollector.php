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

namespace Poweradmin\Infrastructure\Metrics;

use PDO;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Version;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects the gauges behind the /api/metrics endpoint: counts about Poweradmin
 * itself, never keyed by zone or user name, so every metric has a handful of
 * series at most.
 *
 * Zone counts come from the PowerDNS API when the API backend is used (one
 * zone list request, which also carries the dnssec flag) and from the PowerDNS
 * tables otherwise. The values are computed on every scrape.
 */
class PoweradminMetricsCollector
{
    public function __construct(
        private readonly PDO $db,
        private readonly TableNameService $tables,
        private readonly ?PowerdnsApiClient $apiClient,
        private readonly bool $apiBackend,
        private readonly bool $countRecords,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return list<array{name: string, type: string, help: string, samples: list<array{labels?: array<string, string>, value: int|float}>}>
     */
    public function collect(): array
    {
        $start = microtime(true);
        $families = $this->gauges();

        $families[] = [
            'name' => 'poweradmin_metrics_collect_duration_seconds',
            'type' => 'gauge',
            'help' => 'Time spent collecting these metrics.',
            'samples' => [['value' => round(microtime(true) - $start, 6)]],
        ];

        return $families;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gauges(): array
    {
        $families = [[
            'name' => 'poweradmin_info',
            'type' => 'gauge',
            'help' => 'Poweradmin version and DNS backend, always 1.',
            'samples' => [['labels' => ['version' => Version::VERSION, 'backend' => $this->apiBackend ? 'api' : 'sql'], 'value' => 1]],
        ]];

        $zones = $this->zoneCounts();
        $families[] = [
            'name' => 'poweradmin_up',
            'type' => 'gauge',
            'help' => 'Whether the zone counts could be read from the DNS backend (1) or not (0).',
            'samples' => [['value' => $zones === null ? 0 : 1]],
        ];

        if ($zones !== null) {
            $families[] = [
                'name' => 'poweradmin_zones',
                'type' => 'gauge',
                'help' => 'Zones by type.',
                'samples' => array_map(
                    static fn(string $type, int $count): array => ['labels' => ['type' => $type], 'value' => $count],
                    array_keys($zones['kinds']),
                    array_values($zones['kinds'])
                ),
            ];
            $families[] = [
                'name' => 'poweradmin_zones_dnssec_signed',
                'type' => 'gauge',
                'help' => 'Zones with at least one active DNSSEC key, or presigned.',
                'samples' => [['value' => $zones['dnssec_signed']]],
            ];
        }

        $users = $this->userCounts();
        if ($users !== null) {
            $families[] = [
                'name' => 'poweradmin_users',
                'type' => 'gauge',
                'help' => 'Poweradmin users by state.',
                'samples' => [
                    ['labels' => ['active' => 'true'], 'value' => $users['active']],
                    ['labels' => ['active' => 'false'], 'value' => $users['inactive']],
                ],
            ];
        }

        if ($this->countRecords && !$this->apiBackend) {
            $records = $this->scalar('SELECT COUNT(*) FROM ' . $this->tables->getTable(PdnsTable::RECORDS));
            if ($records !== null) {
                $families[] = [
                    'name' => 'poweradmin_records',
                    'type' => 'gauge',
                    'help' => 'Records in all zones, including SOA and NS.',
                    'samples' => [['value' => $records]],
                ];
            }
        }

        return $families;
    }

    /**
     * @return array{kinds: array<string, int>, dnssec_signed: int}|null
     */
    private function zoneCounts(): ?array
    {
        try {
            if ($this->apiBackend) {
                return $this->apiClient?->getZoneCounts();
            }

            $domains = $this->tables->getTable(PdnsTable::DOMAINS);
            $kinds = [];
            foreach ($this->db->query("SELECT type, COUNT(*) AS n FROM $domains GROUP BY type") as $row) {
                $kind = strtolower((string)$row['type']) ?: 'unknown';
                $kinds[$kind] = ($kinds[$kind] ?? 0) + (int)$row['n'];
            }

            $cryptokeys = $this->tables->getTable(PdnsTable::CRYPTOKEYS);
            $metadata = $this->tables->getTable(PdnsTable::DOMAINMETADATA);
            // Bare column: active is BOOL on PostgreSQL, where "= 1" fails. PRESIGNED zones count
            // as signed, as PowerDNS's dnssec flag in API mode and the zone list treat them
            $signed = $this->scalar(
                "SELECT COUNT(*) FROM $domains d WHERE EXISTS (SELECT 1 FROM $cryptokeys c WHERE c.domain_id = d.id AND c.active)"
                . " OR EXISTS (SELECT 1 FROM $metadata m WHERE m.domain_id = d.id AND m.kind = 'PRESIGNED')"
            );

            return ['kinds' => $kinds, 'dnssec_signed' => $signed ?? 0];
        } catch (Throwable $e) {
            $this->logger->warning('Metrics: could not count zones: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array{active: int, inactive: int}|null
     */
    private function userCounts(): ?array
    {
        try {
            $counts = ['active' => 0, 'inactive' => 0];
            foreach ($this->db->query('SELECT active, COUNT(*) AS n FROM users GROUP BY active') as $row) {
                $counts[(int)$row['active'] === 1 ? 'active' : 'inactive'] += (int)$row['n'];
            }
            return $counts;
        } catch (Throwable $e) {
            $this->logger->warning('Metrics: could not count users: ' . $e->getMessage());
            return null;
        }
    }

    private function scalar(string $sql): ?int
    {
        try {
            $value = $this->db->query($sql)->fetchColumn();
            return $value === false ? null : (int)$value;
        } catch (Throwable $e) {
            $this->logger->warning('Metrics: query failed: ' . $e->getMessage());
            return null;
        }
    }
}
