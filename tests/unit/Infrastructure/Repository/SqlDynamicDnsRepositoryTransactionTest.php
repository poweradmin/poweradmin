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
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Infrastructure\Repository\SqlDynamicDnsRepository;

/**
 * A dynamic DNS sync that fails part way leaves none of its inserts or deletes behind.
 */
class SqlDynamicDnsRepositoryTransactionTest extends TestCase
{
    private PDO $db;
    private SqlDynamicDnsRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)');
        $this->repository = new SqlDynamicDnsRepository($this->db, $this->createMock(SOARecordManagerInterface::class), 'records', 'domains');
    }

    private function recordCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM records')->fetchColumn();
    }

    public function testAFailureRollsBackTheInsertsAlreadyMade(): void
    {
        $host = new HostnameValue('h.example.com');

        try {
            $this->repository->transactional(function () use ($host): void {
                $this->repository->insertDnsRecord(1, $host, 'A', '10.0.0.1');
                $this->repository->insertDnsRecord(1, $host, 'A', '10.0.0.2');
                throw new \RuntimeException('boom');
            });
            $this->fail('The failure must reach the caller');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $this->recordCount());
    }

    public function testASuccessfulSyncIsCommittedAndReturnsTheResult(): void
    {
        $host = new HostnameValue('h.example.com');

        $result = $this->repository->transactional(function () use ($host): string {
            $this->repository->insertDnsRecord(1, $host, 'A', '10.0.0.1');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $this->recordCount());
    }

    public function testTheSqliteTransactionTakesTheWriteLockUpFrontAndStaysVisibleToPdo(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ddns');
        try {
            $db = new PDO('sqlite:' . $file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, content TEXT)');
            $other = new PDO('sqlite:' . $file);
            $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $other->exec('PRAGMA busy_timeout = 0');
            $repository = new SqlDynamicDnsRepository($db, $this->createMock(SOARecordManagerInterface::class), 'records', 'domains');

            $seen = $repository->transactional(function () use ($db, $other): array {
                try {
                    $other->exec('BEGIN IMMEDIATE');
                    $other->exec('ROLLBACK');
                    $locked = false;
                } catch (\PDOException) {
                    $locked = true;
                }

                // Nested code such as the SOA serial update joins only a transaction PDO knows about
                return ['inTransaction' => $db->inTransaction(), 'locked' => $locked];
            });

            $this->assertSame(['inTransaction' => true, 'locked' => true], $seen);
            $this->assertFalse($db->inTransaction());
        } finally {
            @unlink($file);
        }
    }
}
