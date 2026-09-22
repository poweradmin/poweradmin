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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\RecordSearch;

/**
 * Pins the row shape searchRecords() hands back from a real (SQLite) query:
 * the column keys and the disabled flag decoded to a bool (PostgreSQL 't'/'f' included).
 */
#[CoversClass(RecordSearch::class)]
class RecordSearchRowShapeTest extends TestCase
{
    private const ROW_KEYS = ['id', 'domain_id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled', 'zone_id', 'owner', 'user_id', 'fullname'];

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach (
            [
                "CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER)",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "CREATE TABLE users (id INTEGER PRIMARY KEY, fullname TEXT)",
                "INSERT INTO users (id, fullname) VALUES (7, 'Zone Owner')",
                "INSERT INTO zones (id, domain_id, owner) VALUES (3, 1, 7)",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
    }

    private function search(): RecordSearch
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === 'database' && $key === 'type' ? 'sqlite' : $default
        );

        return new RecordSearch($this->db, $config, 'sqlite');
    }

    private function insertRecord(int $id, string $name, mixed $disabled): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (:id, 1, :name, 'A', '192.0.2.1', 3600, 0, :disabled)"
        );
        $stmt->execute([':id' => $id, ':name' => $name, ':disabled' => $disabled]);
    }

    private function rows(bool $grouped = false): array
    {
        $parameters = [
            'query' => 'example.com',
            'zones' => false,
            'records' => true,
            'comments' => false,
            'wildcard' => true,
            'reverse' => false,
            'type_filter' => '',
            'content_filter' => '',
        ];

        return $this->search()->searchRecords($parameters, 'all', null, 'name', 'ASC', $grouped, 10, false, 1);
    }

    public function testRowsCarryTheSelectedColumnsPlusTheOwner(): void
    {
        $this->insertRecord(10, 'www.example.com', 0);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(self::ROW_KEYS, array_keys($rows[0]));
        $this->assertSame('www.example.com', $rows[0]['name']);
        $this->assertSame('Zone Owner', $rows[0]['fullname']);
    }

    public static function disabledProvider(): array
    {
        return [
            'integer zero' => [0, false],
            'integer one' => [1, true],
            'string one' => ['1', true],
            'string zero' => ['0', false],
            'postgres true' => ['t', true],
            'postgres false' => ['f', false],
        ];
    }

    #[DataProvider('disabledProvider')]
    public function testDisabledIsABool(mixed $stored, bool $expected): void
    {
        $this->insertRecord(10, 'www.example.com', $stored);

        $this->assertSame($expected, $this->rows()[0]['disabled']);
    }

    public function testGroupedRowsKeepTheSameShape(): void
    {
        $this->insertRecord(10, 'www.example.com', 1);
        $this->insertRecord(11, 'www.example.com', 1);

        $rows = $this->rows(true);

        $this->assertCount(1, $rows);
        $this->assertSame(self::ROW_KEYS, array_keys($rows[0]));
        $this->assertTrue($rows[0]['disabled']);
    }
}
