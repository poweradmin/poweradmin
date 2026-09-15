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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The totals on the admin dashboard. Zone and record counts are null when
 * they cannot be read, so the page still renders.
 */
class DashboardStatsService
{
    private TableNameService $tables;

    public function __construct(
        private readonly PDO $db,
        private readonly ConfigurationInterface $config,
        private readonly LoggerInterface $logger,
        private readonly UserRepositoryInterface $users,
        private readonly UserGroupRepositoryInterface $groups,
        private readonly ZoneReadRepositoryInterface $zones,
        private readonly DnsBackendProviderInterface $backend
    ) {
        $this->tables = new TableNameService($config);
    }

    /**
     * @param bool $countAllUsers Whether the viewer may see other accounts; otherwise only their own is counted
     * @return array{zones: ?int, records: ?int, users: int, groups: int}
     */
    public function stats(int $userId, bool $countAllUsers): array
    {
        $counts = [
            'users' => $countAllUsers ? $this->users->getTotalUserCount() : $this->users->getTotalUserCount($userId),
            'groups' => $this->groups->countAll(),
        ];

        if ($this->backend->isApiBackend()) {
            return ['zones' => $this->apiZoneCount(), 'records' => null] + $counts;
        }

        // The PowerDNS tables may be absent or ungranted (PowerDNS not yet deployed,
        // or its schema in a separate database); show no counts rather than fail
        try {
            $zones = $this->zones->getZoneCount();
            $records = (int)$this->db->query('SELECT COUNT(*) FROM ' . $this->tables->getTable(PdnsTable::RECORDS))->fetchColumn();
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard zone/record count failed: {error}', ['error' => $e->getMessage()]);
            $zones = null;
            $records = null;
        }

        return ['zones' => $zones, 'records' => $records] + $counts;
    }

    /**
     * Counts through the API for freshness (the local zones table lags until sync
     * runs) and falls back to the local cache count on an outage instead of showing 0.
     */
    private function apiZoneCount(): int
    {
        try {
            $count = count($this->backend->getZones());
            if ($count > 0 || (new ApiStatusService())->getLastError() === null) {
                return $count;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard zone count via API failed: {error}', ['error' => $e->getMessage()]);
        }

        return $this->zones->getZoneCount();
    }
}
