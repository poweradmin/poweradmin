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

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Application\Service\Mail\MailService;
use Poweradmin\Application\Service\User\PasswordResetService;
use Poweradmin\Application\Service\User\UsernameRecoveryService;
use Poweradmin\Application\Service\Web\UrlService;
use Poweradmin\Domain\Repository\PasswordResetTokenRepositoryInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\UsernameRecoveryRepositoryInterface;
use Poweradmin\Infrastructure\Utility\ProtocolDetector;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * Per-IP throttle of password reset and username recovery counts an IPv6 client by
 * its /64 like the login throttle, and recovery mails the stored address.
 */
class RecoveryThrottleAndRecipientTest extends TestCase
{
    private const CLIENT = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
    private const KEY = '2001:db8:1:2::/64';

    private function config(): FakeConfiguration
    {
        return new FakeConfiguration([
            'security' => [
                'password_reset' => ['enabled' => true, 'rate_limit_attempts' => 3, 'rate_limit_window' => 3600, 'min_time_between_requests' => 300],
                'username_recovery' => ['enabled' => true, 'rate_limit_attempts' => 3, 'rate_limit_window' => 3600, 'min_time_between_requests' => 300],
            ],
            'interface' => ['application_url' => 'https://test.example'],
        ]);
    }

    public function testPasswordResetCountsAndStoresTheIpv6Network(): void
    {
        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->method('countRecentAttempts')->willReturn(0);
        $tokens->expects($this->once())->method('countRecentAttemptsByIp')->with(self::KEY, 3600)->willReturn(6);
        $tokens->expects($this->never())->method('create');
        $config = $this->config();

        $service = new PasswordResetService(
            $tokens,
            $this->createMock(UserRepositoryInterface::class),
            $this->createMock(MailService::class),
            $config,
            $this->createMock(UserAuthenticationService::class),
            new ClientContext(self::CLIENT, 'phpunit', 'Unknown', false),
            new NullLogger(),
            new UrlService($config, new ProtocolDetector(), new NullLogger())
        );

        $this->assertTrue($service->createResetRequest('someone@example.test'));
    }

    public function testUsernameRecoveryCountsAndStoresTheIpv6Network(): void
    {
        $recovery = $this->createMock(UsernameRecoveryRepositoryInterface::class);
        $recovery->method('countRecentAttempts')->willReturn(0);
        $recovery->expects($this->once())->method('countRecentAttemptsByIp')->with(self::KEY, 3600)->willReturn(6);
        $recovery->expects($this->never())->method('create');

        $this->assertTrue($this->recoveryService($recovery, $this->createMock(UserLookupInterface::class), $this->createMock(MailService::class))
            ->createRecoveryRequest('someone@example.test'));
    }

    public function testUsernameRecoveryMailsTheStoredAddressNotTheTypedOne(): void
    {
        $recovery = $this->createMock(UsernameRecoveryRepositoryInterface::class);
        $recovery->method('countRecentAttempts')->willReturn(0);
        $recovery->method('countRecentAttemptsByIp')->willReturn(0);
        $recovery->method('create')->willReturn(true);

        $users = $this->createMock(UserLookupInterface::class);
        $users->method('findActiveUsersByEmail')->willReturn([
            ['id' => 1, 'username' => 'alice', 'fullname' => 'Alice', 'email' => 'Alice@Example.test', 'auth_method' => 'sql', 'active' => 1],
            ['id' => 2, 'username' => 'alice2', 'fullname' => 'Alice', 'email' => 'Alice@Example.test', 'auth_method' => 'sql', 'active' => 1],
            ['id' => 3, 'username' => 'other', 'fullname' => 'Other', 'email' => 'ALICE@example.test', 'auth_method' => 'sql', 'active' => 1],
        ]);

        $recipients = [];
        $mail = $this->createMock(MailService::class);
        $mail->method('sendMail')->willReturnCallback(function (string $to) use (&$recipients): bool {
            $recipients[] = $to;
            return true;
        });

        $this->recoveryService($recovery, $users, $mail)->createRecoveryRequest('alice@example.test');

        $this->assertSame(['Alice@Example.test', 'ALICE@example.test'], $recipients);
    }

    private function recoveryService(
        UsernameRecoveryRepositoryInterface $recovery,
        UserLookupInterface $users,
        MailService $mail
    ): UsernameRecoveryService {
        $config = $this->config();

        return new UsernameRecoveryService(
            $recovery,
            $mail,
            $config,
            new ClientContext(self::CLIENT, 'phpunit', 'Unknown', false),
            new NullLogger(),
            $users,
            new UrlService($config, new ProtocolDetector(), new NullLogger())
        );
    }
}
