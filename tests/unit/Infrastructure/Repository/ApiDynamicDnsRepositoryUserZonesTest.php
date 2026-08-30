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
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Repository\ApiDynamicDnsRepository;

/**
 * getUserZones() decides which zones a dyndns client may update, so a zone missing here
 * answers "!yours". It must resolve the canonical id for API-mode rows and must keep
 * extra-ownership rows, which carry no zone_name and reach the zone through domain_id.
 */
class ApiDynamicDnsRepositoryUserZonesTest extends TestCase
{
    private PDO $db;
    private ApiDynamicDnsRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER)");

        $this->repository = new ApiDynamicDnsRepository(
            $this->db,
            $this->createMock(DnsRecord::class),
            $this->createMock(DnsBackendProvider::class)
        );
    }

    private function seed(int $id, ?int $domainId, ?string $name, int $owner): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (:id, :did, :name, :owner)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function zonesFor(int $userId): array
    {
        $zones = $this->repository->getUserZones(new User($userId, 'hash', false));
        sort($zones);

        return $zones;
    }

    public function testSecondaryOwnerKeepsTheZone(): void
    {
        // addOwnerToZone() records extra ownership as a row with no zone_name; filtering
        // those out made dyndns answer "!yours" for a legitimate secondary owner.
        $this->seed(30, 30, 'shared.example.com', 1);
        $this->seed(31, 30, null, 2);

        $this->assertSame([30], $this->zonesFor(2));
    }

    public function testStrandedZoneResolvesToItsOwnId(): void
    {
        $this->seed(55, 0, 'stranded.example.com', 1);

        $this->assertSame([55], $this->zonesFor(1));
    }

    public function testNullDomainIdZoneResolvesToItsOwnId(): void
    {
        $this->seed(56, null, 'nulled.example.com', 1);

        $this->assertSame([56], $this->zonesFor(1));
    }

    public function testMigratedZoneResolvesByDomainId(): void
    {
        $this->seed(7, 201, 'migrated.example.com', 1);

        $this->assertSame([201], $this->zonesFor(1));
    }

    public function testAnotherUsersZoneIsNotListed(): void
    {
        $this->seed(55, 0, 'theirs.example.com', 2);

        $this->assertSame([], $this->zonesFor(1));
    }

    public function testOwningBothTheZoneAndAnExtraRowYieldsOneEntry(): void
    {
        $this->seed(40, 40, 'mine.example.com', 1);
        $this->seed(41, 40, null, 1);

        $this->assertSame([40], $this->zonesFor(1));
    }
}
