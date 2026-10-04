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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Presenter\ChangeRequestPresenter;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Utility\DnsIdnService;

/**
 * Lists change requests: reviewers see the zones their approve level covers,
 * everyone else sees the requests they filed.
 */
class ListChangeRequestsController extends BaseController
{
    private const STATUSES = [
        ZoneChangeRequest::STATUS_PENDING,
        ZoneChangeRequest::STATUS_APPROVED,
        ZoneChangeRequest::STATUS_REJECTED,
        ZoneChangeRequest::STATUS_CANCELLED,
        ZoneChangeRequest::STATUS_FAILED,
    ];

    public function run(): void
    {
        if (!$this->changeApproval()->enabled()) {
            $this->renderNotFound();
            return;
        }

        $this->setCurrentPage('list_change_requests');
        $this->setPageTitle(_('Change requests'));

        $userId = (int)$this->getCurrentUserId();
        $scope = $this->changeApproval()->reviewScope($this->getCurrentUserId());
        $requestLevel = $this->services()->permissionService()->getChangeRequestPermissionLevel($userId);
        if ($scope === [] && $requestLevel === 'none') {
            $this->showError(_('You do not have permission to view change requests.'));
            return;
        }

        $status = (string)$this->httpRequest->getQueryParam('status', ZoneChangeRequest::STATUS_PENDING);
        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            $status = ZoneChangeRequest::STATUS_PENDING;
        }
        $zoneIdParam = $this->httpRequest->getQueryParam('zone_id');
        $zoneId = is_numeric($zoneIdParam) && (int)$zoneIdParam > 0 ? (int)$zoneIdParam : null;

        // A user without a review scope only ever sees their own requests
        $ownOnly = $scope === [];
        $filters = [];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }
        if ($ownOnly) {
            $filters['requesterId'] = $userId;
            $filters['zoneIds'] = $zoneId === null ? null : [$zoneId];
        } else {
            $filters['zoneIds'] = $this->narrowScope($scope, $zoneId);
        }

        $repository = $this->services()->zoneChangeRequestRepository();
        $rowsPerPage = $this->resolveRowsPerPage();
        $total = $repository->count($filters);
        $offset = ($this->httpRequest->getPage() - 1) * $rowsPerPage;
        $requests = $repository->list($filters, $offset, $rowsPerPage);

        $zoneName = null;
        if ($zoneId !== null) {
            $domainName = $this->services()->domainRepository()->getDomainNameById($zoneId);
            $zoneName = $domainName !== null ? DnsIdnService::toUtf8($domainName) : null;
        }

        $this->render('list_change_requests.html', [
            'requests' => $this->flagUnreviewable(ChangeRequestPresenter::summaries($requests)),
            'total_requests' => $total,
            'status_filter' => $status,
            'statuses' => self::STATUSES,
            'zone_id_filter' => $zoneId,
            'zone_name' => $zoneName,
            'own_only' => $ownOnly,
            'session_userid' => $userId,
            ...$this->paginationVariables($total, $rowsPerPage, '/zones/requests?start={PageNumber}', [
                'status' => $status,
                'zone_id' => $zoneId,
            ]),
        ]);
    }

    /**
     * Marks open requests (pending, or failed and awaiting a retry) that nobody but
     * the requester could approve, so they do not sit unnoticed once
     * approval.allow_self_approval is off.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function flagUnreviewable(array $rows): array
    {
        $allowSelf = (bool)$this->config->get('approval', 'allow_self_approval', true);
        $userIds = null;
        $checked = [];
        foreach ($rows as &$row) {
            $row['no_other_reviewer'] = false;
            if ($allowSelf || !in_array($row['status'], [ZoneChangeRequest::STATUS_PENDING, ZoneChangeRequest::STATUS_FAILED], true)) {
                continue;
            }
            $userIds ??= array_map(
                static fn(array $user): int => (int)$user['id'],
                $this->services()->userRepository()->listActiveUsers()
            );
            $zoneId = (int)$row['zone_id'];
            $requesterId = $row['requester_id'] === null ? null : (int)$row['requester_id'];
            $checked["$zoneId:$requesterId"] ??= $this->changeApproval()->hasReviewerOtherThan($userIds, $zoneId, $requesterId);
            $row['no_other_reviewer'] = !$checked["$zoneId:$requesterId"];
        }

        return $rows;
    }

    /**
     * Intersects the review scope with a requested zone, so an owner-scoped user
     * cannot list another zone's requests by guessing its id.
     *
     * @param list<int>|null $scope
     * @return list<int>|null
     */
    private function narrowScope(?array $scope, ?int $zoneId): ?array
    {
        if ($zoneId === null) {
            return $scope;
        }
        if ($scope === null || in_array($zoneId, $scope, true)) {
            return [$zoneId];
        }

        return [];
    }
}
