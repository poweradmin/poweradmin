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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Logger\DbGroupLogger;
use Poweradmin\Infrastructure\Logger\DbUserLogger;

/**
 * The log type filters match the operation as a whole field wherever it sits in the
 * line: operations that end the line (logout) are found, siblings sharing a prefix are not.
 */
#[CoversClass(DbCompat::class)]
#[CoversClass(DbUserLogger::class)]
#[CoversClass(DbApiLogger::class)]
#[CoversClass(DbGroupLogger::class)]
class DbLogOperationFilterTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['log_users', 'log_api', 'log_groups'] as $table) {
            $extra = $table === 'log_groups' ? ', group_id INTEGER' : '';
            $this->db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY AUTOINCREMENT, event VARCHAR(2048) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, priority INTEGER NOT NULL DEFAULT 6$extra)");
        }
    }

    /** @param list<string> $events */
    private function insert(string $table, array $events): void
    {
        $stmt = $this->db->prepare("INSERT INTO $table (event) VALUES (:e)");
        foreach ($events as $event) {
            $stmt->execute([':e' => $event]);
        }
    }

    public function testTheUsersLogFindsOperationsThatEndTheLine(): void
    {
        $this->insert('log_users', [
            'client_ip:192.0.2.1 user:alice operation:logout',
            'client_ip:192.0.2.1 user:alice operation:change_password',
            'client_ip:192.0.2.1 user:alice operation:password_reset_request email:a@example.com',
            'client_ip:192.0.2.1 operation:password_reset',
            'client_ip:192.0.2.1 user:alice operation:session_expired',
        ]);
        $logger = new DbUserLogger($this->db);

        $this->assertSame(1, $logger->countFilteredLogs(['event_type' => 'logout']));
        $this->assertSame(1, $logger->countFilteredLogs(['event_type' => 'change_password']));
        $this->assertSame(1, $logger->countFilteredLogs(['event_type' => 'session_expired']));
        $this->assertSame(1, $logger->countFilteredLogs(['event_type' => 'password_reset']), 'must not match password_reset_request');
        $this->assertSame(1, $logger->countFilteredLogs(['event_type' => 'password_reset_request']));
        $this->assertCount(1, $logger->getFilteredLogs(['event_type' => 'logout'], 50, 0));
    }

    public function testTheApiAndGroupLogsFindOperationsAtTheEndOfTheLine(): void
    {
        $this->insert('log_api', [
            'client_ip:192.0.2.1 user:alice operation:api_key_toggle',
            'client_ip:192.0.2.1 user:alice operation:api_key_toggle_x key_id:1',
            'operation:api_request method:GET path:/x status:200 key_id:1 user:alice client_ip:192.0.2.1',
        ]);
        $this->insert('log_groups', [
            'client_ip:192.0.2.1 user:alice operation:delete_group',
            'client_ip:192.0.2.1 user:alice operation:delete_group_x id:1',
            'client_ip:192.0.2.1 user:alice operation:add_zones group_id:3',
        ]);

        $api = new DbApiLogger($this->db);
        $this->assertSame(1, $api->countFilteredLogs(['event_type' => 'api_key_toggle']));
        $this->assertSame(1, $api->countFilteredLogs(['event_type' => 'api_request']), 'operation first in the line');

        $groups = new DbGroupLogger($this->db);
        $this->assertSame(1, $groups->countFilteredLogs(['event_type' => 'delete_group']));
        $this->assertSame(1, $groups->countFilteredLogs(['event_type' => 'add_zones']));
    }

    public function testTheOperationConditionTreatsLikeWildcardsInTheNameLiterally(): void
    {
        $params = [];
        $sql = DbCompat::operationFieldCondition('event', 'a_b%', $params, 'p');

        $this->assertSame(['operation:a!_b!%', 'operation:a!_b!% %', '% operation:a!_b!%', '% operation:a!_b!% %'], array_column($params, 0));
        $this->assertStringContainsString("ESCAPE '!'", $sql);
    }

    public function testTheUserFilterMatchesTheUserFieldAsAWholeField(): void
    {
        $events = [
            'client_ip:192.0.2.1 user:admin operation:perm_template_change target_user:alice old_template:x',
            'client_ip:192.0.2.1 user:alice operation:logout',
            'operation:api_request status:200 user:alice',
            'client_ip:192.0.2.1 user:a%20b operation:x',
            'client_ip:192.0.2.1 user:a_b operation:x',
            'client_ip:192.0.2.1 user:axb operation:x',
        ];
        $this->insert('log_users', $events);
        $this->insert('log_api', $events);

        foreach ([new DbUserLogger($this->db), new DbApiLogger($this->db)] as $logger) {
            $name = $logger::class;
            $this->assertSame(2, $logger->countFilteredLogs(['name' => 'alice']), $name);
            $this->assertCount(2, $logger->getFilteredLogs(['name' => 'alice'], 50, 0), $name);
            $this->assertSame(1, $logger->countFilteredLogs(['name' => 'a b']), $name);
            $this->assertSame(1, $logger->countFilteredLogs(['name' => 'a_b']), $name);
            $this->assertSame(1, $logger->countFilteredLogs(['name' => 'axb']), $name);
            $this->assertSame(1, $logger->countFilteredLogs(['name' => 'admin']), $name);
        }
    }

    public function testTheFieldConditionNamesTheFieldAndEscapesTheValue(): void
    {
        $params = [];
        $sql = DbCompat::fieldCondition('event', 'user', 'a_b%', $params, 'u');

        $this->assertSame(['user:a!_b!%', 'user:a!_b!% %', '% user:a!_b!%', '% user:a!_b!% %'], array_column($params, 0));
        $this->assertSame(['u0', 'u1', 'u2', 'u3'], array_map(fn($k) => substr($k, 1), array_keys($params)));
        $this->assertStringContainsString("ESCAPE '!'", $sql);
    }
}
