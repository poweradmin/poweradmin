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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\PermissionTemplateAssignmentGuard;

class PermissionTemplateAssignmentGuardTest extends TestCase
{
    private function permissionService(
        bool $isUeberuser,
        bool $canEditTemplPerm,
        bool $canEditOthers = false,
        ?int $currentTemplateId = null,
        bool $templateGrantsSuperuser = false
    ): ApiPermissionService {
        $svc = $this->createMock(ApiPermissionService::class);
        $svc->method('userHasPermission')->willReturnCallback(
            static function (int $userId, string $perm) use ($isUeberuser, $canEditTemplPerm, $canEditOthers): bool {
                if ($perm === 'user_is_ueberuser') {
                    return $isUeberuser;
                }
                if ($perm === 'user_edit_templ_perm') {
                    return $canEditTemplPerm;
                }
                if ($perm === 'user_edit_others') {
                    return $canEditOthers;
                }
                return false;
            }
        );
        $svc->method('getUserPermissionTemplateId')->willReturn($currentTemplateId);
        $svc->method('templateGrantsSuperuser')->willReturn($templateGrantsSuperuser);
        return $svc;
    }

    public function testUeberuserMayPassAnyTemplate(): void
    {
        $svc = $this->permissionService(isUeberuser: true, canEditTemplPerm: false);
        $input = ['perm_templ' => 1, 'username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertNull($error);
        $this->assertSame(1, $input['perm_templ'], 'ueberuser-supplied template stays as-is');
    }

    public function testEditTemplPermHolderMayPassAnyTemplate(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: true);
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertNull($error);
        $this->assertSame(1, $input['perm_templ']);
    }

    public function testNonPrivilegedCallerWithSuppliedTemplateIsRejected(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['perm_templ' => 1, 'username' => 'attacker'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertSame(PermissionTemplateAssignmentGuard::REJECT_MESSAGE, $error);
    }

    public function testNonPrivilegedCallerWithSuppliedTemplateAsStringIsRejected(): void
    {
        // The service layer normalises types, but the guard runs before that.
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['perm_templ' => '1'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertSame(PermissionTemplateAssignmentGuard::REJECT_MESSAGE, $error);
    }

    public function testNonPrivilegedCallerWithoutTemplateGetsMinimalDefault(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['username' => 'x', 'password' => 'y'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ'], 'Caller did not choose; safe minimal template injected');
    }

    public function testNonPrivilegedCallerWithNullTemplateGetsMinimalDefault(): void
    {
        // A caller explicitly sending `perm_templ: null` is treated as "not chosen".
        // The service layer would otherwise hit the repository's historical fallback
        // to template id 1 (Administrator) which is exactly the escalation we close.
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['perm_templ' => null, 'username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ']);
    }

    public function testNonPrivilegedCallerWithoutMinimalDefaultLeavesInputAlone(): void
    {
        // Update path passes null default - guard must not inject. The service layer
        // then rejects perm_templ=null on its own (existing behavior).
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['email' => 'updated@example.com'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input);

        $this->assertNull($error);
        $this->assertArrayNotHasKey('perm_templ', $input);
    }

    public function testSelfUpdateEchoingTheStoredTemplateIsNotATemplateChange(): void
    {
        // A full-object PUT round-trips perm_templ unchanged. Gating on its presence
        // rejected an ordinary self-edit that the web UI allows.
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            canEditOthers: false,
            currentTemplateId: 4
        );
        $input = ['perm_templ' => 4, 'email' => 'updated@example.com'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 7);

        $this->assertNull($error);
    }

    public function testSelfUpdateChangingTheTemplateIsStillRejected(): void
    {
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            canEditOthers: false,
            currentTemplateId: 4
        );
        $input = ['perm_templ' => 5];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 7);

        $this->assertSame(PermissionTemplateAssignmentGuard::SELF_ASSIGN_MESSAGE, $error);
    }

    public function testUnchangedSuperuserTemplateIsStillRejected(): void
    {
        // The exemption must not become a way to keep a superuser template alive on
        // an account a non-ueberuser is rewriting.
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            canEditOthers: true,
            currentTemplateId: 1,
            templateGrantsSuperuser: true
        );
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 9);

        $this->assertSame(PermissionTemplateAssignmentGuard::SUPERUSER_TEMPLATE_MESSAGE, $error);
    }

    public function testUnchangedTemplateOnAnotherAccountNeedsNoTemplatePermission(): void
    {
        // Mirrors UserManager::templateAssignmentRejected(), which exempts an unchanged
        // non-superuser template before any permission is consulted.
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: false,
            canEditOthers: true,
            currentTemplateId: 4
        );
        $input = ['perm_templ' => 4, 'email' => 'other@example.com'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 9);

        $this->assertNull($error);
    }

    public function testPrivilegedCallerOmittingTemplateGetsMinimalDefault(): void
    {
        $svc = $this->permissionService(isUeberuser: true, canEditTemplPerm: false);
        $input = ['username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ'], 'Caller did not choose; safe minimal template injected');
    }
}
