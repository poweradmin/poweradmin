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
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * A template the add-user form filled in itself is not the caller's choice, so it
 * skips the template gate; any template the caller submitted is still checked.
 */
class UserManagerAddUserTemplateTest extends TestCase
{
    private const MINIMAL_TEMPLATE = 4;

    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 7;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password TEXT,
            fullname TEXT,
            email TEXT,
            description TEXT,
            perm_templ INTEGER,
            active INTEGER,
            use_ldap INTEGER,
            auth_method TEXT
        )");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    public function testDefaultedMinimalTemplateCreatesTheUserWithoutTheTemplateGate(): void
    {
        $manager = $this->manager($this->never());

        $newId = $manager->addNewUser($this->details(self::MINIMAL_TEMPLATE), true);

        $this->assertNotFalse($newId);
        $this->assertSame(self::MINIMAL_TEMPLATE, $this->storedTemplate($newId));
    }

    public function testExplicitMinimalTemplateIsStillChecked(): void
    {
        $manager = $this->manager($this->once());

        $this->assertFalse($manager->addNewUser($this->details(self::MINIMAL_TEMPLATE)));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testExplicitRicherTemplateIsRefused(): void
    {
        $manager = $this->manager($this->once());

        $this->assertFalse($manager->addNewUser($this->details(3)));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function details(int $permTempl): array
    {
        return [
            'username' => 'newuser',
            'email' => 'new@example.com',
            'perm_templ' => $permTempl,
            'active' => 1,
            'use_ldap' => 1,
        ];
    }

    private function manager(InvocationOrder $gateCalls): UserManager
    {
        // LDAP accounts store no password hash, which keeps the global config out of the test.
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed =>
                $group === 'ldap' && $key === 'enabled' ? true : $default
        );

        // A delegate with user_add_new and user_edit_templ_perm whose template lacks a
        // permission held by every template offered here.
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturn(true);
        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('templateGrantsSuperuser')->willReturn(false);
        $apiPermissionService->expects($gateCalls)->method('checkPermissionTemplateAssignment')
            ->willReturn(ApiPermissionService::TEMPLATE_SUBSET_DENIED);

        $manager = new UserManager($this->db, $config);
        $this->setProperty($manager, 'permissionService', $permissionService);
        $this->setProperty($manager, 'apiPermissionService', $apiPermissionService);

        return $manager;
    }

    private function storedTemplate(int $id): int
    {
        return (int)$this->db->query('SELECT perm_templ FROM users WHERE id = ' . $id)->fetchColumn();
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
