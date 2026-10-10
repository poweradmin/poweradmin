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
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * The q filter and sort of GET /api/v2/users: the search matches the same columns
 * as getTotalUserCount(), so the page and the count agree.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryUsersListTest extends TestCase
{
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT, description TEXT,
                    active INTEGER, perm_templ INTEGER, max_zones INTEGER)",
                "CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT, template_type TEXT DEFAULT 'user')",
                "CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)",
                "INSERT INTO perm_templ (id, name) VALUES (1, 'Administrator')",
                "INSERT INTO users (id, username, fullname, email, description, active, perm_templ) VALUES
                    (1, 'admin', 'Administrator', 'root@example.com', '', 1, 1),
                    (2, 'Bob', 'Bob Builder', 'bob@ops.example', 'DNS ops', 1, 1),
                    (3, 'carol', 'Carol', 'carol@example.com', 'ops_team lead', 1, 1),
                    (4, 'dave', 'Dave', 'dave@example.com', 'opsXteam', 0, 1)",
                "INSERT INTO zones (domain_id, owner) VALUES (10, 2), (11, 2)",
            ] as $sql
        ) {
            $db->exec($sql);
        }
        $this->repository = new DbUserRepository($db, new FakeConfiguration(), false);
    }

    /** @return string[] */
    private function usernames(array $users): array
    {
        return array_column($users, 'username');
    }

    public function testWithoutSearchOrSortEveryUserIsListedById(): void
    {
        $this->assertSame(['admin', 'Bob', 'carol', 'dave'], $this->usernames($this->repository->getUsersList(0, 100)));
    }

    public function testTheSearchIgnoresCaseAndAgreesWithTheCount(): void
    {
        $users = $this->repository->getUsersList(0, 100, 'OPS');

        $this->assertSame(['Bob', 'carol', 'dave'], $this->usernames($users));
        $this->assertSame(3, $this->repository->getTotalUserCount(null, 'OPS'));
        // Zone counts are still per user, not per matching column
        $this->assertSame(2, (int)$users[0]['zone_count']);
    }

    public function testAnUnderscoreInTheSearchMatchesLiterally(): void
    {
        $this->assertSame(['carol'], $this->usernames($this->repository->getUsersList(0, 100, 'ops_team')));
        $this->assertSame(1, $this->repository->getTotalUserCount(null, 'ops_team'));
    }

    public function testSortsCaseInsensitivelyAndPages(): void
    {
        $sort = [['field' => 'username', 'desc' => true]];

        $this->assertSame(['dave', 'carol', 'Bob', 'admin'], $this->usernames($this->repository->getUsersList(0, 100, null, $sort)));
        $this->assertSame(['carol'], $this->usernames($this->repository->getUsersList(1, 1, null, $sort)));
    }

    public function testSortsByEmailWithinTheSearch(): void
    {
        $users = $this->repository->getUsersList(0, 100, 'example', [['field' => 'email', 'desc' => false]]);

        $this->assertSame(['Bob', 'carol', 'dave', 'admin'], $this->usernames($users));
    }
}
