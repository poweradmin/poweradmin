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
use PHPUnit\Framework\MockObject\MockObject;
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
 * Editing a record into an exact copy of another record in the zone is refused the
 * way adding one is; the record itself never counts as its own duplicate.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerEditDuplicateTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;
    private const RECORD_ID = 5;
    private const SIBLING_ID = 6;

    /** @var RecordWriteBackendInterface&BackendCapabilitiesInterface&MockObject */
    private RecordWriteBackendInterface&BackendCapabilitiesInterface&MockObject $backend;

    /** @var list<array<string, mixed>> */
    private array $rrset = [];

    #[Test]
    public function editingARecordIntoACopyOfASiblingIsRefused(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '192.0.2.1'), $this->row(self::SIBLING_ID, '192.0.2.2')];
        $manager = $this->manager();
        $this->backend->expects($this->never())->method('editRecord');

        $result = $manager->editRecord($this->edit('A', '192.0.2.2'));

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
        $this->assertSame(RecordField::DUPLICATE, $result->field);
        $this->assertSame('A record with this hostname, type, and content already exists.', $result->message);
    }

    #[Test]
    public function aDryRunReportsTheDuplicateToo(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '192.0.2.1'), $this->row(self::SIBLING_ID, '192.0.2.2')];

        $result = $this->manager()->editRecord($this->edit('A', '192.0.2.2'), true, null, true);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function anAaaaWrittenInAnotherFormIsStillACopy(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '2001:db8::1', 'AAAA'), $this->row(self::SIBLING_ID, '2001:db8:0:0::2', 'AAAA')];
        $manager = $this->manager();
        $this->backend->expects($this->never())->method('editRecord');

        $result = $manager->editRecord($this->edit('AAAA', '2001:db8::2'));

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function savingTheRecordUnchangedIsNotADuplicateOfItself(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '192.0.2.1'), $this->row(self::SIBLING_ID, '192.0.2.2')];
        $manager = $this->manager();
        $this->backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $manager->editRecord($this->edit('A', '192.0.2.1'));

        $this->assertTrue($result->success);
    }

    #[Test]
    public function anEncodedRecordIdOfTheApiBackendIsRecognisedAsTheRecordItself(): void
    {
        $this->rrset = [$this->row('enc-own', '192.0.2.1'), $this->row('enc-other', '192.0.2.2')];
        $manager = $this->manager('enc-own');
        $this->backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $manager->editRecord(['rid' => 'enc-own'] + $this->edit('A', '192.0.2.1'));

        $this->assertTrue($result->success);
    }

    #[Test]
    public function aLegacyTwinCanStillChangeItsTtl(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '192.0.2.1'), $this->row(self::SIBLING_ID, '192.0.2.1')];
        $manager = $this->manager();
        $this->backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $manager->editRecord(['ttl' => 7200] + $this->edit('A', '192.0.2.1'));

        $this->assertTrue($result->success);
    }

    #[Test]
    public function aLegacyHttpsTwinWithAnUnquotedEchCanStillChangeItsTtl(): void
    {
        // Validation quotes ech, the stored legacy rows are unquoted
        $this->rrset = [$this->row(self::RECORD_ID, '1 . ech=AAAA', 'HTTPS'), $this->row(self::SIBLING_ID, '1 . ech=AAAA', 'HTTPS')];
        $manager = $this->manager();
        $this->backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $manager->editRecord(['ttl' => 7200] + $this->edit('HTTPS', '1 . ech="AAAA"'));

        $this->assertTrue($result->success);
    }

    #[Test]
    public function aGenuineChangeIsWritten(): void
    {
        $this->rrset = [$this->row(self::RECORD_ID, '192.0.2.1'), $this->row(self::SIBLING_ID, '192.0.2.2')];
        $manager = $this->manager();
        $this->backend->expects($this->once())->method('editRecord')->willReturn(true);

        $result = $manager->editRecord($this->edit('A', '192.0.2.3'));

        $this->assertTrue($result->success);
    }

    /**
     * @return array<string, mixed>
     */
    private function edit(string $type, string $content): array
    {
        return [
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'www.example.test',
            'type' => $type,
            'content' => $content,
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int|string $id, string $content, string $type = 'A'): array
    {
        // PDO hands SQL ids back as strings
        return ['id' => (string)$id, 'domain_id' => self::ZONE_ID, 'name' => 'www.example.test', 'type' => $type, 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
    }

    private function manager(int|string $storedId = self::RECORD_ID): RecordManager
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.test'],
        ]);

        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturnCallback(
            static fn(int|string $rid, int $zid, string $type, string $content, string $name, ?int $prio, ?int $ttl): ValidationResult => ValidationResult::success([
                'content' => $content,
                'name' => $name,
                'ttl' => $ttl,
                'prio' => $prio,
            ])
        );

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.test');

        $own = null;
        foreach ($this->rrset as $row) {
            if ($row['id'] === (string)$storedId) {
                $own = $row;
            }
        }
        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('getRecordDetailsFromRecordId')->willReturn(['zid' => self::ZONE_ID] + (array)$own);
        $recordRepository->method('getRRSetRecords')->willReturnCallback(
            fn(int $zoneId, string $name, string $type): array => array_values(array_filter(
                $this->rrset,
                static fn(array $r): bool => $r['name'] === $name && $r['type'] === $type
            ))
        );
        $repositoryFactory = $this->createMock(RepositoryFactoryInterface::class);
        $repositoryFactory->method('createRecordRepository')->willReturn($recordRepository);

        $backend = $this->createMockForIntersectionOfInterfaces([RecordWriteBackendInterface::class, BackendCapabilitiesInterface::class]);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);
        $this->backend = $backend;

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
