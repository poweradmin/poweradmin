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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;

/**
 * Tests for EditController::handleZoneMetadataPost(), which dispatches the
 * three meta-edit POST actions (type change, slave master change, template
 * change) using the Request wrapper instead of $_POST.
 *
 * The test invokes the private method via reflection with the rest of the
 * controller's dependency graph stubbed - the goal is to confirm the
 * Request-based parameter extraction routes to the correct DnsRecord call,
 * not to exercise the full run() pipeline.
 */
class EditControllerZoneMetadataPostTest extends TestCase
{
    private ReflectionClass $controllerReflection;
    private array $configBackup = [];
    private bool $configInitializedBackup = false;
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerReflection = new ReflectionClass(EditController::class);

        $configReflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $configReflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $configReflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        $this->configBackup = $settingsProperty->getValue($config);
        $this->configInitializedBackup = $initializedProperty->getValue($config);

        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $configReflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $configReflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $configReflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $config = ConfigurationManager::getInstance();
        $settingsProperty->setValue($config, $this->configBackup);
        $initializedProperty->setValue($config, $this->configInitializedBackup);

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
            ->willReturn(true);
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
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
            ->willReturn(true);
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
    }

    public function testTemplateChangePostDispatchesUpdateZoneRecords(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => '7',
            'current_zone_template' => '3',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $domainManager->expects($this->once())
            ->method('updateZoneRecords')
            ->with('mysql', 86400, 42, '7');

        $this->invokeHandler($domainManager, 42);
    }

    public function testTemplateChangeNoneIsTreatedAsZero(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => 'none',
            'current_zone_template' => '3',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->once())
            ->method('updateZoneRecords')
            ->with('mysql', 86400, 42, 0);

        $this->invokeHandler($domainManager, 42);
    }

    public function testTemplateChangeSkippedWhenUnchanged(): void
    {
        $_POST = [
            'template_change' => '1',
            'zone_template' => '3',
            'current_zone_template' => '3',
        ];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
    }

    public function testNoZoneMetaPostKeysIsNoop(): void
    {
        $_POST = ['unrelated_field' => 'foo'];

        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');
        $domainManager->expects($this->never())->method('changeZoneSlaveMaster');
        $domainManager->expects($this->never())->method('updateZoneRecords');

        $this->invokeHandler($domainManager, 42);
    }

    private function invokeHandler(DomainManagerInterface $domainManager, int $zone_id): void
    {
        $controller = $this->controllerReflection->newInstanceWithoutConstructor();

        $this->setProperty($controller, 'request', new Request());
        $this->setProperty($controller, 'domainManager', $domainManager);
        $this->setProperty($controller, 'domainRepository', $this->createMock(DomainRepositoryInterface::class));

        $config = $this->primeConfig();
        $this->setBaseProperty($controller, 'config', $config);
        $this->setBaseProperty($controller, 'messageService', new MessageService());

        $method = $this->controllerReflection->getMethod('handleZoneMetadataPost');
        $method->setAccessible(true);
        $method->invoke($controller, $zone_id);
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $settingsProperty->setValue($config, [
            'database' => ['type' => 'mysql'],
            'dns' => ['ttl' => 86400],
            'security' => ['global_token_validation' => false],
        ]);
        $initializedProperty->setValue($config, true);

        return $config;
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
