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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A new zone that receives a domain id left behind by a zone deleted outside Poweradmin
 * does not inherit that zone's owners, group shares or template links.
 */
class DomainManagerStaleZoneRowsTest extends SqliteIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER)');
        $this->db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, group_id INTEGER, created_at TEXT)');
        $this->db->exec('CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)');
        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (40, 'zone_slave_add')");
        $this->db->exec('INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (1, 40)');
    }

    #[RunInSeparateProcess]
    public function testStaleOwnershipRowsForAReusedDomainIdAreDropped(): void
    {
        $this->db->exec('INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (5, 9, 0), (5, 8, 0), (6, 9, 0)');
        $this->db->exec('INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 3), (6, 3)');
        $this->db->exec('INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (5, 100, 1), (6, 101, 1)');

        $backend = $this->dnsBackendStub(false);
        $backend->method('createZone')->willReturn(5);

        $manager = new DomainManager(
            $this->db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class),
            $backend
        );

        $this->assertTrue($manager->addDomain($this->db, 'example.com', null, 'SLAVE', '192.0.2.1', 'none'));

        $this->assertSame([null], $this->db->query('SELECT owner FROM zones WHERE domain_id = 5')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('0', (string)$this->db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 5')->fetchColumn());
        $this->assertSame('0', (string)$this->db->query('SELECT COUNT(*) FROM records_zone_templ WHERE domain_id = 5')->fetchColumn());
        $this->assertSame('1', (string)$this->db->query('SELECT COUNT(*) FROM zones WHERE domain_id = 6')->fetchColumn());
        $this->assertSame('1', (string)$this->db->query('SELECT COUNT(*) FROM zones_groups WHERE domain_id = 6')->fetchColumn());
        $this->assertSame('1', (string)$this->db->query('SELECT COUNT(*) FROM records_zone_templ WHERE domain_id = 6')->fetchColumn());
    }
}
