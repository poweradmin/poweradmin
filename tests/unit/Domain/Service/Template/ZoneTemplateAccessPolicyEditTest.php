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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;

/**
 * The list page's edit/delete decision follows the edit and delete pages:
 * a ueberuser may touch any template, an owner needs zone_templ_edit, and
 * nobody else (including holders of zone_templ_edit) may touch a template
 * they do not own.
 */
class ZoneTemplateAccessPolicyEditTest extends TestCase
{
    private const USER_ID = 5;

    /** @var list<string> */
    private array $granted = [];

    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [SessionKeys::USERID => self::USER_ID];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    private function policy(): ZoneTemplateAccessPolicy
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));

        return new ZoneTemplateAccessPolicy($this->createMock(ZoneTemplateRepositoryInterface::class), $permissions, new UserContextService());
    }

    public function testUeberuserMayEditAnyTemplate(): void
    {
        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];

        $this->assertTrue($this->policy()->canCurrentUserEditTemplate(0));
        $this->assertTrue($this->policy()->canCurrentUserEditTemplate(7));
        $this->assertTrue($this->policy()->canCurrentUserEditTemplate(self::USER_ID));
    }

    public function testOwnerWithEditPermissionMayEditOnlyTheirOwn(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];

        $this->assertTrue($this->policy()->canCurrentUserEditTemplate(self::USER_ID));
        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(7));
        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(0));
    }

    public function testOwnerWithoutEditPermissionMayNotEdit(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_ADD];

        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(self::USER_ID));
    }

    public function testAnonymousMayNotEdit(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];
        $_SESSION = [];

        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(0));
    }
}
