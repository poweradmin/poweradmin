<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

class ZoneCountTest extends TestCase
{
    private PDOLayer $dbMock;
    private ConfigurationManager $configMock;
    private ZoneCountService $zoneCountService;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);
    }

    public function testCountZonesWithAllZonesAndAllPermissions(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(10);

        $result = $this->zoneCountService->countZones('all');
        $this->assertEquals(10, $result);
    }

    public function testCountZonesWithForwardZonesOnly(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(8);

        $result = $this->zoneCountService->countZones('all', 'all', 'forward');
        $this->assertEquals(8, $result);
    }

    public function testCountZonesWithReverseZonesOnly(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(2);

        $result = $this->zoneCountService->countZones('all', 'all', 'reverse');
        $this->assertEquals(2, $result);
    }

    public function testCountZonesWithOwnPermissionsOnly(): void
    {
        // Set up session for 'own' permission test
        $_SESSION['userid'] = 5;

        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(3);

        $result = $this->zoneCountService->countZones('own');
        $this->assertEquals(3, $result);

        // Clean up session
        unset($_SESSION['userid']);
    }

    public function testCountZonesWithLetterFilter(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(5);

        $result = $this->zoneCountService->countZones('all', 'a');
        $this->assertEquals(5, $result);
    }

    public function testCountZonesWithNumericFilter(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(2);

        $result = $this->zoneCountService->countZones('all', '1');
        $this->assertEquals(2, $result);
    }

    public function testCountZonesWithDatabasePrefix(): void
    {
        // Configure mocks
        $this->configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_name') {
                    return 'pdns'; // Add prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(10);

        $result = $this->zoneCountService->countZones('all');
        $this->assertEquals(10, $result);
    }

    public function testCountZonesWithInvalidPermissions(): void
    {
        $this->dbMock->expects($this->never())
            ->method('queryOne');

        $result = $this->zoneCountService->countZones('invalid');
        $this->assertEquals(0, $result);
    }
}
