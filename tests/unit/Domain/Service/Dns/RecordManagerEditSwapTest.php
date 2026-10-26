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

namespace Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\BackendCapabilitiesInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\RecordWriteBackendInterface;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * A save that moves several records at once (a swap, a rotation) is not refused because
 * one row briefly copies another the same save moves away; the writes run one by one.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerEditSwapTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;

    /** @var array<int, array<string, mixed>> The zone's A records by id, updated by each write */
    private array $rows = [];

    private bool $apiBackend = false;

    private bool $stripTrailingDot = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rows = [1 => $this->row('1', '192.0.2.1'), 2 => $this->row('2', '192.0.2.2'), 3 => $this->row('3', '192.0.2.3')];
    }

    #[Test]
    public function aSwapOfTwoRecordsIsWritten(): void
    {
        $manager = $this->manager();
        $vacated = ['1' => '192.0.2.1', '2' => '192.0.2.2'];

        $first = $manager->editRecord($this->edit('1', '192.0.2.2'), true, null, false, $vacated);
        $second = $manager->editRecord($this->edit('2', '192.0.2.1'), true, null, false, $vacated);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame('192.0.2.2', $this->rows[1]['content']);
        $this->assertSame('192.0.2.1', $this->rows[2]['content']);
    }

    #[Test]
    public function aThreeWayRotationIsWritten(): void
    {
        $manager = $this->manager();
        $vacated = ['1' => '192.0.2.1', '2' => '192.0.2.2', '3' => '192.0.2.3'];

        $this->assertTrue($manager->editRecord($this->edit('1', '192.0.2.2'), true, null, false, $vacated)->success);
        $this->assertTrue($manager->editRecord($this->edit('2', '192.0.2.3'), true, null, false, $vacated)->success);
        $this->assertTrue($manager->editRecord($this->edit('3', '192.0.2.1'), true, null, false, $vacated)->success);
    }

    #[Test]
    public function aMoveOntoARecordTheSaveLeavesInPlaceIsStillRefused(): void
    {
        $result = $this->manager()->editRecord($this->edit('1', '192.0.2.2'), true, null, false, ['1' => '192.0.2.1']);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
        $this->assertSame(RecordField::DUPLICATE, $result->field);
    }

    #[Test]
    public function withoutAVacatedSetTheCopyIsRefusedAsBefore(): void
    {
        $result = $this->manager()->editRecord($this->edit('1', '192.0.2.2'));

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function aSiblingThatAlreadyMovedOntoTheContentStillCounts(): void
    {
        // 1: .1 -> .2, 2: .2 -> .3, 3: .3 -> .2; 1 already holds .2 when 3 is written
        $manager = $this->manager();
        $vacated = ['1' => '192.0.2.1', '2' => '192.0.2.2', '3' => '192.0.2.3'];

        $this->assertTrue($manager->editRecord($this->edit('1', '192.0.2.2'), true, null, false, $vacated)->success);
        $this->assertTrue($manager->editRecord($this->edit('2', '192.0.2.3'), true, null, false, $vacated)->success);
        $result = $manager->editRecord($this->edit('3', '192.0.2.2'), true, null, false, $vacated);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function theApiBackendIgnoresTheVacatedSet(): void
    {
        // PowerDNS gets the whole RRset with each write and would hold the copy in between
        $this->apiBackend = true;

        $result = $this->manager()->editRecord($this->edit('1', '192.0.2.2'), true, null, false, ['1' => '192.0.2.1', '2' => '192.0.2.2']);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function aPreviewReportsTheStoredKeyARecordLeaves(): void
    {
        $preview = $this->manager()->previewEdit($this->edit('1', '192.0.2.9'));

        $this->assertTrue($preview->success);
        $this->assertSame(['name' => 'www.example.test', 'type' => 'A', 'content' => '192.0.2.1'], $preview->vacates);
        $this->assertSame(['name' => 'www.example.test', 'type' => 'A', 'content' => '192.0.2.9'], $preview->key);
    }

    #[Test]
    public function aPreviewIgnoresTheDuplicateCheck(): void
    {
        $preview = $this->manager()->previewEdit($this->edit('1', '192.0.2.2'));

        $this->assertTrue($preview->success);
        $this->assertNotNull($preview->vacates);
    }

    #[Test]
    public function aPreviewOfContentThatValidatesToTheStoredOneStays(): void
    {
        // Validation drops the trailing dot of a hostname, as HostnameValidator does
        $this->stripTrailingDot = true;

        $preview = $this->manager()->previewEdit($this->edit('2', '192.0.2.2.'));

        $this->assertTrue($preview->success);
        $this->assertNull($preview->vacates);
    }

    #[Test]
    public function aPreviewThatFailsValidationReportsTheRefusal(): void
    {
        $preview = $this->manager()->previewEdit($this->edit('1', 'invalid'));

        $this->assertFalse($preview->success);
        $this->assertSame(Refusal::INVALID_INPUT, $preview->refusal);
        $this->assertNull($preview->vacates);
    }

    /**
     * @return array<string, mixed>
     */
    private function edit(string $id, string $content): array
    {
        return ['rid' => $id, 'zid' => self::ZONE_ID, 'name' => 'www.example.test', 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $id, string $content): array
    {
        return ['id' => $id, 'domain_id' => self::ZONE_ID, 'name' => 'www.example.test', 'type' => 'A', 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    private function manager(): RecordManager
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.test'],
        ]);

        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturnCallback(
            fn(int|string $rid, int $zid, string $type, string $content, string $name, ?int $prio, ?int $ttl): ValidationResult => $content === 'invalid'
                ? ValidationResult::failure('Invalid IP address.')
                : ValidationResult::success([
                    'content' => $this->stripTrailingDot ? rtrim($content, '.') : $content,
                    'name' => $name,
                    'ttl' => $ttl,
                    'prio' => $prio,
                ])
        );

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.test');

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('getRecordDetailsFromRecordId')->willReturnCallback(
            fn(int|string $rid): array => ['zid' => self::ZONE_ID] + $this->rows[(int)$rid]
        );
        $recordRepository->method('getRRSetRecords')->willReturnCallback(fn(): array => array_values($this->rows));
        $repositoryFactory = $this->createMock(RepositoryFactoryInterface::class);
        $repositoryFactory->method('createRecordRepository')->willReturn($recordRepository);

        $backend = $this->createMockForIntersectionOfInterfaces([RecordWriteBackendInterface::class, BackendCapabilitiesInterface::class]);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);
        $backend->method('isApiBackend')->willReturn($this->apiBackend);
        $backend->method('editRecord')->willReturnCallback(function (int|string $rid, string $name, string $type, string $content): bool {
            $this->rows[(int)$rid]['content'] = $content;
            return true;
        });

        return new RecordManager(
            new PdoTransaction($db),
            $config,
            $validation,
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            $repositoryFactory,
            fn() => $this->createMock(ZoneRectifierInterface::class),
            $backend,
            $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]]),
            $this->createMock(RecordChangeWriterInterface::class),
            new DbTemplateRecordLinkRepository($db, $config, $backend),
            new StubActor(self::CALLER_ID)
        );
    }
}
