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
 * Script that handles zones deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;

class DeleteDomainsController extends BaseController
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
        $zone_ids = $_POST['zone_id'] ?? null;
        if (!$zone_ids) {
            $referrer = $_SERVER['HTTP_REFERER'] ?? null;
            $return_page = 'list_forward_zones';

            if ($referrer && str_contains($referrer, 'list_reverse_zones')) {
                $return_page = 'list_reverse_zones';
            }

            $this->setMessage($return_page, 'error', _('No zone selected for deletion.'));
            $route = $return_page === 'list_reverse_zones' ? '/zones/reverse' : '/zones/forward';
            $this->redirect($route);
            return;
        }

        if (isset($_POST['confirm'])) {
            $this->deleteDomains($zone_ids);
        }

        $this->showDomains($zone_ids);
    }

    public function deleteDomains($zone_ids): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $deleted_zones = $dnsRecord->getZoneInfoFromIds($zone_ids);
        $delete_domains = $dnsRecord->deleteDomains($zone_ids);

        if ($delete_domains) {
            foreach ($deleted_zones as $deleted_zone) {
                $this->logger->logInfo(sprintf(
                    'client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                    $_SERVER['REMOTE_ADDR'],
                    $_SESSION["userlogin"],
                    $deleted_zone['name'],
                    $deleted_zone['type']
                ), $deleted_zone['id']);
            }

            foreach ($zone_ids as $zone_id) {
                $this->recordCommentService->deleteCommentsByDomainId($zone_id);
            }

            // Determine if we should redirect to reverse or forward zones page
            $all_reverse = true;
            foreach ($deleted_zones as $zone) {
                if (!DnsHelper::isReverseZone($zone['name'])) {
                    $all_reverse = false;
                    break;
                }
            }

            $return_page = $all_reverse ? 'list_reverse_zones' : 'list_forward_zones';

            if (count($deleted_zones) == 1) {
                $this->setMessage($return_page, 'success', _('Zone has been deleted successfully.'));
            } else {
                $this->setMessage($return_page, 'success', _('Zones have been deleted successfully.'));
            }
            $route = $return_page === 'list_reverse_zones' ? '/zones/reverse' : '/zones/forward';
            $this->redirect($route);
        }
    }

    public function showDomains($zone_ids): void
    {
        $zones = $this->getZoneInfo($zone_ids);
        // Check if we're dealing with only reverse zones, only forward zones, or mixed
        $all_reverse = true;
        $all_forward = true;

        foreach ($zones as $zone) {
            $is_reverse = DnsHelper::isReverseZone($zone['name']);
            if ($is_reverse) {
                $all_forward = false;
            } else {
                $all_reverse = false;
            }
        }

        $this->render('delete_domains.html', [
            'perm_edit' => Permission::getEditPermission($this->db),
            'zones' => $zones,
            'error' => _("You do not have the permission to delete a zone."),
            'is_reverse_zone' => $all_reverse, // If all zones are reverse, use reverse breadcrumb
            'is_mixed_zones' => (!$all_reverse && !$all_forward) // Flag for mixed zone types
        ]);
    }

    private function getZoneInfo($zone_ids): array
    {
        $zones = [];
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        foreach ($zone_ids as $zone_id) {
            $zones[$zone_id]['id'] = $zone_id;
            $zones[$zone_id] = $dnsRecord->getZoneInfoFromId($zone_id);
            $zones[$zone_id]['owner'] = UserManager::getFullnamesOwnersFromFomainId($this->db, $zone_id);
            $zones[$zone_id]['is_owner'] = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

            $zones[$zone_id]['has_supermaster'] = false;
            $zones[$zone_id]['slave_master'] = null;
            if ($zones[$zone_id]['type'] == "SLAVE") {
                $slave_master = $dnsRecord->getDomainSlaveMaster($zone_id);
                $zones[$zone_id]['slave_master'] = $slave_master;

                if ($dnsRecord->supermasterExists($slave_master)) {
                    $zones[$zone_id]['has_supermaster'] = true;
                }
            }

            if (str_starts_with($zones[$zone_id]['name'], "xn--")) {
                $zones[$zone_id]['idn_zone_name'] = DnsIdnService::toUtf8($zones[$zone_id]['name']);
            } else {
                $zones[$zone_id]['idn_zone_name'] = "";
            }
        }
        return $zones;
    }
}
