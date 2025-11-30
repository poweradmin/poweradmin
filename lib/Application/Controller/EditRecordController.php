<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * Script that handles requests to edit zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Domain\Repository\RecordRepository;

class EditRecordController extends BaseController
{

    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;
    private RecordTypeService $recordTypeService;
    private UserContextService $userContextService;
    private RecordRepository $recordRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
        $this->commentSyncService = new RecordCommentSyncService($this->recordCommentService, $this->recordRepository);
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        // Validate record ID parameter
        $record_id = $this->getSafeRequestValue('id');
        if (!$record_id || !Validator::isNumber($record_id)) {
            $this->showError(_('Invalid record ID.'));
            return;
        }
        $record_id = (int)$record_id;
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        // Get zone ID from record first
        $zid = $dnsRecord->getZoneIdFromRecordId($record_id);
        if ($zid == null) {
            $this->showError(_('Invalid record ID.'));
            return;
        }

        // Early permission check - validate access before further operations
        $userId = $this->userContextService->getLoggedInUserId();
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zid);

        // Check view permission first (zone-aware for group support)
        $canView = UserManager::canUserPerformZoneAction($this->db, $userId, $zid, 'zone_content_view_own');
        $canViewOthers = UserManager::verifyPermission($this->db, 'zone_content_view_others');

        if (!$canViewOthers && !$canView) {
            $this->showError(_("You do not have permission to view this record."));
            return;
        }

        // Get zone type after permission validation
        $zone_type = $dnsRecord->getDomainType($zid);

        // Check edit permission for SLAVE zones and zone-specific edit rights
        if ($zone_type == "SLAVE") {
            $this->showError(_("You cannot edit records in a SLAVE zone."));
            return;
        }

        // Check zone-specific edit permission (includes group permissions)
        $canEdit = UserManager::canUserPerformZoneAction($this->db, $userId, $zid, 'zone_content_edit_own');
        $canEditAsClient = UserManager::canUserPerformZoneAction($this->db, $userId, $zid, 'zone_content_edit_own_as_client');
        $canEditOthers = UserManager::verifyPermission($this->db, 'zone_content_edit_others');

        if (!$canEditOthers && !$canEdit && !$canEditAsClient) {
            $this->showError(_("You do not have permission to edit this record."));
            return;
        }

        // Determine permission level for UI (for backward compatibility with templates)
        $perm_edit = 'none';
        if ($canEditOthers) {
            $perm_edit = 'all';
        } elseif ($canEdit) {
            $perm_edit = 'own';
        } elseif ($canEditAsClient) {
            $perm_edit = 'own_as_client';
        }

        $validationFailed = false;
        if ($this->isPost()) {
            $this->validateCsrfToken();
            $validationFailed = !$this->saveRecord($zid);
        }

        $this->showRecordEditForm($record_id, $zone_type, $zid, $perm_edit, $user_is_zone_owner, $validationFailed);
    }

    public function showRecordEditForm($record_id, string $zone_type, $zid, string $perm_edit, $user_is_zone_owner, bool $validationFailed = false): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->getDomainNameById($zid);

        $recordTypes = $this->recordTypeService->getAllTypes();
        $record = $dnsRecord->getRecordFromId($record_id);
        if ($record === null) {
            $this->showError(_('Record not found.'));
            return;
        }

        // Use the new hostname-only display if enabled
        $display_hostname_only = $this->config->get('interface', 'display_hostname_only', false);
        if ($display_hostname_only) {
            $record['record_name'] = DnsHelper::stripZoneSuffix($record['name'], $zone_name);
        } else {
            // Legacy behavior - simple string replacement
            $record['record_name'] = trim(str_replace(htmlspecialchars($zone_name), '', htmlspecialchars($record["name"])), '.');
        }

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_name);
        } else {
            $idn_zone_name = "";
        }

        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);
        // Use record ID to find per-record comment, with fallback to RRset-based lookup for legacy comments
        $recordComment = $this->recordCommentService->findCommentByRecordId($record_id);
        if ($recordComment === null) {
            // Fallback to legacy RRset-based comment lookup
            $recordComment = $this->recordCommentService->findComment($zid, $record['name'], $record['type']);
        }

        $this->render('edit_record.html', [
            'record_id' => $record_id,
            'record' => $record,
            'recordTypes' => $recordTypes,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_type' => $zone_type,
            'zid' => $zid,
            'perm_edit' => $perm_edit,
            'user_is_zone_owner' => $user_is_zone_owner,
            'iface_record_comments' => $iface_record_comments,
            'comment' => $recordComment ? $recordComment->getComment() : '',
            'is_reverse_zone' => DnsHelper::isReverseZone($zone_name),
        ]);
    }

    public function saveRecord($zid): bool
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $old_record_info = $dnsRecord->getRecordFromId($_POST["rid"]);
        if ($old_record_info === null) {
            $this->setMessage('edit', 'error', _('Record not found.'));
            return false;
        }

        $postData = $_POST;

        // Normalize record name to full FQDN (always, regardless of display setting)
        // This converts @ to zone apex and ensures proper zone suffix
        if (isset($postData['name'])) {
            $zone_name = $dnsRecord->getDomainNameById($zid);
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

        $ret_val = $dnsRecord->editRecord($postData);
        if (!$ret_val) {
            return false;
        }

        $dnsRecord->updateSOASerial($zid);

        $new_record_info = $dnsRecord->getRecordFromId($_POST["rid"]);
        if ($new_record_info === null) {
            $this->setMessage('edit', 'error', _('Failed to retrieve updated record information.'));
            return false;
        }

        $this->logger->logInfo(
            sprintf(
                'client_ip:%s user:%s operation:edit_record'
                . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
                . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'],
                $_SESSION["userlogin"],
                $old_record_info['type'],
                $old_record_info['name'],
                $old_record_info['content'],
                $old_record_info['ttl'],
                $old_record_info['prio'],
                $new_record_info['type'],
                $new_record_info['name'],
                $new_record_info['content'],
                $new_record_info['ttl'],
                $new_record_info['prio']
            ),
            $zid
        );

        // Use per-record comment (linked by record ID)
        // Get all records in the RRset for legacy comment migration
        $rrsetRecords = $this->recordRepository->getRRSetRecords($zid, $new_record_info['name'], $new_record_info['type']);
        $rrsetRecordIds = array_map(fn($r) => (int)$r['id'], $rrsetRecords);

        $this->recordCommentService->updateCommentForRecord(
            $zid,
            $new_record_info['name'],
            $new_record_info['type'],
            $_POST['comment'] ?? '',
            (int)$_POST['rid'],
            $rrsetRecordIds
        );

        if ($this->config->get('misc', 'record_comments_sync')) {
            $this->commentSyncService->updateRelatedRecordComments(
                $dnsRecord,
                $new_record_info,
                $_POST['comment'] ?? '',
                $_SESSION['userlogin']
            );
        }

        if ($this->config->get('dnssec', 'enabled', false)) {
            $zone_name = $dnsRecord->getDomainNameById($zid);
            if ($zone_name !== null) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                $dnssecProvider->rectifyZone($zone_name);
            }
        }

        $this->setMessage('edit', 'success', _('The record has been updated successfully.'));
        $this->redirect('/zones/' . $zid . '/edit');

        return true;
    }
}
