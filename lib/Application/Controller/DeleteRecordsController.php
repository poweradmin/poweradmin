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
 * Script that handles bulk record deletions from zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;

class DeleteRecordsController extends BaseController
{
    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;
    private ReverseRecordCreator $reverseRecordCreator;
    private UserContextService $userContextService;

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
            $dnsRecord,
            $this->recordCommentService
        );
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $record_ids = $_POST['record_id'] ?? null;
        if (!$record_ids) {
            $this->setMessage('search', 'error', _('No records selected for deletion.'));
            $this->redirect('/search');
            return;
        }

        if (isset($_POST['confirm'])) {
            $this->deleteRecords($record_ids);
        }

        $this->showRecords($record_ids);
    }

    public function deleteRecords($record_ids): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $deleted_count = 0;
        $affected_zones = [];

        foreach ($record_ids as $record_id) {
            $record_info = $dnsRecord->getRecordFromId($record_id);
            if ($record_info === null) {
                continue;
            }

            $zid = $dnsRecord->getZoneIdFromRecordId($record_id);

            if ($zid !== null) {
                $domain_id = $dnsRecord->recidToDomid($record_id);

                // Check if this is an A or AAAA record that might have a corresponding PTR record
                $hasPtrRecord = false;
                if (
                    ($record_info['type'] === RecordType::A || $record_info['type'] === RecordType::AAAA) &&
                    $this->config->get('interface', 'add_reverse_record', false)
                ) {
                    $hasPtrRecord = true;
                }

                if ($dnsRecord->deleteRecord($record_id)) {
                    $deleted_count++;
                    $affected_zones[$zid] = true;

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

                    // Delete corresponding PTR record if this was an A or AAAA record and deletion is requested
                    $delete_ptr = isset($_POST['delete_ptr']) && $_POST['delete_ptr'] === '1';
                    if ($hasPtrRecord && $delete_ptr) {
                        $this->reverseRecordCreator->deleteReverseRecord(
                            $record_info['type'],
                            $record_info['content'],
                            $record_info['name']
                        );
                    }

                    // Delete comment for this specific record (per-record comment by record_id)
                    $this->recordCommentService->deleteCommentByRecordId($record_id);

                    // For backward compatibility, also clean up RRset-based comments if no similar records remain
                    if (!$dnsRecord->hasSimilarRecords($domain_id, $record_info['name'], $record_info['type'], $record_id)) {
                        $this->recordCommentService->deleteComment($domain_id, $record_info['name'], $record_info['type']);
                    }
                }
            }
        }

        // Update SOA serials and rectify zones
        foreach (array_keys($affected_zones) as $zone_id) {
            $dnsRecord->updateSOASerial($zone_id);

            if ($this->config->get('dnssec', 'enabled', false)) {
                $zone_name = $dnsRecord->getDomainNameById($zone_id);
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                $dnssecProvider->rectifyZone($zone_name);
            }
        }

        $redirectPage = 'search';
        $messageKey = 'search';
        $redirectParams = [];

        // Check if this was submitted from a zone edit page
        if (isset($_POST['zone_id']) && is_numeric($_POST['zone_id'])) {
            $zone_id = (int) $_POST['zone_id'];
            // Validate zone exists
            if ($dnsRecord->getZoneInfoFromId($zone_id) !== null) {
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

    public function showRecords($record_ids): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $records = [];

        foreach ($record_ids as $record_id) {
            $record_info = $dnsRecord->getRecordFromId($record_id);
            if ($record_info) {
                $zid = $dnsRecord->getZoneIdFromRecordId($record_id);
                $domain_id = $dnsRecord->recidToDomid($record_id);

                $zone_info = $dnsRecord->getZoneInfoFromId($zid);
                $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $domain_id);

                // Check zone-specific edit permission (includes group permissions)
                $userId = $this->userContextService->getLoggedInUserId();
                $canEdit = UserManager::canUserPerformZoneAction($this->db, $userId, $domain_id, 'zone_content_edit_own');
                $canEditAsClient = UserManager::canUserPerformZoneAction($this->db, $userId, $domain_id, 'zone_content_edit_own_as_client');
                $canEditOthers = UserManager::verifyPermission($this->db, 'zone_content_edit_others');

                if ($zone_info['type'] == "SLAVE" || (!$canEditOthers && !$canEdit && !$canEditAsClient)) {
                    continue;
                }

                $record_info['zone_name'] = $dnsRecord->getDomainNameById($domain_id);

                // Shorten IPv6 addresses in AAAA record content for display
                if ($record_info['type'] === 'AAAA' && isset($record_info['content'])) {
                    $record_info['content'] = IpHelper::shortenIPv6Address($record_info['content']);
                }

                // Shorten IPv6 reverse zone names (PTR records) for display
                if (isset($record_info['name']) && str_ends_with($record_info['name'], '.ip6.arpa')) {
                    $shortened = IpHelper::shortenIPv6ReverseZone($record_info['name']);
                    if ($shortened !== null) {
                        $record_info['display_name'] = $shortened;
                    }
                }

                $records[] = $record_info;
            }
        }

        if (empty($records)) {
            $redirectPage = 'search';
            $messageKey = 'search';
            $redirectParams = [];

            // Check if this was submitted from a zone edit page
            if (isset($_POST['zone_id']) && is_numeric($_POST['zone_id'])) {
                $zone_id = (int) $_POST['zone_id'];
                // Validate zone exists
                if ($dnsRecord->getZoneInfoFromId($zone_id) !== null) {
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

        $this->render('delete_records.html', [
            'records' => $records,
            'total_records' => count($records),
            'zone_id' => $_POST['zone_id'] ?? null
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
