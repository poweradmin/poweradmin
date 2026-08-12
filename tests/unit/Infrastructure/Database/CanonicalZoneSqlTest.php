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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\CanonicalZoneSql;

/**
 * The zones id spaces overlap on installs migrated from SQL mode, so one identifier can
 * match two different zones. These pin which row wins, because both ApiDnsBackendProvider
 * and ApiZoneRepository write back to the row this resolves to.
 */
#[CoversClass(CanonicalZoneSql::class)]
class CanonicalZoneSqlTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT)");
    }

    private function seed(int $id, ?int $domainId, ?string $zoneName): void
    {
        $stmt = $this->db->prepare("INSERT INTO zones (id, domain_id, zone_name) VALUES (:id, :did, :name)");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':did', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $zoneName, $zoneName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }

    private function resolve(int $zoneId): ?array
    {
        $stmt = $this->db->prepare(CanonicalZoneSql::selectByZoneId('id, domain_id, zone_name'));
        CanonicalZoneSql::bindZoneId($stmt, $zoneId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function testResolvesAZoneThisApplicationCreated(): void
    {
        $this->seed(5, 5, 'example.com');

        $this->assertSame('example.com', $this->resolve(5)['zone_name']);
    }

    public function testMatchesByDomainIdWhenTheIdSpacesDiffer(): void
    {
        // Migrated install: the UI is handed domain_id 10, the row's primary key is 1.
        $this->seed(1, 10, 'example.com');

        $row = $this->resolve(10);

        $this->assertSame(1, (int)$row['id']);
        $this->assertSame('example.com', $row['zone_name']);
    }

    public function testDomainIdMatchBeatsAnUnrelatedRowSharingThatValueAsItsId(): void
    {
        $this->seed(1, 10, 'example.com');
        $this->seed(10, 77, 'other.example.com');

        // 10 is example.com's domain_id and also other.example.com's primary key. API mode
        // hands out domain_id, so example.com must win.
        $this->assertSame('example.com', $this->resolve(10)['zone_name']);
    }

    public function testSelfConsistentRowBeatsARowMerelySharingTheDomainId(): void
    {
        $this->seed(20, 20, 'self.example.com');
        $this->seed(21, 20, 'shadow.example.com');

        $this->assertSame('self.example.com', $this->resolve(20)['zone_name']);
    }

    public function testPlaceholderOwnershipRowsNeverWin(): void
    {
        // Extra owner rows share domain_id and carry no zone_name.
        $this->seed(1, 10, null);
        $this->seed(2, 10, 'example.com');

        $row = $this->resolve(10);

        $this->assertSame(2, (int)$row['id']);
        $this->assertSame('example.com', $row['zone_name']);
    }

    public function testReturnsNothingWhenOnlyPlaceholderRowsMatch(): void
    {
        $this->seed(1, 10, null);

        $this->assertNull($this->resolve(10));
    }

    public function testReturnsNothingForAnUnknownZoneId(): void
    {
        $this->seed(1, 10, 'example.com');

        $this->assertNull($this->resolve(999));
    }
}
