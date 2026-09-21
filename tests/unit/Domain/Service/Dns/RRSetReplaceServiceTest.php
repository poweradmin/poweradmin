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
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\AuditLoggerInterface;
use Poweradmin\Domain\Service\BackendCapabilitiesInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use RuntimeException;

class RRSetReplaceServiceTest extends TestCase
{
    private const ZONE_ID = 42;
    private const ZONE_NAME = 'example.com';

    private PDO&MockObject $db;
    private BackendCapabilitiesInterface&MockObject $backend;
    private DnsRecordValidationServiceInterface&MockObject $validator;
    private RecordRepositoryInterface&MockObject $records;
    private RecordManagerInterface&MockObject $manager;
    private SOARecordManagerInterface&MockObject $soa;
    private AuditLoggerInterface&MockObject $audit;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];

        $this->db = $this->createMock(PDO::class);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $this->db->method($method)->willReturnCallback(function () use ($method) {
                $this->calls[] = $method;
                return true;
            });
        }

        $this->backend = $this->createMock(BackendCapabilitiesInterface::class);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);

        $this->validator = $this->createMock(DnsRecordValidationServiceInterface::class);
        $this->validator->method('validateRecord')->willReturnCallback(
            function ($rid, $zid, $type, $content, $name, $prio, $ttl) {
                $this->calls[] = "validate:$content";
                return ValidationResult::success(['content' => $content, 'ttl' => $ttl, 'prio' => $prio]);
            }
        );

        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->records->method('getRRSetRecords')->willReturn([['id' => 5], ['id' => 6]]);

        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturnCallback(function ($id) {
            $this->calls[] = "delete:$id";
            return RecordWriteResult::ok();
        });
        $this->manager->method('addRecordGetId')->willReturnCallback(function ($zoneId, $name, $type, $content) {
            $this->calls[] = "add:$content";
            return RecordWriteResult::ok(99);
        });
        $this->manager->method('finalizeZone')->willReturnCallback(function () {
            $this->calls[] = 'finalizeZone';
        });

        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->soa->method('updateSOASerial')->willReturnCallback(function () {
            $this->calls[] = 'updateSOASerial';
            return true;
        });

        $this->audit = $this->createMock(AuditLoggerInterface::class);
    }

    private function service(): RRSetReplaceService
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn($group, $key) => $key === 'hostmaster' ? 'hostmaster@example.com' : 86400);

        return new RRSetReplaceService($this->db, $config, $this->backend, $this->validator, $this->records, $this->manager, $this->soa, $this->audit);
    }

    /**
     * @param list<string> $contents
     * @return list<array{content: string, priority: int, disabled: int}>
     */
    private static function input(array $contents): array
    {
        return array_map(fn(string $c) => ['content' => $c, 'priority' => 0, 'disabled' => 0], $contents);
    }

    public function testBumpsTheSerialInsideTheTransactionAndRectifiesAfterTheCommit(): void
    {
        $this->audit->expects($this->once())->method('logApiRrsetReplace')->with(self::ZONE_ID, 'www.example.com', 'A', 2);

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1', '192.0.2.2']));

        $this->assertSame([
            'beginTransaction',
            'validate:192.0.2.1',
            'validate:192.0.2.2',
            'delete:5',
            'delete:6',
            'add:192.0.2.1',
            'add:192.0.2.2',
            'updateSOASerial',
            'commit',
            'finalizeZone',
        ], $this->calls);
        $this->assertSame([
            'success' => true,
            'message' => 'RRSet replaced successfully',
            'status' => 200,
            'name' => 'www.example.com',
            'records' => [
                ['content' => '192.0.2.1', 'ttl' => 300, 'priority' => 0, 'disabled' => 0],
                ['content' => '192.0.2.2', 'ttl' => 300, 'priority' => 0, 'disabled' => 0],
            ],
        ], $result);
    }

    public function testASoaReplacementDoesNotBumpTheSerialSeparately(): void
    {
        $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'example.com', 'SOA', 300, self::input(['ns1.example.com hostmaster.example.com 1 2 3 4 5']));

        $this->assertNotContains('updateSOASerial', $this->calls);
        $this->assertContains('commit', $this->calls);
    }

    public function testNoTransactionIsOpenedWhenTheBackendCannotWrapWrites(): void
    {
        $this->backend = $this->createMock(BackendCapabilitiesInterface::class);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1']));

        $this->assertTrue($result['success']);
        $this->assertSame(['validate:192.0.2.1', 'delete:5', 'delete:6', 'add:192.0.2.1', 'updateSOASerial', 'finalizeZone'], $this->calls);
    }

    public function testAValidatorRefusalRollsBackBeforeAnythingIsDeleted(): void
    {
        $this->validator = $this->createMock(DnsRecordValidationServiceInterface::class);
        $this->validator->method('validateRecord')->willReturnOnConsecutiveCalls(
            ValidationResult::success(['content' => '192.0.2.1']),
            ValidationResult::failure('Invalid IPv4 address')
        );

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1', 'nope']));

        $this->assertSame(['success' => false, 'message' => 'Invalid IPv4 address', 'status' => 400], $result);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->calls);
    }

    public function testAnEmptySetIsRefused(): void
    {
        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, []);

        $this->assertSame(['success' => false, 'message' => 'No valid records to create', 'status' => 400], $result);
        $this->assertSame(['beginTransaction', 'rollBack'], $this->calls);
    }

    public function testARepeatedContentIsRefusedAsADuplicateBeforeTheOldSetIsDeleted(): void
    {
        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1', '192.0.2.1']));

        $this->assertSame(['success' => false, 'message' => 'A record with this hostname, type, and content already exists', 'status' => 409], $result);
        $this->assertNotContains('delete:5', $this->calls);
        $this->assertSame('rollBack', end($this->calls));
    }

    public function testAFailedDeleteRollsBack(): void
    {
        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturn(RecordWriteResult::backendFailure('db down'));
        $this->manager->expects($this->never())->method('addRecordGetId');

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1']));

        $this->assertSame(['success' => false, 'message' => 'Failed to delete existing record with ID 5', 'status' => 500], $result);
        $this->assertSame(['beginTransaction', 'validate:192.0.2.1', 'rollBack'], $this->calls);
    }

    public function testAFailedInsertRollsBackAndHandsBackTheWriteResult(): void
    {
        $refused = RecordWriteResult::failure('Content too long', 422);
        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->manager->method('addRecordGetId')->willReturn($refused);
        $this->manager->expects($this->never())->method('finalizeZone');
        $this->soa->expects($this->never())->method('updateSOASerial');

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1']));

        $this->assertSame([
            'success' => false,
            'message' => 'Content too long',
            'status' => 422,
            'write' => $refused,
            'content' => '192.0.2.1',
        ], $result);
        $this->assertSame('rollBack', end($this->calls));
    }

    public function testAnUnexpectedFailureRollsBackAndPropagates(): void
    {
        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->manager->method('addRecordGetId')->willThrowException(new RuntimeException('connection lost'));

        try {
            $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com', 'A', 300, self::input(['192.0.2.1']));
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('connection lost', $e->getMessage());
        }

        $this->assertSame(['beginTransaction', 'validate:192.0.2.1', 'rollBack'], $this->calls);
    }

    public function testTheValidatorsNormalisedValuesWinOverTheInput(): void
    {
        $this->validator = $this->createMock(DnsRecordValidationServiceInterface::class);
        $this->validator->method('validateRecord')->willReturn(ValidationResult::success(['content' => '"quoted"', 'ttl' => 60, 'prio' => 10]));

        $added = [];
        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->manager->method('addRecordGetId')->willReturnCallback(function (...$args) use (&$added) {
            $added[] = $args;
            return RecordWriteResult::ok(1);
        });

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'txt.example.com', 'TXT', 300, [['content' => 'quoted', 'priority' => 0, 'disabled' => 1]]);

        $this->assertSame([[self::ZONE_ID, 'txt.example.com', 'TXT', '"quoted"', 60, 10, 1, false, null]], $added);
        $this->assertSame([['content' => '"quoted"', 'ttl' => 60, 'priority' => 10, 'disabled' => 1]], $result['records']);
    }

    public function testTheRecordNameIsNormalisedAgainstTheZone(): void
    {
        $names = [];
        $this->manager = $this->createMock(RecordManagerInterface::class);
        $this->manager->method('deleteRecord')->willReturn(RecordWriteResult::ok());
        $this->manager->method('addRecordGetId')->willReturnCallback(function ($zoneId, $name) use (&$names) {
            $names[] = $name;
            return RecordWriteResult::ok(1);
        });

        $result = $this->service()->replace(self::ZONE_ID, self::ZONE_NAME, 'www.example.com.', 'A', 300, self::input(['192.0.2.1']));

        $this->assertSame(['www.example.com'], $names);
        $this->assertSame('www.example.com', $result['name']);
    }
}
