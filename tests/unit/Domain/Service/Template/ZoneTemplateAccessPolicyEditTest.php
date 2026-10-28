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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Infrastructure\Session\SessionActor;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * The list page's edit/delete decision follows the edit and delete pages:
 * a ueberuser may touch any template, an owner needs zone_templ_edit, a
 * member of a group the template is shared with may edit but not delete or
 * re-share it, and nobody else may touch a template they do not own.
 */
class ZoneTemplateAccessPolicyEditTest extends TestCase
{
    private ArraySession $session;

    private const USER_ID = 5;

    /** @var list<string> */
    private array $granted = [];


    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->session = new ArraySession([SessionKeys::USERID => self::USER_ID]);
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
    }


    private function policy(): ZoneTemplateAccessPolicy
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));

        return new ZoneTemplateAccessPolicy($this->repository, $permissions, new SessionActor($this->session));
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

    public function testGroupMemberWithEditPermissionMayEditButNotManage(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];

        $this->assertTrue($this->policy()->canCurrentUserEditTemplate(7, true));
        $this->assertFalse($this->policy()->canCurrentUserManageTemplate(7));
        $this->assertTrue($this->policy()->canCurrentUserManageTemplate(self::USER_ID));
    }

    public function testSharingNeverOpensAGlobalTemplateForEditing(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];

        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(0, true));
    }

    public function testGroupMemberWithoutEditPermissionMayNotEdit(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_ADD];

        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(7, true));
    }

    public function testEditByIdLooksUpOwnerAndShare(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];
        $this->repository->method('getOwner')->willReturnMap([[1, 7], [2, 7], [3, null]]);
        $this->repository->method('isSharedWithUser')->willReturnMap([[1, self::USER_ID, true], [2, self::USER_ID, false]]);

        $this->assertTrue($this->policy()->canCurrentUserEditTemplateById(1));
        $this->assertFalse($this->policy()->canCurrentUserEditTemplateById(2));
        $this->assertFalse($this->policy()->canCurrentUserEditTemplateById(3));
        $this->assertFalse($this->policy()->canCurrentUserManageTemplateById(1));
    }

    public function testOnlyAUeberuserChangesTheOwnerOnEdit(): void
    {
        $this->repository->method('getOwner')->willReturnMap([[1, 7], [2, 0]]);

        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];
        $this->assertNull($this->policy()->resolveEditedTemplateOwner(1, true, self::USER_ID));

        $this->granted = [Permission::PERM_USER_IS_UEBERUSER];
        $this->assertNull($this->policy()->resolveEditedTemplateOwner(1, false, self::USER_ID));
        $this->assertSame(0, $this->policy()->resolveEditedTemplateOwner(1, true, self::USER_ID));
        $this->assertSame(self::USER_ID, $this->policy()->resolveEditedTemplateOwner(2, false, self::USER_ID));
        $this->assertSame(0, $this->policy()->resolveEditedTemplateOwner(2, true, self::USER_ID));
    }

    public function testAnonymousMayNotEdit(): void
    {
        $this->granted = [Permission::PERM_ZONE_TEMPL_EDIT];
        $this->session = new ArraySession();

        $this->assertFalse($this->policy()->canCurrentUserEditTemplate(0));
    }
}
