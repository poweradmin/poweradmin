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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\DbApiLogger;

class DbApiLoggerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE log_api (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event VARCHAR(2048) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            priority INTEGER NOT NULL
        )");
    }

    private function insertRow(string $event, string $createdAt): void
    {
        $stmt = $this->db->prepare('INSERT INTO log_api (event, created_at, priority) VALUES (:e, :c, 6)');
        $stmt->execute([':e' => $event, ':c' => $createdAt]);
    }

    #[Test]
    public function pruneOlderThanDeletesOnlyRowsPastRetention(): void
    {
        $this->insertRow('old', gmdate('Y-m-d H:i:s', time() - 40 * 86400));
        $this->insertRow('recent', gmdate('Y-m-d H:i:s', time() - 5 * 86400));

        (new DbApiLogger($this->db))->pruneOlderThan(30);

        $remaining = $this->db->query('SELECT event FROM log_api')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['recent'], $remaining);
    }

    /** The cutoff must come from the same clock that wrote created_at, whatever the PHP time zone is */
    #[Test]
    public function pruneOlderThanComparesAgainstTheDatabaseClock(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $this->db->exec("INSERT INTO log_api (event, created_at, priority) VALUES ('old', datetime('now', '-30 days', '-1 hour'), 6)");
            $this->db->exec("INSERT INTO log_api (event, created_at, priority) VALUES ('recent', datetime('now', '-30 days', '+1 hour'), 6)");

            (new DbApiLogger($this->db))->pruneOlderThan(30);
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame(['recent'], $this->db->query('SELECT event FROM log_api')->fetchAll(PDO::FETCH_COLUMN));
    }

    #[Test]
    public function pruneOlderThanSendsNoPhpClockValueToTheDatabase(): void
    {
        $db = new class ('sqlite::memory:') extends PDO {
            /** @var list<string> */
            public array $executed = [];

            public function exec(string $statement): int|false
            {
                $this->executed[] = $statement;

                return parent::exec($statement);
            }
        };
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE log_api (id INTEGER PRIMARY KEY, event TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, priority INTEGER)');

        (new DbApiLogger($db))->pruneOlderThan(30);

        $deletes = array_values(array_filter($db->executed, fn(string $sql): bool => str_starts_with($sql, 'DELETE')));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString("datetime('now'", $deletes[0]);
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $deletes[0]);
    }

    #[Test]
    public function pruneOlderThanIsNoOpForZeroOrNegativeDays(): void
    {
        $this->insertRow('old', date('Y-m-d H:i:s', time() - 400 * 86400));

        $logger = new DbApiLogger($this->db);
        $logger->pruneOlderThan(0);
        $logger->pruneOlderThan(-5);

        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM log_api')->fetchColumn());
    }

    #[Test]
    public function distinctEventTypesIncludePerRequestAndViolation(): void
    {
        $types = (new DbApiLogger($this->db))->getDistinctEventTypes();

        $this->assertContains('api_request', $types);
        $this->assertContains('api_violation', $types);
        // Pre-existing CRUD audit types must remain
        $this->assertContains('api_key_create', $types);
    }

    /** The user: field stores colons as %3A, so the name filter must build the same form */
    #[Test]
    public function nameFilterMatchesAUsernameWithAColon(): void
    {
        $this->insertRow('operation:api_request method:GET path:/api/v2/zones status:200 key_id:4 user:urn%3Aann client_ip:192.0.2.1', date('Y-m-d H:i:s'));
        $this->insertRow('operation:api_request method:GET path:/api/v2/zones status:200 key_id:5 user:bob client_ip:192.0.2.1', date('Y-m-d H:i:s'));

        $this->assertSame(1, (new DbApiLogger($this->db))->countFilteredLogs(['name' => 'urn:ann']));
    }
}
