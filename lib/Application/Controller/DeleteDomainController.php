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

use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the delete-zone confirmation page and deletes the zone on confirmed POST.
 */
class DeleteDomainController extends BaseController
{

    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->getRequest())) {
            $this->showFirstValidationError($this->getRequest());
        }

        $zone_id = (int)$this->getSafeRequestValue('id');

        // Check zone-specific delete permission (includes group permissions)
        $userId = $this->userContextService->getLoggedInUserId();
        $user_is_zone_owner = $this->isZoneOwner($zone_id);
        $canDelete = $this->createPermissionService()->canPerformZoneAction($userId, $zone_id, 'zone_delete_own');
        $canDeleteOthers = $this->hasPermission('zone_delete_others');

        $this->checkCondition(
            !$canDeleteOthers && !$canDelete,
            _("You do not have the permission to delete a zone.")
        );

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->deleteDomain($zone_id);
        } else {
            $this->showDeleteDomain($zone_id);
        }
    }

    private function deleteDomain(int $zone_id): void
    {
        $zone_info = $this->createDomainRepository()->getZoneInfoFromId($zone_id);

        // The zone service deletes keys, comments, records and metadata with the zone, as the API does
        $deleted = $this->createZoneManagementService()->deleteZone($zone_id);
        if ($deleted['success']) {
            $this->createAuditService()->logZoneDelete($zone_id, (string)$zone_info['name'], (string)$zone_info['type']);

            // Check if the zone is a reverse zone and redirect accordingly
            if (!empty($zone_info['name']) && DnsHelper::isReverseZoneName($zone_info['name'])) {
                $this->setMessage('list_reverse_zones', 'success', _('Zone has been deleted successfully.'));
                $this->redirect('/zones/reverse');
            } else {
                $this->setMessage('list_forward_zones', 'success', _('Zone has been deleted successfully.'));
                $this->redirect('/zones/forward');
            }
        } else {
            // The backend may already have dropped the zone, so leave the page instead of re-rendering it.
            $this->addSystemMessage('error', ($deleted['code'] ?? null) === ZoneManagementService::ERR_NOT_FOUND
                ? _('There is no zone with this ID.')
                : _('The zone could not be deleted.'));
            $this->redirect(!empty($zone_info['name']) && DnsHelper::isReverseZoneName($zone_info['name']) ? '/zones/reverse' : '/zones/forward');
        }
    }

    private function showDeleteDomain(int $zone_id): void
    {
        $domainRepository = $this->createDomainRepository();
        $zone_info = $domainRepository->getZoneInfoFromId($zone_id);
        $zone_owners = $this->createUserRepository()->getZoneOwnerFullNames($zone_id);

        $slave_master_exists = false;
        if ($zone_info['type'] == 'SLAVE') {
            $slave_master = $domainRepository->getDomainMaster($zone_id);
            if ($slave_master) {
                // Extract first IP from master field (can contain multiple IPs, hostnames, ports)
                $master_ip = IpHelper::extractFirstIpFromMaster($slave_master);
                $supermasterManager = DnsServiceFactory::createSupermasterManager($this->db, $this->getConfig());
                if ($master_ip && $supermasterManager->supermasterExists($master_ip)) {
                    $slave_master_exists = true;
                }
            }
        }

        if (str_starts_with($zone_info['name'], "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_info['name']);
        } else {
            $idn_zone_name = "";
        }

        $this->render('delete_domain.html', [
            'zone_id' => $zone_id,
            'zone_info' => $zone_info,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_info['name']),
            'zone_owners' => $zone_owners,
            'slave_master_exists' => $slave_master_exists,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($zone_info['name']),
        ]);
    }
}
