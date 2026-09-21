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

use Closure;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneOwnershipRepositoryInterface;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * Answers what the change-approval feature means for one user: whether it is on,
 * how their edits to a zone are handled, which zones they review, and how many
 * requests are waiting.
 *
 * The caller supplies the user id, so nothing here reads the session and every
 * answer is reproducible in a test.
 */
class ChangeApprovalContext
{
    /**
     * The collaborators arrive as factories: with approval turned off every
     * question is answered from configuration alone, and nothing below should
     * open a repository for an answer it will not use.
     *
     * @param Closure(): PermissionService $permissions
     * @param Closure(): ZoneOwnershipRepositoryInterface $zones
     * @param Closure(): ZoneChangeRequestRepositoryInterface $requests
     */
    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly Closure $permissions,
        private readonly Closure $zones,
        private readonly Closure $requests
    ) {
    }

    private function permissions(): PermissionService
    {
        return ($this->permissions)();
    }

    public function enabled(): bool
    {
        return (bool)$this->config->get('approval', 'enabled', false);
    }

    private function requireReviewForAll(): bool
    {
        return (bool)$this->config->get('approval', 'require_review_for_all', false);
    }

    /**
     * How the user's changes to the zone are handled: written directly, filed as
     * a change request, or refused (one of ChangeApprovalPolicy::MODE_*). With
     * approval.enabled off this is today's edit check.
     */
    public function modeForZone(?int $userId, int $zoneId): string
    {
        if ($userId === null) {
            return ChangeApprovalPolicy::MODE_NONE;
        }
        $enabled = $this->enabled();

        return ChangeApprovalPolicy::mode(
            $enabled,
            $enabled && $this->requireReviewForAll(),
            $this->permissions()->getEditPermissionLevelForZone($userId, $zoneId),
            $enabled ? $this->permissions()->getChangeRequestPermissionLevelForZone($userId, $zoneId) : 'none',
            $this->permissions()->userOwnsZone($userId, $zoneId)
        );
    }

    /**
     * Whether the user may approve or reject change requests for the zone.
     */
    public function canReviewZone(?int $userId, int $zoneId): bool
    {
        if ($userId === null || !$this->enabled()) {
            return false;
        }

        return ChangeApprovalPolicy::canReview(
            $this->permissions()->getChangeApprovePermissionLevelForZone($userId, $zoneId),
            $this->permissions()->getEditPermissionLevelForZone($userId, $zoneId),
            $this->permissions()->userOwnsZone($userId, $zoneId)
        );
    }

    /**
     * The zones whose change requests the user reviews, in the shape the request
     * repository filters take: null for every zone, [] for none, otherwise the
     * owned zone ids.
     *
     * @return list<int>|null
     */
    public function reviewScope(?int $userId): ?array
    {
        if ($userId === null || !$this->enabled()) {
            return [];
        }
        // Reviewing needs the edit permission too, so the scope is the narrower of the two levels
        $approve = $this->permissions()->getChangeApprovePermissionLevel($userId);
        $edit = $this->permissions()->getEditPermissionLevel($userId);
        if ($approve === 'none' || $edit === 'none') {
            return [];
        }
        if ($approve === 'all' && $edit === 'all') {
            return null;
        }

        return ($this->zones)()->getOwnedZoneIds($userId);
    }

    /**
     * Pending change requests per zone for a zone list, empty when the feature is
     * off or the user neither files nor reviews requests.
     *
     * @param list<int> $zoneIds
     * @return array<int, int>
     */
    public function pendingByZone(?int $userId, array $zoneIds): array
    {
        if ($userId === null || $zoneIds === [] || !$this->enabled()) {
            return [];
        }
        $takesPart = $this->permissions()->getChangeRequestPermissionLevel($userId) !== 'none'
            || $this->permissions()->getChangeApprovePermissionLevel($userId) !== 'none'
            || $this->requireReviewForAll();
        if (!$takesPart) {
            return [];
        }

        // The same requests the list page shows: the reviewed zones or the user's own
        return ($this->requests)()->countPendingByZone($zoneIds, $this->reviewScope($userId), $userId);
    }

    /**
     * Pending requests awaiting the user's review, for the navigation badge.
     */
    public function pendingReviewCount(?int $userId): int
    {
        $scope = $this->reviewScope($userId);

        return $scope === [] ? 0 : ($this->requests)()->countPending($scope);
    }
}
