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

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneCountService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * Zones created in API mode have no domain_id, so the own-scope count has to
 * match them by their canonical id or the zone list undercounts and loses pages.
 */
class ZoneCountServiceApiOwnershipTest extends TestCase
{
    private const USER_ID = 5;

    private PDO $db;

    protected function setUp(): void
    {
        CanonicalZoneSql::setRowIdFallback(true);
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT NULL, owner INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE zones_groups (domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE user_group_members (user_id INTEGER NOT NULL, group_id INTEGER NOT NULL)');

        // 10 was created in API mode (no domain_id), 11 mirrors PowerDNS domain 20.
        $this->db->exec('INSERT INTO zones (id, domain_id, owner) VALUES (10, NULL, ' . self::USER_ID . ')');
        $this->db->exec('INSERT INTO zones (id, domain_id, owner) VALUES (11, 20, ' . self::USER_ID . ')');
        $this->db->exec('INSERT INTO zones (id, domain_id, owner) VALUES (12, 30, 99)');
    }

    protected function tearDown(): void
    {
        CanonicalZoneSql::setRowIdFallback(true);
    }

    public function testOwnScopeCountsZonesCreatedWithoutADomainId(): void
    {
        $this->assertSame(2, $this->service()->countZones('own'));
    }

    public function testAllScopeCountsEveryForwardZone(): void
    {
        $this->assertSame(3, $this->service()->countZones('all'));
    }

    public function testOwnScopeCountsASharedIdOnlyForTheResolvedZonesDirectOwner(): void
    {
        // Zone 40 was created here and migrated zone 41 also carries canonical id 40
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner) VALUES (40, 40, 'created.example', 7), (41, 40, 'migrated.example', " . self::USER_ID . ")");
        $this->db->exec('INSERT INTO zones (id, domain_id, owner) VALUES (42, 40, ' . self::USER_ID . ')');
        $zones = [['id' => 40, 'name' => 'created.example.'], ['id' => 40, 'name' => 'migrated.example.']];

        $this->assertSame(2, $this->service($zones)->countZones('own'));
    }

    /** @param array<int, array{id: int, name: string}>|null $extraZones */
    private function service(?array $extraZones = null): ZoneCountService
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('getZones')->willReturn(array_merge([
            ['id' => 10, 'name' => 'api-created.example.'],
            ['id' => 20, 'name' => 'mirrored.example.'],
            ['id' => 30, 'name' => 'someone-else.example.'],
        ], $extraZones ?? []));

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(self::USER_ID);

        return new ZoneCountService($this->db, $this->createMock(ConfigurationInterface::class), $userContext, $backend);
    }
}
