<?php

namespace Poweradmin\Tests\Unit\Api\V2;

use Poweradmin\Application\Controller\Api\V2\ZoneDnssecController;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test double that injects mocks and skips the parent constructor (which performs
 * authentication and a real DB connection). The handler logic under test is the
 * real ZoneDnssecController code.
 */
class TestableZoneDnssecController extends ZoneDnssecController
{
    public ?array $loggedChange = null;
    public array $soaBumps = [];
    public ?string $signingValidationError = null;

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

    public function setDnssecProvider(DnssecProvider $provider): void
    {
        $this->dnssecProvider = $provider;
    }

    public function setApiClient(?PowerdnsApiClient $client): void
    {
        $this->apiClient = $client;
    }

    public function setRequestBody(string $content): void
    {
        $this->request = new Request([], [], [], [], [], [], $content);
    }

    public function callGetStatus(): JsonResponse
    {
        return $this->getStatus();
    }

    public function callSetStatus(): JsonResponse
    {
        return $this->setStatus();
    }

    protected function validateZoneForSigning(int $zoneId, string $zoneName): ?string
    {
        return $this->signingValidationError;
    }

    protected function bumpSoaSerial(int $zoneId): void
    {
        $this->soaBumps[] = $zoneId;
    }

    protected function logDnssecChange(int $zoneId, string $zoneName, bool $enabled): void
    {
        $this->loggedChange = ['zoneId' => $zoneId, 'zoneName' => $zoneName, 'enabled' => $enabled];
    }
}
