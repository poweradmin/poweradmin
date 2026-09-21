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
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Repository\ApiRecordSearch;
use Poweradmin\Infrastructure\Repository\ApiZoneSearch;
use Poweradmin\Infrastructure\Repository\ApiSearchBase;

/**
 * The API searches take the user for an 'own' view from the caller and ask the
 * zone repository which zones that user owns; no session is read.
 */
class ApiSearchOwnViewTest extends TestCase
{
    private PDO $db;
    private DnsBackendProviderInterface $backend;
    private ZoneRepositoryInterface $zones;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (7, 'alice', 'Alice')");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, comment) VALUES (11, 1, 7, ''), (12, 2, 7, '')");

        $this->backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $this->backend->method('searchDnsData')->willReturn([
            'zones' => [['id' => 1, 'name' => 'a.example', 'type' => 'NATIVE'], ['id' => 2, 'name' => 'b.example', 'type' => 'NATIVE']],
            'records' => [
                ['id' => 'r1', 'domain_id' => 1, 'name' => 'www.a.example', 'type' => 'A', 'content' => '192.0.2.1'],
                ['id' => 'r2', 'domain_id' => 2, 'name' => 'www.b.example', 'type' => 'A', 'content' => '192.0.2.2'],
            ],
        ]);
        $this->backend->method('countZoneRecords')->willReturn(0);

        $this->zones = $this->createMock(ZoneRepositoryInterface::class);
        $this->zones->method('getOwnedZoneIds')->willReturnCallback(fn(int $userId) => $userId === 7 ? [2] : []);
    }

    private function parameters(bool $zones): array
    {
        return ['query' => 'example', 'zones' => $zones, 'records' => !$zones, 'wildcard' => true, 'reverse' => false];
    }

    public function testOwnViewKeepsOnlyTheZonesTheRepositoryReportsForThatUser(): void
    {
        $zoneSearch = new ApiZoneSearch($this->db, $this->backend, $this->zones);
        $recordSearch = new ApiRecordSearch($this->db, $this->backend, $this->zones);

        $this->assertInstanceOf(ApiSearchBase::class, $zoneSearch);
        $this->assertSame([2], array_column($zoneSearch->searchZones($this->parameters(true), 'own', 7, 'name', 'ASC', 10, false, 1), 'id'));
        $this->assertSame(1, $zoneSearch->getTotalZones($this->parameters(true), 'own', 7));
        $this->assertSame(['r2'], array_column($recordSearch->searchRecords($this->parameters(false), 'own', 7, 'name', 'ASC', false, 10, false, 1), 'id'));
        $this->assertSame(1, $recordSearch->getTotalRecords($this->parameters(false), 'own', 7, false));
    }

    public function testOwnViewWithoutAUserMatchesNothing(): void
    {
        $zoneSearch = new ApiZoneSearch($this->db, $this->backend, $this->zones);
        $recordSearch = new ApiRecordSearch($this->db, $this->backend, $this->zones);

        $this->assertSame([], $zoneSearch->searchZones($this->parameters(true), 'own', null, 'name', 'ASC', 10, false, 1));
        $this->assertSame(0, $zoneSearch->getTotalZones($this->parameters(true), 'own', null));
        $this->assertSame([], $recordSearch->searchRecords($this->parameters(false), 'own', null, 'name', 'ASC', false, 10, false, 1));
        $this->assertSame(0, $recordSearch->getTotalRecords($this->parameters(false), 'own', null, false));
    }

    public function testAllViewNeverAsksForOwnership(): void
    {
        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->expects($this->never())->method('getOwnedZoneIds');

        $zoneSearch = new ApiZoneSearch($this->db, $this->backend, $zones);

        $this->assertSame([1, 2], array_column($zoneSearch->searchZones($this->parameters(true), 'all', null, 'name', 'ASC', 10, false, 1), 'id'));
    }
}
