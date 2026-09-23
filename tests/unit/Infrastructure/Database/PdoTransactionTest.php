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
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\PdoTransaction;

class PdoTransactionTest extends TestCase
{
    private PDO $db;
    private PdoTransaction $transaction;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->transaction = new PdoTransaction($this->db);
    }

    private function rowCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM t')->fetchColumn();
    }

    public function testRowsAreVisibleInsideAndGoneAfterRollBack(): void
    {
        $this->assertFalse($this->transaction->inTransaction());
        $this->transaction->begin();
        $this->assertTrue($this->transaction->inTransaction());
        $this->assertTrue($this->transaction->inTransaction(), 'the port drives the same handle the repositories write through');

        $this->db->exec('INSERT INTO t (id) VALUES (1)');
        $this->assertSame(1, $this->rowCount());

        $this->transaction->rollBack();
        $this->assertFalse($this->transaction->inTransaction());
        $this->assertSame(0, $this->rowCount());
    }

    public function testCommitKeepsTheRows(): void
    {
        $this->transaction->begin();
        $this->db->exec('INSERT INTO t (id) VALUES (1)');
        $this->transaction->commit();

        $this->assertFalse($this->transaction->inTransaction());
        $this->assertSame(1, $this->rowCount());
    }

    public function testASecondPortOverTheSameHandleSeesTheOpenTransaction(): void
    {
        // Several services wrap the same connection in their own port; they all
        // have to agree, or one of them opens a second transaction and fails.
        $other = new PdoTransaction($this->db);

        $this->transaction->begin();
        $this->assertTrue($other->inTransaction());

        $this->transaction->rollBack();
        $this->assertFalse($other->inTransaction());
    }

    public function testTheWriteLockIsTakenAtBeginOnSqlite(): void
    {
        $file = sys_get_temp_dir() . '/poweradmin-tx-lock-' . getmypid() . '.db';
        @unlink($file);

        try {
            $owner = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $owner->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
            $transaction = new PdoTransaction($owner);
            $transaction->begin();

            $other = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $other->exec('PRAGMA busy_timeout = 250');

            $refused = false;
            try {
                $other->exec('BEGIN IMMEDIATE');
            } catch (\PDOException) {
                $refused = true;
            }

            $transaction->rollBack();
            $this->assertTrue($refused, 'the write lock must be held from BEGIN, not from the first write');
        } finally {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                @unlink($path);
            }
        }
    }
}
