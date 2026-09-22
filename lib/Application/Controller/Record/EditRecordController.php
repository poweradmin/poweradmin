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

namespace Poweradmin\Application\Controller\Record;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordEditRequest;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

/**
 * Handles the edit-record form: validates the change, saves it and syncs the matching PTR record when ticked.
 */
class EditRecordController extends BaseController
{
    private ?RecordCommentService $recordCommentService = null;
    private ?RecordTypeService $recordTypeService = null;
    private ?PermissionService $permissionService = null;

    private function recordCommentService(): RecordCommentService
    {
        if ($this->recordCommentService === null) {
            $repositoryFactory = $this->services()->repositoryFactory($this->services()->dnsBackendProvider());
            $this->recordCommentService = new RecordCommentService(
                $repositoryFactory->createRecordCommentRepository(),
                $repositoryFactory->createRecordLinkedCommentRepository()
            );
        }

        return $this->recordCommentService;
    }

    private function recordTypeService(): RecordTypeService
    {
        return $this->recordTypeService ??= new RecordTypeService($this->getConfig());
    }

    private function permissionService(): PermissionService
    {
        return $this->permissionService ??= $this->services()->permissionService();
    }

    public function run(): void
    {
        $recordRepository = $this->services()->recordRepository();
        $domainRepository = $this->services()->domainRepository();
        // Validate record ID parameter
        $record_id = $this->getSafeRequestValue('id');
        if (!$record_id || (!Validator::isNumber($record_id) && !RecordIdentifier::isEncoded($record_id))) {
            $this->showError(_('Invalid record ID.'));
            return;
        }
        if (Validator::isNumber($record_id)) {
            $record_id = (int)$record_id;
        }

        // Get zone ID from record first
        $zid = $recordRepository->getZoneIdFromRecordId($record_id);
        if ($zid === 0) {
            $this->showError(_('Invalid record ID.'));
            return;
        }

        // Early permission check - validate access before further operations
        $userId = $this->getUserContextService()->getLoggedInUserId();
        $user_is_zone_owner = $this->isZoneOwner($zid);

        // Check view permission first (zone-aware for group support)
        $canView = $this->permissionService()->canPerformZoneAction($userId, $zid, Permission::PERM_ZONE_CONTENT_VIEW_OWN);
        $canViewOthers = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);

        if (!$canViewOthers && !$canView) {
            $this->showError(_("You do not have permission to view this record."));
            return;
        }

        // Get zone type after permission validation
        $zone_type = $domainRepository->getDomainType($zid);

        // Secondary and Consumer zones replicate records from a primary - records are read-only
        if (ZoneType::isReadOnly($zone_type)) {
            $this->showError(_("You cannot edit records in a read-only zone."));
            return;
        }

        $perm_edit = $this->permissionService()->getEditPermissionLevelForZone($userId, $zid);
        $edit_mode = $this->changeApprovalModeForZone($zid);
        // A requester gets the editor's form; the save files a request instead
        if ($edit_mode === ChangeApprovalPolicy::MODE_REQUEST && !ZoneAccessPolicy::canEditZone($perm_edit, $user_is_zone_owner)) {
            $perm_edit = $this->permissionService()->getChangeRequestPermissionLevelForZone($userId, $zid);
        }
        if ($perm_edit === 'none') {
            $this->showError(_("You do not have permission to edit this record."));
            return;
        }

        $validationFailed = false;
        if ($this->isPost()) {
            $validationFailed = $edit_mode === ChangeApprovalPolicy::MODE_REQUEST
                ? !$this->requestRecordEdit($zid)
                : !$this->saveRecord($zid);
        }

