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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * The last-super-admin check and the write it protects run in one transaction that
 * holds the active super admin rows, so two concurrent changes cannot both pass.
 */
class LastSuperAdminLockTest extends TestCase
{
    private DbUserRepository&MockObject $repo;
    private array $calls = [];

    protected function setUp(): void
    {
        $this->repo = $this->createMock(DbUserRepository::class);
        $this->repo->method('getUserById')->willReturn(['id' => 1, 'auth_method' => 'sql']);
        $this->repo->method('isUberuser')->willReturnCallback(function () {
            $this->calls[] = 'isUberuser';
            return true;
        });
        $this->repo->method('countUberusers')->willReturnCallback(function () {
            $this->calls[] = 'count';
            return 2;
        });
        $this->repo->method('beginUberuserLock')->willReturnCallback(function () {
            $this->calls[] = 'lock';
            return true;
        });
        $this->repo->method('endUberuserLock')->willReturnCallback(function (bool $owned, bool $commit) {
            $this->calls[] = $commit ? 'commit' : 'rollback';
        });
    }

    private function service(): UserManagementService
    {
        return new UserManagementService(
            $this->repo,
            $this->createMock(PermissionService::class),
            $this->createMock(UserGroupRepositoryInterface::class)
        );
    }

    #[Test]
    public function testDisablingASuperAdminCountsAndWritesUnderTheLock(): void
    {
        $this->repo->method('updateUser')->willReturnCallback(function () {
            $this->calls[] = 'write';
            return true;
        });

        $result = $this->service()->updateUser(1, ['active' => false]);

        $this->assertTrue($result['success']);
        $this->assertSame(['isUberuser', 'lock', 'isUberuser', 'count', 'write', 'commit'], $this->calls);
    }

    #[Test]
    public function testDeletingASuperAdminCountsAndWritesUnderTheLock(): void
    {
        $this->repo->method('getUserZones')->willReturn([]);
        $this->repo->method('deleteUser')->willReturnCallback(function () {
            $this->calls[] = 'write';
            return true;
        });

        $result = $this->service()->deleteUser(1);

        $this->assertTrue($result['success']);
        $this->assertSame(['isUberuser', 'lock', 'isUberuser', 'count', 'write', 'commit'], $this->calls);
    }

    #[Test]
    public function testDemotingASuperAdminCountsAndWritesUnderTheLock(): void
    {
        $this->repo->method('permissionTemplateExists')->willReturn(true);
        $this->repo->method('templateGrantsUberuser')->willReturn(false);
        $this->repo->method('assignPermissionTemplate')->willReturnCallback(function () {
            $this->calls[] = 'write';
            return true;
        });

        $result = $this->service()->assignPermissionTemplate(1, 5);

        $this->assertTrue($result['success']);
        $this->assertSame(['isUberuser', 'lock', 'isUberuser', 'count', 'write', 'commit'], $this->calls);
    }

    #[Test]
    public function testRefusalUnderTheLockRollsBack(): void
    {
        $repo = $this->createMock(DbUserRepository::class);
        $repo->method('getUserById')->willReturn(['id' => 1]);
        $repo->method('isUberuser')->willReturn(true);
        $repo->method('countUberusers')->willReturn(1);
        $repo->method('beginUberuserLock')->willReturn(true);
        $repo->expects($this->never())->method('deleteUser');
        $repo->expects($this->once())->method('endUberuserLock')->with(true, false);

        $service = new UserManagementService(
            $repo,
            $this->createMock(PermissionService::class),
            $this->createMock(UserGroupRepositoryInterface::class)
        );

        $this->assertSame(409, $service->deleteUser(1)['status']);
    }

    #[Test]
    public function testRepositoryWithoutLockSupportKeepsTheUnlockedCheck(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getUserById')->willReturn(['id' => 1]);
        $repo->method('isUberuser')->willReturn(true);
        $repo->method('countUberusers')->willReturn(1);
        $repo->expects($this->never())->method('deleteUser');

        $service = new UserManagementService(
            $repo,
            $this->createMock(PermissionService::class),
            $this->createMock(UserGroupRepositoryInterface::class)
        );

        $this->assertSame(409, $service->deleteUser(1)['status']);
    }

    #[Test]
    public function testLockOpensATransactionAndEndClosesIt(): void
    {
        $db = $this->database();
        $repo = new DbUserRepository($db, $this->createMock(ConfigurationManager::class));

        $owned = $repo->beginUberuserLock();
        $this->assertTrue($owned);
        $this->assertTrue($db->inTransaction());

        $db->exec('UPDATE users SET active = 0 WHERE id = 1');
        $repo->endUberuserLock($owned, false);

        $this->assertFalse($db->inTransaction());
        $this->assertSame(1, (int)$db->query('SELECT active FROM users WHERE id = 1')->fetchColumn());
    }

    #[Test]
    public function testLockJoinsAnOuterTransactionWithoutClosingIt(): void
    {
        $db = $this->database();
        $repo = new DbUserRepository($db, $this->createMock(ConfigurationManager::class));
        $db->beginTransaction();

        $owned = $repo->beginUberuserLock();
        $repo->endUberuserLock($owned, true);

        $this->assertFalse($owned);
        $this->assertTrue($db->inTransaction());
        $db->rollBack();
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER, active INTEGER)');
        $db->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)');
        $db->exec("INSERT INTO perm_items VALUES (1, 'user_is_ueberuser')");
        $db->exec('INSERT INTO perm_templ_items VALUES (1, 1, 1)');
        $db->exec('INSERT INTO users VALUES (1, 1, 1)');

        return $db;
    }
}
