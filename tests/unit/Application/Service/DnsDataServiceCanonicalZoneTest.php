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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\UserContextService;
use TestHelpers\FakeConfiguration;

/**
 * The API-mode search enriches zones and records with owners from the zones table, keyed
 * by canonical id. The zones.id fallback is what lets a row stranded at domain_id 0 keep
 * its owner; whether it applies is the backend's answer, not a process-wide default.
 */
class DnsDataServiceCanonicalZoneTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, zone_name TEXT, owner INTEGER, comment TEXT)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'alice', 'Alice')");
        $this->db->exec("INSERT INTO zones (id, domain_id, zone_name, owner, comment) VALUES (55, 0, 'stranded.example.com', 1, 'c')");
    }

    private function service(bool $zonesTableIsCanonical): DnsDataService
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn(true);
        $backend->method('allocatesZoneIdsLocally')->willReturn($zonesTableIsCanonical);
        $backend->method('searchDnsData')->willReturn([
            'zones' => [['id' => 55, 'name' => 'stranded.example.com', 'type' => 'NATIVE']],
            'records' => [['id' => 'r1', 'domain_id' => 55, 'name' => 'www.stranded.example.com', 'type' => 'A', 'content' => '192.0.2.1']],
        ]);
        $backend->method('countZoneRecords')->willReturn(0);

        return new DnsDataService($backend, $this->db, new FakeConfiguration(['database' => ['type' => 'sqlite']]), new UserContextService());
    }

    public function testStrandedZoneKeepsItsOwnerWhenTheRowIdIsCanonical(): void
    {
        $zones = $this->service(true)->searchZones(['query' => 'stranded', 'zones' => true, 'records' => false], 'all', 'name', 'ASC', 10, false, 1);
        $records = $this->service(true)->searchRecords(['query' => 'stranded', 'zones' => false, 'records' => true], 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame(['alice'], $zones[0]['owner_usernames']);
        $this->assertSame('Alice', $records[0]['fullname']);
    }

    public function testStrandedZoneHasNoOwnerWithoutTheRowIdFallback(): void
    {
        // A backend that does not allocate zone ids locally matches domain_id alone.
        $zones = $this->service(false)->searchZones(['query' => 'stranded', 'zones' => true, 'records' => false], 'all', 'name', 'ASC', 10, false, 1);
        $records = $this->service(false)->searchRecords(['query' => 'stranded', 'zones' => false, 'records' => true], 'all', 'name', 'ASC', false, 10, false, 1);

        $this->assertSame([], $zones[0]['owner_usernames']);
        $this->assertSame('', $records[0]['fullname']);
    }
}
