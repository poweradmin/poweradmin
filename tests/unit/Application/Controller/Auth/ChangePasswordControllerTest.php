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
use Poweradmin\Application\Controller\Auth\ChangePasswordController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\PasswordChangeService;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Pins the collaborator wiring of the change-password form: a successful
 * change is audited and then ends the session through the request-scoped
 * AuthenticationService the service factory hands out, while a rejected one
 * re-renders the form with the service's message and touches neither.
 */
#[CoversClass(ChangePasswordController::class)]
class ChangePasswordControllerTest extends SeamControllerTestCase
{
    public function testSuccessfulChangeAuditsThenLogsOutThroughTheSharedAuthenticationService(): void
    {
        $config = $this->configure();

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logPasswordChange');
        $this->factory->method('auditService')->willReturn($audit);

        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->once())->method('logout')->with($this->callback(
            static fn(FlashMessage $entity): bool => $entity->getMessage() === 'Password changed'
                && $entity->getType() === 'success'
        ));
        $this->factory->expects($this->once())->method('authenticationService')->willReturn($authentication);

        $this->post(['old_password' => 'old-secret', 'new_password' => 'new-secret', 'new_password2' => 'new-secret']);
        $controller = new TestableChangePasswordController([], true, $this->environment($config));

        $passwordService = $this->createMock(PasswordChangeService::class);
        $passwordService->expects($this->once())->method('changePassword')
            ->with('old-secret', 'new-secret')
            ->willReturn([true, 'Password changed']);
        $controller->plantPasswordService($passwordService);

        $controller->run();

        $this->assertSame([], $this->output->rendered);
    }

    public function testRejectedChangeRerendersTheFormWithoutEndingTheSession(): void
    {
        $config = $this->configure();

        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->never())->method('logout');
        $this->factory->method('authenticationService')->willReturn($authentication);

        $this->post(['old_password' => 'wrong', 'new_password' => 'new-secret', 'new_password2' => 'new-secret']);
        $controller = new TestableChangePasswordController([], true, $this->environment($config));

        $passwordService = $this->createMock(PasswordChangeService::class);
        $passwordService->method('changePassword')->willReturn([false, 'Current password is incorrect']);
        $controller->plantPasswordService($passwordService);

        $controller->run();

        $this->assertSame('change_password.html', $this->output->rendered[0][0]);
        $this->assertSame([['error', 'Current password is incorrect']], $this->messagesFor('change_password'));
    }
}
