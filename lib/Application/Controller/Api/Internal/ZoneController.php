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
 * Internal API controller for zone operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Infrastructure\Repository\DbZoneRepository;

class ZoneController extends InternalApiBaseController
{
    private DbZoneRepository $zoneRepository;

    /**
     * Constructor for ZoneController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
    }

    /**
     * Run the controller based on the action parameter
     */
    public function run(): void
    {
        $action = $_GET['action'] ?? '';

        switch ($action) {
            case 'list':
                $this->listZones();
                break;
            case 'get':
                $this->getZone();
                break;
            default:
                $this->returnErrorResponse('Unknown action', 400);
        }
    }

    /**
     * List zones accessible to the current user
     */
    private function listZones(): void
    {
        // Check if user can view zones
        $this->validatePermission('zone_content_view_own');

        $zones = $this->zoneRepository->listZones();

        $this->returnJsonResponse([
            'success' => true,
            'zones' => $zones
        ]);
    }

    /**
     * Get a specific zone by ID
     */
    private function getZone(): void
    {
        // Validate required parameters
        $zoneId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($zoneId <= 0) {
            $this->returnErrorResponse('Missing or invalid zone ID', 400);
            return;
        }

        // Check if user can view this zone
        if (!$this->hasPermission('zone_content_view_others')) {
            // Verify that the zone belongs to the current user
            if (!$this->zoneRepository->zoneExists($zoneId, $_SESSION['userid'])) {
                $this->returnErrorResponse('Zone not found or access denied', 404);
                return;
            }
        }

        $zone = $this->zoneRepository->getZone($zoneId);

        if (!$zone) {
            $this->returnErrorResponse('Zone not found', 404);
            return;
        }

        $this->returnJsonResponse([
            'success' => true,
            'zone' => $zone
        ]);
    }
}
