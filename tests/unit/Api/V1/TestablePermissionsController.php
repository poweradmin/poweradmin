<?php

namespace unit\Api\V1;

use Exception;
use Poweradmin\Application\Controller\Api\V1\PermissionsController;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TestablePermissionsController extends PermissionsController
{
    private DbPermissionTemplateRepository $testRepository;

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

    public function testListPermissions(): JsonResponse
    {
        try {
            $permissions = $this->testRepository->getPermissionsByTemplateId(0);
            return $this->returnApiResponse($permissions);
        } catch (Exception $e) {
            return $this->returnApiError('Failed to fetch permissions: ' . $e->getMessage(), 500);
        }
    }

    public function testMethodNotAllowed(): JsonResponse
    {
        return $this->returnApiError('Method not allowed', 405);
    }
}
