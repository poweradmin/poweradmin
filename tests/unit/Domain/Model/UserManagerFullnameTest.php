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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/**
 * A full name is shown in mail bodies, so line breaks and other control or
 * format characters are dropped when it is saved; the rest of the name is kept.
 */
class UserManagerFullnameTest extends TestCase
{
    private const DIRTY = "Eve\r\nBcc: x@example.com\u{200B}\u{202E} Smith";
    private const CLEAN = 'EveBcc: x@example.com Smith';

    private PDO $db;

    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            VALUES (1, 'admin', 'Admin', 'admin@example.com', '', 1, 1, 0, 'sql')");
        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, perm_templ, active, use_ldap, auth_method)
            VALUES (2, 'bob', 'Bob', 'bob@example.com', '', 2, 1, 0, 'sql')");
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID], $_SESSION['messages']);
        parent::tearDown();
    }

    public function testCleanFullnameKeepsOrdinaryNames(): void
    {
        $this->assertSame("Zoë O'Brien-Müller 山田", UserManager::cleanFullname("Zoë O'Brien-Müller 山田"));
    }

    public function testCleanFullnameKeepsJoinersAndSoftHyphens(): void
    {
        $persian = "علی\u{200C}رضا";
        $sinhala = "ශ\u{0DCA}\u{200D}රී";
        $this->assertSame($persian, UserManager::cleanFullname($persian));
        $this->assertSame($sinhala, UserManager::cleanFullname($sinhala));
        $this->assertSame("a\u{00AD}b", UserManager::cleanFullname("a\u{00AD}b"));
    }

    public function testCleanFullnameDropsArabicLetterMarkAndTagCharacters(): void
    {
        $this->assertSame('AB', UserManager::cleanFullname("A\u{061C}B"));
        $this->assertSame('AB', UserManager::cleanFullname("A\u{E0041}\u{E007F}B"));
    }

    public function testCleanFullnameDoesNotBlankInvalidUtf8(): void
    {
        $this->assertNotSame('', UserManager::cleanFullname("caf\xe9"));
        $this->assertStringStartsWith('caf', UserManager::cleanFullname("caf\xe9"));
    }

    public function testEditUserStoresACleanName(): void
    {
        $this->assertTrue($this->manager()->editUser(2, 'bob', self::DIRTY, 'bob@example.com', '2', '', 1, '', 0));
        $this->assertSame(self::CLEAN, $this->storedName(2));
    }

    public function testUpdateUserDetailsStoresACleanName(): void
    {
        $this->assertTrue($this->manager()->updateUserDetails([
            'uid' => 2,
            'username' => 'bob',
            'fullname' => self::DIRTY,
            'email' => 'bob@example.com',
            'active' => 'on',
            'templ_id' => 2,
        ]));
        $this->assertSame(self::CLEAN, $this->storedName(2));
    }

    public function testRepositoryCreateAndUpdateStoreACleanName(): void
    {
        $repository = new DbUserRepository($this->db, $this->createMock(ConfigurationManager::class));

        $id = $repository->createUser([
            'username' => 'carol', 'password' => 'x', 'fullname' => self::DIRTY, 'perm_templ' => 2,
        ]);
        $this->assertSame(self::CLEAN, $this->storedName((int)$id));

        $this->assertTrue($repository->updateUser(2, ['fullname' => self::DIRTY]));
        $this->assertSame(self::CLEAN, $this->storedName(2));
    }

    private function manager(): UserManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed => $default
        );

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('hasPermission')->willReturn(true);
        $apiPermissionService = $this->createMock(ApiPermissionService::class);
        $apiPermissionService->method('checkPermissionTemplateAssignment')->willReturn(null);

        $manager = new UserManager($this->db, $config);
        foreach (['permissionService' => $permissionService, 'apiPermissionService' => $apiPermissionService] as $name => $value) {
            (new \ReflectionProperty($manager, $name))->setValue($manager, $value);
        }

        return $manager;
    }

    private function storedName(int $id): string
    {
        return (string)$this->db->query('SELECT fullname FROM users WHERE id = ' . $id)->fetchColumn();
    }
}
