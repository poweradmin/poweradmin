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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * DomainManager::addDomain()/deleteDomain() report refusals through the result
 * (status and reason) and hand back the new zone id on success.
 */
class DomainManagerWriteResultTest extends SqliteIntegrationTestCase
{
    private const CLIENT_USER_ID = 100;
    private const CLIENT_PERM_TEMPL_ID = 100;
    private const NEW_DOMAIN_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");

        // A user holding no zone_*_add and no delete grant at all.
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CLIENT_USER_ID . ", 'client', " . self::CLIENT_PERM_TEMPL_ID . ")");
    }

    #[RunInSeparateProcess]
    public function testUnknownZoneTypeIsRefusedBeforeAnythingIsWritten(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('createZone');

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::ADMIN_USER_ID, 'BOGUS', '', 'none');

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->status);
    }

    #[RunInSeparateProcess]
    public function testCreatingWithoutTheAddGrantIsForbidden(): void
    {
        $_SESSION['userid'] = self::CLIENT_USER_ID;
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('createZone');

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', self::CLIENT_USER_ID, 'MASTER', '', 'none');

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
    }

    #[RunInSeparateProcess]
    public function testBackendRefusalIsReportedAsBackendFailure(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->willReturn(false);

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', null, 'SLAVE', '192.0.2.1', 'none', [5]);

        $this->assertFalse($result->success);
        $this->assertSame(500, $result->status);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM zones')->fetchColumn());
    }

    #[RunInSeparateProcess]
    public function testCreatedSecondaryZoneReturnsItsIdAndOwnershipRows(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->with('new.example', 'SLAVE', '192.0.2.1')->willReturn(self::NEW_DOMAIN_ID);

        $result = $this->makeDomainManager($backend)->addDomain($this->db, 'new.example', null, 'SLAVE', '192.0.2.1', 'none', [5, 5, 6]);

        $this->assertTrue($result->success);
        $this->assertSame(self::NEW_DOMAIN_ID, $result->zoneId);
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM zones WHERE domain_id = ' . self::NEW_DOMAIN_ID)->fetchColumn());
        $this->assertSame([5, 6], array_map('intval', $this->db->query('SELECT group_id FROM zones_groups WHERE domain_id = ' . self::NEW_DOMAIN_ID . ' ORDER BY group_id')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    #[RunInSeparateProcess]
    public function testDeletingWithoutTheGrantIsForbidden(): void
    {
        $_SESSION['userid'] = self::CLIENT_USER_ID;
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (" . self::NEW_DOMAIN_ID . ", " . self::ADMIN_USER_ID . ")");
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('deleteZone');

        $result = $this->makeDomainManager($backend)->deleteDomain(self::NEW_DOMAIN_ID);

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
    }

    private function makeDomainManager(DnsBackendProvider $backend): DomainManager
    {
        $config = $this->primeConfigurationManager([
            'dns' => ['ns1' => 'ns1.example', 'hostmaster' => 'hostmaster.example', 'ttl' => 3600],
        ]);

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn('new.example');

        return new DomainManager(
            $this->db,
            $config,
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            $backend
        );
    }
}
