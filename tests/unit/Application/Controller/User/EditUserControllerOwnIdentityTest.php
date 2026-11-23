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

namespace Poweradmin\Tests\Unit\Application\Controller\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\User\EditUserController;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * Changing your own username or email asks for the current password, counted on
 * the reauth stage like the change password page; other users' rows are unchanged.
 */
#[CoversClass(EditUserController::class)]
class EditUserControllerOwnIdentityTest extends SeamControllerTestCase
{
    private const PASSWORD = 'correct horse';

    private UserManagementService&MockObject $users;
    private LoginAttemptService&MockObject $attempts;

    /** @param array<string, mixed> $fields */
    private function submit(int $editId, array $fields, string $authType = 'sql', string $storedEmail = 'jane@example.org'): EditUserController
    {
        $apiPermissions = $this->createMock(ApiPermissionService::class);
        $apiPermissions->method('canEditUserPassword')->willReturn(true);
        $this->factory->method('apiPermissionService')->willReturn($apiPermissions);
        // An editor of others, so the username field is live on the own profile too
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturnCallback(
            static fn(int $id, string $permission): bool => $permission === Permission::PERM_USER_EDIT_OTHERS
        );
        $this->factory->method('permissionService')->willReturn($permissions);

        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $repository = $this->createMock(UserRepositoryInterface::class);
        $stored = ['username' => 'jane', 'fullname' => 'Jane', 'email' => $storedEmail, 'auth_type' => $authType, 'use_ldap' => 0, 'tpl_id' => 1];
        $repository->method('getUserDetailList')->willReturn([$stored]);
        $repository->method('findByUsername')->with(self::USERNAME)->willReturn(new User(self::USER_ID, $hash, false));
        $this->factory->method('userRepository')->willReturn($repository);

        $this->users = $this->createMock(UserManagementService::class);
        $this->factory->method('userManagementService')->willReturn($this->users);
        $this->attempts = $this->createMock(LoginAttemptService::class);
        $this->factory->method('loginAttemptService')->willReturn($this->attempts);
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.5', 'phpunit', 'Unknown', false));

        $this->post($fields + ['username' => 'jane', 'fullname' => 'Jane', 'email' => $storedEmail, 'description' => '', 'form_state' => $this->formStateServed()]);

        return $this->getMockBuilder(EditUserController::class)
            ->setConstructorArgs([$this->requestData(), true, $this->environment($this->configure())])
            ->onlyMethods(['showUserEditForm'])
            ->getMock();
    }

    /**
     * Stamp of the row the factory serves; a stub configured by an earlier submit() in the same test wins.
     */
    private function formStateServed(): string
    {
        return EditUserController::formState($this->factory->userRepository()->getUserDetailList(false, null, self::USER_ID)[0]);
    }

    private function update(EditUserController $controller, int $editId): void
    {
        try {
            (new ReflectionMethod(EditUserController::class, 'updateUser'))->invoke($controller, $editId, []);
        } catch (RequestHalted) {
            // a saved profile redirects
        }
    }

    public function testANewEmailOnYourOwnProfileNeedsTheCurrentPassword(): void
    {
        $controller = $this->submit(self::USER_ID, ['email' => 'mallory@example.org']);
        $this->users->expects($this->never())->method('updateUser');
        $this->attempts->expects($this->once())->method('reserveAttempt')
            ->with(self::USERNAME, '203.0.113.5', LoginAttemptService::STAGE_REAUTH, self::USER_ID)->willReturn(true);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $controller->expects($this->once())->method('showUserEditForm');

        $this->update($controller, self::USER_ID);

        $this->assertSame([['error', 'You did not enter the correct current password.']], $this->messagesFor('edit_user'));
    }

    public function testANewUsernameOnYourOwnProfileNeedsTheCurrentPassword(): void
    {
        $controller = $this->submit(self::USER_ID, ['username' => 'mallory', 'current_password' => 'guess']);
        $this->users->expects($this->never())->method('updateUser');
        $this->attempts->method('reserveAttempt')->willReturn(true);

        $this->update($controller, self::USER_ID);

        $this->assertSame([['error', 'You did not enter the correct current password.']], $this->messagesFor('edit_user'));
    }

    public function testTheCorrectCurrentPasswordLetsTheChangeThrough(): void
    {
        $controller = $this->submit(self::USER_ID, ['email' => 'new@example.org', 'current_password' => self::PASSWORD]);
        $this->attempts->method('reserveAttempt')->willReturn(true);
        $this->attempts->expects($this->once())->method('recordAttempt')->with(self::USERNAME, '203.0.113.5', true);
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }

    public function testALockedReauthStageRefusesTheChange(): void
    {
        $controller = $this->submit(self::USER_ID, ['email' => 'new@example.org', 'current_password' => self::PASSWORD]);
        $this->attempts->method('reserveAttempt')->willReturn(false);
        $this->users->expects($this->never())->method('updateUser');

        $this->update($controller, self::USER_ID);

        $this->assertSame([['error', 'Too many failed attempts. Please try again later.']], $this->messagesFor('edit_user'));
    }

    public function testUnchangedIdentityNeedsNoPassword(): void
    {
        $controller = $this->submit(self::USER_ID, ['fullname' => 'Jane Q']);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }

    public function testChangingAnotherUsersEmailNeedsNoPasswordOfYours(): void
    {
        $controller = $this->submit(self::USER_ID + 5, ['email' => 'new@example.org']);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID + 5);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }

    /** There is no local password to ask for, so the change is refused outright */
    public function testAnExternallyAuthenticatedAccountCannotChangeItsOwnEmailOrUsername(): void
    {
        foreach (['ldap', 'remote_user'] as $authType) {
            foreach ([['email' => 'new@example.org'], ['username' => 'mallory']] as $fields) {
                $controller = $this->submit(self::USER_ID, $fields, $authType);
                $this->attempts->expects($this->never())->method('recordAttempt');
                $this->users->expects($this->never())->method('updateUser');
                $controller->expects($this->once())->method('showUserEditForm');

                $this->update($controller, self::USER_ID);
            }
        }
        $this->assertSame([['error', 'Authentication settings can only be changed by an administrator']], $this->messagesFor('edit_user'));
    }

    public function testAnIdentityProviderAccountIsNotAffectedByTheSelfEditRefusal(): void
    {
        $controller = $this->submit(self::USER_ID, ['username' => 'mallory'], 'oidc');
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }

    /** The read-only email posts the stored value, which is empty when the web server supplies none */
    public function testAnOwnWebServerRowWithAnEmptyEmailStillSaves(): void
    {
        $controller = $this->submit(self::USER_ID, ['fullname' => 'Jane Q'], 'remote_user', '');
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }

    public function testAnExternallyAuthenticatedAccountStillSavesOtherFields(): void
    {
        $controller = $this->submit(self::USER_ID, ['fullname' => 'Jane Q'], 'ldap');
        $this->users->expects($this->once())->method('updateUser')->willReturn(['success' => true]);

        $this->update($controller, self::USER_ID);

        $this->assertSame([], $this->messagesFor('edit_user'));
    }
}
