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
use Poweradmin\Infrastructure\Repository\DbRecordTypeDefaultRepository;

#[CoversClass(DbRecordTypeDefaultRepository::class)]
class DbRecordTypeDefaultRepositoryTest extends TestCase
{
    private PDO $db;
    private DbRecordTypeDefaultRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(<<<SQL
            CREATE TABLE record_type_defaults (
                record_type text NOT NULL PRIMARY KEY,
                ttl integer NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $this->repository = new DbRecordTypeDefaultRepository($this->db);
    }

    public function testFindReturnsNullWhenNoRow(): void
    {
        $this->assertNull($this->repository->find('PTR'));
    }

    public function testSaveInsertsNewRow(): void
    {
        $this->repository->save('PTR', 300);
        $this->assertSame(300, $this->repository->find('PTR'));
    }

    public function testSaveUpdatesExistingRow(): void
    {
        $this->repository->save('MX', 1800);
        $this->repository->save('MX', 3600);
        $this->assertSame(3600, $this->repository->find('MX'));
    }

    public function testFindIsCaseInsensitiveOnInput(): void
    {
        $this->repository->save('ptr', 120);
        $this->assertSame(120, $this->repository->find('PTR'));
        $this->assertSame(120, $this->repository->find('ptr'));
    }

    public function testFindMatchesLowercaseRowFromRawSql(): void
    {
        $this->db->exec("INSERT INTO record_type_defaults (record_type, ttl) VALUES ('mx', 900)");
        $this->assertSame(900, $this->repository->find('MX'));
    }

    public function testUpdateMatchesLowercaseRowFromRawSql(): void
    {
        $this->db->exec("INSERT INTO record_type_defaults (record_type, ttl) VALUES ('mx', 900)");
        $this->repository->save('MX', 1800);
        $this->assertSame(1800, $this->repository->find('MX'));
        // Existing lowercase row was updated in place, no duplicate created.
        $count = $this->db->query("SELECT COUNT(*) FROM record_type_defaults")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testFindAllReturnsAllRowsKeyedByUppercaseType(): void
    {
        $this->repository->save('PTR', 300);
        $this->repository->save('MX', 1800);
        $this->repository->save('A', 60);

        $result = $this->repository->findAll();
        $this->assertSame(['A' => 60, 'MX' => 1800, 'PTR' => 300], $result);
    }

    public function testDeleteRemovesRow(): void
    {
        $this->repository->save('PTR', 300);
        $this->repository->delete('PTR');
        $this->assertNull($this->repository->find('PTR'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->repository->delete('NONEXISTENT');
        $this->assertNull($this->repository->find('NONEXISTENT'));
    }

    public function testZeroIsAValidStoredTtl(): void
    {
        $this->repository->save('PTR', 0);
        $this->assertSame(0, $this->repository->find('PTR'));
    }

    public function testFindReturnsNullWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbRecordTypeDefaultRepository($db);
        $this->assertNull($repository->find('PTR'));
    }

    public function testFindAllReturnsEmptyArrayWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbRecordTypeDefaultRepository($db);
        $this->assertSame([], $repository->findAll());
    }

    public function testSaveIsNoopWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbRecordTypeDefaultRepository($db);
        $repository->save('PTR', 300);
        $this->assertNull($repository->find('PTR'));
    }

    public function testDeleteIsNoopWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbRecordTypeDefaultRepository($db);
        $repository->delete('PTR');
        $this->assertNull($repository->find('PTR'));
    }

    public function testIsReadyReturnsTrueWhenTableExists(): void
    {
        $this->assertTrue($this->repository->isReady());
    }

    public function testIsReadyReturnsFalseWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbRecordTypeDefaultRepository($db);
        $this->assertFalse($repository->isReady());
    }
}
