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

namespace Poweradmin\Application\Service\Web;

use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserAdminInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Port\ZoneReadBackendInterface;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The totals on the admin dashboard. Zone and record counts are null when
 * they cannot be read, so the page still renders.
 */
class DashboardStatsService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserAdminInterface $users,
        private readonly UserGroupLookupInterface $groups,
        private readonly ZoneReadRepositoryInterface $zones,
        private readonly ZoneReadBackendInterface $backend
    ) {
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

        return ['zones' => $this->zoneCount(), 'records' => $this->recordCount()] + $counts;
    }

    /**
     * Counts through the backend for freshness (the local zones table lags behind the
     * API until sync runs); on an outage the local count is shown instead of 0.
     */
    private function zoneCount(): ?int
    {
        try {
            $count = $this->backend->countZones();
            if ($count > 0 || (new ApiStatusService())->getLastError() === null) {
                return $count;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard zone count failed: {error}', ['error' => $e->getMessage()]);
        }

        // On SQL this reads the same table that just failed; the null keeps the page rendering
        try {
            return $this->zones->getZoneCount();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Null when the backend keeps no global count, or when the PowerDNS tables are
     * absent or ungranted (not yet deployed, or a schema in a separate database).
     */
    private function recordCount(): ?int
    {
        try {
            return $this->backend->countRecords();
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard record count failed: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
