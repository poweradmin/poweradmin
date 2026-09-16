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

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\UserContextService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Internal endpoint /api/internal/zone: lists or fetches zones the logged-in user may view.
 */
class ZoneController extends InternalApiController
{
    private ZoneReadRepositoryInterface $zoneRepository;
    private UserContextService $userContextService;

    /**
     * Constructor for ZoneController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->zoneRepository = $this->createZoneRepository();
        $this->userContextService = new UserContextService();
    }

    /**
     * Run the controller based on the action parameter
     */
    public function run(): void
    {
        $action = $this->request->query->get('action', '');

        $response = match ($action) {
            'list' => $this->listZones(),
            'get' => $this->getZone(),
            default => $this->returnErrorResponse('Unknown action', 400),
        };

        $response->send();
    }

    /**
     * Either view permission gets you in; which zones are visible is decided per action.
     */
    private function hasAnyZoneViewPermission(): bool
    {
        return $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OWN)
            || $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);
    }

    /**
     * List zones accessible to the current user
     */
    private function listZones(): JsonResponse
    {
        if (!$this->hasAnyZoneViewPermission()) {
            return $this->returnErrorResponse('Forbidden: insufficient permissions', 403);
        }

        // Scope to the user's own zones unless they hold zone_content_view_others.
        // Without these args the repository defaults leak every zone in the system.
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $viewOthers = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);
        $zones = $this->zoneRepository->listZones($userId, $viewOthers);

        return $this->returnJsonResponse([
            'success' => true,
            'message' => 'Zones retrieved successfully',
            'data' => [
                'zones' => $zones
            ],
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    }

    /**
     * Get a specific zone by ID
     *
     * Serialises the repository row unchanged, so ZoneReadRepositoryInterface::getZone()
     * documents the response keys (master, account, owner, record_count added in 4.6.0).
     */
    private function getZone(): JsonResponse
    {
        // Validate required parameters
        $zoneId = (int)$this->request->query->get('id', 0);

        if ($zoneId <= 0) {
            return $this->returnErrorResponse('Missing or invalid zone ID', 400);
        }

        if (!$this->hasAnyZoneViewPermission()) {
            return $this->returnErrorResponse('Forbidden: insufficient permissions', 403);
        }

        $viewOthers = $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS);

        // A zone the user neither owns nor may view others of reads as missing
        if (!$viewOthers && !$this->isZoneOwner($zoneId)) {
            return $this->returnErrorResponse('Zone not found or access denied', 404);
        }

        $zone = $this->zoneRepository->getZone($zoneId);

        if (!$zone) {
            return $this->returnErrorResponse('Zone not found', 404);
        }

        return $this->returnJsonResponse([
            'success' => true,
            'message' => 'Zone retrieved successfully',
            'data' => [
                'zone' => $zone
            ],
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    }
}
