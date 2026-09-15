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
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;

/**
 * getZone() in API mode returns the same key set as the SQL repository, with
 * the extra ownership rows folded into the owner lists.
 */
#[CoversClass(ApiZoneRepository::class)]
class ApiZoneRepositoryGetZoneTest extends TestCase
{
    private PDO $db;
    private ApiZoneRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT NULL, zone_type TEXT,
                    zone_master TEXT, comment TEXT, owner INTEGER NULL, zone_templ_id INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)",
                "INSERT INTO users VALUES (5, 'alice', 'Alice A'), (6, 'bob', NULL)",
                "INSERT INTO zones VALUES
                    (7, 0, 'signed.example', 'MASTER', NULL, 'signed zone', 5, 0),
                    (8, 7, NULL, NULL, NULL, NULL, 6, 0),
                    (9, 0, 'plain.example', 'NATIVE', NULL, NULL, NULL, 0)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $provider = $this->createMock(DnsBackendProviderInterface::class);
        $provider->method('countZoneRecords')->willReturn(4);
        $provider->method('getZoneById')->willReturnCallback(
            fn(int $id) => $id === 7 ? ['id' => 7, 'name' => 'signed.example', 'dnssec' => true] : null
        );

        $this->repository = new ApiZoneRepository(
            $this->db,
            $provider,
            'sqlite',
            $this->createMock(ConfigurationManager::class)
        );
    }

    public function testGetZoneReturnsTheGetZoneByIdCorePlusTheWebExtras(): void
    {
        $zone = $this->repository->getZone(7);

        $this->assertNotNull($zone);
        $expectedKeys = [
            'id', 'name', 'type', 'master', 'account', 'owner', 'record_count',
            'count_records', 'username', 'fullname', 'secured', 'comment', 'utf8_name',
            'owners', 'full_names', 'users',
        ];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($zone));

        $this->assertSame(7, (int)$zone['id']);
        $this->assertSame('signed.example', $zone['name']);
        $this->assertSame('MASTER', $zone['type']);
        $this->assertNull($zone['master']);
        $this->assertSame('', $zone['account']);
        $this->assertSame(5, $zone['owner']);
        $this->assertSame(4, $zone['record_count']);
        $this->assertSame(4, $zone['count_records']);
        $this->assertTrue($zone['secured']);
        $this->assertSame('signed zone', $zone['comment']);
        $this->assertSame(['alice', 'bob'], $zone['owners']);
        $this->assertSame(['Alice A', ''], $zone['full_names']);
        $this->assertSame(['alice', 'bob'], $zone['users']);
    }

    public function testGetZoneOnAnUnownedZone(): void
    {
        $zone = $this->repository->getZone(9);

        $this->assertNotNull($zone);
        $this->assertSame(0, $zone['owner']);
        $this->assertFalse($zone['secured']);
        $this->assertNull($zone['username']);
        $this->assertSame('', $zone['comment']);
        $this->assertSame([], $zone['owners']);
        $this->assertSame([], $zone['users']);
    }

    public function testGetZoneReturnsNullForAMissingZone(): void
    {
        $this->assertNull($this->repository->getZone(999));
    }
}
