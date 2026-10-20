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
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Repository\ApiRecordSearch;
use Poweradmin\Infrastructure\Repository\ApiZoneSearch;

/**
 * In API backend mode a zone created here and a zone migrated from SQL mode can share a
 * canonical id. Search lists only the zone that id opens, as the zone list does.
 */
class ApiSearchSharedZoneIdTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0, zone_name TEXT, zone_type TEXT, zone_master TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        // Row 7 was migrated with canonical id 4011; a.example was later created with row id 4011
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_name) VALUES (7, 4011, 1, 'b.example'), (4011, 4011, 2, 'a.example'), (20, 20, 1, 'c.example')");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'one', 'One'), (2, 'two', 'Two')");
        SharedZoneIds::forget($this->db);
    }

    public function testZoneSearchListsOnlyTheZoneASharedIdOpens(): void
    {
        $search = new ApiZoneSearch($this->db, $this->backend([
            'zones' => [
                ['id' => 4011, 'name' => 'a.example', 'type' => 'NATIVE'],
                ['id' => 4011, 'name' => 'b.example', 'type' => 'NATIVE'],
                ['id' => 20, 'name' => 'c.example', 'type' => 'NATIVE'],
            ],
            'records' => [],
        ]), $this->createMock(ZoneRepositoryInterface::class));
        $parameters = ['query' => 'example', 'zones' => true, 'records' => false];

        $zones = $search->searchZones($parameters, 'all', null, 'name', 'ASC', 10, false, 1);

        $this->assertSame(['a.example', 'c.example'], array_column($zones, 'name'));
        $this->assertSame(2, $search->getTotalZones($parameters, 'all', null));
    }

    public function testRecordSearchListsOnlyRecordsOfTheZoneASharedIdOpens(): void
    {
        $record = fn(string $name, int $domainId, string $zone): array => [
            'id' => $name, 'domain_id' => $domainId, 'name' => $name, 'type' => 'A', 'content' => '192.0.2.1',
            'ttl' => 3600, 'prio' => 0, 'disabled' => 0, 'zone_name' => $zone,
        ];
        $search = new ApiRecordSearch($this->db, $this->backend([
            'zones' => [],
            'records' => [
                $record('www.a.example', 4011, 'a.example'),
                $record('www.b.example', 4011, 'b.example'),
                $record('www.c.example', 20, 'c.example'),
            ],
        ]), $this->createMock(ZoneRepositoryInterface::class));
        $parameters = ['query' => 'www', 'zones' => false, 'records' => true, 'type_filter' => '', 'content_filter' => ''];

        $records = $search->searchRecords($parameters, 'all', null, 'name', 'ASC', false, 10, false, 1);

        $this->assertSame(['www.a.example', 'www.c.example'], array_column($records, 'name'));
        $this->assertSame(2, $search->getTotalRecords($parameters, 'all', null, false));
    }

    /** @param array{zones: array<int, array<string, mixed>>, records: array<int, array<string, mixed>>} $results */
    private function backend(array $results): DnsBackendProviderInterface
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('allocatesZoneIdsLocally')->willReturn(true);
        $backend->method('searchDnsData')->willReturn($results);
        $backend->method('countZoneRecords')->willReturn(0);

        return $backend;
    }
}
