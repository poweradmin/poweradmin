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
use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;

/**
 * An AAAA record is a duplicate when the address is the same, whichever way it is written.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerEquivalentAaaaTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;

    private PDO $db;
    private FakeConfiguration $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (1, " . self::ZONE_ID . ", 'host.example.com', 'AAAA', '2001:0db8:0:0::1', 3600, 0, 0)");
        $this->config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600, 'hostmaster' => 'hostmaster.example.com'],
        ]);
    }

    public function testRefusesAnAaaaWhoseAddressIsAlreadyStoredInAnotherForm(): void
    {
        $result = $this->manager('2001:db8::1')->addRecordGetId(self::ZONE_ID, 'host', 'AAAA', '2001:db8::1', 3600, 0);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    public function testADifferentAddressIsNotADuplicate(): void
    {
        $manager = $this->manager('2001:db8::2');

        $this->assertFalse($this->isConflict($manager->addRecordGetId(self::ZONE_ID, 'host', 'AAAA', '2001:db8::2', 3600, 0)->refusal));
    }

    private function isConflict(?Refusal $refusal): bool
    {
        return $refusal === Refusal::CONFLICT;
    }

    private function manager(string $content): RecordManager
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('example.com');
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(false);
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturn(ValidationResult::success([
            'content' => $content,
            'name' => 'host.example.com',
            'ttl' => 3600,
            'prio' => 0,
        ]));

        return new RecordManager(
            new PdoTransaction($this->db),
            $this->config,
            $validation,
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            new RepositoryFactory($this->db, $this->config, $backend),
            fn() => $this->createMock(ZoneRectifierInterface::class),
            $backend,
            $this->buildPermissionService(permissionsByUser: [self::CALLER_ID => [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]]),
            $this->createMock(RecordChangeWriterInterface::class),
            new DbTemplateRecordLinkRepository($this->db, $this->config, $backend),
            new StubActor(self::CALLER_ID)
        );
    }
}