        $this->showRecordEditForm($record_id, $zone_type, $zid, $perm_edit, $user_is_zone_owner, $validationFailed, $edit_mode);
    }

    public function showRecordEditForm($record_id, string $zone_type, $zid, string $perm_edit, $user_is_zone_owner, bool $validationFailed = false, string $edit_mode = ChangeApprovalPolicy::MODE_DIRECT): void
    {
        $recordRepository = $this->services()->recordRepository();
        $domainRepository = $this->services()->domainRepository();
        $zone_name = $domainRepository->getDomainNameById($zid);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }

        $recordTypes = $this->recordTypeService()->getAllTypes($this->getRecordTypeCapabilities());
        $record = $recordRepository->getRecordFromId($record_id);
        if ($record === null) {
            $this->showError(_('Record not found.'));
            return;
        }

        $display_hostname_only = $this->services()->userPreferenceService()->getDisplayHostnameOnly(
            $this->getUserContextService()->getLoggedInUserId()
        );
        if ($display_hostname_only) {
            $record['record_name'] = DnsHelper::stripZoneSuffix($record['name'], $zone_name);
        }

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);
        // Use record ID to find per-record comment, with fallback to RRset-based lookup for legacy comments
        $recordComment = $this->recordCommentService()->findCommentByRecordId($record_id);
        if ($recordComment === null) {
            // Fallback to legacy RRset-based comment lookup
            $recordComment = $this->recordCommentService()->findComment($zid, $record['name'], $record['type']);
        }

        $zone_is_read_only = ZoneType::isReadOnly($zone_type);
        $user_can_edit_zone = ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner);

        $this->render('edit_record.html', [
            'record_id' => $record_id,
            'record' => $record,
            'recordTypes' => $recordTypes,
            'deprecated_types' => RecordType::DEPRECATED_TYPES,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'zone_type' => $zone_type,
            'zid' => $zid,
            'perm_edit' => $perm_edit,
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_is_editable' => $user_can_edit_zone && !$zone_is_read_only,
            'edit_mode' => $edit_mode,
            'require_change_comment' => (bool)$this->config->get('logging', 'require_change_comment', false),
            'iface_record_comments' => $iface_record_comments,
            'comment' => $recordComment ? $recordComment->getComment() : '',
            'is_reverse_zone' => DnsHelper::isReverseZoneName($zone_name),
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', false),
            'display_hostname_only' => $display_hostname_only,
        ]);
    }

    /**
     * Files the posted row as a change request instead of writing it. Returns
     * true when it was filed (the request then redirects to the zone).
     */
    private function requestRecordEdit(int $zid): bool
    {
        $zone_name = $this->services()->domainRepository()->getDomainNameById($zid);
        if ($zone_name === null) {
            $this->setMessage('edit', 'error', _('Zone not found.'));
            return false;
        }

        // One zone editor row, so the same diff and validation serve both forms
        $type = (string)$this->httpRequest->getPostParam('type', '');
        $row = new ZoneEditRow(
            (string)$this->httpRequest->getPostParam('rid', ''),
            DnsIdnService::toPunycode((string)$this->httpRequest->getPostParam('name', '')),
            $type,
            DnsIdnService::convertContentToPunycode($type, (string)$this->httpRequest->getPostParam('content', '')),
            (int)$this->httpRequest->getPostParam('ttl', ''),
            (int)$this->httpRequest->getPostParam('prio', '0'),
            $this->httpRequest->getPostParam('disabled') === 'on',
            (string)$this->httpRequest->getPostParam('comment', '')
        );

        $submission = new ZoneEditSubmission(
            $zid,
            $zone_name,
            (int)$this->getCurrentUserId(),
            (string)$this->getUserContextService()->getLoggedInUsername(),
            [$row],
            false,
            null,
            false,
            null
        );
        $comment = trim((string)$this->httpRequest->getPostParam('request_comment', ''));
        $result = $this->services()->zoneChangeRequestService()->fileRecordEdits($submission, $comment === '' ? null : $comment);

        if (!$result->success) {
            foreach ($result->errors as $error) {
                $this->addSystemMessage('error', $error);
            }
            $this->setMessage('edit_record', $result->code === ZoneChangeRequestResult::CODE_NO_CHANGES ? 'info' : 'error', ChangeRequestMessages::forResult($result));
            return false;
        }

        $this->setMessage('edit', 'success', ChangeRequestMessages::submitted());
        $this->redirect('/zones/' . $zid . '/edit');

        return true;
    }

    public function saveRecord($zid): bool
    {
        $rid = $this->httpRequest->getPostParam('rid');
        $old_record_info = $this->services()->recordRepository()->getRecordFromId($rid);
        if ($old_record_info === null) {
            $this->setMessage('edit', 'error', _('Record not found.'));
            return false;
        }

        $zone_name = $this->services()->domainRepository()->getDomainNameById($zid);
        if ($zone_name === null) {
            $this->setMessage('edit', 'error', _('Zone not found.'));
            return false;
        }

        $type = (string)$this->httpRequest->getPostParam('type', '');
        $content = (string)$this->httpRequest->getPostParam('content', '');
        // Let users type a serial placeholder like [SERIAL] in the SOA form;
        // non-placeholder content passes through unchanged and the service
        // bumps the resolved value after the write (#1360).
        if ($type === RecordType::SOA) {
            $content = SOARecordManager::expandSerialPlaceholder(
                DnsIdnService::convertContentToPunycode($type, $content),
                $old_record_info['content'] ?? ''
            );
        }

        $showRecordComments = $this->config->get('interface', 'show_record_comments', false);
        $edited = $this->services()->recordEditService()->edit(new RecordEditRequest(
            (int)$zid,
            $zone_name,
            RecordIdHelper::normalizeId((string)$rid),
            $old_record_info,
            (string)$this->httpRequest->getPostParam('name', ''),
            $type,
            $content,
            (int)$this->httpRequest->getPostParam('ttl', 0),
            (int)$this->httpRequest->getPostParam('prio', 0),
            $this->httpRequest->getPostParam('disabled') == 'on' ? 1 : 0,
            $showRecordComments ? (string)$this->httpRequest->getPostParam('comment', '') : null,
            $this->httpRequest->getPostParam('update_ptr') !== null,
            true,
            $this->getUserContextService()->getLoggedInUsername() ?? ''
        ));
        if (!$edited->isOk()) {
            $this->addSystemMessage('error', (string)$edited->write->message);
            return false;
        }

        if ($edited->ptrFailed()) {
            $this->setMessage('edit', 'warning', _('The record was updated, but the PTR record could not be updated: ') . ($edited->ptrMessage ?? ''));
        }

        $this->setMessage('edit', 'success', _('The record has been updated successfully.'));
        $this->redirect('/zones/' . $zid . '/edit');

        return true;
    }
}
