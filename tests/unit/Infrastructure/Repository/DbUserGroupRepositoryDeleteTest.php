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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;

#[CoversClass(DbUserGroupRepository::class)]
class DbUserGroupRepositoryDeleteTest extends TestCase
{
    #[Test]
    public function deleteRemovesMembershipAndZoneGrantsWithoutForeignKeyCascade(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, group_id INTEGER, user_id INTEGER)');
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $db->exec("INSERT INTO user_groups (id, name) VALUES (1, 'a'), (2, 'b')");
        $db->exec('INSERT INTO user_group_members (group_id, user_id) VALUES (1, 10), (2, 11)');
        $db->exec('INSERT INTO zones_groups (domain_id, group_id) VALUES (5, 1), (6, 2)');

        $this->assertTrue((new DbUserGroupRepository($db))->delete(1));

        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM user_group_members WHERE group_id = 1')->fetchColumn());
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM zones_groups WHERE group_id = 1')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM user_group_members WHERE group_id = 2')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM zones_groups WHERE group_id = 2')->fetchColumn());
    }
}
