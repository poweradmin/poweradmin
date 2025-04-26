<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

class ZoneCountTest extends TestCase
{
    private PDOLayer $dbMock;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
    }

    public function testZoneCountNgWithAllZonesAndAllPermissions(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all');
        $this->assertEquals(10, $result);
    }

    public function testZoneCountNgWithForwardZonesOnly(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all', 'all', 'forward');
        $this->assertEquals(8, $result);
    }

    public function testZoneCountNgWithReverseZonesOnly(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all', 'all', 'reverse');
        $this->assertEquals(2, $result);
    }

    public function testZoneCountNgWithOwnPermissionsOnly(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'own');
        $this->assertEquals(3, $result);

        // Clean up session
        unset($_SESSION['userid']);
    }

    public function testZoneCountNgWithLetterFilter(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all', 'a');
        $this->assertEquals(5, $result);
    }

    public function testZoneCountNgWithNumericFilter(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all', 1);
        $this->assertEquals(2, $result);
    }

    public function testZoneCountNgWithDatabasePrefix(): void
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

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'all');
        $this->assertEquals(10, $result);
    }

    public function testZoneCountNgWithInvalidPermissions(): void
    {
        $this->dbMock->expects($this->never())
            ->method('queryOne');

        $result = DnsRecord::zone_count_ng($this->dbMock, $this->configMock, 'invalid');
        $this->assertEquals(0, $result);
    }
}
