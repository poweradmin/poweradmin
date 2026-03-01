<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;

#[CoversClass(SqlDnsBackendProvider::class)]
class SqlDnsBackendProviderTest extends TestCase
{
    private $mockDb;
    private $mockConfig;
    private SqlDnsBackendProvider $provider;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDOCommon::class);
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);
        $this->mockConfig->method('get')->willReturnMap([
            ['database', 'pdns_db_name', null, ''],
        ]);

        $this->provider = new SqlDnsBackendProvider($this->mockDb, $this->mockConfig);
    }

    public function testIsApiBackendReturnsFalse(): void
    {
        $this->assertFalse($this->provider->isApiBackend());
    }

    // ---------------------------------------------------------------
    // Zone operations
    // ---------------------------------------------------------------

    public function testCreateZoneInsertsAndReturnsId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute');
        $stmt->method('bindValue');

        $this->mockDb->method('prepare')->willReturn($stmt);
        $this->mockDb->method('lastInsertId')->willReturn('42');

        $result = $this->provider->createZone('example.com', 'NATIVE');

        $this->assertEquals(42, $result);
    }

    public function testCreateSlaveZoneSetsMaster(): void
    {
        $stmtInsert = $this->createMock(PDOStatement::class);
        $stmtInsert->expects($this->once())->method('execute');
        $stmtInsert->method('bindValue');

        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtUpdate->expects($this->once())->method('execute');
        $stmtUpdate->method('bindValue');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls($stmtInsert, $stmtUpdate);
        $this->mockDb->method('lastInsertId')->willReturn('43');

        $result = $this->provider->createZone('slave.example.com', 'SLAVE', '192.168.1.1');

        $this->assertEquals(43, $result);
    }

    public function testDeleteZoneDeletesAllRelatedTables(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->exactly(4))->method('execute');

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->deleteZone(1, 'example.com');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeNonSlaveClearsMaster(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return $params[':type'] === 'MASTER'
                    && $params[':master'] === ''
                    && $params[':id'] === 1;
            }));

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateZoneType(1, 'MASTER');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeSlaveDoesNotClearMaster(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return $params[':type'] === 'SLAVE'
                    && !array_key_exists(':master', $params);
            }));

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateZoneType(1, 'SLAVE');

        $this->assertTrue($result);
    }

    public function testUpdateZoneMasterSetsNewMaster(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([':master' => '10.0.0.1', ':id' => 1]);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateZoneMaster(1, '10.0.0.1');

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function testAddRecordInsertsRecord(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute');
        $stmt->method('bindValue');

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertTrue($result);
    }

    public function testAddRecordGetIdReturnsNewId(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute');
        $stmt->method('bindValue');

        $this->mockDb->method('prepare')->willReturn($stmt);
        $this->mockDb->method('lastInsertId')->willReturn('100');

        $result = $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertEquals(100, $result);
    }

    public function testEditRecordUpdatesRecord(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with(['www.example.com', 'A', '10.0.0.1', 7200, 0, 0, 5]);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertTrue($result);
    }

    public function testDeleteRecordDeletesById(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([5]);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->deleteRecord(5);

        $this->assertTrue($result);
    }

    public function testDeleteRecordsByDomainIdDeletesAll(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with([':id' => 1]);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->deleteRecordsByDomainId(1);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // SOA operations
    // ---------------------------------------------------------------

    public function testUpdateSOASerialReturnsTrue(): void
    {
        $result = $this->provider->updateSOASerial(1);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Supermaster operations
    // ---------------------------------------------------------------

    public function testAddSupermasterInserts(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([':ip' => '192.168.1.1', ':ns' => 'ns1.example.com', ':account' => 'admin']);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertTrue($result);
    }

    public function testDeleteSupermasterDeletes(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([':ip' => '192.168.1.1', ':ns' => 'ns1.example.com']);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->deleteSupermaster('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result);
    }

    public function testGetSupermastersReturnsFormattedArray(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['ip' => '192.168.1.1', 'nameserver' => 'ns1.example.com', 'account' => 'admin'],
                false
            );

        $this->mockDb->method('query')->willReturn($stmt);

        $result = $this->provider->getSupermasters();

        $this->assertCount(1, $result);
        $this->assertEquals('192.168.1.1', $result[0]['master_ip']);
        $this->assertEquals('ns1.example.com', $result[0]['ns_name']);
        $this->assertEquals('admin', $result[0]['account']);
    }

    public function testUpdateSupermasterExecutesUpdate(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':new_ip' => '10.0.0.1',
                ':new_ns' => 'ns2.example.com',
                ':account' => 'admin',
                ':old_ip' => '192.168.1.1',
                ':old_ns' => 'ns1.example.com',
            ]);

        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateSupermaster(
            '192.168.1.1', 'ns1.example.com',
            '10.0.0.1', 'ns2.example.com', 'admin'
        );

        $this->assertTrue($result);
    }
}
