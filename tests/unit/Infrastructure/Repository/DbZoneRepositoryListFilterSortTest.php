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
 * The q filter and sort of GET /api/v2/zones in SQL mode: the substring filter is
 * case-insensitive and literal, and the count agrees with the list.
 */
#[CoversClass(DbZoneRepository::class)]
class DbZoneRepositoryListFilterSortTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, type TEXT, master TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name, type) VALUES
            (1, 'alpha.example', 'MASTER'),
            (2, 'Beta.example', 'SLAVE'),
            (3, 'gamma.test', 'MASTER'),
            (4, 'a_b.example', 'NATIVE'),
            (5, 'axb.example', 'MASTER')");
        $this->db->exec("INSERT INTO zones (domain_id, owner) VALUES (1, 5), (2, 5), (3, 9), (4, 9), (5, 9)");
    }

    private function repository(): DbZoneRepository
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        return new DbZoneRepository($this->db, $config);
    }

    /** @return string[] */
    private function names(array $zones): array
    {
        return array_column($zones, 'name');
    }

    public function testTheSubstringFilterIgnoresCaseAndAgreesWithTheCount(): void
    {
        $repository = $this->repository();

        $byId = [['field' => 'id', 'desc' => false]];

        $this->assertSame(
            ['alpha.example', 'Beta.example', 'a_b.example', 'axb.example'],
            $this->names($repository->getAllZonesFiltered(null, null, null, null, null, 'ExAmPlE', $byId))
        );
        $this->assertSame(4, $repository->getZoneCountFiltered(null, null, null, 'EXAMPLE'));
        $this->assertSame(['gamma.test'], $this->names($repository->getAllZonesFiltered(null, null, null, null, null, 'TEST')));
    }

    public function testAnUnderscoreInTheFilterMatchesLiterally(): void
    {
        $repository = $this->repository();

        $this->assertSame(['a_b.example'], $this->names($repository->getAllZonesFiltered(null, null, null, null, null, 'a_b')));
        $this->assertSame(1, $repository->getZoneCountFiltered(null, null, null, 'a_b'));
        $this->assertSame(0, $repository->getZoneCountFiltered(null, null, null, '%'));
    }

    public function testTheFilterCombinesWithOwnershipAndTheZoneIdAllowlist(): void
    {
        $repository = $this->repository();

        $this->assertSame(['Beta.example', 'alpha.example'], $this->names($repository->getAllZonesFiltered([1, 2, 3], 5, null, null, null, 'example')));
        $this->assertSame(2, $repository->getZoneCountFiltered([1, 2, 3], 5, null, 'example'));
    }

    public function testSortsByTypeThenNameDescendingAndPagesWithTheTiebreaker(): void
    {
        $sort = [['field' => 'type', 'desc' => false], ['field' => 'name', 'desc' => true]];
        $repository = $this->repository();

        $this->assertSame(
            ['gamma.test', 'axb.example', 'alpha.example', 'a_b.example', 'Beta.example'],
            $this->names($repository->getAllZonesFiltered(null, null, null, null, null, null, $sort))
        );
        $this->assertSame(['alpha.example'], $this->names($repository->getAllZonesFiltered(null, null, null, 2, 1, null, $sort)));
    }

    public function testSortsByIdDescending(): void
    {
        $zones = $this->repository()->getAllZonesFiltered(null, null, null, null, null, null, [['field' => 'id', 'desc' => true]]);

        $this->assertSame([5, 4, 3, 2, 1], array_map('intval', array_column($zones, 'id')));
    }

    public function testWithoutSortTheListKeepsTheNameOrder(): void
    {
        $this->assertSame(
            ['Beta.example', 'a_b.example', 'alpha.example', 'axb.example', 'gamma.test'],
            $this->names($this->repository()->getAllZonesFiltered(null, null, null))
        );
    }
}
