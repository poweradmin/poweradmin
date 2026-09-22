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
 */

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Zone\EditController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use Poweradmin\Domain\Service\Validation\Refusal;
use TestHelpers\FakeConfiguration;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Tests for EditController::handleZoneMetadataPost(), which dispatches the
 * meta-edit POST actions (type change, slave master change, zone retrieval,
 * template change) using the Request wrapper instead of $_POST.
 *
 * The test invokes the private method via reflection with the rest of the
 * controller's dependency graph stubbed - the goal is to confirm the
 * Request-based parameter extraction routes to the correct DomainManager call,
 * not to exercise the full run() pipeline.
 */
class EditControllerZoneMetadataPostTest extends TestCase
{
    private ReflectionClass $controllerReflection;
    private array $postBackup = [];
    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerReflection = new ReflectionClass(EditController::class);
        $this->postBackup = $_POST;
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;

        parent::tearDown();
    }

    public function testTypeChangePostDispatchesChangeZoneType(): void
    {
        $_POST = [
            'type_change' => '1',
            'newtype' => 'MASTER',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->once())
            ->method('changeZoneType')
            ->with('MASTER', 42)
            ->willReturn(ZoneWriteResult::ok(42));
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
    }

    public function testTypeChangeNeedsTheCreateGrantForTheTargetType(): void
    {
        $_POST = [
            'type_change' => '1',
            'newtype' => 'SLAVE',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');

        $this->invokeHandler($domainManager, 42, canCreateZone: false);
    }

    public function testTypeChangeIgnoresUnknownType(): void
    {
        $_POST = [
            'type_change' => '1',
            'newtype' => 'BOGUS',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');

        $this->invokeHandler($domainManager, 42);
    }

    public function testSlaveMasterChangePostDispatchesChangeZoneSlaveMaster(): void
    {
        $_POST = [
            'slave_master_change' => '1',
            'new_master' => '192.0.2.10',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');
        $domainManager->expects($this->once())
            ->method('changeZoneSlaveMaster')
            ->with(42, '192.0.2.10')
            ->willReturn(ZoneWriteResult::ok(42));
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
    }

    public function testTemplateChangePostAppliesTheTemplateThroughTheZoneService(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => '7',
            'current_zone_template' => '3',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $zoneService = $this->createMock(ZoneManagementService::class);
        $zoneService->expects($this->once())->method('applyTemplate')->with(42, '7', 7)->willReturn(['success' => true, 'template_id' => 7]);

        $messages = $this->invokeHandler($domainManager, 42, zoneService: $zoneService);

        $this->assertSame('success', $messages[0]['type']);
    }

    public function testTemplateChangeNonePassesNoneAndWordsARefusal(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => 'none',
            'current_zone_template' => '3',
        ];

        $zoneService = $this->createMock(ZoneManagementService::class);
        $zoneService->expects($this->once())->method('applyTemplate')->with(42, 'none', 7)
            ->willReturn(['success' => false, 'message' => 'Cannot apply a template to a read-only zone', 'refusal' => Refusal::INVALID_INPUT, 'code' => ZoneManagementService::ERR_READ_ONLY]);

        $messages = $this->invokeHandler($this->createMock(DomainManagerInterface::class), 42, zoneService: $zoneService);

        $this->assertSame('error', $messages[0]['type']);
        $this->assertStringContainsString('read-only', $messages[0]['content']);
    }

    public function testTemplateChangeSkippedWhenUnchanged(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => '3',
            'current_zone_template' => '3',
        ];

        $zoneService = $this->createMock(ZoneManagementService::class);
        $zoneService->expects($this->never())->method('applyTemplate');

        $this->invokeHandler($this->createMock(DomainManagerInterface::class), 42, zoneService: $zoneService);
    }

    public function testRetrieveZonePostDispatchesRetrieveZoneOnApiBackend(): void
    {
        $_POST = ['retrieve_zone' => '1'];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->once())
            ->method('retrieveZone')
            ->with(42)
            ->willReturn(true);

        $this->invokeHandler($domainManager, 42, $this->domainRepositoryOfType('SLAVE'), ['dns' => ['backend' => 'api']]);
    }

    public function testRetrieveZoneIsRefusedOnSqlBackend(): void
    {
        $_POST = ['retrieve_zone' => '1'];

        // SqlDnsBackendProvider::retrieveZone() always returns false, so the request is refused up front
        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('retrieveZone');

        $this->invokeHandler($domainManager, 42, $this->domainRepositoryOfType('SLAVE'), ['dns' => ['backend' => 'sql']]);
    }

    public function testRetrieveZoneIsRefusedForNonSecondaryZone(): void
    {
        $_POST = ['retrieve_zone' => '1'];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('retrieveZone');

        $this->invokeHandler($domainManager, 42, $this->domainRepositoryOfType('MASTER'), ['dns' => ['backend' => 'api']]);
    }

    public function testNoZoneMetaPostKeysIsNoop(): void
    {
        $_POST = ['unrelated_field' => 'foo'];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $domainManager->expects($this->never())->method('updateZoneRecords');
        $domainManager->expects($this->never())->method('retrieveZone');

        $this->invokeHandler($domainManager, 42);
    }

    private function domainRepositoryOfType(string $type): DomainRepositoryInterface
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn($type);

        return $domainRepository;
    }

    /**
     * @return array<int, array{type: string, content: string}> The messages set for the edit page
     */
    private function invokeHandler(
        DomainManagerInterface $domainManager,
        int $zone_id,
        ?DomainRepositoryInterface $domainRepository = null,
        array $configOverrides = [],
        bool $canCreateZone = true,
        ?ZoneManagementService $zoneService = null
    ): array {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();

        $this->setBaseProperty($controller, 'httpRequest', new Request());
        $this->setProperty($controller, 'domainRepository', $domainRepository ?? $this->createMock(DomainRepositoryInterface::class));
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('canCreateZone')->willReturn($canCreateZone);
        $this->setProperty($controller, 'permissionService', $permissionService);
        $this->session->set('userid', 7);
        $this->setBaseProperty($controller, 'userContextService', new UserContextService($this->session));

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('zoneManagementService')->willReturn($zoneService ?? $this->createMock(ZoneManagementService::class));
        $factory->method('domainManager')->willReturn($domainManager);
        $config = $this->primeConfig($configOverrides);
        $factory->method('dnsBackendProvider')->willReturn($config->get('dns', 'backend') === 'api'
            ? new ApiDnsBackendProvider($this->createMock(PowerdnsApiClient::class), $this->createMock(PDO::class), $config, new NullLogger())
            : new SqlDnsBackendProvider($this->createMock(PDO::class), $config, new NullLogger()));
        $this->setBaseProperty($controller, 'serviceFactory', $factory);

        $this->setBaseProperty($controller, 'config', $config);
        $messages = new MessageService(new UserContextService($this->session));
        $this->setBaseProperty($controller, 'messageService', $messages);

        $method = $this->controllerReflection->getMethod('handleZoneMetadataPost');
        $method->setAccessible(true);
        $method->invoke($controller, $zone_id);

        return $messages->getMessages('edit') ?? [];
    }

    private function primeConfig(array $overrides = []): FakeConfiguration
    {
        return new FakeConfiguration(array_replace_recursive([
            'database' => ['type' => 'mysql'],
            'dns' => ['ttl' => 86400, 'backend' => 'sql'],
            'security' => ['global_token_validation' => false],
        ], $overrides));
    }

    private function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $property = $this->controllerReflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setBaseProperty(object $object, string $propertyName, mixed $value): void
    {
        $baseReflection = new ReflectionClass($this->controllerReflection->getParentClass()->getName());
        $property = $baseReflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
