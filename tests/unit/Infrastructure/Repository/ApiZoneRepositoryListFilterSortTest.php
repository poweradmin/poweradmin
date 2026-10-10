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
 * The q filter and sort of GET /api/v2/zones in API backend mode, where the list
 * comes from the local zones table: zone_name and zone_type instead of the
 * PowerDNS domains columns, and the canonical id for the id sort.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryListFilterSortTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT,
            zone_type TEXT, zone_master TEXT, comment TEXT, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)");
        // Zone 2 was migrated from SQL mode, so its canonical id is its domain_id
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, zone_type, owner) VALUES
            (1, NULL, 'alpha.example', 'MASTER', 5),
            (2, 900, 'Beta.example', 'SLAVE', 5),
            (3, NULL, '42.example', 'MASTER', 9),
            (4, NULL, 'a_b.test', 'NATIVE', 9),
            (5, NULL, 'axb.test', 'MASTER', 9)");
    }

    private function repository(): ApiZoneRepository
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);

        return new ApiZoneRepository($this->db, $backend, 'sqlite', new FakeConfiguration());
    }

    /** @return string[] */
    private function names(array $zones): array
    {
        return array_column($zones, 'name');
    }

    public function testTheSubstringFilterIgnoresCaseAndAgreesWithTheCount(): void
    {
        $repository = $this->repository();

        $this->assertSame(
            ['42.example', 'Beta.example', 'alpha.example'],
            $this->names($repository->getAllZonesFiltered(null, null, null, null, null, 'EXAMPLE'))
        );
        $this->assertSame(3, $repository->getZoneCountFiltered(null, null, null, 'EXAMPLE'));
    }

    public function testAnUnderscoreInTheFilterMatchesLiterally(): void
    {
        $repository = $this->repository();

        $this->assertSame(['a_b.test'], $this->names($repository->getAllZonesFiltered(null, null, null, null, null, 'a_b')));
        $this->assertSame(1, $repository->getZoneCountFiltered(null, null, null, 'a_b'));
    }

    public function testTheFilterCombinesWithTheCanonicalZoneIdAllowlist(): void
    {
        $repository = $this->repository();

        $this->assertSame(['Beta.example'], $this->names($repository->getAllZonesFiltered([900, 4], null, null, null, null, 'example')));
        $this->assertSame(1, $repository->getZoneCountFiltered([900, 4], null, null, 'example'));
    }

    public function testSortsNamesNaturallyWithNumericNamesLast(): void
    {
        $this->assertSame(
            ['Beta.example', 'a_b.test', 'alpha.example', 'axb.test', '42.example'],
            $this->names($this->repository()->getAllZonesFiltered(null, null, null, null, null, null, [['field' => 'name', 'desc' => false]]))
        );
    }

    public function testSortsByTypeAndPagesWithTheTiebreaker(): void
    {
        $sort = [['field' => 'type', 'desc' => true]];
        $repository = $this->repository();

        $this->assertSame(
            ['Beta.example', 'a_b.test', 'alpha.example', '42.example', 'axb.test'],
            $this->names($repository->getAllZonesFiltered(null, null, null, null, null, null, $sort))
        );
        $this->assertSame(['42.example'], $this->names($repository->getAllZonesFiltered(null, null, null, 3, 1, null, $sort)));
    }

    public function testSortsByTheCanonicalId(): void
    {
        $zones = $this->repository()->getAllZonesFiltered(null, null, null, null, null, null, [['field' => 'id', 'desc' => true]]);

        $this->assertSame([900, 5, 4, 3, 1], array_column($zones, 'id'));
    }
}
