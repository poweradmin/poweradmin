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
use Poweradmin\Application\Service\Zone\ChangeRequestMessages;
use Poweradmin\Domain\Model\ZoneChangeRequest;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Shows one change request with its before/after actions and takes the
 * reviewer's approve or reject decision, or the requester's cancellation.
 */
class ChangeRequestController extends BaseController
{
    public function run(): void
    {
        if (!$this->changeApproval()->enabled()) {
            $this->renderNotFound();
            return;
        }

        $this->setCurrentPage('change_request');
        $this->setPageTitle(_('Change request'));

        $id = $this->requireNumericParam('id');
        $request = $this->services()->zoneChangeRequestRepository()->find($id);
        if ($request === null) {
            $this->showError(_('There is no change request with this ID.'));
            return;
        }

        $userId = (int)$this->getCurrentUserId();
        $canReview = $this->changeApproval()->canReviewZone($this->getCurrentUserId(), $request->zoneId);
        $isRequester = $request->requesterId === $userId;
        // The edit page lists pending requests to everyone who may change the zone, so reading one follows the same rule
        if (!$canReview && !$isRequester && $this->changeApprovalModeForZone($request->zoneId) === ChangeApprovalPolicy::MODE_NONE) {
            $this->showError(_('You do not have permission to view this change request.'));
            return;
        }

        if ($this->isPost()) {
            $this->decide($request, $canReview, $isRequester);
            return;
        }
        // The snapshot is the whole zone, so requesting rights alone do not open it
        $canSeeSnapshot = $canReview || $isRequester || $this->services()->permissionService()->canViewZone($userId, $request->zoneId);
        if ($this->httpRequest->getQueryParam('snapshot') !== null) {
            if (!$canSeeSnapshot) {
                $this->showError(_('You do not have permission to view this zone.'));
                return;
            }
            $this->sendSnapshot($request);
            return;
        }

        $this->show($request, $canReview, $isRequester, $canSeeSnapshot);
    }

    /**
     * The zone file kept before an approved deletion, as a download.
     */
    private function sendSnapshot(ZoneChangeRequest $request): void
    {
        if ($request->snapshot === null) {
            $this->showError(_('This change request has no zone snapshot.'));
            return;
        }
        $filename = sprintf('%s-request-%d.zone', $request->zoneName, $request->id);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }
        echo $request->snapshot;
    }

    private function decide(ZoneChangeRequest $request, bool $canReview, bool $isRequester): void
    {
        $action = (string)$this->httpRequest->getPostParam('action', '');
        $comment = trim((string)$this->httpRequest->getPostParam('review_comment', ''));
        $comment = $comment === '' ? null : $comment;
        $userId = (int)$this->getCurrentUserId();
        $username = (string)$this->getUserContextService()->getLoggedInUsername();
        $service = $this->services()->zoneChangeRequestService();

        switch ($action) {
            case 'approve':
            case 'reject':
                if (!$canReview) {
                    $this->showError(_('You do not have permission to review this change request.'));
                    return;
                }
                $result = $action === 'approve'
                    ? $service->approve($request->id, $userId, $username, $comment)
                    : $service->reject($request->id, $userId, $username, $comment);
                break;

            case 'cancel':
                if (!$isRequester) {
                    $this->showError(_('Only the requester can cancel this request.'));
                    return;
                }
                $result = $service->cancel($request->id, $userId);
                break;

            default:
                $this->showError(_('Invalid or unexpected input given.'));
                return;
        }

        if ($result->success && $action === 'cancel') {
            $this->setMessage('list_change_requests', 'success', ChangeRequestMessages::forDecision($action));
            $this->redirect('/zones/requests');
            return;
        }

        $this->setMessage(
            'change_request',
            $result->success ? 'success' : 'error',
            $result->success ? ChangeRequestMessages::forDecision($action) : ChangeRequestMessages::forResult($result)
        );
        $this->redirect('/zones/requests/' . $request->id);
    }

    private function show(ZoneChangeRequest $request, bool $canReview, bool $isRequester, bool $canSeeSnapshot): void
    {
        $service = $this->services()->zoneChangeRequestService();
        // Staleness only matters while the request can still be applied
        $stale = $request->canBeApplied() ? $service->staleActions($request) : [];
        $zoneExists = $this->services()->domainRepository()->zoneIdExists($request->zoneId);

        $this->render('change_request.html', [
            'request' => ChangeRequestPresenter::summary($request),
            'actions' => ChangeRequestPresenter::actions($request, $stale),
            'stale_count' => count($stale),
            'base_serial' => $request->baseSerial,
            'base_serial_mismatch' => $request->canBeApplied() && $service->baseSerialMismatch($request),
            'zone_comment' => $request->zoneComment,
            'zone_exists' => $zoneExists,
            'has_snapshot' => $canSeeSnapshot && $request->snapshot !== null,
            'zone_display_name' => DnsIdnService::toDisplay($request->zoneName),
            'is_reverse_zone' => DnsHelper::isReverseZoneName($request->zoneName),
            'can_review' => $canReview && $request->isPending(),
            'can_retry' => $canReview && $request->status === ZoneChangeRequest::STATUS_FAILED && $zoneExists,
            'can_cancel' => $isRequester && $request->isPending(),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', false),
        ]);
    }
}
