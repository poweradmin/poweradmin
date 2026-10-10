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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * The web edit form refuses to move the last active super admin to a template
 * without super admin when security.protect_last_admin_on_edit is on, matching
 * the API update path.
 */
#[CoversClass(UserManager::class)]
class UserManagerLastAdminGuardTest extends TestCase
{
    private const ADMIN_ID = 1;
    private const ADMIN_TEMPLATE = 1;
    private const PLAIN_TEMPLATE = 5;

    private PDO $db;
    private UserRepository&MockObject $userRepository;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = self::ADMIN_ID;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password TEXT,
            fullname TEXT,
            email TEXT,
            description TEXT,
            perm_templ INTEGER,
            perm_templ_source TEXT,
            active INTEGER,
            use_ldap INTEGER,
            auth_method TEXT
        )");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (1, 'admin', 'Admin', 'admin@example.com', '', " . self::ADMIN_TEMPLATE . ", 1, 0, 'sql')");

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('isUberuser')->with(self::ADMIN_ID)->willReturn(true);
        $this->userRepository->method('countUberusers')->willReturn(1);
        $this->userRepository->method('templateGrantsUberuser')
            ->willReturnCallback(fn (int $templId): bool => $templId === self::ADMIN_TEMPLATE);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    #[Test]
    public function testEditFormRefusesDemotingTheLastSuperAdminWhenTheGuardIsOn(): void
    {
        $this->assertFalse($this->editAdmin(self::PLAIN_TEMPLATE, true));
        $this->assertSame(self::ADMIN_TEMPLATE, $this->storedTemplate());
    }

    #[Test]
    public function testEditFormLetsTheLastSuperAdminKeepASuperAdminTemplateWhenTheGuardIsOn(): void
    {
        $this->assertTrue($this->editAdmin(self::ADMIN_TEMPLATE, true));
        $this->assertSame(self::ADMIN_TEMPLATE, $this->storedTemplate());
    }

    #[Test]
    public function testEditFormAllowsDemotionWhenOtherSuperAdminsExist(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('isUberuser')->willReturn(true);
        $this->userRepository->method('countUberusers')->willReturn(2);
        $this->userRepository->method('templateGrantsUberuser')->willReturn(false);

        $this->assertTrue($this->editAdmin(self::PLAIN_TEMPLATE, true));
        $this->assertSame(self::PLAIN_TEMPLATE, $this->storedTemplate());
    }

    #[Test]
    public function testEditFormKeepsTodaysBehaviourWhenTheGuardIsOff(): void
    {
        $this->userRepository->expects($this->never())->method('isUberuser');

        $this->assertTrue($this->editAdmin(self::PLAIN_TEMPLATE, false));
        $this->assertSame(self::PLAIN_TEMPLATE, $this->storedTemplate());
    }

    private function editAdmin(int $permTempl, bool $guardOn): bool
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed =>
                $group === 'security' && $key === 'protect_last_admin_on_edit' ? $guardOn : $default
        );

        // The caller is a super admin, so every permission and template check passes
        // and only the last admin guard decides.
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturn(true);
        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('checkPermissionTemplateAssignment')->willReturn(null);
        $apiPermissionService->method('templateGrantsSuperuser')->willReturn(true);

        $manager = new UserManager($this->db, $config);
        $this->setProperty($manager, 'permissionService', $permissionService);
        $this->setProperty($manager, 'apiPermissionService', $apiPermissionService);
        $this->setProperty($manager, 'userRepository', $this->userRepository);

        return $manager->editUser(self::ADMIN_ID, 'admin', 'Admin', 'admin@example.com', (string)$permTempl, '', 1, '', 0);
    }

    private function storedTemplate(): int
    {
        return (int)$this->db->query('SELECT perm_templ FROM users WHERE id = ' . self::ADMIN_ID)->fetchColumn();
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
