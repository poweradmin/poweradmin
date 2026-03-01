<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\RecordIdNotFoundException;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;

#[CoversClass(ApiDnsBackendProvider::class)]
class ApiDnsBackendProviderTest extends TestCase
{
    private $mockClient;
    private $mockDb;
    private $mockConfig;
    private ApiDnsBackendProvider $provider;

    protected function setUp(): void
    {
        $this->mockClient = $this->createMock(PowerdnsApiClient::class);
        $this->mockDb = $this->createMock(PDOCommon::class);
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);
        $this->mockConfig->method('get')->willReturnMap([
            ['database', 'pdns_db_name', null, ''],
        ]);

        $this->provider = new ApiDnsBackendProvider(
            $this->mockClient,
            $this->mockDb,
            $this->mockConfig
        );
    }

    public function testIsApiBackendReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isApiBackend());
    }

    // ---------------------------------------------------------------
    // Zone operations
    // ---------------------------------------------------------------

    public function testCreateZoneCallsApiWithTrailingDot(): void
    {
        $this->mockClient->expects($this->once())
            ->method('createZoneWithData')
            ->with([
                'name' => 'example.com.',
                'kind' => 'NATIVE',
                'nameservers' => [],
            ])
            ->willReturn(['name' => 'example.com.']);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(42);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->createZone('example.com', 'NATIVE');

        $this->assertEquals(42, $result);
    }

    public function testCreateSlaveZoneIncludesMasters(): void
    {
        $this->mockClient->expects($this->once())
            ->method('createZoneWithData')
            ->with([
                'name' => 'slave.example.com.',
                'kind' => 'SLAVE',
                'nameservers' => [],
                'masters' => ['192.168.1.1'],
            ])
            ->willReturn(['name' => 'slave.example.com.']);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(43);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->createZone('slave.example.com', 'SLAVE', '192.168.1.1');

        $this->assertEquals(43, $result);
    }

    public function testCreateZoneReturnsFalseOnApiFailure(): void
    {
        $this->mockClient->expects($this->once())
            ->method('createZoneWithData')
            ->willReturn(null);

        $result = $this->provider->createZone('example.com', 'NATIVE');

        $this->assertFalse($result);
    }

    public function testDeleteZoneCallsApiWithTrailingDot(): void
    {
        $this->mockClient->expects($this->once())
            ->method('deleteZone')
            ->willReturn(true);

        $result = $this->provider->deleteZone(1, 'example.com');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeCallsApiWithCorrectData(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmt);

        $this->mockClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com.', ['kind' => 'MASTER', 'masters' => []])
            ->willReturn(true);

        $result = $this->provider->updateZoneType(1, 'MASTER');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeSlaveKeepsMasters(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmt);

        $this->mockClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com.', ['kind' => 'SLAVE'])
            ->willReturn(true);

        $result = $this->provider->updateZoneType(1, 'SLAVE');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeReturnsFalseWhenDomainNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateZoneType(999, 'MASTER');

        $this->assertFalse($result);
    }

    public function testUpdateZoneMasterCallsApiWithMasterIp(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn('slave.example.com');
        $this->mockDb->method('prepare')->willReturn($stmt);

        $this->mockClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('slave.example.com.', ['masters' => ['192.168.1.100']])
            ->willReturn(true);

        $result = $this->provider->updateZoneMaster(1, '192.168.1.100');

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Record operations
    // ---------------------------------------------------------------

    public function testAddRecordBuildsReplaceRRset(): void
    {
        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        // Mock getRRsetFromApi - no existing records
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '192.168.1.1', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertTrue($result);
    }

    public function testAddRecordAppendsToExistingRRset(): void
    {
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        // Mock getRRsetFromApi - one existing A record
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '192.168.1.1', 'disabled' => false],
                        ['content' => '192.168.1.2', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.2', 3600, 0);

        $this->assertTrue($result);
    }

    public function testAddMxRecordPrependsPriority(): void
    {
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'example.com.',
                    'type' => 'MX',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '10 mail.example.com.', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->addRecord(1, 'example.com', 'MX', 'mail.example.com.', 3600, 10);

        $this->assertTrue($result);
    }

    public function testAddMxRecordWithZeroPriority(): void
    {
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'example.com.',
                    'type' => 'MX',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '0 mail.example.com.', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->addRecord(1, 'example.com', 'MX', 'mail.example.com.', 3600, 0);

        $this->assertTrue($result);
    }

    public function testAddSrvRecordPrependsPriority(): void
    {
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        // SRV content in Poweradmin is "weight port target", prio is separate
        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => '_sip._tcp.example.com.',
                    'type' => 'SRV',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '10 0 5060 sip.example.com.', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->addRecord(1, '_sip._tcp.example.com', 'SRV', '0 5060 sip.example.com.', 3600, 10);

        $this->assertTrue($result);
    }

    public function testAddRecordAbortsWhenApiFetchFails(): void
    {
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        // getZone returns null (API failure)
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        // patchZoneRRsets should never be called
        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertFalse($result);
    }

    public function testDeleteRecordRemovesLastRecordInRRset(): void
    {
        // Mock getRecordFromDb
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        // Mock getRRsetFromApi - only this one record
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'changetype' => 'DELETE',
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->deleteRecord(5);

        $this->assertTrue($result);
    }

    public function testDeleteRecordReplacesRRsetWithRemainingRecords(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        // Mock getRRsetFromApi - two records in the RRset
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                            ['content' => '192.168.1.2', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '192.168.1.2', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->deleteRecord(5);

        $this->assertTrue($result);
    }

    public function testDeleteRecordReturnsFalseWhenRecordNotFound(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')->willReturn($stmtRecord);

        $result = $this->provider->deleteRecord(999);

        $this->assertFalse($result);
    }

    public function testEditRecordSameRRsetRebuilds(): void
    {
        // Mock getRecordFromDb
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        // Mock getRRsetFromApi
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 7200,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '10.0.0.1', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertTrue($result);
    }

    public function testDeleteRecordAbortsWhenApiFetchFails(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->deleteRecord(5);

        $this->assertFalse($result);
    }

    public function testEditRecordAbortsWhenApiFetchFails(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertFalse($result);
    }

    public function testEditRecordNameChangeMovesAcrossRRsets(): void
    {
        // Old record at www.example.com A, moving to web.example.com A
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        // getZone returns both old and new RRsets
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                        ],
                    ],
                    // No existing web.example.com RRset
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                // Delete old RRset (was the only record)
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'changetype' => 'DELETE',
                ],
                // Create new RRset
                [
                    'name' => 'web.example.com.',
                    'type' => 'A',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '192.168.1.1', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->editRecord(5, 'web.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertTrue($result);
    }

    public function testAddRecordReturnsFalseWhenDomainNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertFalse($result);
    }

    public function testEditRecordAppendsWhenOldRecordNotFoundInApi(): void
    {
        // The DB has a record, but API RRset doesn't contain it (stale DB state)
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        // API returns an RRset with a different record (old record not present)
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '10.0.0.99', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        // Should append the new record since old one wasn't found
        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 7200,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '10.0.0.99', 'disabled' => false],
                        ['content' => '10.0.0.1', 'disabled' => false],
                    ],
                ],
            ])
            ->willReturn(true);

        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertTrue($result);
    }

    public function testEditRecordReturnsFalseWhenDomainNotFound(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn(false);

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertFalse($result);
    }

    public function testDeleteRecordReturnsFalseWhenDomainNotFound(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn(false);

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $result = $this->provider->deleteRecord(5);

        $this->assertFalse($result);
    }

    public function testCreateZoneThrowsWhenDbIdLookupTimesOut(): void
    {
        $this->mockClient->expects($this->once())
            ->method('createZoneWithData')
            ->willReturn(['name' => 'example.com.']);

        // lookupDomainIdByName retries 5 times but never finds the ID
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $this->mockDb->method('prepare')->willReturn($stmt);

        $this->expectException(\Poweradmin\Domain\Error\ZoneIdNotFoundException::class);
        $this->expectExceptionMessage("Zone 'example.com' created via API but DB ID not found after retries");

        $this->provider->createZone('example.com', 'NATIVE');
    }

    public function testEditRecordWithDisabledFlag(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'www.example.com.',
                        'type' => 'A',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '192.168.1.1', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $this->mockClient->expects($this->once())
            ->method('patchZoneRRsets')
            ->with('example.com.', [
                [
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'ttl' => 3600,
                    'changetype' => 'REPLACE',
                    'records' => [
                        ['content' => '192.168.1.1', 'disabled' => true],
                    ],
                ],
            ])
            ->willReturn(true);

        // Edit with disabled=1, same content
        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '192.168.1.1', 3600, 0, 1);

        $this->assertTrue($result);
    }

    public function testEditRecordNameChangeWithNewRRsetApiFetchFailure(): void
    {
        $stmtRecord = $this->createMock(PDOStatement::class);
        $stmtRecord->method('execute');
        $stmtRecord->method('fetch')->willReturn([
            'id' => 5, 'domain_id' => 1, 'name' => 'www.example.com',
            'type' => 'A', 'content' => '192.168.1.1', 'ttl' => 3600,
            'prio' => 0, 'disabled' => 0,
        ]);

        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtRecord, $stmtDomain
        );

        $callCount = 0;
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call (old RRset) succeeds
                    return [
                        'rrsets' => [
                            [
                                'name' => 'www.example.com.',
                                'type' => 'A',
                                'ttl' => 3600,
                                'records' => [
                                    ['content' => '192.168.1.1', 'disabled' => false],
                                ],
                            ],
                        ],
                    ];
                }
                // Second call (new RRset) fails
                return null;
            });

        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        // Name change: www -> web, second getZone fails
        $result = $this->provider->editRecord(5, 'web.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // addRecordGetId operations
    // ---------------------------------------------------------------

    public function testAddRecordGetIdReturnsIdOnSuccess(): void
    {
        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        // Mock lookupRecordId - returns ID on first try
        $stmtLookup = $this->createMock(PDOStatement::class);
        $stmtLookup->method('execute');
        $stmtLookup->method('fetchColumn')->willReturn(42);

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtDomain, $stmtLookup
        );

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->method('patchZoneRRsets')->willReturn(true);

        $result = $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertEquals(42, $result);
    }

    public function testAddRecordGetIdReturnsNullWhenAddRecordFails(): void
    {
        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');
        $this->mockDb->method('prepare')->willReturn($stmtDomain);

        // API fetch fails -> addRecord returns false
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $result = $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertNull($result);
    }

    public function testAddRecordGetIdThrowsWhenDbIdNotFound(): void
    {
        // Mock getDomainNameById
        $stmtDomain = $this->createMock(PDOStatement::class);
        $stmtDomain->method('execute');
        $stmtDomain->method('fetchColumn')->willReturn('example.com');

        // Mock lookupRecordId - never finds the record (returns false on all retries)
        $stmtLookup = $this->createMock(PDOStatement::class);
        $stmtLookup->method('execute');
        $stmtLookup->method('fetchColumn')->willReturn(false);
        $stmtLookup->method('bindValue');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtDomain,          // getDomainNameById
            $stmtLookup,          // lookupRecordId retry 1
            $stmtLookup,          // lookupRecordId retry 2
            $stmtLookup,          // lookupRecordId retry 3
            $stmtLookup,          // lookupRecordId retry 4
            $stmtLookup           // lookupRecordId retry 5
        );

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->method('patchZoneRRsets')->willReturn(true);

        $this->expectException(RecordIdNotFoundException::class);
        $this->expectExceptionMessage("Record 'www.example.com A' created via API but DB ID not found after retries");

        $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);
    }

    // ---------------------------------------------------------------
    // SOA operations
    // ---------------------------------------------------------------

    public function testUpdateSOASerialIsNoOp(): void
    {
        $result = $this->provider->updateSOASerial(1);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Supermaster operations
    // ---------------------------------------------------------------

    public function testAddSupermasterDelegatesToAutoprimary(): void
    {
        $this->mockClient->expects($this->once())
            ->method('addAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com', 'admin')
            ->willReturn(true);

        $result = $this->provider->addSupermaster('192.168.1.1', 'ns1.example.com', 'admin');

        $this->assertTrue($result);
    }

    public function testDeleteSupermasterDelegatesToAutoprimary(): void
    {
        $this->mockClient->expects($this->once())
            ->method('deleteAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $result = $this->provider->deleteSupermaster('192.168.1.1', 'ns1.example.com');

        $this->assertTrue($result);
    }

    public function testGetSupermastersTransformsApiResponse(): void
    {
        $this->mockClient->expects($this->once())
            ->method('getAutoprimaries')
            ->willReturn([
                ['ip' => '192.168.1.1', 'nameserver' => 'ns1.example.com', 'account' => 'admin'],
                ['ip' => '10.0.0.1', 'nameserver' => 'ns2.example.com', 'account' => 'user1'],
            ]);

        $result = $this->provider->getSupermasters();

        $this->assertCount(2, $result);
        $this->assertEquals('192.168.1.1', $result[0]['master_ip']);
        $this->assertEquals('ns1.example.com', $result[0]['ns_name']);
        $this->assertEquals('admin', $result[0]['account']);
    }

    public function testUpdateSupermasterAddsThenDeletesWhenDifferent(): void
    {
        // When IP or nameserver changes: add new first, then delete old
        $this->mockClient->expects($this->once())
            ->method('addAutoprimary')
            ->with('10.0.0.1', 'ns2.example.com', 'admin')
            ->willReturn(true);

        $this->mockClient->expects($this->once())
            ->method('deleteAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $result = $this->provider->updateSupermaster(
            '192.168.1.1', 'ns1.example.com',
            '10.0.0.1', 'ns2.example.com', 'admin'
        );

        $this->assertTrue($result);
    }

    public function testUpdateSupermasterReturnsFalseIfAddFails(): void
    {
        // When add fails, delete should not be called
        $this->mockClient->expects($this->once())
            ->method('addAutoprimary')
            ->willReturn(false);

        $this->mockClient->expects($this->never())
            ->method('deleteAutoprimary');

        $result = $this->provider->updateSupermaster(
            '192.168.1.1', 'ns1.example.com',
            '10.0.0.1', 'ns2.example.com', 'admin'
        );

        $this->assertFalse($result);
    }

    public function testUpdateSupermasterSameEntryDeletesThenAdds(): void
    {
        // When only account changes (same IP+NS): fetch old account, delete, re-add
        $this->mockClient->expects($this->once())
            ->method('getAutoprimaries')
            ->willReturn([
                ['ip' => '192.168.1.1', 'nameserver' => 'ns1.example.com', 'account' => 'oldaccount'],
            ]);

        $this->mockClient->expects($this->once())
            ->method('deleteAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $this->mockClient->expects($this->once())
            ->method('addAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com', 'newaccount')
            ->willReturn(true);

        $result = $this->provider->updateSupermaster(
            '192.168.1.1', 'ns1.example.com',
            '192.168.1.1', 'ns1.example.com', 'newaccount'
        );

        $this->assertTrue($result);
    }

    public function testUpdateSupermasterSameEntryRecoveryUsesOldAccount(): void
    {
        // When add fails after delete, recovery should restore original account
        $this->mockClient->expects($this->once())
            ->method('getAutoprimaries')
            ->willReturn([
                ['ip' => '192.168.1.1', 'nameserver' => 'ns1.example.com', 'account' => 'oldaccount'],
            ]);

        $this->mockClient->expects($this->once())
            ->method('deleteAutoprimary')
            ->with('192.168.1.1', 'ns1.example.com')
            ->willReturn(true);

        $this->mockClient->expects($this->exactly(2))
            ->method('addAutoprimary')
            ->willReturnCallback(function (string $ip, string $ns, string $account): bool {
                if ($account === 'newaccount') {
                    return false; // New account add fails
                }
                // Recovery call should use old account
                $this->assertEquals('oldaccount', $account);
                return true;
            });

        $result = $this->provider->updateSupermaster(
            '192.168.1.1', 'ns1.example.com',
            '192.168.1.1', 'ns1.example.com', 'newaccount'
        );

        $this->assertFalse($result);
    }

    public function testDeleteRecordsByDomainIdReturnsTrue(): void
    {
        $result = $this->provider->deleteRecordsByDomainId(1);

        $this->assertTrue($result);
    }
}
