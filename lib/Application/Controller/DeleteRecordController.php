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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Dns\RecordDeletionOutcome;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Handles the delete-record confirmation page and removes the record, plus its PTR counterpart when ticked.
 */
class DeleteRecordController extends BaseController
{

    private UserContextService $userContextService;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
        $this->permissionService = $this->services()->permissionService();
    }

    public function run(): void
    {
        $record_id = $this->getSafeRequestValue('id');
        if (!$record_id || (!Validator::isNumber($record_id) && !RecordIdentifier::isEncoded($record_id))) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }
        if (Validator::isNumber($record_id)) {
            $record_id = (int)$record_id;
        }

        $recordRepository = $this->services()->recordRepository();
        $domainRepository = $this->services()->domainRepository();

        // Get zone ID from record first
        $zid = $recordRepository->getZoneIdFromRecordId($record_id);
        if ($zid == null) {
            $this->showError(_('Invalid record ID.'));
            return;
        }

        // Early permission check - validate zone access before proceeding
        $userId = $this->userContextService->getLoggedInUserId();
        $user_is_zone_owner = $this->isZoneOwner($zid);

        // Check zone-specific edit permission (includes group permissions)
        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $zid);
        $edit_mode = $this->changeApprovalModeForZone($zid);

        if ($perm_edit === "none" && $edit_mode !== ChangeApprovalPolicy::MODE_REQUEST) {
            $this->showError(_('You do not have permission to delete records in this zone.'));
            return;
        }

        $domain_id = $recordRepository->recidToDomid($record_id);

        if ($this->isPost() && $edit_mode === ChangeApprovalPolicy::MODE_REQUEST) {
            $this->requestRecordDelete($zid, $record_id);
        } elseif ($this->isPost()) {
            $outcome = $this->services()->recordDeletionService()->deleteWithReverse(
                $zid,
                $record_id,
                $this->httpRequest->getPostParam('delete_ptr') === '1',
                $this->httpRequest->getPostParam('delete_forward') === '1'
            );
            if ($outcome->notFound) {
                $this->showError((string)$outcome->message);
                return;
            }
            if ($outcome->recordDeleted) {
                $this->setMessage('edit', 'success', self::successMessage($outcome));
                $this->redirect('/zones/' . $zid . '/edit');
            } else {
                $this->addSystemMessage('error', (string)$outcome->message);
            }
        }

        $zone_info = $domainRepository->getZoneInfoFromId($zid, $this->getViewPermissionLevel());

        // Secondary and Consumer zones replicate records from a primary - records are read-only
        if (ZoneType::isReadOnly($zone_info['type'])) {
            $this->showError(_("You cannot delete records from a read-only zone."));
        }

        // Permission already validated with zone-aware check at top of method

        $this->showQuestion((string)$record_id, $zid, $domain_id, $edit_mode);
    }

    private static function successMessage(RecordDeletionOutcome $outcome): string
    {
        return match (true) {
            $outcome->ptrDeleted && $outcome->forwardDeleted => _('The record and its corresponding PTR and A/AAAA records have been deleted successfully.'),
            $outcome->ptrDeleted => _('The record and its corresponding PTR record have been deleted successfully.'),
            $outcome->forwardDeleted => _('The record and its corresponding A/AAAA record have been deleted successfully.'),
            $outcome->ptrCandidate => _('The record has been deleted successfully. No matching PTR record was found.'),
            $outcome->forwardCandidate => _('The record has been deleted successfully. No matching A/AAAA record was found.'),
            default => _('The record has been deleted successfully.'),
        };
    }

    /**
     * Files the deletion as a change request; a filed request redirects to the zone.
     */
    private function requestRecordDelete(int $zid, int|string $record_id): void
    {
        $comment = trim((string)$this->httpRequest->getPostParam('request_comment', ''));
        $result = $this->services()->zoneChangeRequestService()->fileRecordDelete(
            $zid,
            $record_id,
            (int)$this->getCurrentUserId(),
            (string)$this->userContextService->getLoggedInUsername(),
            $comment === '' ? null : $comment
        );
        if (!$result->success) {
            $this->addSystemMessage('error', ChangeRequestMessages::forResult($result));
            return;
        }

        $this->setMessage('edit', 'success', ChangeRequestMessages::submitted());
        $this->redirect('/zones/' . $zid . '/edit');
    }

    public function showQuestion(string $record_id, $zid, int $zone_id, string $edit_mode = ChangeApprovalPolicy::MODE_DIRECT): void
    {
        $recordRepository = $this->services()->recordRepository();
        $domainRepository = $this->services()->domainRepository();
        $zone_name = $domainRepository->getDomainNameById($zone_id);

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        $record_info = $recordRepository->getRecordFromId($record_id);

        // Shorten IPv6 addresses in AAAA record content for display
        if ($record_info && $record_info['type'] === 'AAAA' && isset($record_info['content'])) {
            $record_info['content'] = IpHelper::shortenIPv6Address($record_info['content']);
        }

        // Shorten IPv6 reverse zone names (PTR records) for display
        if ($record_info && isset($record_info['name'])) {
            $record_info['display_name'] = IpHelper::displayZoneName($record_info['name']);
        }

        if ($record_info) {
            $record_info['display_name'] ??= $record_info['name'] ?? '';
        }

        $this->render('delete_record.html', [
            'record_id' => $record_id,
            'zone_id' => $zid,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'record_info' => $record_info,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($zone_name),
            'edit_mode' => $edit_mode,
            'require_change_comment' => (bool)$this->config->get('logging', 'require_change_comment', false),
        ]);
    }
}
