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
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\CatalogZoneService;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Manage which zones a producer zone publishes in its catalog.
 */
class ZoneCatalogController extends BaseController
{
    private UserContextService $userContextService;
    private ZoneRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;
    private CatalogZoneService $catalogService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->userContextService = new UserContextService();
        $this->zoneRepository = $this->createZoneRepository();
        $this->permissionService = $this->createPermissionService();
        $this->catalogService = $this->createCatalogZoneService();
    }

    public function run(): void
    {
        $this->setCurrentPage('edit');
        $this->setPageTitle(_('Catalog Members'));

        $zoneId = $this->getSafeRequestValue('id');
        if (!$zoneId || !is_numeric($zoneId)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }
        $zoneId = (int)$zoneId;

        // The capability cache expires on its own; refresh here so the page does not
        // start refusing a producer zone it happily showed a few minutes earlier.
        $this->refreshPdnsCapabilities();
        if (!$this->getPdnsCapabilities()->supportsCatalogZones()) {
            $this->showError(_('Catalog zones require PowerDNS 4.7 or newer.'));
            return;
        }

        $zoneName = $this->zoneRepository->getDomainNameById($zoneId);
        if ($zoneName === null) {
            $this->showError(_('Zone not found.'));
            return;
        }

        if ($this->zoneRepository->getDomainType($zoneId) !== ZoneType::PRODUCER) {
            $this->showError(_('Only producer zones publish a catalog.'));
            return;
        }

        $userId = $this->userContextService->getLoggedInUserId();
        $metadataView = $this->permissionService->getZoneMetadataViewPermissionLevel($userId);
        $isOwner = $this->isZoneOwner($zoneId);

        if ($metadataView !== 'all' && !($metadataView === 'own' && $isOwner)) {
            $this->showError(_('You do not have permission to access this zone.'));
            return;
        }

        $mayEdit = $this->catalogService->canManageZone($userId, $zoneId);

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->handleFormSubmission($zoneId, $userId, $mayEdit);
        }

        $members = $this->catalogService->getMembers($zoneName);
        $memberIds = array_column($members, 'id');

        $available = array_values(array_filter(
            $this->catalogService->getEligibleMembers(),
            fn(array $zone): bool => $zone['id'] !== $zoneId && !in_array($zone['id'], $memberIds, true)
        ));

        $this->render('zone-catalog.html', [
            'zone_id' => $zoneId,
            'zone_name' => DnsIdnService::toDisplay($zoneName),
            'is_reverse_zone' => DnsHelper::isReverseZoneName($zoneName),
            'members' => $members,
            'available_zones' => $available,
            'may_edit' => $mayEdit,
        ]);
    }

    private function handleFormSubmission(int $producerId, int $userId, bool $mayEdit): void
    {
        if (!$mayEdit) {
            $this->setMessage('zone-catalog', 'error', _('You do not have permission to edit this zone.'));
            return;
        }

        $memberId = $this->request->getPostParam('member_zone_id');
        if (!is_numeric($memberId)) {
            $this->setMessage('zone-catalog', 'error', _('Invalid or unexpected input given.'));
            return;
        }
        $memberId = (int)$memberId;

        // CatalogZoneService writes the audit entry, so both this page and the
        // member-side selector log the same way.
        if ($this->request->getPostParam('add_member') !== null) {
            $done = $this->catalogService->assign($userId, $memberId, $producerId);
            $message = $done ? _('The zone has been added to the catalog.') : _('You do not have permission to edit this zone.');
        } elseif ($this->request->getPostParam('remove_member') !== null) {
            $done = $this->catalogService->clear($userId, $memberId);
            $message = $done ? _('The zone has been removed from the catalog.') : _('You do not have permission to edit this zone.');
        } else {
            return;
        }

        $this->setMessage('zone-catalog', $done ? 'success' : 'error', $message);
    }
}
