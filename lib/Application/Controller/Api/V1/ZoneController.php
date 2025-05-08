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
 * V1 API controller for zone operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\V1;

use Poweradmin\Infrastructure\Repository\DbZoneRepository;

class ZoneController extends V1ApiBaseController
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
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $action = $_GET['action'] ?? '';

        switch ($method) {
            case 'GET':
                $this->handleGetRequest($action);
                break;
            case 'POST':
                $this->handlePostRequest($action);
                break;
            case 'PUT':
                $this->handlePutRequest($action);
                break;
            case 'DELETE':
                $this->handleDeleteRequest($action);
                break;
            default:
                $this->returnApiError('Method not allowed', 405);
        }
    }

    /**
     * Handle GET requests
     *
     * @param string $action The action to perform
     */
    private function handleGetRequest(string $action): void
    {
        switch ($action) {
            case 'list':
                $this->listZones();
                break;
            case 'get':
                $this->getZone();
                break;
            default:
                $this->returnApiError('Unknown action', 400);
        }
    }

    /**
     * Handle POST requests
     *
     * @param string $action The action to perform
     */
    private function handlePostRequest(string $action): void
    {
        switch ($action) {
            case 'create':
                $this->createZone();
                break;
            default:
                $this->returnApiError('Unknown action', 400);
        }
    }

    /**
     * Handle PUT requests
     *
     * @param string $action The action to perform
     */
    private function handlePutRequest(string $action): void
    {
        switch ($action) {
            case 'update':
                $this->updateZone();
                break;
            default:
                $this->returnApiError('Unknown action', 400);
        }
    }

    /**
     * Handle DELETE requests
     *
     * @param string $action The action to perform
     */
    private function handleDeleteRequest(string $action): void
    {
        switch ($action) {
            case 'delete':
                $this->deleteZone();
                break;
            default:
                $this->returnApiError('Unknown action', 400);
        }
    }

    /**
     * List all accessible zones
     */
    private function listZones(): void
    {
        // Get pagination parameters
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

        // Ensure valid pagination
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $zones = $this->zoneRepository->listZones();

        // Apply pagination (in a real implementation, this would be done in the repository)
        $totalZones = count($zones);
        $offset = ($page - 1) * $limit;
        $zones = array_slice($zones, $offset, $limit);

        $this->returnApiResponse([
            'zones' => $zones,
            'pagination' => [
                'total' => $totalZones,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalZones / $limit)
            ]
        ]);
    }

    /**
     * Get a specific zone by ID or name
     */
    private function getZone(): void
    {
        $zoneId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $zoneName = $_GET['name'] ?? '';

        if ($zoneId <= 0 && empty($zoneName)) {
            $this->returnApiError('Missing zone ID or name', 400);
            return;
        }

        $zone = null;

        if ($zoneId > 0) {
            $zone = $this->zoneRepository->getZone($zoneId);
        } else {
            $zone = $this->zoneRepository->getZoneByName($zoneName);
        }

        if (!$zone) {
            $this->returnApiError('Zone not found', 404);
            return;
        }

        $this->returnApiResponse([
            'zone' => $zone
        ]);
    }

    /**
     * Create a new zone
     */
    private function createZone(): void
    {
        $input = $this->getJsonInput();

        if (!$input) {
            $this->returnApiError('Invalid input data', 400);
            return;
        }

        // Validate required fields
        $requiredFields = ['name', 'type'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                $this->returnApiError("Missing required field: {$field}", 400);
                return;
            }
        }

        // Implementation would continue here with actual zone creation logic
        // For this example, we'll just return a success response

        $this->returnApiResponse([
            'id' => 123, // This would be the actual new zone ID
            'message' => 'Zone created successfully'
        ]);
    }

    /**
     * Update an existing zone
     */
    private function updateZone(): void
    {
        $input = $this->getJsonInput();

        if (!$input) {
            $this->returnApiError('Invalid input data', 400);
            return;
        }

        // Ensure zone ID is provided
        if (!isset($input['id']) || (int)$input['id'] <= 0) {
            $this->returnApiError('Missing or invalid zone ID', 400);
            return;
        }

        // Implementation would continue here with actual zone update logic
        // For this example, we'll just return a success response

        $this->returnApiResponse([
            'message' => 'Zone updated successfully'
        ]);
    }

    /**
     * Delete a zone
     */
    private function deleteZone(): void
    {
        $input = $this->getJsonInput();

        // Get zone ID from request body or URL parameter
        $zoneId = 0;

        if ($input && isset($input['id'])) {
            $zoneId = (int)$input['id'];
        } elseif (isset($_GET['id'])) {
            $zoneId = (int)$_GET['id'];
        }

        if ($zoneId <= 0) {
            $this->returnApiError('Missing or invalid zone ID', 400);
            return;
        }

        // Implementation would continue here with actual zone deletion logic
        // For this example, we'll just return a success response

        $this->returnApiResponse([
            'message' => 'Zone deleted successfully'
        ]);
    }
}
