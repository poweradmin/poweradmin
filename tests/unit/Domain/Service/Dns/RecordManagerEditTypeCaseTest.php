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
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * The edit form posts the stored type back verbatim, so a row written lowercase by
 * an SQL tool must be validated and saved under its uppercase type.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerEditTypeCaseTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;
    private const RECORD_ID = 5;

    #[Test]
    public function aLowercaseStoredTypeIsValidatedUppercase(): void
    {
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->expects($this->once())->method('validateRecord')
            ->with(self::RECORD_ID, self::ZONE_ID, 'MX')
            ->willReturn(ValidationResult::failure('stop after validation'));

        $result = $this->manager($validation)->editRecord([
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'example.test',
            'type' => 'mx',
            'content' => 'mail.example.test',
            'ttl' => 3600,
            'prio' => 10,
            'disabled' => 0,
        ]);

        $this->assertFalse($result->success);
    }

    private function manager(DnsRecordValidationServiceInterface $validation): RecordManager
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.test'],
        ]);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.test');

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('getRecordDetailsFromRecordId')->willReturn([
            'rid' => self::RECORD_ID,
            'zid' => self::ZONE_ID,
            'name' => 'example.test',
            'type' => 'mx',
            'content' => 'mail.example.test',
            'ttl' => 3600,
            'prio' => 10,
            'disabled' => 0,
        ]);
        $repositoryFactory = $this->createMock(RepositoryFactoryInterface::class);
        $repositoryFactory->method('createRecordRepository')->willReturn($recordRepository);

        $backend = $this->createMockForIntersectionOfInterfaces([RecordWriteBackendInterface::class, BackendCapabilitiesInterface::class]);
        $this->assertInstanceOf(RecordWriteBackendInterface::class, $backend);
        $this->assertInstanceOf(BackendCapabilitiesInterface::class, $backend);

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
