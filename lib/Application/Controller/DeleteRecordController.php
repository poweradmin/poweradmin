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
 * Script that handles record deletions from zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

class DeleteRecordController extends BaseController
{

    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;
    private ReverseRecordCreator $reverseRecordCreator;
    private UserContextService $userContextService;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $this->reverseRecordCreator = new ReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $dnsRecord
        );

        $this->userContextService = new UserContextService();
        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $this->permissionService = new PermissionService($userRepository);
    }

    public function run(): void
    {
        $record_id = $this->getSafeRequestValue('id');
        if (!$record_id || !Validator::isNumber($record_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        // Get zone ID from record first
        $zid = $dnsRecord->getZoneIdFromRecordId($record_id);
        if ($zid == null) {
            $this->showError(_('Invalid record ID.'));
            return;
        }

        // Early permission check - validate zone access before proceeding
        $userId = $this->userContextService->getLoggedInUserId();
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zid);

        // Check zone-specific edit permission (includes group permissions)
        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($this->db, $userId, $zid);

        if ($perm_edit === "none") {
            $this->showError(_('You do not have permission to delete records in this zone.'));
            return;
        }

        $domain_id = $dnsRecord->recidToDomid($record_id);

        if (isset($_GET['confirm'])) {
            $record_info = $dnsRecord->getRecordFromId($record_id);
            if ($record_info === null) {
                $this->showError(_('Record not found.'));
                return;
            }

            // Check if this is an A or AAAA record that might have a corresponding PTR record
            $hasPtrRecord = false;
            $deletedPtrRecord = false;
            if (
                ($record_info['type'] === RecordType::A || $record_info['type'] === RecordType::AAAA) &&
                $this->config->get('interface', 'add_reverse_record', false)
            ) {
                $hasPtrRecord = true;
            }

            // Check if this is a PTR record that might have a corresponding A/AAAA record
            $hasForwardRecord = false;
            $deletedForwardRecord = false;
            if (
                $record_info['type'] === RecordType::PTR &&
                $this->config->get('interface', 'add_reverse_record', false)
            ) {
                $hasForwardRecord = true;
            }

            if ($dnsRecord->deleteRecord($record_id)) {
                if (isset($record_info['prio'])) {
                    $this->logger->logInfo(sprintf(
                        'client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s priority:%s',
                        $_SERVER['REMOTE_ADDR'],
                        $_SESSION["userlogin"],
                        $record_info['type'],
                        $record_info['name'],
                        $record_info['content'],
                        $record_info['ttl'],
                        $record_info['prio']
                    ), $zid);
                } else {
                    $this->logger->logInfo(sprintf(
                        'client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                        $_SERVER['REMOTE_ADDR'],
                        $_SESSION["userlogin"],
                        $record_info['type'],
                        $record_info['name'],
                        $record_info['content'],
                        $record_info['ttl']
                    ), $zid);
                }

                DnsRecord::deleteRecordZoneTempl($this->db, $record_id);
                $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                $dnsRecord->updateSOASerial($zid);

                // Delete corresponding PTR record if this was an A or AAAA record and deletion is requested
                $delete_ptr = isset($_GET['delete_ptr']) && $_GET['delete_ptr'] === '1';
                if ($hasPtrRecord && $delete_ptr) {
                    $deletedPtrRecord = $this->reverseRecordCreator->deleteReverseRecord(
                        $record_info['type'],
                        $record_info['content'],
                        $record_info['name']
                    );
                }

                // Delete corresponding A/AAAA record if this was a PTR record and deletion is requested
                $delete_forward = isset($_GET['delete_forward']) && $_GET['delete_forward'] === '1';
                if ($hasForwardRecord && $delete_forward) {
                    $deletedForwardRecord = $this->reverseRecordCreator->deleteForwardRecord(
                        $record_info['name'],
                        $record_info['content']
                    );
                }

                if ($this->config->get('dnssec', 'enabled', false)) {
                    $zone_name = $dnsRecord->getDomainNameById($zid);
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                    $dnssecProvider->rectifyZone($zone_name);
                }

                if (!$dnsRecord->hasSimilarRecords($domain_id, $record_info['name'], $record_info['type'], $record_id)) {
                    $this->recordCommentService->deleteComment($domain_id, $record_info['name'], $record_info['type']);

                    if ($deletedPtrRecord && $deletedForwardRecord) {
                        $this->setMessage('edit', 'success', _('The record and its corresponding PTR and A/AAAA records have been deleted successfully.'));
                    } elseif ($deletedPtrRecord) {
                        $this->setMessage('edit', 'success', _('The record and its corresponding PTR record have been deleted successfully.'));
                    } elseif ($deletedForwardRecord) {
                        $this->setMessage('edit', 'success', _('The record and its corresponding A/AAAA record have been deleted successfully.'));
                    } elseif ($hasPtrRecord) {
                        $this->setMessage('edit', 'success', _('The record has been deleted successfully. No matching PTR record was found.'));
                    } elseif ($hasForwardRecord) {
                        $this->setMessage('edit', 'success', _('The record has been deleted successfully. No matching A/AAAA record was found.'));
                    } else {
                        $this->setMessage('edit', 'success', _('The record has been deleted successfully.'));
                    }
                } elseif ($this->config->get('interface', 'show_record_comments', false)) {
                    if ($deletedPtrRecord && $deletedForwardRecord) {
                        $this->setMessage('edit', 'warn', _('The record and its corresponding PTR and A/AAAA records were deleted but the comment was preserved because similar records exist.'));
                    } elseif ($deletedPtrRecord) {
                        $this->setMessage('edit', 'warn', _('The record and its corresponding PTR record were deleted but the comment was preserved because similar records exist.'));
                    } elseif ($deletedForwardRecord) {
                        $this->setMessage('edit', 'warn', _('The record and its corresponding A/AAAA record were deleted but the comment was preserved because similar records exist.'));
                    } else {
                        $this->setMessage('edit', 'warn', _('The record was deleted but the comment was preserved because similar records exist.'));
                    }
                }

                $this->redirect('/zones/' . $zid . '/edit');
            }
        }

        $perm_edit = Permission::getEditPermission($this->db);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->getZoneInfoFromId($zid);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $domain_id);
        if ($zone_info['type'] == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to edit this record."));
        }

        $this->showQuestion($record_id, $zid, $domain_id);
    }

    public function showQuestion(string $record_id, $zid, int $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->getDomainNameById($zone_id);

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_name);
        } else {
            $idn_zone_name = "";
        }

        $this->render('delete_record.html', [
            'record_id' => $record_id,
            'zone_id' => $zid,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'record_info' => $dnsRecord->getRecordFromId($record_id),
            'is_reverse_zone' => DnsHelper::isReverseZone($zone_name),
        ]);
    }
}
