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

use InvalidArgumentException;
use Poweradmin\Application\Controller\Api\InternalApiController;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Internal endpoint /api/internal/user-preferences: reads, updates and resets the logged-in user's preferences.
 */
class UserPreferencesController extends InternalApiController
{
    private UserPreferenceService $userPreferenceService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->userPreferenceService = $this->services()->userPreferenceService();
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->respond()->send();
    }

    /**
     * The response for the current request; the verb picks the handler.
     */
    protected function respond(): JsonResponse
    {
        $userId = $this->userContextService->getLoggedInUserId();
        if (!$userId) {
            return $this->returnJsonResponse(['error' => 'Unauthorized'], 401);
        }

        return match (strtoupper($this->request->getMethod())) {
            'GET' => $this->handleGet($userId),
            'PUT', 'POST' => $this->handleUpdate($userId),
            'DELETE' => $this->handleDelete($userId),
            default => $this->returnJsonResponse(['error' => 'Method not allowed'], 405),
        };
    }

    private function handleGet(int $userId): JsonResponse
    {
        $key = $this->request->query->get('key');
        if (!$key) {
            return $this->returnJsonResponse(['preferences' => $this->userPreferenceService->getAllPreferences($userId)]);
        }
        if (!UserPreference::isValidKey($key)) {
            return $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
        }

        return $this->returnJsonResponse(['key' => $key, 'value' => $this->userPreferenceService->getPreference($userId, $key)]);
    }

    private function handleUpdate(int $userId): JsonResponse
    {
        $input = $this->getJsonInput();
        if (!$input || !isset($input['key']) || !isset($input['value'])) {
            return $this->returnJsonResponse(['error' => 'Missing key or value'], 400);
        }

        $key = $input['key'];
        $value = $input['value'];
        try {
            $this->userPreferenceService->setPreference($userId, $key, $value);
        } catch (InvalidArgumentException $e) {
            return $this->returnJsonResponse(['error' => $e->getMessage()], 400);
        }

        return $this->returnJsonResponse(['success' => true, 'key' => $key, 'value' => $value]);
    }

    private function handleDelete(int $userId): JsonResponse
    {
        $key = $this->request->query->get('key');
        if (!$key) {
            return $this->returnJsonResponse(['error' => 'Missing key parameter'], 400);
        }
        if (!UserPreference::isValidKey($key)) {
            return $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
        }

        $this->userPreferenceService->resetPreference($userId, $key);

        return $this->returnJsonResponse(['success' => true, 'key' => $key]);
    }
}
