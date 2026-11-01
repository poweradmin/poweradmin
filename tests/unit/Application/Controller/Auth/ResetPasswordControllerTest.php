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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Auth\ResetPasswordController;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Domain\Repository\PasswordResetTokenRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

#[CoversClass(ResetPasswordController::class)]
class ResetPasswordControllerTest extends SeamControllerTestCase
{
    public function testAnArrayTokenIsTreatedAsAMissingToken(): void
    {
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false));
        $this->session->remove(SessionKeys::USERID);
        $this->query(['token' => ['abc']]);

        $controller = new ResetPasswordController([], $this->environment($this->configure([
            'security' => ['password_reset' => ['enabled' => true]],
        ])));
        $controller->run();

        $this->assertSame('reset_password.html', $this->renderedTemplate());
        $this->assertSame('Invalid or missing password reset token.', $this->renderedParams()['error']);
    }

    public function testAnEmptyNewPasswordIsRefusedWithoutTouchingTheAccount(): void
    {
        $config = $this->configure(['security' => [
            'global_token_validation' => false,
            'password_reset' => ['enabled' => true],
            'password_policy.enable_password_rules' => false,
        ]]);
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false));
        $this->factory->method('passwordPolicyService')->willReturn(new PasswordPolicyService($config));

        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->method('findByToken')->willReturn(['id' => 3, 'email' => 'user@example.com']);
        $tokens->expects($this->never())->method('markAsUsed');
        $this->factory->method('passwordResetTokenRepository')->willReturn($tokens);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUserByEmail')->willReturn(['id' => 5, 'email' => 'user@example.com', 'auth_method' => 'sql']);
        $users->method('countUsersByEmail')->willReturn(1);
        $users->expects($this->never())->method('updatePassword');
        $this->factory->method('userRepository')->willReturn($users);

        $this->session->remove(SessionKeys::USERID);
        $this->query(['token' => 'abc']);
        $this->post(['password' => '', 'confirm_password' => '']);

        $controller = new ResetPasswordController([], $this->environment($config));
        $controller->run();

        $this->assertSame('reset_password.html', $this->renderedTemplate());
        $this->assertSame(['An empty password is not allowed'], $this->renderedParams()['policy_errors']);
    }
}
