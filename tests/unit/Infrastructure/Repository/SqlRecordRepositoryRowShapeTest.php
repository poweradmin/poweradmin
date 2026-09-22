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

use Poweradmin\Domain\Model\RecordRow;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;

/**
 * Pins the row shape the record reads hand back from a real (SQLite) query: the
 * column keys and the values of the disabled and auth flags.
 */
#[CoversClass(SqlRecordRepository::class)]
class SqlRecordRepositoryRowShapeTest extends TestCase
{
    private const ROW_KEYS = ['id', 'domain_id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled', 'ordername', 'auth'];

    private PDO $db;
    private SqlRecordRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER, ordername TEXT, auth INTEGER)");
        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, comment TEXT, account TEXT, modified_at INTEGER)");
        $this->db->exec("INSERT INTO domains (id, name) VALUES (1, 'example.com')");

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $section, string $key, $default = null) => $section === 'database' && $key === 'type' ? 'sqlite' : $default
        );
        $this->repository = new SqlRecordRepository($this->db, $config);
    }

    /**
     * SQLite keeps whatever it is given, so the same table can hold the integer
     * flags MySQL returns and the 't'/'f' strings PostgreSQL returns.
     */
    private function insert(int $id, string $name, string $type, mixed $disabled, mixed $auth): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES (:id, 1, :name, :type, '192.0.2.1', 3600, 0, :disabled, NULL, :auth)"
        );
        $stmt->execute([':id' => $id, ':name' => $name, ':type' => $type, ':disabled' => $disabled, ':auth' => $auth]);
    }

    public function testEveryReadReturnsTheSameColumnsWithDecodedFlags(): void
    {
        $this->insert(1, 'example.com', 'SOA', 0, 1);
        $this->insert(2, 'www.example.com', 'A', 1, 0);
        $this->insert(3, 'pg.example.com', 'A', 't', 'f');

        $byId = $this->repository->getRecordById(2);
        $this->assertSame(self::ROW_KEYS, array_keys($byId));
        $this->assertTrue($byId['disabled']);
        $this->assertFalse($byId['auth']);

        $fromId = $this->repository->getRecordFromId(1);
        $this->assertSame(self::ROW_KEYS, array_keys($fromId));
        $this->assertFalse($fromId['disabled']);
        $this->assertTrue($fromId['auth']);

        // The listing crosses the boundary as a read model, so it is the one
        // read whose shape is defined by RecordRow rather than by the columns
        $listing = $this->repository->getRecordsFromDomainId(1);
        $this->assertContainsOnlyInstancesOf(RecordRow::class, $listing);
        foreach (['id', 'domain_id', 'name', 'type', 'content', 'ttl', 'prio', 'disabled', 'comment', 'comment_account', 'comment_modified_at'] as $key) {
            $this->assertArrayHasKey($key, $listing[0]->toArray());
        }
        $this->assertSame([false, true, true], array_map(static fn(RecordRow $r): bool => $r->disabled, $listing));
        $this->assertSame([true, false, false], array_map(static fn(RecordRow $r): ?bool => $r->auth, $listing));

        // The filtered listing crosses the same boundary as the full one
        $filtered = $this->repository->getFilteredRecords(1, 0, 100, 'name', 'ASC', false);
        $this->assertContainsOnlyInstancesOf(RecordRow::class, $filtered);
        $this->assertSame([false, true, true], array_map(static fn(RecordRow $r): bool => $r->disabled, $filtered));

        $byDomain = $this->repository->getRecordsByDomainId(1, 'A');
        $this->assertSame(self::ROW_KEYS, array_keys($byDomain[0]));
        $this->assertSame([true, true], array_column($byDomain, 'disabled'));
        $this->assertSame([false, false], array_column($byDomain, 'auth'));

        $byName = $this->repository->getRecordsByName(1, 'PG.example.com');
        $this->assertTrue($byName[0]['disabled']);
        $this->assertFalse($byName[0]['auth']);

        $rrset = $this->repository->getRRSetRecords(1, 'pg.example.com', 'A');
        $this->assertTrue($rrset[0]['disabled']);
        $this->assertFalse($rrset[0]['auth']);

        $details = $this->repository->getRecordDetailsFromRecordId(3);
        $this->assertSame(['rid', 'zid', 'name', 'type', 'content', 'ttl', 'prio', 'disabled'], array_keys($details));
        $this->assertTrue($details['disabled']);
    }

    public function testAPostgresFalseStringDecodesToFalse(): void
    {
        $this->insert(4, 'off.example.com', 'A', 'f', 't');

        $row = $this->repository->getRecordById(4);

        $this->assertFalse($row['disabled']);
        $this->assertTrue($row['auth']);
    }

    public function testTheOtherColumnsKeepTheDriverValues(): void
    {
        $this->insert(5, 'www.example.com', 'A', 0, 1);

        $row = $this->repository->getRecordById(5);

        $this->assertSame(5, $row['id']);
        $this->assertSame('www.example.com', $row['name']);
        $this->assertSame(3600, $row['ttl']);
        $this->assertNull($row['ordername']);
    }
}
