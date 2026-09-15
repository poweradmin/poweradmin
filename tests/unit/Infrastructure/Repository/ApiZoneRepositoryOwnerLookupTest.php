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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * getOwnerIdsByZoneIds() drives the owner column and the edit/delete controls of
 * the zone lists. It matches on the canonical id expression, which carries no column
 * affinity, so ids bound as strings matched nothing on SQLite and every directly
 * owned zone looked unowned.
 */
class ApiZoneRepositoryOwnerLookupTest extends TestCase
{
    private PDO $db;
    private ApiZoneRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES
            (1, 100, 'migrated.example.com', 10),
            (55, 0, 'stranded.example.com', 20),
            (56, NULL, 'nulled.example.com', 30)");

        $this->repository = new ApiZoneRepository(
            $this->db,
            $this->createMock(DnsBackendProviderInterface::class),
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );
    }

    public function testResolvesOwnersForEveryDomainIdShape(): void
    {
        $map = $this->repository->getOwnerIdsByZoneIds([100, 55, 56]);

        $this->assertSame([10], $map[100] ?? [], 'migrated zone lost its owner');
        $this->assertSame([20], $map[55] ?? [], 'stranded zone lost its owner');
        $this->assertSame([30], $map[56] ?? [], 'null domain_id zone lost its owner');
    }

    public function testDoesNotResolveAMigratedZoneByItsRowId(): void
    {
        $this->assertSame([], $this->repository->getOwnerIdsByZoneIds([1]), 'the canonical fallback fired on a populated domain_id');
    }

    public function testReturnsAnEmptyMapForNoIds(): void
    {
        $this->assertSame([], $this->repository->getOwnerIdsByZoneIds([]));
    }

    public function testOwnedZoneIdsUseTheLogIdSpaceAndIncludeGroupZones(): void
    {
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (100, 3)");
        $this->db->exec("INSERT INTO user_group_members (user_id, group_id) VALUES (20, 3)");

        $owned = $this->repository->getOwnedZoneIds(20);
        sort($owned);

        $this->assertSame([55, 100], $owned, 'stranded zone keyed by its row id plus the group-owned zone');
        $this->assertSame([56], $this->repository->getOwnedZoneIds(30));
        $this->assertSame([], $this->repository->getOwnedZoneIds(99));
    }

    public function testAggregatesSeveralOwnersOfOneZone(): void
    {
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (57, 55, NULL, 40)");

        $owners = $this->repository->getOwnerIdsByZoneIds([55])[55];
        sort($owners);

        $this->assertSame([20, 40], $owners);
    }
}
