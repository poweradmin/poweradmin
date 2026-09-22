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
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use TestHelpers\FakeConfiguration;
use TestHelpers\PermissionServiceTestCase;
use TestHelpers\StubActor;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Database\PdoTransaction;

/**
 * editZoneComment() updates the zones row of the zone, and creates one for a
 * zone that has none (a zone created outside Poweradmin), so the comment is
 * never lost.
 */
#[CoversClass(RecordManager::class)]
class RecordManagerEditZoneCommentTest extends PermissionServiceTestCase
{
    private const CALLER_ID = 7;
    private const ZONE_ID = 10;

    private PDO $db;
    private FakeConfiguration $config;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->config = new FakeConfiguration([
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
            'dns' => ['ttl' => 3600],
        ]);
    }

    public function testExistingZoneRowKeepsItsOwnerAndTemplate(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id) VALUES (3, " . self::ZONE_ID . ", 5, 'old', 9)");

        $result = $this->manager()->editZoneComment(self::ZONE_ID, 'new comment');

        $this->assertTrue($result->success);
        $this->assertSame(
            [['id' => 3, 'domain_id' => self::ZONE_ID, 'owner' => 5, 'comment' => 'new comment', 'zone_templ_id' => 9]],
            $this->rows()
        );
    }

    public function testZoneWithoutARowGetsOneOwnedByTheFirstUser(): void
    {
        $result = $this->manager()->editZoneComment(self::ZONE_ID, 'first comment');

        $this->assertTrue($result->success);
        $this->assertSame(
            [['id' => 1, 'domain_id' => self::ZONE_ID, 'owner' => 1, 'comment' => 'first comment', 'zone_templ_id' => 0]],
            $this->rows()
        );
    }

    public function testReadOnlyZoneIsRefusedBeforeAnyWrite(): void
    {
        $result = $this->manager('SLAVE')->editZoneComment(self::ZONE_ID, 'comment');

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
        $this->assertSame('You do not have the permission to edit this comment.', $result->message);
        $this->assertSame([], $this->rows());
    }

    private function manager(string $zoneType = 'MASTER'): RecordManager
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn($zoneType);
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(false);

        return new RecordManager(
            new PdoTransaction($this->db),
            $this->config,
            $this->createMock(DnsRecordValidationServiceInterface::class),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->db->query('SELECT id, domain_id, owner, comment, zone_templ_id FROM zones')->fetchAll(PDO::FETCH_ASSOC);
    }
}
