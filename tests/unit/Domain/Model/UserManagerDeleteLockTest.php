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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * A delete refused under the super admin lock (the other admin went first) must leave
 * the user's zone ownership rows and external login links as they were.
 */
class UserManagerDeleteLockTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
    }

    #[Test]
    public function testRefusalUnderTheLockKeepsZoneRowsAndOidcLinks(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, owner INTEGER, zone_name TEXT)');
        $db->exec('CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY, user_id INTEGER)');
        $db->exec("INSERT INTO users VALUES (2, 'a@example.com')");
        $db->exec("INSERT INTO zones VALUES (1, 2, NULL)");
        $db->exec('INSERT INTO oidc_user_links VALUES (1, 2)');

        $repo = $this->createMock(DbUserRepository::class);
        $repo->method('isUberuser')->willReturn(true);
        // Passes the early check, then the other admin's delete lands before the lock
        $repo->method('countUberusers')->willReturnOnConsecutiveCalls(2, 1);
        $repo->expects($this->once())->method('beginUberuserLock')->willReturn(true);
        $repo->expects($this->once())->method('endUberuserLock')->with(true, false);

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);

        $manager = new UserManager($db, $this->createMock(ConfigurationManager::class));
        foreach (['userRepository' => $repo, 'permissionService' => $permissions] as $name => $value) {
            (new \ReflectionProperty($manager, $name))->setValue($manager, $value);
        }

        $this->assertFalse($manager->deleteUser(2, []));
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM zones WHERE owner = 2')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM oidc_user_links WHERE user_id = 2')->fetchColumn());
    }
}
