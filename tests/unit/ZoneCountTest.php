<?php

namespace unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

class ZoneCountTest extends TestCase
{
    private MockObject&PDOCommon $dbMock;
    private MockObject&ConfigurationManager $configMock;
    private ZoneCountService $zoneCountService;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOCommon::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        // Don't create the service here - do it in each test to allow proper mock setup
    }

    public function testCountZonesWithAllZonesAndAllPermissions(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null); // No prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 10]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all');
        $this->assertEquals(10, $result);
    }

    public function testCountZonesWithForwardZonesOnly(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null); // No prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 8]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all', 'all', 'forward');
        $this->assertEquals(8, $result);
    }

    public function testCountZonesWithReverseZonesOnly(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null); // No prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 2]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all', 'all', 'reverse');
        $this->assertEquals(2, $result);
    }

    public function testCountZonesWithOwnPermissionsOnly(): void
    {
        // Set up session for 'own' permission test
        $_SESSION['userid'] = 5;

        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null); // No prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 3]);

        $this->dbMock->expects($this->once())
            ->method('prepare')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('own');
        $this->assertEquals(3, $result);

        // Clean up session
        unset($_SESSION['userid']);
    }

    public function testCountZonesWithLetterFilter(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null); // No prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 5]);

        $this->dbMock->expects($this->once())
            ->method('prepare')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all', 'a');
        $this->assertEquals(5, $result);
    }

    public function testCountZonesWithNumericFilter(): void
    {
        // Configure mocks - This test uses '1' filter which needs database type
        $this->configMock->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return null; // No prefix for tables
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return null;
            });

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 2]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all', '1');
        $this->assertEquals(2, $result);
    }

    public function testCountZonesWithDatabasePrefix(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn('pdns'); // Add prefix for tables

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetch')
            ->willReturn(['count_zones' => 10]);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->zoneCountService->countZones('all');
        $this->assertEquals(10, $result);
    }

    public function testCountZonesWithInvalidPermissions(): void
    {
        // Configure mocks - TableNameService calls get() during construction
        $this->configMock->expects($this->once())
            ->method('get')
            ->with('database', 'pdns_db_name')
            ->willReturn(null);

        // Create service after mock expectations are set
        $this->zoneCountService = new ZoneCountService($this->dbMock, $this->configMock);

        $this->dbMock->expects($this->never())
            ->method('query');

        $result = $this->zoneCountService->countZones('invalid');
        $this->assertEquals(0, $result);
    }
}
