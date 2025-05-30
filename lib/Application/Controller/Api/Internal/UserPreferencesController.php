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

namespace Poweradmin\Application\Controller\Api\Internal;

use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Infrastructure\Repository\DbUserPreferenceRepository;

class UserPreferencesController extends InternalApiController
{
    private UserPreferenceService $userPreferenceService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $db_type = $this->config->get('database', 'type');
        $repository = new DbUserPreferenceRepository($this->db, $db_type);
        $this->userPreferenceService = new UserPreferenceService($repository);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $userId = $this->userContextService->getLoggedInUserId();

        if (!$userId) {
            $response = $this->returnJsonResponse(['error' => 'Unauthorized'], 401);
            $response->send();
            exit;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        switch ($method) {
            case 'GET':
                $this->handleGet($userId);
                break;
            case 'PUT':
            case 'POST':
                $this->handleUpdate($userId);
                break;
            case 'DELETE':
                $this->handleDelete($userId);
                break;
            default:
                $response = $this->returnJsonResponse(['error' => 'Method not allowed'], 405);
                $response->send();
                exit;
        }
    }

    private function handleGet(int $userId): void
    {
        $key = $_GET['key'] ?? null;

        if ($key) {
            if (!UserPreference::isValidKey($key)) {
                $response = $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
                $response->send();
                exit;
            }

            $value = $this->userPreferenceService->getPreference($userId, $key);
            $response = $this->returnJsonResponse(['key' => $key, 'value' => $value]);
            $response->send();
            exit;
        } else {
            $preferences = $this->userPreferenceService->getAllPreferences($userId);
            $response = $this->returnJsonResponse(['preferences' => $preferences]);
            $response->send();
            exit;
        }
    }

    private function handleUpdate(int $userId): void
    {
        $input = $this->getJsonInput();

        if (!$input || !isset($input['key']) || !isset($input['value'])) {
            $response = $this->returnJsonResponse(['error' => 'Missing key or value'], 400);
            $response->send();
            exit;
        }

        $key = $input['key'];
        $value = $input['value'];

        try {
            $this->userPreferenceService->setPreference($userId, $key, $value);
            $response = $this->returnJsonResponse([
                'success' => true,
                'key' => $key,
                'value' => $value
            ]);
            $response->send();
            exit;
        } catch (\InvalidArgumentException $e) {
            $response = $this->returnJsonResponse(['error' => $e->getMessage()], 400);
            $response->send();
            exit;
        }
    }

    private function handleDelete(int $userId): void
    {
        $key = $_GET['key'] ?? null;

        if (!$key) {
            $response = $this->returnJsonResponse(['error' => 'Missing key parameter'], 400);
            $response->send();
            exit;
        }

        if (!UserPreference::isValidKey($key)) {
            $response = $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
            $response->send();
            exit;
        }

        $this->userPreferenceService->resetPreference($userId, $key);
        $response = $this->returnJsonResponse(['success' => true, 'key' => $key]);
        $response->send();
        exit;
    }
}
