<?php

namespace unit\Api\V1;

use Exception;
use Poweradmin\Application\Controller\Api\V1\PermissionTemplatesController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TestablePermissionTemplatesController extends PermissionTemplatesController
{
    private DbPermissionTemplateRepository $testRepository;
    private array $jsonInput = [];

    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(array $request = [], array $pathParameters = [])
    {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
    }

    public function setRepository(DbPermissionTemplateRepository $repository): void
    {
        $this->testRepository = $repository;
    }

    public function setJsonInput(array $data): void
    {
        $this->jsonInput = $data;
    }

    protected function getJsonInput(): ?array
    {
        return $this->jsonInput ?: null;
    }

    public function testListPermissionTemplates(): JsonResponse
    {
        try {
            $templates = $this->testRepository->listPermissionTemplates();
            return $this->returnApiResponse($templates);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permission templates: ' . $e->getMessage(), 500);
        }
    }

    public function testGetPermissionTemplate(): JsonResponse
    {
        try {
            $id = $this->pathParameters['id'];

            $template = $this->testRepository->getPermissionTemplateDetails($id);
            if (!$template) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $template['permissions'] = $this->testRepository->getPermissionsByTemplateId($id);

            return $this->returnApiResponse($template);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permission template: ' . $e->getMessage(), 500);
        }
    }

    public function testCreatePermissionTemplate(): JsonResponse
    {
        try {
            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['descr']) ||
                empty(trim($data['name'])) || empty(trim($data['descr']))
            ) {
                return $this->returnApiError('Missing required fields: name, descr', 400);
            }

            $details = [
                'templ_name' => $data['name'],
                'templ_descr' => $data['descr']
            ];

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $details['perm_id'] = $data['permissions'];
            }

            $result = $this->testRepository->addPermissionTemplate($details);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template created successfully', 201);
            } else {
                return $this->returnApiError('Failed to create permission template', 500);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to create permission template: ' . $e->getMessage(), 500);
        }
    }

    public function testUpdatePermissionTemplate(): JsonResponse
    {
        try {
            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Permission template ID is required', 400);
            }

            $id = $this->pathParameters['id'];
            $data = $this->getJsonInput();

            if (
                !$data || !isset($data['name']) || !isset($data['descr']) ||
                empty(trim($data['name'])) || empty(trim($data['descr']))
            ) {
                return $this->returnApiError('Missing required fields: name, descr', 400);
            }

            $existing = $this->testRepository->getPermissionTemplateDetails($id);
            if (!$existing) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $details = [
                'templ_id' => $id,
                'templ_name' => $data['name'],
                'templ_descr' => $data['descr']
            ];

            if (isset($data['permissions']) && is_array($data['permissions'])) {
                $details['perm_id'] = $data['permissions'];
            }

            $result = $this->testRepository->updatePermissionTemplateDetails($details);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template updated successfully');
            } else {
                return $this->returnApiError('Failed to update permission template', 500);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to update permission template: ' . $e->getMessage(), 500);
        }
    }

    public function testDeletePermissionTemplate(): JsonResponse
    {
        try {
            if (!isset($this->pathParameters['id'])) {
                return $this->returnApiError('Permission template ID is required', 400);
            }

            $id = $this->pathParameters['id'];

            $existing = $this->testRepository->getPermissionTemplateDetails($id);
            if (!$existing) {
                return $this->returnApiError('Permission template not found', 404);
            }

            $result = $this->testRepository->deletePermissionTemplate($id);

            if ($result) {
                return $this->returnApiResponse(null, true, 'Permission template deleted successfully');
            } else {
                return $this->returnApiError('Cannot delete permission template - it is assigned to one or more users', 409);
            }
        } catch (Exception $e) {
            return $this->returnApiError('Failed to delete permission template: ' . $e->getMessage(), 500);
        }
    }
}
