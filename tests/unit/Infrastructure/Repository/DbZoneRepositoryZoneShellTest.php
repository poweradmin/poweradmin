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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * The native zones row of a zone in SQL mode: created after the backend has
 * allocated the domain id, deleted by domain id, and given a comment even when
 * the zone was created outside Poweradmin and has no row yet.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryZoneShellTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0)");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new DbZoneRepository($this->db, $config);
    }

    public function testCreateZoneShellInsertsTheRowAndReturnsItsId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (5, 50, 1)");

        $id = $this->repository->createZoneShell(77, 7, 9);

        $this->assertSame(6, (int)$id);
        $this->assertSame(
            [['id' => 6, 'domain_id' => 77, 'owner' => 7, 'zone_templ_id' => 9]],
            $this->rows('SELECT id, domain_id, owner, zone_templ_id FROM zones WHERE domain_id = 77')
        );
    }

    public function testCreateZoneShellStoresAnOwnerlessZoneAsNull(): void
    {
        $this->repository->createZoneShell(77, null, 0);

        $this->assertSame([['owner' => null, 'zone_templ_id' => 0]], $this->rows('SELECT owner, zone_templ_id FROM zones'));
    }

    public function testDeleteZoneShellRemovesOnlyThatZone(): void
    {
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (77, 1), (78, 1)");

        $this->repository->deleteZoneShell(77);

        $this->assertSame([['domain_id' => 78]], $this->rows('SELECT domain_id FROM zones'));
    }

    public function testSaveZoneCommentUpdatesAnExistingRow(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment, zone_templ_id) VALUES (3, 77, 5, 'old', 9)");

        $this->repository->saveZoneComment(77, 'new');

        $this->assertSame(
            [['id' => 3, 'domain_id' => 77, 'owner' => 5, 'comment' => 'new', 'zone_templ_id' => 9]],
            $this->rows('SELECT id, domain_id, owner, comment, zone_templ_id FROM zones')
        );
    }

    public function testSaveZoneCommentCreatesTheRowForAZoneWithoutOne(): void
    {
        $this->repository->saveZoneComment(77, 'first');

        $this->assertSame(
            [['domain_id' => 77, 'owner' => 1, 'comment' => 'first', 'zone_templ_id' => 0]],
            $this->rows('SELECT domain_id, owner, comment, zone_templ_id FROM zones')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
