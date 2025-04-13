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
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;

class DeleteRecordsController extends BaseController
{
    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
    }

    public function run(): void
    {
        $record_ids = $_POST['record_id'] ?? null;
        if (!$record_ids) {
            $this->setMessage('search', 'error', _('No records selected for deletion.'));
            $this->redirect('index.php', ['page' => 'search']);
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
            $record_info = $dnsRecord->get_record_from_id($record_id);
            $zid = $dnsRecord->get_zone_id_from_record_id($record_id);

            if ($zid !== null) {
                $domain_id = $dnsRecord->recid_to_domid($record_id);

                if ($dnsRecord->delete_record($record_id)) {
                    $deleted_count++;
                    $affected_zones[$zid] = true;

                    if (isset($record_info['prio'])) {
                        $this->logger->log_info(sprintf(
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
                        $this->logger->log_info(sprintf(
                            'client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                            $_SERVER['REMOTE_ADDR'],
                            $_SESSION["userlogin"],
                            $record_info['type'],
                            $record_info['name'],
                            $record_info['content'],
                            $record_info['ttl']
                        ), $zid);
                    }

                    DnsRecord::delete_record_zone_templ($this->db, $record_id);

                    if (!$dnsRecord->has_similar_records($domain_id, $record_info['name'], $record_info['type'], $record_id)) {
                        $this->recordCommentService->deleteComment($domain_id, $record_info['name'], $record_info['type']);
                    }
                }
            }
        }

        // Update SOA serials and rectify zones
        foreach (array_keys($affected_zones) as $zone_id) {
            $dnsRecord->update_soa_serial($zone_id);

            if ($this->config->get('dnssec', 'enabled', false)) {
                $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                $dnssecProvider->rectifyZone($zone_name);
            }
        }

        if ($deleted_count > 0) {
            if ($deleted_count == 1) {
                $this->setMessage('search', 'success', _('The record has been deleted successfully.'));
            } else {
                $this->setMessage('search', 'success', sprintf(_('%d records have been deleted successfully.'), $deleted_count));
            }
        } else {
            $this->setMessage('search', 'error', _('No records could be deleted. Please check permissions.'));
        }

        $this->redirect('index.php', ['page' => 'search']);
    }

    public function showRecords($record_ids): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $records = [];

        foreach ($record_ids as $record_id) {
            $record_info = $dnsRecord->get_record_from_id($record_id);
            if ($record_info) {
                $zid = $dnsRecord->get_zone_id_from_record_id($record_id);
                $domain_id = $dnsRecord->recid_to_domid($record_id);

                $zone_info = $dnsRecord->get_zone_info_from_id($zid);
                $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $domain_id);

                $perm_edit = Permission::getEditPermission($this->db);
                if (
                    $zone_info['type'] == "SLAVE" || $perm_edit == "none" ||
                    (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")
                ) {
                    continue;
                }

                $record_info['zone_name'] = $dnsRecord->get_domain_name_by_id($domain_id);
                $records[] = $record_info;
            }
        }

        if (empty($records)) {
            $this->setMessage('search', 'error', _('No valid records selected for deletion or you lack permission to delete them.'));
            $this->redirect('index.php', ['page' => 'search']);
            return;
        }

        $this->render('delete_records.html', [
            'records' => $records,
            'total_records' => count($records)
        ]);
    }
}
