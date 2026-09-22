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
 *
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;

/**
 * PowerDNS reads the same SQLite file Poweradmin writes, so the connection has
 * to be opened in a journal mode that lets a reader and a writer overlap.
 */
#[CoversClass(PDODatabaseConnection::class)]
class PDODatabaseConnectionSqliteTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/poweradmin-wal-test-' . getmypid() . '.db';
        $this->removeDatabaseFiles();
    }

    protected function tearDown(): void
    {
        $this->removeDatabaseFiles();
    }

    public function testAFileBackedDatabaseIsOpenedInWalMode(): void
    {
        $pdo = (new PDODatabaseConnection())->connect([
            'db_type' => 'sqlite',
            'db_file' => $this->file,
            'db_user' => '',
            'db_pass' => '',
        ]);

        $this->assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testAWriterIsNotBlockedByAReaderHoldingATransaction(): void
    {
        $connection = new PDODatabaseConnection();
        $credentials = ['db_type' => 'sqlite', 'db_file' => $this->file, 'db_user' => '', 'db_pass' => ''];

        $reader = $connection->connect($credentials);
        $reader->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT)');
        $reader->exec("INSERT INTO zones (name) VALUES ('seed')");

        $writer = $connection->connect($credentials);

        $reader->beginTransaction();
        $reader->query('SELECT * FROM zones')->fetchAll();

        // Without WAL this raises "General error: 5 database is locked"
        $this->assertSame(1, $writer->exec("INSERT INTO zones (name) VALUES ('other-process')"));

        $reader->rollBack();
    }

    public function testAnInMemoryDatabaseKeepsWorkingWhereWalCannotApply(): void
    {
        $pdo = (new PDODatabaseConnection())->connect([
            'db_type' => 'sqlite',
            'db_file' => ':memory:',
            'db_user' => '',
            'db_pass' => '',
        ]);

        $this->assertNotSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    /**
     * Switching the journal needs a writable file, so a read-only database must
     * keep working in whatever mode it already has rather than failing to
     * connect, which would take down every page including the login form.
     */
    public function testAReadOnlyDatabaseStillConnects(): void
    {
        $connection = new PDODatabaseConnection();
        $credentials = ['db_type' => 'sqlite', 'db_file' => $this->file, 'db_user' => '', 'db_pass' => ''];

        $seed = $connection->connect($credentials);
        $seed->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT)');
        $seed->exec("INSERT INTO zones (name) VALUES ('seed')");
        $seed->exec('PRAGMA journal_mode = DELETE');
        unset($seed);
        chmod($this->file, 0444);

        try {
            $pdo = $connection->connect($credentials);
            $this->assertSame('seed', $pdo->query('SELECT name FROM zones')->fetchColumn());
        } finally {
            chmod($this->file, 0644);
        }
    }

    /**
     * The switch needs an exclusive lock, so it must be skipped rather than
     * fatal while another connection is mid-read on a rollback-journal file.
     */
    public function testConnectingSucceedsWhileAnotherConnectionHoldsAReadLock(): void
    {
        $connection = new PDODatabaseConnection();
        $credentials = ['db_type' => 'sqlite', 'db_file' => $this->file, 'db_user' => '', 'db_pass' => ''];

        $seed = $connection->connect($credentials);
        $seed->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, name TEXT)');
        $seed->exec("INSERT INTO zones (name) VALUES ('seed')");
        $seed->exec('PRAGMA journal_mode = DELETE');

        $reader = new \PDO('sqlite:' . $this->file);
        $reader->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $reader->beginTransaction();
        $reader->query('SELECT * FROM zones')->fetchAll();

        $pdo = $connection->connect($credentials);
        $this->assertSame('seed', $pdo->query('SELECT name FROM zones')->fetchColumn());

        $reader->rollBack();
    }

    private function removeDatabaseFiles(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
