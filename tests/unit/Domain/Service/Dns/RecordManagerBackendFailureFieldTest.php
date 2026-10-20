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
use Poweradmin\Domain\Port\ReportsWriteRejection;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Validation\RecordField;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * A failed backend write flags the content input only when PowerDNS gave a reason
 * for refusing it; SQL failures and reasonless API faults leave the field unset.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerBackendFailureFieldTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;
    private const RECORD_ID = 5;

    #[Test]
    public function testAddWithRejectionReasonFlagsContent(): void
    {
        $result = $this->add($this->apiBackend('addRecordGetId', 'Not in expected format'));

        $this->assertBackendFailure($result, 'Failed to add record to DNS backend: Not in expected format', RecordField::CONTENT);
    }

    #[Test]
    public function testAddOnSqlBackendFlagsNoField(): void
    {
        $result = $this->add($this->sqlBackend('addRecordGetId'));

        $this->assertBackendFailure($result, 'Failed to add record to DNS backend.', null);
    }

    #[Test]
    public function testAddWithoutRejectionReasonFlagsNoField(): void
    {
        $result = $this->add($this->apiBackend('addRecordGetId', null));

        $this->assertBackendFailure($result, 'Failed to add record to DNS backend.', null);
    }

    #[Test]
    public function testAddWithBlankRejectionReasonFlagsNoField(): void
    {
        $result = $this->add($this->apiBackend('addRecordGetId', " \t\n "));

        $this->assertBackendFailure($result, 'Failed to add record to DNS backend.', null);
    }

    #[Test]
    public function testEditWithRejectionReasonFlagsContent(): void
    {
        $result = $this->edit($this->apiBackend('editRecord', 'Not in expected format'));

        $this->assertBackendFailure($result, 'Failed to update record in DNS backend: Not in expected format', RecordField::CONTENT);
    }

    #[Test]
    public function testEditOnSqlBackendFlagsNoField(): void
    {
        $result = $this->edit($this->sqlBackend('editRecord'));

        $this->assertBackendFailure($result, 'Failed to update record in DNS backend.', null);
    }

    #[Test]
    public function testEditWithoutRejectionReasonFlagsNoField(): void
    {
        $result = $this->edit($this->apiBackend('editRecord', null));

        $this->assertBackendFailure($result, 'Failed to update record in DNS backend.', null);
    }

    #[Test]
    public function testEditWithBlankRejectionReasonFlagsNoField(): void
    {
        $result = $this->edit($this->apiBackend('editRecord', " \t\n "));

        $this->assertBackendFailure($result, 'Failed to update record in DNS backend.', null);
    }

    private function assertBackendFailure(RecordWriteResult $result, string $message, ?RecordField $field): void
    {
        $this->assertFalse($result->success);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
        $this->assertSame($message, $result->message);
        $this->assertSame($field, $result->field);
    }

    private function add(RecordWriteBackendInterface&BackendCapabilitiesInterface $backend): RecordWriteResult
    {
        return $this->manager($backend)->addRecordGetId(self::ZONE_ID, 'www.example.test', 'A', '192.0.2.2', 3600, 0);
    }

    private function edit(RecordWriteBackendInterface&BackendCapabilitiesInterface $backend): RecordWriteResult
    {
        return $this->manager($backend)->editRecord([
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'www.example.test',
            'type' => 'A',
            'content' => '192.0.2.2',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);
    }

    private function sqlBackend(string $failingWrite): RecordWriteBackendInterface&BackendCapabilitiesInterface
    {
        $backend = $this->createMockForIntersectionOfInterfaces([RecordWriteBackendInterface::class, BackendCapabilitiesInterface::class]);
        $backend->method($failingWrite)->willReturn($failingWrite === 'editRecord' ? false : null);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);

        return $backend;
    }

    private function apiBackend(string $failingWrite, ?string $reason): RecordWriteBackendInterface&BackendCapabilitiesInterface
    {
        $backend = $this->createMockForIntersectionOfInterfaces([
            RecordWriteBackendInterface::class,
            BackendCapabilitiesInterface::class,
            ReportsWriteRejection::class,
        ]);
        $backend->method($failingWrite)->willReturn($failingWrite === 'editRecord' ? false : null);
        $backend->method('lastWriteRejection')->willReturn($reason);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);

        return $backend;
    }

    private function manager(RecordWriteBackendInterface&BackendCapabilitiesInterface $backend): RecordManager
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.test'],
        ]);

        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturn(ValidationResult::success([
            'content' => '192.0.2.2',
            'name' => 'www.example.test',
            'ttl' => 3600,
            'prio' => 0,
        ]));

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.test');

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('recordExists')->willReturn(false);
        $recordRepository->method('getRecordDetailsFromRecordId')->willReturn([
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'www.example.test',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);
        $repositoryFactory = $this->createMock(RepositoryFactoryInterface::class);
        $repositoryFactory->method('createRecordRepository')->willReturn($recordRepository);

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
