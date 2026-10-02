<?php

namespace Poweradmin\Tests\Unit\Api\V2;

use Poweradmin\Application\Controller\Api\V2\ZoneDnssecKeysController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test double that injects mocks and skips the parent constructor (which performs
 * authentication and a real DB connection).
 */
class TestableZoneDnssecKeysController extends ZoneDnssecKeysController
{
    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(array $request = [], array $pathParameters = [])
    {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
        $this->authenticatedUserId = 1;
        $this->config = ConfigurationManager::getInstance();
    }

    public function setZoneRepository(ZoneRepositoryInterface $repository): void
    {
        $this->zoneRepository = $repository;
    }

    public function setApiPermissionService(ApiPermissionService $service): void
    {
        $this->apiPermissionService = $service;
    }

    public function setApiClient(?PowerdnsApiClient $client): void
    {
        $this->apiClient = $client;
    }

    public function setRequestBody(string $content): void
    {
        $this->request = new Request([], [], [], [], [], [], $content);
    }

    public function callAddKey(): JsonResponse
    {
        return $this->addKey();
    }
}
