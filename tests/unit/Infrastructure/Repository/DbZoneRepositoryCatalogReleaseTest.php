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
 * A producer that stops being one must not leave its members pointing at the name,
 * or a later producer created under it would start publishing them.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryCatalogReleaseTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT, catalog TEXT)");
        $this->db->exec("INSERT INTO domains VALUES
            (1, 'catalog.example', 'PRODUCER', '', NULL),
            (2, 'member.example', 'MASTER', '', 'catalog.example'),
            (3, 'other.example', 'MASTER', '', 'elsewhere.example')");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new DbZoneRepository($this->db, $config);
    }

    private function catalogOf(int $id): string
    {
        return (string)$this->db->query("SELECT catalog FROM domains WHERE id = $id")->fetchColumn();
    }

    public function testChangingAProducerToAnotherKindEmptiesTheCatalogOfItsMembers(): void
    {
        $this->assertTrue($this->repository->updateZone(1, ['type' => 'NATIVE']));

        $this->assertSame('', $this->catalogOf(2));
        $this->assertSame('elsewhere.example', $this->catalogOf(3));
    }

    public function testKeepingAProducerAProducerLeavesItsMembers(): void
    {
        $this->assertTrue($this->repository->updateZone(1, ['type' => 'PRODUCER']));

        $this->assertSame('catalog.example', $this->catalogOf(2));
    }

    public function testChangingAMemberKindLeavesOtherCatalogsAlone(): void
    {
        $this->assertTrue($this->repository->updateZone(2, ['type' => 'NATIVE']));

        $this->assertSame('catalog.example', $this->catalogOf(2));
    }

    public function testAFailedKindChangeLeavesTheMembersInTheCatalog(): void
    {
        $this->db->exec("CREATE TRIGGER refuse_kind BEFORE UPDATE OF type ON domains BEGIN SELECT RAISE(ABORT, 'refused'); END");

        try {
            $this->repository->updateZone(1, ['type' => 'NATIVE']);
            $this->fail('The kind change should have failed');
        } catch (\PDOException) {
            // expected
        }

        $this->assertSame('catalog.example', $this->catalogOf(2));
    }
}
