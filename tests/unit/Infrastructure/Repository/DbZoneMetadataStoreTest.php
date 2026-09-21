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
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;

#[CoversClass(DbZoneMetadataStore::class)]
class DbZoneMetadataStoreTest extends TestCase
{
    private const ZONE_ID = 5;

    private PDO $db;
    private DbZoneMetadataStore $store;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE domainmetadata (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, kind TEXT, content TEXT)');
        $this->db->exec("INSERT INTO domainmetadata (domain_id, kind, content) VALUES
            (5, 'X-NOTE', 'old'), (5, 'ALLOW-AXFR-FROM', '192.0.2.10'), (6, 'X-NOTE', 'other zone')");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn(null);
        $this->store = new DbZoneMetadataStore($this->db, $config);
    }

    /** @return list<array{kind: string, content: string}> */
    private function rowsOf(int $zoneId): array
    {
        return $this->db->query("SELECT kind, content FROM domainmetadata WHERE domain_id = $zoneId ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testLoadReturnsTheZonesRowsSortedByKind(): void
    {
        $this->assertSame([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'X-NOTE', 'content' => 'old'],
        ], $this->store->load(self::ZONE_ID, 'example.com'));
    }

    public function testReplaceAllRewritesTheZonesRowsAndLeavesOtherZonesAlone(): void
    {
        $rows = [['kind' => 'SOA-EDIT', 'content' => 'EPOCH']];

        $this->assertTrue($this->store->replaceAll(self::ZONE_ID, 'example.com', $rows, []));

        $this->assertSame($rows, $this->rowsOf(self::ZONE_ID));
        $this->assertSame([['kind' => 'X-NOTE', 'content' => 'other zone']], $this->rowsOf(6));
    }

    public function testReplaceAllWithNoRowsClearsTheZone(): void
    {
        $this->assertTrue($this->store->replaceAll(self::ZONE_ID, 'example.com', [], []));

        $this->assertSame([], $this->rowsOf(self::ZONE_ID));
    }

    public function testReplaceKindRewritesOnlyThatKindFromTheSnapshot(): void
    {
        $before = $this->store->load(self::ZONE_ID, 'example.com');

        $this->assertTrue($this->store->replaceKind(self::ZONE_ID, 'example.com', 'X-NOTE', ['new', 'newer'], $before));

        $this->assertSame([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'X-NOTE', 'content' => 'new'],
            ['kind' => 'X-NOTE', 'content' => 'newer'],
        ], $this->rowsOf(self::ZONE_ID));
    }

    public function testReplaceKindWithNoValuesRemovesTheKind(): void
    {
        $before = $this->store->load(self::ZONE_ID, 'example.com');

        $this->assertTrue($this->store->replaceKind(self::ZONE_ID, 'example.com', 'X-NOTE', [], $before));

        $this->assertSame([['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10']], $this->rowsOf(self::ZONE_ID));
    }

    public function testAFailedWriteRollsBackAndReportsFalse(): void
    {
        $this->db->exec('DROP TABLE domainmetadata');

        $this->assertFalse($this->store->replaceAll(self::ZONE_ID, 'example.com', [['kind' => 'X', 'content' => '1']], []));
    }

    public function testTheTableHoldsAnyKindWithoutAskingTheServer(): void
    {
        $neverAsked = fn(): PdnsCapabilities => $this->fail('the database store must not probe the server version');

        $this->assertNull($this->store->writeRejection('PRESIGNED'));
        $this->assertNull($this->store->writeRejection('MY-KIND'));
        $this->assertSame(DbZoneMetadataStore::SUPPORT_SUPPORTED, $this->store->kindSupport(['min_version' => '9.9.9'], $neverAsked));
    }
}
