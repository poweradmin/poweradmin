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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Auth\MfaSetupController;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Starting an MFA enrolment needs the current password, so a stolen session cannot bind
 * its own factor to the account and lock the owner out.
 */
#[CoversClass(MfaSetupController::class)]
class MfaSetupControllerEnrolmentPasswordTest extends SeamControllerTestCase
{
    private const PASSWORD = 'correct horse';

    private MfaService&MockObject $mfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mfa = $this->createMock(MfaService::class);
        $this->mfa->method('isMfaEnforced')->willReturn(false);
        $this->mfa->method('getUserMfa')->willReturn(null);
        $this->factory->method('mfaService')->willReturn($this->mfa);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByUsername')->willReturn(new User(self::USER_ID, password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), false));
        $this->factory->method('userRepository')->willReturn($users);

        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('reserveAttempt')->willReturn(true);
        $this->factory->method('loginAttemptService')->willReturn($attempts);
        $this->factory->method('clientContext')->willReturn(new ClientContext('192.0.2.10', 'test', 'test', false));

        $this->session->set(SessionKeys::EMAIL, 'tester@example.com');
    }

    /** @return array<string, array{string}> */
    public static function enrolments(): array
    {
        return ['app' => ['setup_app'], 'email' => ['setup_email']];
    }

    private function submit(array $fields): void
    {
        $config = $this->configure([
            'security' => ['mfa' => ['enabled' => true]],
            'mail' => ['enabled' => true],
        ]);
        $this->post($fields);
        (new MfaSetupController([], true, $this->environment($config)))->run();
    }

    #[DataProvider('enrolments')]
    public function testEnrolmentWithoutThePasswordCreatesNothing(string $button): void
    {
        $this->mfa->expects($this->never())->method('getOrCreateUserMfa');

        $this->submit([$button => '1']);

        $this->assertSame([['error', 'You did not enter the correct current password.']], $this->messagesFor('system'));
    }

    #[DataProvider('enrolments')]
    public function testEnrolmentWithAWrongPasswordCreatesNothing(string $button): void
    {
        $this->mfa->expects($this->never())->method('getOrCreateUserMfa');

        $this->submit([$button => '1', 'current_password' => 'guess']);

        $this->assertSame([['error', 'You did not enter the correct current password.']], $this->messagesFor('system'));
    }

    #[DataProvider('enrolments')]
    public function testEnrolmentWithThePasswordProceeds(string $button): void
    {
        $this->mfa->expects($this->once())->method('getOrCreateUserMfa')->with(self::USER_ID)->willReturn(null);

        $this->submit([$button => '1', 'current_password' => self::PASSWORD]);

        $this->assertSame([['error', 'Failed to create MFA record.']], $this->messagesFor('system'));
    }

    #[DataProvider('enrolments')]
    public function testAnAccountWithoutALocalPasswordIsNotAsked(string $button): void
    {
        $this->session->set(SessionKeys::AUTH_USED, 'ldap');
        $this->mfa->expects($this->once())->method('getOrCreateUserMfa')->willReturn(null);

        $this->submit([$button => '1']);

        $this->assertSame([['error', 'Failed to create MFA record.']], $this->messagesFor('system'));
    }

    public function testThePageAsksForThePasswordOnlyOfAccountsThatHaveOne(): void
    {
        $this->submit([]);
        $this->assertTrue($this->renderedParams()['confirm_password']);
    }

    public function testThePageDoesNotAskAnSsoAccountForAPassword(): void
    {
        $this->session->set(SessionKeys::AUTH_USED, 'saml');
        $this->submit([]);
        $this->assertFalse($this->renderedParams()['confirm_password']);
    }
}
