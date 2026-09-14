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

/**
 * Script that handles bulk record deletions from zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;

class DeleteRecordsController extends BaseController
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
        if ($this->isPost()) {
            $this->validateCsrfToken();
        }

        $raw_ids = $this->request->getPostParam('record_id');
        if (!is_array($raw_ids) || empty($raw_ids)) {
            $this->setMessage('search', 'error', _('No records selected for deletion.'));
            $this->redirect('/search');
            return;
        }

        $record_ids = array_values(array_filter($raw_ids, fn($id) => is_int($id) || is_string($id)));

        if ($this->request->getPostParam('confirm') !== null) {
            $comment = (string)($this->request->getPostParam('change_comment') ?? '');
            if (trim($comment) === '' && RecordChangeLogger::changeCommentRequired()) {
                $this->setMessage('delete_records', 'error', _('Describe why you are making this change.'));
            } else {
                $this->deleteRecords($record_ids);
            }
        }

        $this->showRecords($record_ids);
    }

    /**
     * @param array<int|string> $record_ids
     */
    public function deleteRecords(array $record_ids): void
    {
        $recordRepository = $this->createRecordRepository();
        $recordManager = $this->createRecordManager();
        $domainRepository = $this->createDomainRepository();
        $audit = $this->createAuditService();
        $deleted_count = 0;
        $affected_zones = [];

        // One submission, one changeset, so a multi-record delete reads as a single
        // action in the change log rather than N unrelated deletions. The selection can
        // span zones, so the changeset carries no zone of its own.
        RecordChangeLogger::beginChangeset(null, (string)($this->request->getPostParam('change_comment') ?? ''));

        try {
            foreach ($record_ids as $record_id) {
                $record_info = $recordRepository->getRecordFromId($record_id);
                if ($record_info === null) {
                    continue;
                }

                $zid = $recordRepository->getZoneIdFromRecordId($record_id);

                // 0 means the record no longer exists
                if ($zid > 0) {
                    // Check if this is an A or AAAA record that might have a corresponding PTR record
                    $hasPtrRecord = false;
                    if (
                        ($record_info['type'] === RecordType::A || $record_info['type'] === RecordType::AAAA) &&
                        $this->config->get('interface', 'add_reverse_record', false)
                    ) {
                        $hasPtrRecord = true;
                    }

                    $deleted = $recordManager->deleteRecord($record_id, false);
                    if ($deleted->success) {
                        $deleted_count++;
                        $affected_zones[$zid] = true;

                        $audit->logRecordDelete(
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
                            $this->reverseRecordCreator->deleteReverseRecord(
                                $record_info['type'],
                                $record_info['content'],
                                $record_info['name']
                            );
                        }
                    } else {
                        $this->addSystemMessage('error', (string)$deleted->message);
                    }
                }
            }
        } finally {
            RecordChangeLogger::endChangeset();
        }

        foreach (array_keys($affected_zones) as $zone_id) {
            $recordManager->finalizeZone($zone_id);
        }

        $redirectPage = 'search';
        $messageKey = 'search';
        $redirectParams = [];

        // Check if this was submitted from a zone edit page
        $post_zone_id = $this->request->getPostParam('zone_id');
        if (is_numeric($post_zone_id)) {
            $zone_id = (int) $post_zone_id;
            // Validate zone exists
            if ($domainRepository->getZoneInfoFromId($zone_id) !== []) {
                $redirectPage = 'edit';
                $messageKey = 'edit';
                $redirectParams['id'] = $zone_id;
            }
        }

        if ($deleted_count > 0) {
            if ($deleted_count == 1) {
                $this->setMessage($messageKey, 'success', _('The record has been deleted successfully. Any corresponding PTR records were also removed.'));
            } else {
                $this->setMessage($messageKey, 'success', sprintf(_('%d records have been deleted successfully. Any corresponding PTR records were also removed.'), $deleted_count));
            }
        } else {
            $this->setMessage($messageKey, 'error', _('No records could be deleted. Please check permissions.'));
        }

        $route = $this->buildModernRoute($redirectPage, $redirectParams);
        $this->redirect($route);
    }

    /**
     * @param array<int|string> $record_ids
     */
    public function showRecords(array $record_ids): void
    {
        $recordRepository = $this->createRecordRepository();
        $domainRepository = $this->createDomainRepository();
        $records = [];

        foreach ($record_ids as $record_id) {
            $record_info = $recordRepository->getRecordFromId($record_id);
            if ($record_info) {
                $zid = $recordRepository->getZoneIdFromRecordId($record_id);
                $domain_id = $recordRepository->recidToDomid($record_id);

                $zone_info = $domainRepository->getZoneInfoFromId($zid);

                $userId = $this->userContextService->getLoggedInUserId();
                $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $domain_id);

                if (ZoneType::isReadOnly($zone_info['type']) || $perm_edit === 'none') {
                    continue;
                }

                $record_info['zone_name'] = $domainRepository->getDomainNameById($domain_id);

                // Shorten IPv6 addresses in AAAA record content for display
                if ($record_info['type'] === 'AAAA' && isset($record_info['content'])) {
                    $record_info['content'] = IpHelper::shortenIPv6Address($record_info['content']);
                }

                // Shorten IPv6 reverse zone names (PTR records) for display
                if (isset($record_info['name'])) {
                    $record_info['display_name'] = IpHelper::displayZoneName($record_info['name']);
                }

                $records[] = $record_info;
            }
        }

        if (empty($records)) {
            $redirectPage = 'search';
            $messageKey = 'search';
            $redirectParams = [];

            // Check if this was submitted from a zone edit page
            $post_zone_id = $this->request->getPostParam('zone_id');
            if (is_numeric($post_zone_id)) {
                $zone_id = (int) $post_zone_id;
                // Validate zone exists
                if ($domainRepository->getZoneInfoFromId($zone_id) !== []) {
                    $redirectPage = 'edit';
                    $messageKey = 'edit';
                    $redirectParams['id'] = $zone_id;
                }
            }

            $this->setMessage($messageKey, 'error', _('No valid records selected for deletion or you lack permission to delete them.'));
            $route = $this->buildModernRoute($redirectPage, $redirectParams);
            $this->redirect($route);
            return;
        }

        foreach ($records as &$record) {
            $record['display_name'] ??= $record['name'] ?? '';
        }
        unset($record);

        $has_ip_records = (bool)array_filter($records, fn($r) => in_array($r['type'] ?? '', ['A', 'AAAA'], true));

        $this->render('delete_records.html', [
            'records' => $records,
            'total_records' => count($records),
            'has_ip_records' => $has_ip_records,
            'zone_id' => $this->request->getPostParam('zone_id'),
            'change_comment' => (string)($this->request->getPostParam('change_comment') ?? ''),
            'require_change_comment' => RecordChangeLogger::changeCommentRequired(),
        ]);
    }

    private function buildModernRoute(string $page, array $params = []): string
    {
        switch ($page) {
            case 'search':
                return '/search';
            case 'edit':
                return '/zones/' . ($params['id'] ?? '') . '/edit';
            default:
                return '/';
        }
    }
}
