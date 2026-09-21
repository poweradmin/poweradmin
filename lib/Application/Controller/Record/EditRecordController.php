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
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\RecordIdHelper;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\ValueObject\RecordIdentifier;

/**
 * Handles the edit-record form: validates the change, saves it and syncs the matching PTR record when ticked.
 */
class EditRecordController extends BaseController
{

    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;
    private RecordTypeService $recordTypeService;
    private UserContextService $userContextService;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $backendProvider = $this->services()->dnsBackendProvider();
        $repositoryFactory = $this->services()->repositoryFactory($backendProvider);
        $this->recordCommentService = new RecordCommentService(
            $repositoryFactory->createRecordCommentRepository(),
            $repositoryFactory->createRecordLinkedCommentRepository()
        );
        $this->commentSyncService = new RecordCommentSyncService($this->recordCommentService, $repositoryFactory->createRecordRepository(), $backendProvider);
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->userContextService = new UserContextService();
        $this->permissionService = $this->services()->permissionService();
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
        $userId = $this->userContextService->getLoggedInUserId();
        $user_is_zone_owner = $this->isZoneOwner($zid);

        // Check view permission first (zone-aware for group support)
        $canView = $this->permissionService->canPerformZoneAction($userId, $zid, Permission::PERM_ZONE_CONTENT_VIEW_OWN);
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

        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $zid);
        $edit_mode = $this->changeApprovalModeForZone($zid);
        // A requester gets the editor's form; the save files a request instead
        if ($edit_mode === ChangeApprovalPolicy::MODE_REQUEST && !ZoneAccessPolicy::canEditZone($perm_edit, $user_is_zone_owner)) {
            $perm_edit = $this->permissionService->getChangeRequestPermissionLevelForZone($userId, $zid);
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

        $recordTypes = $this->recordTypeService->getAllTypes($this->getRecordTypeCapabilities());
        $record = $recordRepository->getRecordFromId($record_id);
        if ($record === null) {
            $this->showError(_('Record not found.'));
            return;
        }

        $display_hostname_only = $this->services()->userPreferenceService()->getDisplayHostnameOnly(
            $this->userContextService->getLoggedInUserId()
        );
        if ($display_hostname_only) {
            $record['record_name'] = DnsHelper::stripZoneSuffix($record['name'], $zone_name);
        }

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);
        // Use record ID to find per-record comment, with fallback to RRset-based lookup for legacy comments
        $recordComment = $this->recordCommentService->findCommentByRecordId($record_id);
        if ($recordComment === null) {
            // Fallback to legacy RRset-based comment lookup
            $recordComment = $this->recordCommentService->findComment($zid, $record['name'], $record['type']);
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
     * Whether the stored row differs from its pre-edit copy. In API mode the record
     * ID changes with name, type, content or prio, so a missing row is itself a change.
     */
    private function savedRecordDiffers(RecordLookupInterface $recordRepository, int|string $rid, array $old_record_info): bool
    {
        $saved_record_info = $recordRepository->getRecordFromId($rid);

        return $saved_record_info === null || RecordManager::recordFieldsDiffer($old_record_info, $saved_record_info);
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

        // The zone editor's row shape, so the same diff and validation serve both forms
        $row = [
            'rid' => (string)$this->httpRequest->getPostParam('rid', ''),
            'zid' => (string)$zid,
            'name' => DnsIdnService::toPunycode((string)$this->httpRequest->getPostParam('name', '')),
            'type' => (string)$this->httpRequest->getPostParam('type', ''),
            'content' => (string)$this->httpRequest->getPostParam('content', ''),
            'ttl' => (string)$this->httpRequest->getPostParam('ttl', ''),
            'prio' => (string)$this->httpRequest->getPostParam('prio', '0'),
            'comment' => (string)$this->httpRequest->getPostParam('comment', ''),
            '_complete' => '1',
        ];
        $row['content'] = DnsIdnService::convertContentToPunycode($row['type'], $row['content']);
        if ($this->httpRequest->getPostParam('disabled') === 'on') {
            $row['disabled'] = 'on';
        }

        $submission = new ZoneEditSubmission(
            $zid,
            $zone_name,
            (int)$this->getCurrentUserId(),
            (string)$this->userContextService->getLoggedInUsername(),
            [$row],
            true,
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
        $recordRepository = $this->services()->recordRepository();
        $domainRepository = $this->services()->domainRepository();
        $rid = $this->httpRequest->getPostParam('rid');
        $old_record_info = $recordRepository->getRecordFromId($rid);
        if ($old_record_info === null) {
            $this->setMessage('edit', 'error', _('Record not found.'));
            return false;
        }

        $postData = $this->httpRequest->getPostParams();

        // Convert IDN record name and content to punycode
        if (isset($postData['name'])) {
            $postData['name'] = DnsIdnService::toPunycode($postData['name']);
        }
        if (isset($postData['content']) && isset($postData['type'])) {
            $postData['content'] = DnsIdnService::convertContentToPunycode($postData['type'], $postData['content']);
        }

        // Let users type a serial placeholder like [SERIAL] in the SOA form;
        // non-placeholder content passes through unchanged and updateSOASerial()
        // below bumps the resolved value.
        if (
            ($postData['type'] ?? '') === RecordType::SOA
            && isset($postData['content'])
        ) {
            $postData['content'] = SOARecordManager::expandSerialPlaceholder(
                $postData['content'],
                $old_record_info['content'] ?? ''
            );
        }

        // Normalize record name to full FQDN (always, regardless of display setting)
        // This converts @ to zone apex and ensures proper zone suffix
        if (isset($postData['name'])) {
            $zone_name = $domainRepository->getDomainNameById($zid);
            if ($zone_name === null) {
                $this->setMessage('edit', 'error', _('Zone not found.'));
                return false;
            }
            $postData['name'] = DnsHelper::restoreZoneSuffix($postData['name'], $zone_name);
        }
        if (isset($postData['disabled']) && $postData['disabled'] == "on") {
            $postData['disabled'] = 1;
        } else {
            $postData['disabled'] = 0;
        }

        $showRecordComments = $this->config->get('interface', 'show_record_comments', false);
        $result = $this->services()->recordManager()->editRecord($postData, true, $showRecordComments ? [
            'content' => (string)$this->httpRequest->getPostParam('comment', ''),
            'account' => $this->userContextService->getLoggedInUsername() ?? '',
        ] : null);
        if (!$result->success) {
            $this->addSystemMessage('error', (string)$result->message);
            return false;
        }

        // The manager bumps the serial for every other type; an edited SOA carries
        // the placeholder-expanded serial, which is bumped here (#1360) unless the
        // install opted out of bumping and the manager skipped an unchanged save
        if (
            ($postData['type'] ?? '') === RecordType::SOA
            && ($this->config->get('dns', 'bump_serial_on_unchanged_save', true) || $this->savedRecordDiffers($recordRepository, $rid, $old_record_info))
        ) {
            $this->services()->soaRecordManager()->updateSOASerial($zid);
        }

        $this->syncReverseRecord($zid, $old_record_info, $postData);

        $new_record_info = $recordRepository->getRecordFromId($rid);
        if ($new_record_info === null) {
            // In API mode the record ID changes when name/type/content/prio change,
            // so the old ID won't match. Use POST data for audit logging instead.
            $new_record_info = [
                'type' => $postData['type'],
                'name' => $postData['name'],
                'content' => $postData['content'],
                'ttl' => $postData['ttl'],
                'prio' => $postData['prio'] ?? 0,
            ];
        }

        $this->services()->auditService()->logRecordEdit($zid, $old_record_info, $new_record_info);

        $nameOrTypeChanged = ($old_record_info['name'] !== $new_record_info['name'] ||
                              $old_record_info['type'] !== $new_record_info['type']);

        if ($showRecordComments) {
            // Comments visible - use per-record comment (linked by record ID via record_comment_links table)
            $commentValue = $this->httpRequest->getPostParam('comment', '');

            $this->recordCommentService->updateCommentForRecord(
                $zid,
                $new_record_info['name'],
                $new_record_info['type'],
                $commentValue,
                RecordIdHelper::normalizeId($rid),
                $this->userContextService->getLoggedInUsername()
            );

            if ($this->config->get('misc', 'record_comments_sync')) {
                $this->commentSyncService->updateRelatedRecordComments(
                    $domainRepository,
                    $new_record_info,
                    $commentValue,
                    $this->userContextService->getLoggedInUsername()
                );
            }
        } elseif ($nameOrTypeChanged) {
            // Comments hidden but record name/type changed - migrate existing comment
            $existingComment = $this->recordCommentService->findComment(
                $zid,
                $old_record_info['name'],
                $old_record_info['type']
            );

            if ($existingComment !== null) {
                $this->recordCommentService->updateComment(
                    $zid,
                    $old_record_info['name'],
                    $old_record_info['type'],
                    $new_record_info['name'],
                    $new_record_info['type'],
                    $existingComment->getComment(),
                    $this->userContextService->getLoggedInUsername()
                );
            }
        }


        $this->setMessage('edit', 'success', _('The record has been updated successfully.'));
        $this->redirect('/zones/' . $zid . '/edit');

        return true;
    }

    /**
     * Move the automatically created PTR record along when an A/AAAA record changes.
     * Without this the PTR keeps pointing at the old address and the new one has none.
     */
    private function syncReverseRecord(int $zid, array $oldRecord, array $postData): void
    {
        if ($this->httpRequest->getPostParam('update_ptr') === null) {
            return;
        }

        $oldType = (string)($oldRecord['type'] ?? '');
        $newType = (string)($postData['type'] ?? '');
        if (!in_array($oldType, ['A', 'AAAA'], true) && !in_array($newType, ['A', 'AAAA'], true)) {
            return;
        }

        $result = $this->services()->reverseRecordCreator()->updateReverseRecord(
            $oldType,
            (string)($oldRecord['content'] ?? ''),
            (string)($oldRecord['name'] ?? ''),
            $newType,
            (string)($postData['content'] ?? ''),
            (string)($postData['name'] ?? ''),
            $zid,
            (int)($postData['ttl'] ?? 0),
            (int)($postData['prio'] ?? 0)
        );

        if (isset($result['success']) && !$result['success']) {
            $this->setMessage('edit', 'warning', _('The record was updated, but the PTR record could not be updated: ') . ($result['message'] ?? ''));
        }
    }
}
