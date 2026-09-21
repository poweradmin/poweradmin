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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Handles bulk zone deletion from the zone lists: shows the selected zones and deletes them on confirmation.
 */
class DeleteDomainsController extends BaseController
{

    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $zone_ids = $this->httpRequest->getPostParam('zone_id');
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

        // Deleting a zone requires delete permission for every selected zone
        // (direct or group ownership); matches the single-zone delete controller.
        $this->verifyDeletePermission($zone_ids);

        if ($this->httpRequest->getPostParam('confirm') !== null) {
            $this->deleteDomains($zone_ids);
        }

        $this->showDomains($zone_ids);
    }

    private function verifyDeletePermission($zone_ids): void
    {
        $userId = $this->userContextService->getLoggedInUserId();
        $canDeleteOthers = $this->hasPermission(Permission::PERM_ZONE_DELETE_OTHERS);

        foreach ((array)$zone_ids as $zone_id) {
            $canDelete = $canDeleteOthers
                || $this->services()->permissionService()->canPerformZoneAction($userId, (int)$zone_id, Permission::PERM_ZONE_DELETE_OWN);
            $this->checkCondition(!$canDelete, _("You do not have the permission to delete a zone."));
        }
    }

    public function deleteDomains($zone_ids): void
    {
        $domainRepository = $this->services()->domainRepository();
        $deleted_zones = $this->canViewZones() ? $domainRepository->getZoneInfoFromIds($zone_ids) : [];

        // Permission for every zone was already established by verifyDeletePermission();
        // the zone service deletes keys, comments, records and metadata with each zone.
        $zoneService = $this->createZoneManagementService();
        $failed = false;
        foreach ($zone_ids as $zone_id) {
            if (!$zoneService->deleteZone((int)$zone_id)['success']) {
                $failed = true;
            }
        }

        // Determine if we should redirect to reverse or forward zones page
        $all_reverse = true;
        foreach ($deleted_zones as $zone) {
            if (empty($zone['name']) || !DnsHelper::isReverseZoneName($zone['name'])) {
                $all_reverse = false;
                break;
            }
        }
        $return_page = $all_reverse ? 'list_reverse_zones' : 'list_forward_zones';
        $route = $all_reverse ? '/zones/reverse' : '/zones/forward';

        if (!$failed) {
            $audit = $this->services()->auditService();
            foreach ($deleted_zones as $deleted_zone) {
                if (!empty($deleted_zone['name'])) {
                    $audit->logZoneDelete((int)$deleted_zone['id'], (string)$deleted_zone['name'], (string)$deleted_zone['type']);
                }
            }

            if (count($deleted_zones) == 1) {
                $this->setMessage($return_page, 'success', _('Zone has been deleted successfully.'));
            } else {
                $this->setMessage($return_page, 'success', _('Zones have been deleted successfully.'));
            }
        } else {
            // Some zones may already be gone, so report and leave rather than re-render the confirm page.
            $this->setMessage($return_page, 'error', _('Some zones could not be deleted.'));
        }
        $this->redirect($route);
    }

    public function showDomains($zone_ids): void
    {
        $zones = $this->getZoneInfo($zone_ids);
        // Check if we're dealing with only reverse zones, only forward zones, or mixed
        $all_reverse = true;
        $all_forward = true;

        foreach ($zones as $zone) {
            $is_reverse = DnsHelper::isReverseZoneName($zone['name']);
            if ($is_reverse) {
                $all_forward = false;
            } else {
                $all_reverse = false;
            }
        }

        $permissionService = $this->services()->permissionService();
        $userId = $this->userContextService->getLoggedInUserId();
        // Same "all"/"own"/"none" contract as PermissionService::getDeletePermissionLevel(), but off
        // the request-cached service the delete check above already warmed
        $perm_delete = $permissionService->getDeletePermissionLevel($userId);
        foreach ($zones as &$zone) {
            // Effectively always true here: verifyDeletePermission() already blocked
            // any zone the user cannot delete. Kept for the template contract.
            $zone['user_can_delete'] = $permissionService->canDeleteZone($userId, (bool)$zone['is_owner']);
        }
        unset($zone);

        $this->render('delete_domains.html', [
            'perm_delete' => $perm_delete,
            'zones' => $zones,
            'error' => _("You do not have the permission to delete a zone."),
            'is_reverse_zone' => $all_reverse, // If all zones are reverse, use reverse breadcrumb
            'is_mixed_zones' => (!$all_reverse && !$all_forward) // Flag for mixed zone types
        ]);
    }

    private function getZoneInfo($zone_ids): array
    {
        $zones = [];
        $domainRepository = $this->services()->domainRepository();
        $supermasterManager = $this->services()->supermasterManager();

        // Fetch all zone details in one bulk call to avoid per-zone API round-trips
        $zoneInfos = [];
        foreach ($this->canViewZones() ? $domainRepository->getZoneInfoFromIds($zone_ids) : [] as $info) {
            $zoneInfos[(int)($info['id'] ?? 0)] = $info;
        }

        $userRepository = $this->services()->userRepository();
        foreach ($zone_ids as $zone_id) {
            $zones[$zone_id] = $zoneInfos[$zone_id] ?? ['id' => $zone_id];
            $zones[$zone_id]['owner'] = $userRepository->getZoneOwnerFullNames($zone_id);
            $zones[$zone_id]['is_owner'] = $this->isZoneOwner($zone_id);

            $zones[$zone_id]['has_supermaster'] = false;
            $zones[$zone_id]['slave_master'] = null;
            if ($zones[$zone_id]['type'] == "SLAVE") {
                $slave_master = $domainRepository->getDomainMaster($zone_id);
                $zones[$zone_id]['slave_master'] = $slave_master;

                if ($slave_master) {
                    // Extract first IP from master field (can contain multiple IPs, hostnames, ports)
                    $master_ip = IpHelper::extractFirstIpFromMaster($slave_master);
                    if ($master_ip && $supermasterManager->supermasterExists($master_ip)) {
                        $zones[$zone_id]['has_supermaster'] = true;
                    }
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
