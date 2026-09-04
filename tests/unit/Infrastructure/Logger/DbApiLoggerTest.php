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
        $this->insertRow('old', date('Y-m-d H:i:s', time() - 40 * 86400));
        $this->insertRow('recent', date('Y-m-d H:i:s', time() - 5 * 86400));

        (new DbApiLogger($this->db))->pruneOlderThan(30);

        $remaining = $this->db->query('SELECT event FROM log_api')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['recent'], $remaining);
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
}
