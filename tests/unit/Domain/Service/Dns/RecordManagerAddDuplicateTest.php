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
 * Adding a record compares it with its siblings in PHP, as an edit does, so a MySQL
 * case-insensitive collation cannot refuse a TXT record that differs only in case.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerAddDuplicateTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;

    /** @var list<array<string, mixed>> */
    private array $rrset = [];

    #[Test]
    public function aTxtRecordDifferingOnlyInCaseIsAdded(): void
    {
        $this->rrset = [$this->row('"Hello World"')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'TXT', '"hello world"', 3600, 0, 0, false);

        $this->assertTrue($result->success);
        $this->assertSame(99, $result->recordId);
    }

    #[Test]
    public function anExactCopyIsRefused(): void
    {
        $this->rrset = [$this->row('"Hello World"')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'TXT', '"Hello World"', 3600, 0, 0, false);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
        $this->assertSame(RecordField::DUPLICATE, $result->field);
    }

    #[Test]
    public function aNameServerDifferingOnlyInCaseIsACopy(): void
    {
        $this->rrset = [$this->row('ns1.example.com', 'NS')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'NS', 'ns1.Example.com', 3600, 0, 0, false);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function aMailExchangerDifferingOnlyInCaseIsACopy(): void
    {
        $this->rrset = [$this->row('mail.example.com', 'MX')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'MX', 'MAIL.example.com', 3600, 10, 0, false);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function anSrvTargetDifferingOnlyInCaseIsACopy(): void
    {
        $this->rrset = [$this->row('5 5060 sip.example.com', 'SRV')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'SRV', '5 5060 SIP.Example.com', 3600, 10, 0, false);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function anSrvRecordWithAnotherPortIsNotACopy(): void
    {
        $this->rrset = [$this->row('5 5060 sip.example.com', 'SRV')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'SRV', '5 5061 SIP.Example.com', 3600, 10, 0, false);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function aCaaValueDifferingOnlyInCaseIsAdded(): void
    {
        $this->rrset = [$this->row('0 issue "CA.example"', 'CAA')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'CAA', '0 issue "ca.example"', 3600, 0, 0, false);

        $this->assertTrue($result->success);
    }

    #[Test]
    public function anAaaaWrittenInAnotherFormIsStillACopy(): void
    {
        $this->rrset = [$this->row('2001:0db8:0:0::1', 'AAAA')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'AAAA', '2001:db8::1', 3600, 0, 0, false);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[Test]
    public function anHttpsRowStoredWithItsEchUnquotedIsStillACopy(): void
    {
        $this->rrset = [$this->row('1 . ech=AAAA', 'HTTPS')];

        $result = $this->manager()->addRecordGetId(self::ZONE_ID, 'www', 'HTTPS', '1 . ech="AAAA"', 3600, 0, 0, false);

        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $content, string $type = 'TXT'): array
    {
        return ['id' => '5', 'domain_id' => self::ZONE_ID, 'name' => 'www.example.test', 'type' => $type, 'content' => $content, 'ttl' => 3600, 'prio' => 0, 'disabled' => 0];
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

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        // What a MySQL _ci collation answers: any row whose content matches ignoring case
        $recordRepository->method('recordExists')->willReturnCallback(
            fn(int $zoneId, string $name, string $type, string $content): bool => array_filter(
                $this->rrset,
                static fn(array $r): bool => $r['name'] === $name && $r['type'] === $type && strcasecmp((string)$r['content'], $content) === 0
            ) !== []
        );
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
        $backend->method('addRecordGetId')->willReturn(99);

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
