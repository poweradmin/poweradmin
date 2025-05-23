<?php

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
            echo $this->returnJsonResponse(['error' => 'Unauthorized'], 401);
            return;
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
                echo $this->returnJsonResponse(['error' => 'Method not allowed'], 405);
        }
    }

    private function handleGet(int $userId): void
    {
        $key = $_GET['key'] ?? null;

        if ($key) {
            if (!UserPreference::isValidKey($key)) {
                echo $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
                return;
            }

            $value = $this->userPreferenceService->getPreference($userId, $key);
            echo $this->returnJsonResponse(['key' => $key, 'value' => $value]);
        } else {
            $preferences = $this->userPreferenceService->getAllPreferences($userId);
            echo $this->returnJsonResponse(['preferences' => $preferences]);
        }
    }

    private function handleUpdate(int $userId): void
    {
        $input = $this->getJsonInput();

        if (!$input || !isset($input['key']) || !isset($input['value'])) {
            echo $this->returnJsonResponse(['error' => 'Missing key or value'], 400);
            return;
        }

        $key = $input['key'];
        $value = $input['value'];

        try {
            $this->userPreferenceService->setPreference($userId, $key, $value);
            echo $this->returnJsonResponse([
                'success' => true,
                'key' => $key,
                'value' => $value
            ]);
        } catch (\InvalidArgumentException $e) {
            echo $this->returnJsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleDelete(int $userId): void
    {
        $key = $_GET['key'] ?? null;

        if (!$key) {
            echo $this->returnJsonResponse(['error' => 'Missing key parameter'], 400);
            return;
        }

        if (!UserPreference::isValidKey($key)) {
            echo $this->returnJsonResponse(['error' => 'Invalid preference key'], 400);
            return;
        }

        $this->userPreferenceService->resetPreference($userId, $key);
        echo $this->returnJsonResponse(['success' => true, 'key' => $key]);
    }
}
