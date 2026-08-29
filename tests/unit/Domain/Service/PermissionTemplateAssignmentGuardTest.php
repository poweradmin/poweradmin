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
    /**
     * Partial mock: only the two database-backed lookups are stubbed, so the
     * assignment rules in checkPermissionTemplateAssignment() are exercised for real.
     *
     * @param int[] $superuserTemplateIds Templates that carry user_is_ueberuser
     */
    private function permissionService(
        bool $isUeberuser,
        bool $canEditTemplPerm,
        bool $canEditOthers = false,
        array $superuserTemplateIds = [],
        ?int $currentTemplateId = null
    ): ApiPermissionService {
        $svc = $this->createPartialMock(
            ApiPermissionService::class,
            ['userHasPermission', 'templateGrantsSuperuser', 'getUserPermissionTemplateId']
        );
        $svc->method('getUserPermissionTemplateId')->willReturn($currentTemplateId);
        $svc->method('userHasPermission')->willReturnCallback(
            static function (int $userId, string $perm) use ($isUeberuser, $canEditTemplPerm, $canEditOthers): bool {
                return match ($perm) {
                    'user_is_ueberuser' => $isUeberuser,
                    'user_edit_templ_perm' => $canEditTemplPerm,
                    'user_edit_others' => $canEditOthers,
                    default => false,
                };
            }
        );
        $svc->method('templateGrantsSuperuser')->willReturnCallback(
            static fn(int $templId): bool => in_array($templId, $superuserTemplateIds, true)
        );
        return $svc;
    }

    public function testUeberuserMayPassAnyTemplate(): void
    {
        $svc = $this->permissionService(isUeberuser: true, canEditTemplPerm: false);
        $input = ['perm_templ' => 1, 'username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertNull($error);
        $this->assertSame(1, $input['perm_templ'], 'ueberuser-supplied template stays as-is');
    }

    public function testEditTemplPermHolderMayPassAnyTemplate(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: true);
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertNull($error);
        $this->assertSame(1, $input['perm_templ']);
    }

    public function testNonPrivilegedCallerWithSuppliedTemplateIsRejected(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['perm_templ' => 1, 'username' => 'attacker'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertSame(ApiPermissionService::TEMPLATE_ASSIGN_DENIED, $error);
    }

    public function testNonPrivilegedCallerWithSuppliedTemplateAsStringIsRejected(): void
    {
        // The service layer normalises types, but the guard runs before that.
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['perm_templ' => '1'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertSame(ApiPermissionService::TEMPLATE_ASSIGN_DENIED, $error);
    }

    public function testNonPrivilegedCallerWithoutTemplateGetsMinimalDefault(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['username' => 'x', 'password' => 'y'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

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

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ']);
    }

    public function testNonPrivilegedCallerWithoutMinimalDefaultLeavesInputAlone(): void
    {
        // Update path passes null default - guard must not inject. The service layer
        // then rejects perm_templ=null on its own (existing behavior).
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: false);
        $input = ['email' => 'updated@example.com'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, null);

        $this->assertNull($error);
        $this->assertArrayNotHasKey('perm_templ', $input);
    }

    public function testPrivilegedCallerOmittingTemplateStillGetsMinimalDefault(): void
    {
        // Omitting perm_templ must not fall through to the repository's Administrator
        // (id 1) fallback, even for a privileged caller - that would silently create a
        // super admin. The minimal template is injected instead (audit M4).
        $svc = $this->permissionService(isUeberuser: true, canEditTemplPerm: false);
        $input = ['username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ']);
    }

    public function testEditTemplPermHolderOmittingTemplateGetsMinimalDefault(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: true);
        $input = ['username' => 'x'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertNull($error);
        $this->assertSame(4, $input['perm_templ']);
    }

    public function testPrivilegedCallerOmittingTemplateOnUpdatePathIsUntouched(): void
    {
        // Update path passes a null default, so nothing is injected and the user's
        // existing template is preserved.
        $svc = $this->permissionService(isUeberuser: true, canEditTemplPerm: false);
        $input = ['email' => 'updated@example.com'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, null);

        $this->assertNull($error);
        $this->assertArrayNotHasKey('perm_templ', $input);
    }

    public function testTemplPermHolderCannotRetemplateOwnAccount(): void
    {
        // The web user editor discards a self-editor's template unless they hold
        // user_edit_others; the API must not be the softer path.
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: true);
        $input = ['perm_templ' => 2];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 7);

        $this->assertSame(ApiPermissionService::TEMPLATE_SELF_ASSIGN_DENIED, $error);
    }

    public function testTemplPermHolderWithEditOthersMayRetemplateOwnAccount(): void
    {
        $svc = $this->permissionService(isUeberuser: false, canEditTemplPerm: true, canEditOthers: true);
        $input = ['perm_templ' => 2];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 7);

        $this->assertNull($error);
    }

    public function testTemplPermHolderCannotAssignSuperuserTemplate(): void
    {
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            canEditOthers: true,
            superuserTemplateIds: [1]
        );
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 9);

        $this->assertSame(ApiPermissionService::TEMPLATE_SUPERUSER_DENIED, $error);
    }

    public function testUeberuserMayAssignSuperuserTemplate(): void
    {
        $svc = $this->permissionService(
            isUeberuser: true,
            canEditTemplPerm: false,
            superuserTemplateIds: [1]
        );
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 9);

        $this->assertNull($error);
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

    public function testUnchangedSuperuserTemplateIsStillRejected(): void
    {
        // The exemption must not become a way to keep a superuser template alive on
        // an account a non-ueberuser is rewriting.
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            canEditOthers: true,
            superuserTemplateIds: [1],
            currentTemplateId: 1
        );
        $input = ['perm_templ' => 1];

        $error = PermissionTemplateAssignmentGuard::apply($svc, null, 7, $input, 9);

        $this->assertSame(ApiPermissionService::TEMPLATE_SUPERUSER_DENIED, $error);
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

    public function testSuperuserTemplateIsRejectedOnCreatePathToo(): void
    {
        // No target account exists yet, so the self-assignment rule cannot fire -
        // the superuser-template rule still must.
        $svc = $this->permissionService(
            isUeberuser: false,
            canEditTemplPerm: true,
            superuserTemplateIds: [1]
        );
        $input = ['perm_templ' => 1, 'username' => 'attacker'];

        $error = PermissionTemplateAssignmentGuard::apply($svc, 4, 7, $input, null);

        $this->assertSame(ApiPermissionService::TEMPLATE_SUPERUSER_DENIED, $error);
    }
}
