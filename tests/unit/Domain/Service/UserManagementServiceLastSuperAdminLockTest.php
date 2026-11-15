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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * The last-super-admin check and the write that follows it share one
 * transaction with the uberuser rows locked, so two admins removing each
 * other cannot both pass the check.
 */
class UserManagementServiceLastSuperAdminLockTest extends TestCase
{
    private DbUserRepository&MockObject $repo;
    private UserManagementService $service;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->repo = $this->createMock(DbUserRepository::class);
        $this->service = new UserManagementService($this->repo, $this->createMock(PermissionService::class));
        $this->calls = [];
        foreach (['beginTransaction', 'lockActiveUberusers', 'commit', 'rollBack', 'updateUser', 'deleteUser'] as $method) {
            $this->repo->method($method)->willReturnCallback(function () use ($method) {
                $this->calls[] = $method;

                return in_array($method, ['updateUser', 'deleteUser'], true) ? true : null;
            });
        }
        $this->repo->method('getUserById')->willReturn(['id' => 1, 'auth_method' => 'sql']);
        $this->repo->method('getUserZones')->willReturn([]);
        $this->repo->method('isUberuser')->willReturnCallback(function () {
            $this->calls[] = 'isUberuser';

            return true;
        });
    }

    public function testDisableRechecksTheCountInsideTheLockedTransaction(): void
    {
        $this->repo->method('countUberusers')->willReturnCallback(function () {
            $this->calls[] = 'count';

            return 2;
        });

        $result = $this->service->updateUser(1, ['active' => false]);

        $this->assertTrue($result['success']);
        $this->assertSame(['beginTransaction', 'lockActiveUberusers', 'isUberuser', 'count', 'updateUser', 'commit'], $this->calls);
    }

    public function testDisableRefusedWhenTheLockedCountShowsTheLastSuperAdmin(): void
    {
        $this->repo->method('countUberusers')->willReturn(1);

        $result = $this->service->updateUser(1, ['active' => false]);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertSame(['beginTransaction', 'lockActiveUberusers', 'isUberuser', 'rollBack'], $this->calls);
    }

    public function testDeleteRechecksTheCountInsideTheLockedTransaction(): void
    {
        $this->repo->method('countUberusers')->willReturnCallback(function () {
            $this->calls[] = 'count';

            return 2;
        });

        $result = $this->service->deleteUser(1);

        $this->assertTrue($result['success']);
        $this->assertSame(['beginTransaction', 'lockActiveUberusers', 'isUberuser', 'count', 'deleteUser', 'commit'], $this->calls);
    }

    public function testDeleteRefusedWhenTheLockedCountShowsTheLastSuperAdmin(): void
    {
        $this->repo->method('countUberusers')->willReturn(1);

        $result = $this->service->deleteUser(1);

        $this->assertSame(409, $result['status']);
        $this->assertNotContains('deleteUser', $this->calls);
        $this->assertSame('rollBack', end($this->calls));
    }

    public function testLockWaitFailureRollsBackTheOpenTransaction(): void
    {
        $repo = $this->createMock(DbUserRepository::class);
        $repo->method('getUserById')->willReturn(['id' => 1]);
        $repo->method('lockActiveUberusers')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $repo->expects($this->once())->method('rollBack');
        $repo->expects($this->never())->method('deleteUser');

        $result = (new UserManagementService($repo, $this->createMock(PermissionService::class)))->deleteUser(1);

        $this->assertSame(500, $result['status']);
    }

    public function testPlainRepositoryFallsBackToTheUnlockedCheck(): void
    {
        $plain = $this->createMock(UserRepository::class);
        $plain->method('getUserById')->willReturn(['id' => 1, 'auth_method' => 'sql']);
        $plain->method('getUserZones')->willReturn([]);
        $plain->method('isUberuser')->willReturn(true);
        $plain->method('countUberusers')->willReturnOnConsecutiveCalls(1, 2);
        $plain->method('deleteUser')->willReturn(true);
        $service = new UserManagementService($plain, $this->createMock(PermissionService::class));

        $this->assertSame(409, $service->deleteUser(1)['status']);
        $this->assertTrue($service->deleteUser(1)['success']);
    }

    public function testRealRepositoryDeleteRunsInsideTheGuardTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT, description TEXT, active INTEGER, perm_templ INTEGER, use_ldap INTEGER, auth_method TEXT)');
        $pdo->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE perm_templ (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE perm_templ_items (id INTEGER PRIMARY KEY, templ_id INTEGER, perm_id INTEGER)');
        $pdo->exec('CREATE TABLE user_preferences (user_id INTEGER)');
        $pdo->exec('CREATE TABLE user_mfa (user_id INTEGER)');
        $pdo->exec('CREATE TABLE login_attempts (user_id INTEGER)');
        $pdo->exec('CREATE TABLE oidc_user_links (user_id INTEGER)');
        $pdo->exec('CREATE TABLE saml_user_links (user_id INTEGER)');
        $pdo->exec('CREATE TABLE password_reset_tokens (email TEXT)');
        $pdo->exec('CREATE TABLE username_recovery_requests (email TEXT)');
        $pdo->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER)');
        $pdo->exec("INSERT INTO perm_items VALUES (1, 'user_is_ueberuser')");
        $pdo->exec('INSERT INTO perm_templ VALUES (1)');
        $pdo->exec('INSERT INTO perm_templ_items VALUES (1, 1, 1)');
        $pdo->exec("INSERT INTO users VALUES (1, 'a', '', 'a@example.com', '', 1, 1, 0, 'sql'), (2, 'b', '', 'b@example.com', '', 1, 1, 0, 'sql')");

        $repo = new DbUserRepository($pdo, $this->createMock(ConfigurationManager::class));
        $service = new UserManagementService($repo, $this->createMock(PermissionService::class));

        $result = $service->deleteUser(1);
        $this->assertTrue($result['success'], (string)json_encode($result));
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(409, $service->deleteUser(2)['status']);
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
