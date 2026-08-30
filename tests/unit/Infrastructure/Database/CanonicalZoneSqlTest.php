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

    public function testCanonicalIdColumnRendersEachAliasForm(): void
    {
        $this->assertSame('COALESCE(NULLIF(domain_id, 0), id)', CanonicalZoneSql::canonicalIdColumn());
        $this->assertSame('COALESCE(NULLIF(z.domain_id, 0), z.id)', CanonicalZoneSql::canonicalIdColumn('z'));
        $this->assertSame('COALESCE(NULLIF(z.domain_id, 0), z.id)', CanonicalZoneSql::canonicalIdColumn('z.'));
        $this->assertSame('COALESCE(NULLIF(zones.domain_id, 0), zones.id)', CanonicalZoneSql::canonicalIdColumn('zones'));
    }

    /**
     * @return array<int, int>
     */
    private function canonicalIds(string $expression): array
    {
        $rows = $this->db->query("SELECT id, $expression AS canonical_id FROM zones ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'canonical_id', 'id');
    }

    public function testCanonicalIdColumnResolvesEveryDomainIdShape(): void
    {
        $this->seed(1, null, 'null.example.com');
        $this->seed(2, 0, 'zero.example.com');
        $this->seed(3, 99, 'migrated.example.com');
        $this->seed(4, 4, 'self.example.com');

        $expected = [1 => 1, 2 => 2, 3 => 99, 4 => 4];
        $this->assertSame($expected, $this->canonicalIds(CanonicalZoneSql::canonicalIdColumn()));
    }

    public function testBareCoalesceLeavesAZeroRowUnresolved(): void
    {
        // The reason this helper exists: COALESCE skips NULL only, so a row stranded at
        // domain_id = 0 resolves to 0 instead of its own id.
        $this->seed(2, 0, 'zero.example.com');

        $this->assertSame([2 => 0], $this->canonicalIds('COALESCE(domain_id, id)'));
        $this->assertSame([2 => 2], $this->canonicalIds(CanonicalZoneSql::canonicalIdColumn()));
    }

    public function testComparisonNeedsAnIntegerBoundId(): void
    {
        // An expression has no column affinity, so SQLite will not coerce a string-bound
        // id. Callers that compare against this must bind PDO::PARAM_INT.
        $this->seed(55, 0, 'zero.example.com');
        $sql = "SELECT id FROM zones WHERE " . CanonicalZoneSql::canonicalIdColumn() . " = ?";

        $asText = $this->db->prepare($sql);
        $asText->execute(['55']);
        $this->assertFalse($asText->fetchColumn(), 'a string-bound id must not be relied on');

        $asInt = $this->db->prepare($sql);
        $asInt->bindValue(1, 55, PDO::PARAM_INT);
        $asInt->execute();
        $this->assertSame(55, (int)$asInt->fetchColumn());
    }

    public function testCanonicalIdColumnParsesInEveryClauseItIsUsedIn(): void
    {
        $this->seed(1, null, 'null.example.com');
        $this->seed(2, 0, 'zero.example.com');
        $this->seed(3, 99, 'migrated.example.com');

        $aliased = CanonicalZoneSql::canonicalIdColumn('z');

        $where = $this->db->query("SELECT zone_name FROM zones z WHERE $aliased = 2")->fetchColumn();
        $this->assertSame('zero.example.com', $where);

        $distinct = $this->db->query("SELECT DISTINCT $aliased AS cid FROM zones z ORDER BY cid")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 2, 99], array_map('intval', $distinct));

        $this->db->exec("CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)");
        $this->db->exec("INSERT INTO zones_groups (domain_id, group_id) VALUES (2, 7)");
        $join = $this->db->query(
            "SELECT z.zone_name FROM zones z INNER JOIN zones_groups zg ON zg.domain_id = $aliased"
        )->fetchColumn();
        $this->assertSame('zero.example.com', $join);
    }
}
