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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use TestHelpers\FakeConfiguration;

/**
 * In API mode createZone() has already inserted the zones row, so the shell
 * write fills in owner and template on that row instead of adding a second one.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryZoneShellTest extends TestCase
{
    private PDO $db;
    private ApiZoneRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");

        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $this->repository = new ApiZoneRepository($this->db, $backend, 'sqlite', new FakeConfiguration());
    }

    public function testCreateZoneShellFillsThePlaceholderRowAndReturnsTheDomainId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, zone_templ_id) VALUES (77, 77, 'new.example', NULL, 0), (78, 78, 'other.example', NULL, 0)");

        $id = $this->repository->createZoneShell(77, 7, 9);

        $this->assertSame(77, $id);
        $this->assertSame(
            [
                ['id' => 77, 'owner' => 7, 'zone_templ_id' => 9],
                ['id' => 78, 'owner' => null, 'zone_templ_id' => 0],
            ],
            $this->rows('SELECT id, owner, zone_templ_id FROM zones ORDER BY id')
        );
    }

    public function testDeleteZoneShellRemovesOnlyThatZone(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name) VALUES (77, 77, 'new.example'), (78, 78, 'other.example')");

        $this->repository->deleteZoneShell(77);

        $this->assertSame([['id' => 78]], $this->rows('SELECT id FROM zones'));
    }

    public function testSaveZoneCommentUpdatesTheRowKeyedByDomainId(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, comment) VALUES (3, 77, 'new.example', 5, 'old')");

        $this->repository->saveZoneComment(77, 'new');

        $this->assertSame([['id' => 3, 'owner' => 5, 'comment' => 'new']], $this->rows('SELECT id, owner, comment FROM zones'));
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
