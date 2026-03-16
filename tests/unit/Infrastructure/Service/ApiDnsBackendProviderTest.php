<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\ValueObject\RecordIdentifier;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

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
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);

        $this->provider = new ApiDnsBackendProvider(
            $this->mockClient,
            $this->mockDb,
            $this->mockConfig,
            new NullLogger()
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

        // Mock: SELECT id FROM zones WHERE zone_name = :name -> returns existing id 42
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

    public function testCreateZoneInsertsNewEntryWhenNotExists(): void
    {
        $this->mockClient->expects($this->once())
            ->method('createZoneWithData')
            ->willReturn(['name' => 'newzone.com.']);

        // First prepare: SELECT id FROM zones WHERE zone_name = :name -> false (not found)
        $stmtSelect = $this->createMock(PDOStatement::class);
        $stmtSelect->method('execute');
        $stmtSelect->method('fetchColumn')->willReturn(false);

        // Second prepare: INSERT INTO zones
        $stmtInsert = $this->createMock(PDOStatement::class);
        $stmtInsert->method('bindValue');
        $stmtInsert->method('execute');

        // Third prepare: UPDATE zones SET domain_id = :id WHERE id = :id
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtUpdate->method('bindValue');
        $stmtUpdate->method('execute');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtSelect,
            $stmtInsert,
            $stmtUpdate
        );

        $this->mockDb->method('lastInsertId')->willReturn('7');

        $result = $this->provider->createZone('newzone.com', 'NATIVE');

        $this->assertEquals(7, $result);
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
        // Mock getZoneNameByLocalId: SELECT zone_name FROM zones WHERE id = :id OR domain_id = :did
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn('example.com');
        $stmt->method('bindValue');

        // Second prepare for UPDATE zones SET zone_type
        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtUpdate->method('bindValue');
        $stmtUpdate->method('execute');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls($stmt, $stmtUpdate);

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
        $stmt->method('bindValue');

        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtUpdate->method('bindValue');
        $stmtUpdate->method('execute');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls($stmt, $stmtUpdate);

        $this->mockClient->expects($this->once())
            ->method('updateZoneProperties')
            ->with('example.com.', ['kind' => 'SLAVE'])
            ->willReturn(true);

        $result = $this->provider->updateZoneType(1, 'SLAVE');

        $this->assertTrue($result);
    }

    public function testUpdateZoneTypeReturnsFalseWhenZoneNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $stmt->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->updateZoneType(999, 'MASTER');

        $this->assertFalse($result);
    }

    public function testUpdateZoneMasterCallsApiWithMasterIp(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn('slave.example.com');
        $stmt->method('bindValue');

        $stmtUpdate = $this->createMock(PDOStatement::class);
        $stmtUpdate->method('bindValue');
        $stmtUpdate->method('execute');

        $this->mockDb->method('prepare')->willReturnOnConsecutiveCalls($stmt, $stmtUpdate);

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
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

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

    public function testAddRecordReturnsFalseWhenZoneNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(false);
        $stmt->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmt);

        $result = $this->provider->addRecord(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertFalse($result);
    }

    public function testDeleteRecordRemovesLastRecordInRRset(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        // Mock getZoneIdByName for internal lookups
        $stmtZoneId = $this->createMock(PDOStatement::class);
        $stmtZoneId->method('execute');
        $stmtZoneId->method('fetchColumn')->willReturn(1);
        $this->mockDb->method('prepare')->willReturn($stmtZoneId);

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

        $result = $this->provider->deleteRecord($encodedId);

        $this->assertTrue($result);
    }

    public function testDeleteRecordReplacesRRsetWithRemainingRecords(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $stmtZoneId = $this->createMock(PDOStatement::class);
        $stmtZoneId->method('execute');
        $stmtZoneId->method('fetchColumn')->willReturn(1);
        $this->mockDb->method('prepare')->willReturn($stmtZoneId);

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

        $result = $this->provider->deleteRecord($encodedId);

        $this->assertTrue($result);
    }

    public function testDeleteRecordReturnsFalseForNonEncodedId(): void
    {
        // Non-encoded integer ID should return false in API mode
        $result = $this->provider->deleteRecord(999);

        $this->assertFalse($result);
    }

    public function testDeleteRecordAbortsWhenApiFetchFails(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->deleteRecord($encodedId);

        $this->assertFalse($result);
    }

    public function testEditRecordSameRRsetRebuilds(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

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

        $result = $this->provider->editRecord($encodedId, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertTrue($result);
    }

    public function testEditRecordReturnsFalseForNonEncodedId(): void
    {
        $result = $this->provider->editRecord(5, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertFalse($result);
    }

    public function testEditRecordAbortsWhenApiFetchFails(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->editRecord($encodedId, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertFalse($result);
    }

    public function testEditRecordNameChangeMovesAcrossRRsets(): void
    {
        // Old record at www.example.com A, moving to web.example.com A
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

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

        $result = $this->provider->editRecord($encodedId, 'web.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertTrue($result);
    }

    public function testEditRecordFailsWhenOldRecordNotFoundInApi(): void
    {
        // The encoded ID references a record, but API RRset doesn't contain it (stale state)
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

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

        // Should NOT call patchZoneRRsets since the old record wasn't found
        $this->mockClient->expects($this->never())
            ->method('patchZoneRRsets');

        $result = $this->provider->editRecord($encodedId, 'www.example.com', 'A', '10.0.0.1', 7200, 0, 0);

        $this->assertFalse($result, 'editRecord should return false when encoded record content is not found in RRset');
    }

    public function testEditRecordWithDisabledFlag(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

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
        $result = $this->provider->editRecord($encodedId, 'www.example.com', 'A', '192.168.1.1', 3600, 0, 1);

        $this->assertTrue($result);
    }

    public function testEditRecordNameChangeWithNewRRsetApiFetchFailure(): void
    {
        $encodedId = RecordIdentifier::encode('example.com', 'www.example.com', 'A', '192.168.1.1', 0);

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
        $result = $this->provider->editRecord($encodedId, 'web.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // addRecordGetId operations
    // ---------------------------------------------------------------

    public function testAddRecordGetIdReturnsEncodedIdOnSuccess(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->method('patchZoneRRsets')->willReturn(true);

        $result = $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        // Should return an encoded string, not an integer
        $this->assertIsString($result);
        $this->assertTrue(RecordIdentifier::isEncoded($result));

        // Decode and verify the contents
        $decoded = RecordIdentifier::decode($result);
        $this->assertEquals('example.com', $decoded['zone_name']);
        $this->assertEquals('www.example.com', $decoded['name']);
        $this->assertEquals('A', $decoded['type']);
        $this->assertEquals('192.168.1.1', $decoded['content']);
        $this->assertEquals(0, $decoded['prio']);
    }

    public function testAddRecordGetIdReturnsNullWhenAddRecordFails(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        // API fetch fails -> addRecord returns false
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $result = $this->provider->addRecordGetId(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertNull($result);
    }

    // ---------------------------------------------------------------
    // createRecordAtomic
    // ---------------------------------------------------------------

    public function testCreateRecordAtomicReturnsEncodedIdOnSuccess(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->method('patchZoneRRsets')->willReturn(true);

        $result = $this->provider->createRecordAtomic(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertIsString($result);
        $this->assertTrue(RecordIdentifier::isEncoded($result));

        $decoded = RecordIdentifier::decode($result);
        $this->assertEquals('example.com', $decoded['zone_name']);
        $this->assertEquals('www.example.com', $decoded['name']);
        $this->assertEquals('A', $decoded['type']);
    }

    public function testCreateRecordAtomicReturnsNullWhenApiFails(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        // API fetch fails
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(null);

        $result = $this->provider->createRecordAtomic(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0);

        $this->assertNull($result);
    }

    public function testCreateRecordAtomicReturnsNullWhenPatchFails(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        $this->mockClient->method('patchZoneRRsets')->willReturn(false);

        $result = $this->provider->createRecordAtomic(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0, 0);

        $this->assertNull($result);
    }

    public function testCreateRecordAtomicWithDisabledFlag(): void
    {
        // Mock getZoneNameByLocalId
        $stmtZone = $this->createMock(PDOStatement::class);
        $stmtZone->method('execute');
        $stmtZone->method('fetchColumn')->willReturn('example.com');
        $stmtZone->method('bindValue');
        $this->mockDb->method('prepare')->willReturn($stmtZone);

        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn(['rrsets' => []]);

        // Should include disabled=true in the record
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

        $result = $this->provider->createRecordAtomic(1, 'www.example.com', 'A', '192.168.1.1', 3600, 0, 1);

        $this->assertIsString($result);
        $this->assertTrue(RecordIdentifier::isEncoded($result));
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
            '192.168.1.1',
            'ns1.example.com',
            '10.0.0.1',
            'ns2.example.com',
            'admin'
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
            '192.168.1.1',
            'ns1.example.com',
            '10.0.0.1',
            'ns2.example.com',
            'admin'
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
            '192.168.1.1',
            'ns1.example.com',
            '192.168.1.1',
            'ns1.example.com',
            'newaccount'
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
            '192.168.1.1',
            'ns1.example.com',
            '192.168.1.1',
            'ns1.example.com',
            'newaccount'
        );

        $this->assertFalse($result);
    }

    public function testDeleteRecordsByDomainIdReturnsTrue(): void
    {
        $result = $this->provider->deleteRecordsByDomainId(1);

        $this->assertTrue($result);
    }

    // ---------------------------------------------------------------
    // Zone records with encoded IDs
    // ---------------------------------------------------------------

    public function testGetZoneRecordsReturnsEncodedIds(): void
    {
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

        $records = $this->provider->getZoneRecords(1, 'example.com');

        $this->assertCount(2, $records);

        // Each record should have an encoded string ID
        foreach ($records as $record) {
            $this->assertIsString($record['id']);
            $this->assertTrue(RecordIdentifier::isEncoded($record['id']));
            $this->assertEquals(1, $record['domain_id']);
            $this->assertEquals('www.example.com', $record['name']);
            $this->assertEquals('A', $record['type']);
        }

        $this->assertEquals('192.168.1.1', $records[0]['content']);
        $this->assertEquals('192.168.1.2', $records[1]['content']);

        // IDs should be different (different content)
        $this->assertNotEquals($records[0]['id'], $records[1]['id']);
    }

    public function testGetZoneRecordsExtractsMxPriority(): void
    {
        $this->mockClient->method('getZone')
            ->with('example.com.')
            ->willReturn([
                'rrsets' => [
                    [
                        'name' => 'example.com.',
                        'type' => 'MX',
                        'ttl' => 3600,
                        'records' => [
                            ['content' => '10 mail.example.com.', 'disabled' => false],
                        ],
                    ],
                ],
            ]);

        $records = $this->provider->getZoneRecords(1, 'example.com');

        $this->assertCount(1, $records);
        $this->assertEquals(10, $records[0]['prio']);
        // MX content should have trailing dot stripped
        $this->assertEquals('mail.example.com', $records[0]['content']);
    }

    // ---------------------------------------------------------------
    // searchDnsData uses local zones table
    // ---------------------------------------------------------------

    public function testSearchDnsDataUsesLocalZonesTable(): void
    {
        $this->mockClient->method('searchData')
            ->willReturn([
                [
                    'object_type' => 'zone',
                    'name' => 'example.com.',
                    'kind' => 'NATIVE',
                ],
                [
                    'object_type' => 'record',
                    'name' => 'www.example.com.',
                    'type' => 'A',
                    'content' => '192.168.1.1',
                    'ttl' => 3600,
                    'zone' => 'example.com.',
                    'disabled' => false,
                ],
            ]);

        // Mock local zones query
        $stmtZones = $this->createMock(PDOStatement::class);
        $stmtZones->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 1, 'domain_id' => 1, 'zone_name' => 'example.com'],
            false
        );
        $this->mockDb->method('query')->willReturn($stmtZones);

        $result = $this->provider->searchDnsData('example');

        $this->assertCount(1, $result['zones']);
        $this->assertEquals(1, $result['zones'][0]['id']);
        $this->assertEquals('example.com', $result['zones'][0]['name']);

        $this->assertCount(1, $result['records']);
        $this->assertEquals(1, $result['records'][0]['domain_id']);
        $this->assertEquals('www.example.com', $result['records'][0]['name']);
        // Record ID should be encoded
        $this->assertIsString($result['records'][0]['id']);
        $this->assertTrue(RecordIdentifier::isEncoded($result['records'][0]['id']));
    }

    // ---------------------------------------------------------------
    // getZones enriches with local data
    // ---------------------------------------------------------------

    public function testGetZonesEnrichesWithLocalZonesData(): void
    {
        $mockZone = $this->createMock(\Poweradmin\Domain\Model\Zone::class);
        $mockZone->method('getName')->willReturn('example.com.');
        $mockZone->method('isSecured')->willReturn(false);

        $this->mockClient->method('getAllZones')->willReturn([$mockZone]);

        // Mock local zones query
        $stmtZones = $this->createMock(PDOStatement::class);
        $stmtZones->method('fetch')->willReturnOnConsecutiveCalls(
            ['id' => 5, 'domain_id' => 5, 'zone_name' => 'example.com', 'zone_type' => 'NATIVE', 'zone_master' => ''],
            false
        );
        $this->mockDb->method('query')->willReturn($stmtZones);

        $result = $this->provider->getZones();

        $this->assertCount(1, $result);
        $this->assertEquals(5, $result[0]['id']);
        $this->assertEquals('example.com', $result[0]['name']);
        $this->assertEquals('NATIVE', $result[0]['type']);
    }
}
