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

namespace Poweradmin\Application\Controller\Api\v1;

use OpenApi\Annotations as OA;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @OA\Tag(
 *     name="zones",
 *     description="API Endpoints for Zone management"
 * )
 */
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
        $method = $this->request->getMethod();
        $action = $this->request->query->get('action', '');

        $response = match ($method) {
            'GET' => $this->handleGetRequest($action),
            'POST' => $this->handlePostRequest($action),
            'PUT' => $this->handlePutRequest($action),
            'DELETE' => $this->handleDeleteRequest($action),
            default => $this->returnApiError('Method not allowed', 405),
        };

        $response->send();
        exit;
    }

    /**
     * Handle GET requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handleGetRequest(string $action): JsonResponse
    {
        return match ($action) {
            'list' => $this->listZones(),
            'get' => $this->getZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle POST requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handlePostRequest(string $action): JsonResponse
    {
        return match ($action) {
            'create' => $this->createZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle PUT requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handlePutRequest(string $action): JsonResponse
    {
        return match ($action) {
            'update' => $this->updateZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * Handle DELETE requests
     *
     * @param string $action The action to perform
     * @return JsonResponse The JSON response
     */
    private function handleDeleteRequest(string $action): JsonResponse
    {
        return match ($action) {
            'delete' => $this->deleteZone(),
            default => $this->returnApiError('Unknown action', 400),
        };
    }

    /**
     * List all accessible zones
     *
     * @OA\Get(
     *     path="/v1/zone",
     *     operationId="listZones",
     *     summary="List all accessible zones",
     *     tags={"zones"},
     *     security={{"bearerAuth":{}, "apiKeyHeader":{}}},
     *     @OA\Parameter(
     *         name="action",
     *         in="query",
     *         required=true,
     *         description="Action parameter (must be 'list')",
     *         @OA\Schema(type="string", default="list", enum={"list"})
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer", default=1, minimum=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of results per page",
     *         @OA\Schema(type="integer", default=20, minimum=1, maximum=100)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of zones",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="zones",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="example.com"),
     *                         @OA\Property(property="type", type="string", example="MASTER"),
     *                         @OA\Property(property="records_count", type="integer", example=5)
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="pagination",
     *                     type="object",
     *                     @OA\Property(property="total", type="integer", example=150),
     *                     @OA\Property(property="page", type="integer", example=1),
     *                     @OA\Property(property="limit", type="integer", example=20),
     *                     @OA\Property(property="pages", type="integer", example=8)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid or missing API key"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     *
     * @return JsonResponse The JSON response
     */
    private function listZones(): JsonResponse
    {
        // Get pagination parameters from request
        $page = $this->request->query->getInt('page', 1);
        $limit = $this->request->query->getInt('limit', 20);

        // Ensure valid pagination
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $zones = $this->zoneRepository->listZones();

        // Apply pagination (in a real implementation, this would be done in the repository)
        $totalZones = count($zones);
        $offset = ($page - 1) * $limit;
        $paginatedZones = array_slice($zones, $offset, $limit);

        // Use serializer for consistent output format
        $serializedZones = json_decode($this->serialize($paginatedZones), true);

        return $this->returnApiResponse([
            'zones' => $serializedZones,
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
     *
     * @OA\Get(
     *     path="/v1/zone",
     *     operationId="getZone",
     *     summary="Get a specific zone by ID or name",
     *     tags={"zones"},
     *     security={{"bearerAuth":{}, "apiKeyHeader":{}}},
     *     @OA\Parameter(
     *         name="action",
     *         in="query",
     *         required=true,
     *         description="Action parameter (must be 'get')",
     *         @OA\Schema(type="string", default="get", enum={"get"})
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Zone ID (either ID or name must be provided)",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="name",
     *         in="query",
     *         description="Zone name (either ID or name must be provided)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Zone details",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="zone",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="example.com"),
     *                     @OA\Property(property="type", type="string", example="MASTER"),
     *                     @OA\Property(property="owner", type="integer", example=1),
     *                     @OA\Property(property="created", type="string", format="date-time", example="2025-05-01T12:00:00Z"),
     *                     @OA\Property(property="updated", type="string", format="date-time", example="2025-05-09T08:30:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing required parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Missing zone ID or name"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid or missing API key"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Zone not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Zone not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     *
     * @return JsonResponse The JSON response
     */
    private function getZone(): JsonResponse
    {
        $zoneId = $this->request->query->getInt('id', 0);
        $zoneName = $this->request->query->get('name', '');

        if ($zoneId <= 0 && empty($zoneName)) {
            return $this->returnApiError('Missing zone ID or name', 400);
        }

        $zone = null;

        if ($zoneId > 0) {
            $zone = $this->zoneRepository->getZone($zoneId);
        } else {
            $zone = $this->zoneRepository->getZoneByName($zoneName);
        }

        if (!$zone) {
            return $this->returnApiError('Zone not found', 404);
        }

        // Use serializer for consistent output format
        $serializedZone = json_decode($this->serialize($zone), true);

        return $this->returnApiResponse([
            'zone' => $serializedZone
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
