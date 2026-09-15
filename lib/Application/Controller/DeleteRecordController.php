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

use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
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

    private ReverseRecordCreator $reverseRecordCreator;
    private UserContextService $userContextService;
    private PermissionService $permissionService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->reverseRecordCreator = $this->createReverseRecordCreator();
        $this->userContextService = new UserContextService();
        $this->permissionService = $this->createPermissionService();
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

        $recordRepository = $this->createRecordRepository();
        $recordManager = $this->createRecordManager();
        $domainRepository = $this->createDomainRepository();

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

        if ($perm_edit === "none") {
            $this->showError(_('You do not have permission to delete records in this zone.'));
            return;
        }

        $domain_id = $recordRepository->recidToDomid($record_id);

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $record_info = $recordRepository->getRecordFromId($record_id);
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

            $deleted = $recordManager->deleteRecord($record_id);
            if ($deleted->success) {
                $this->createAuditService()->logRecordDelete(
                    $zid,
                    (string)$record_info['type'],
                    (string)$record_info['name'],
                    (string)$record_info['content'],
                    $record_info['ttl'],
                    $record_info['prio'] ?? null
                );

                // Delete corresponding PTR record if this was an A or AAAA record and deletion is requested
                $delete_ptr = $this->request->getPostParam('delete_ptr') === '1';
                if ($hasPtrRecord && $delete_ptr) {
                    $deletedPtrRecord = $this->reverseRecordCreator->deleteReverseRecord(
                        $record_info['type'],
                        $record_info['content'],
                        $record_info['name']
                    );
                }

                // Delete corresponding A/AAAA record if this was a PTR record and deletion is requested
                $delete_forward = $this->request->getPostParam('delete_forward') === '1';
                if ($hasForwardRecord && $delete_forward) {
                    $deletedForwardRecord = $this->reverseRecordCreator->deleteForwardRecord(
                        $record_info['name'],
                        $record_info['content']
                    );
                }

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

                $this->redirect('/zones/' . $zid . '/edit');
            } else {
                $this->addSystemMessage('error', (string)$deleted->message);
            }
        }

        $zone_info = $domainRepository->getZoneInfoFromId($zid);

        // Secondary and Consumer zones replicate records from a primary - records are read-only
        if (ZoneType::isReadOnly($zone_info['type'])) {
            $this->showError(_("You cannot delete records from a read-only zone."));
        }

        // Permission already validated with zone-aware check at top of method

        $this->showQuestion((string)$record_id, $zid, $domain_id);
    }

    public function showQuestion(string $record_id, $zid, int $zone_id): void
    {
        $recordRepository = $this->createRecordRepository();
        $domainRepository = $this->createDomainRepository();
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
        ]);
    }
}
