<?php

namespace unit\Api\V2;

use Exception;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TestableZoneTemplatesController extends ZoneTemplatesController
{
    private DbZoneTemplateRepository $testRepository;
    private ApiPermissionService $testApiPermissionService;
    private array $jsonInput = [];

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(array $request = [], array $pathParameters = [])
    {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
        $this->authenticatedUserId = 1;
    }

    public function setRepository(DbZoneTemplateRepository $repository): void
    {
        $this->testRepository = $repository;
    }

    public function setApiPermissionService(ApiPermissionService $service): void
    {
        $this->testApiPermissionService = $service;
    }

    public function setJsonInput(array $data): void
    {
        $this->jsonInput = $data;
    }

    protected function getJsonInput(): ?array
    {
        return $this->jsonInput ?: null;
    }

    public function testListZoneTemplates(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $isUeberuser = $this->testApiPermissionService->userHasPermission($userId, 'user_is_ueberuser');

            $templates = $this->testRepository->listZoneTemplates($userId, $isUeberuser);

            $formatted = array_map(function (array $template): array {
                return [
                    'id' => (int)$template['id'],
                    'name' => $template['name'],
                    'description' => $template['descr'],
                    'owner' => (int)$template['owner'],
                    'is_global' => (int)$template['owner'] === 0,
                    'zones_linked' => (int)$template['zones_linked'],
                ];
            }, $templates);

            return $this->returnApiResponse($formatted);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch zone templates: ' . $e->getMessage(), 500);
        }
    }

    public function testGetZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();
            $isUeberuser = $this->testApiPermissionService->userHasPermission($userId, 'user_is_ueberuser');

            $id = (int)$this->pathParameters['id'];

            $template = $this->testRepository->getZoneTemplateDetails($id);
            if (!$template) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $owner = (int)$template['owner'];
            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to view this zone template', 403);
            }

            $records = $this->testRepository->getZoneTemplateRecords($id);

            $formattedRecords = array_map(function (array $record): array {
                return [
                    'id' => (int)$record['id'],
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $record['content'],
                    'ttl' => (int)$record['ttl'],
                    'priority' => (int)$record['prio'],
                ];
            }, $records);

            return $this->returnApiResponse([
                'id' => (int)$template['id'],
                'name' => $template['name'],
                'description' => $template['descr'],
                'owner' => $owner,
                'is_global' => $owner === 0,
                'records' => $formattedRecords,
            ]);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch zone template: ' . $e->getMessage(), 500);
        }
    }

    public function testCreateZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->testApiPermissionService->canCreateZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to create zone templates', 403);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['description']) ||
                empty(trim($data['name'])) || empty(trim($data['description']))
            ) {
                return $this->returnApiError('Missing required fields: name, description', 400);
            }

            $name = trim($data['name']);
            $description = trim($data['description']);
            $isGlobal = !empty($data['is_global']);

            if ($isGlobal && !$this->testApiPermissionService->userHasPermission($userId, 'user_is_ueberuser')) {
                return $this->returnApiError('Only ueberusers can create global zone templates', 403);
            }

            if ($this->testRepository->zoneTemplateNameExists($name)) {
                return $this->returnApiError('A zone template with this name already exists', 409);
            }

            $owner = $isGlobal ? 0 : $userId;
            $newId = $this->testRepository->createZoneTemplate($name, $description, $owner, $userId);

            return $this->returnApiResponse(['id' => $newId], true, 'Zone template created successfully', 201);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create zone template: ' . $e->getMessage(), 500);
        }
    }

    public function testUpdateZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->testApiPermissionService->canEditZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to edit zone templates', 403);
            }

            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Zone template ID is required', 400);
            }

            $id = (int)$this->pathParameters['id'];

            if (!$this->testRepository->zoneTemplateExists($id)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $isUeberuser = $this->testApiPermissionService->userHasPermission($userId, 'user_is_ueberuser');
            $owner = $this->testRepository->getOwner($id);

            if ($owner === 0 && !$isUeberuser) {
                return $this->returnApiError('Only ueberusers can edit global zone templates', 403);
            }

            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to edit this zone template', 403);
            }

            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['description']) ||
                empty(trim($data['name'])) || empty(trim($data['description']))
            ) {
                return $this->returnApiError('Missing required fields: name, description', 400);
            }

            $name = trim($data['name']);
            $description = trim($data['description']);

            if ($this->testRepository->zoneTemplateNameExists($name, $id)) {
                return $this->returnApiError('A zone template with this name already exists', 409);
            }

            $this->testRepository->updateZoneTemplate($id, $name, $description);

            return $this->returnApiResponse(null, true, 'Zone template updated successfully');
        } catch (Exception $e) {
            return $this->returnApiError('Failed to update zone template: ' . $e->getMessage(), 500);
        }
    }

    public function testDeleteZoneTemplate(): JsonResponse
    {
        try {
            $userId = $this->getAuthenticatedUserId();

            if (!$this->testApiPermissionService->canEditZoneTemplate($userId)) {
                return $this->returnApiError('You do not have permission to delete zone templates', 403);
            }

            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Zone template ID is required', 400);
            }

            $id = (int)$this->pathParameters['id'];

            if (!$this->testRepository->zoneTemplateExists($id)) {
                return $this->returnApiError('Zone template not found', 404);
            }

            $isUeberuser = $this->testApiPermissionService->userHasPermission($userId, 'user_is_ueberuser');
            $owner = $this->testRepository->getOwner($id);

            if ($owner === 0 && !$isUeberuser) {
                return $this->returnApiError('Only ueberusers can delete global zone templates', 403);
            }

            if ($owner !== 0 && $owner !== $userId && !$isUeberuser) {
                return $this->returnApiError('You do not have permission to delete this zone template', 403);
            }

            $this->testRepository->deleteZoneTemplate($id);

            return $this->returnApiResponse(null, true, 'Zone template deleted successfully');
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete zone template: ' . $e->getMessage(), 500);
        }
    }
}
