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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Auth\AuthOutcome;
use Poweradmin\Application\Service\Auth\AuthOutcomeStatus;
use Poweradmin\Application\Service\Auth\LoginCredentials;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\SqlAuthenticator;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\PasswordEncryptionService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\NullLogger;

/**
 * Pins what SqlAuthenticator decides for good, wrong, unknown, disabled, locked
 * and MFA-enrolled credentials, on the login post and on a later request.
 */
#[CoversClass(SqlAuthenticator::class)]
class SqlAuthenticatorOutcomeTest extends TestCase
{
    private const SESSION_KEY = 'characterization-session-key';
    private const PASSWORD = 'correct horse';

    private array $sessionBackup = [];
    private MockObject&AuditService $audit;
    private MockObject&LoginAttemptService $attempts;
    private MockObject&MfaService $mfa;
    private MockObject&UserRepositoryInterface $users;
    private MockObject&CsrfTokenService $csrf;
    private bool $mfaEnabled = false;

    protected function setUp(): void
    {
        // MfaSessionManager closes and reopens the session; without one open, $_SESSION is dropped
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];

        $this->audit = $this->createMock(AuditService::class);
        $this->attempts = $this->createMock(LoginAttemptService::class);
        $this->mfa = $this->createMock(MfaService::class);
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->csrf = $this->createMock(CsrfTokenService::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    public function testValidCredentialsOnALaterRequestKeepTheSessionWithoutRedirecting(): void
    {
        $this->storeCredentials('alice', self::PASSWORD);
        $this->attempts->expects($this->never())->method('recordAttempt');
        $this->csrf->expects($this->once())->method('ensureTokenExists');

        $outcome = $this->authenticator($this->userRow())->authenticate();

        $this->assertSame(AuthOutcomeStatus::Success, $outcome->status);
        $this->assertNull($outcome->redirectPath);
        $this->assertSame(7, $_SESSION[SessionKeys::USERID]);
        $this->assertTrue($_SESSION[SessionKeys::AUTHENTICATED]);
        $this->assertSame('internal', $_SESSION[SessionKeys::AUTH_USED]);
    }

    public function testValidCredentialsOnTheLoginPostRecordTheLoginAndSendToIndex(): void
    {
        $this->storeCredentials('alice', self::PASSWORD);
        $this->attempts->expects($this->once())->method('recordAttempt')->with('alice', '203.0.113.9', true);
        $this->audit->expects($this->once())->method('logLoginSuccess')->with(AuthMethod::SQL);
        $this->csrf->expects($this->once())->method('regenerateToken');

        $outcome = $this->authenticator($this->userRow())->authenticate($this->login('alice', self::PASSWORD));

        $this->assertSame(AuthOutcomeStatus::Success, $outcome->status);
        $this->assertSame('/', $outcome->redirectPath);
        $this->assertSame(7, $_SESSION[SessionKeys::USERID]);
        $this->assertTrue($_SESSION[SessionKeys::AUTHENTICATED]);
    }

    public function testWrongPasswordOnTheLoginPostFailsAndCountsTheAttempt(): void
    {
        $this->storeCredentials('alice', 'not it');
        $this->attempts->expects($this->once())->method('recordAttempt')->with('alice', '203.0.113.9', false);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD);

        $outcome = $this->authenticator($this->userRow())->authenticate($this->login('alice', 'not it'));

        $this->assertFailure($outcome, 'Authentication failed!');
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
        $this->assertSame('alice', $_SESSION[SessionKeys::USERLOGIN]);
    }

    public function testWrongPasswordOnALaterRequestExpiresTheSession(): void
    {
        $this->storeCredentials('alice', 'not it');
        $this->audit->expects($this->never())->method('logLoginFailed');

        $outcome = $this->authenticator($this->userRow())->authenticate();

        $this->assertFailure($outcome, 'Session expired, please login again.');
        $this->assertArrayNotHasKey(SessionKeys::USERLOGIN, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::USERPWD, $_SESSION);
    }

    public function testUnknownUserOnTheLoginPostFailsLikeAWrongPassword(): void
    {
        $this->storeCredentials('nobody', self::PASSWORD);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::SQL, LoginFailureReason::NO_SUCH_USER);

        $outcome = $this->authenticator(false)->authenticate($this->login('nobody', self::PASSWORD));

        $this->assertFailure($outcome, 'Authentication failed!');
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    public function testDisabledAccountOnTheLoginPostIsRefused(): void
    {
        $this->storeCredentials('alice', self::PASSWORD);
        $this->audit->expects($this->once())->method('logLoginFailed')->with(AuthMethod::SQL, LoginFailureReason::ACCOUNT_DISABLED);

        $outcome = $this->authenticator($this->userRow(['active' => 0]))->authenticate($this->login('alice', self::PASSWORD));

        $this->assertFailure($outcome, 'The user account is disabled.');
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    public function testLockedAccountIsRefusedBeforeThePasswordIsChecked(): void
    {
        $this->storeCredentials('alice', self::PASSWORD);
        $this->attempts->method('isAccountLocked')->willReturn(true);
        $this->audit->expects($this->once())->method('logLoginLocked')->with(AuthMethod::SQL);

        $outcome = $this->authenticator($this->userRow(), expectQuery: false)->authenticate($this->login('alice', self::PASSWORD));

        $this->assertFailure($outcome, 'Account is temporarily locked. Please try again later.');
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    public function testMissingSessionCredentialsFailWithoutAMessage(): void
    {
        $outcome = $this->authenticator($this->userRow(), expectQuery: false)->authenticate();

        $this->assertFailure($outcome, '');
    }

    public function testMfaEnrolledUserOnALaterRequestIsParkedAsPendingWithoutRedirecting(): void
    {
        $this->mfaEnabled = true;
        $this->storeCredentials('alice', self::PASSWORD);
        $this->mfa->method('isMfaEnabled')->with(7)->willReturn(true);
        $this->attempts->expects($this->never())->method('recordAttempt');

        $outcome = $this->authenticator($this->userRow())->authenticate();

        $this->assertSame(AuthOutcomeStatus::MfaRequired, $outcome->status);
        $this->assertNull($outcome->redirectPath);
        $this->assertSame(7, $_SESSION[SessionKeys::PENDING_USERID]);
        $this->assertSame('internal', $_SESSION[SessionKeys::PENDING_AUTH_USED]);
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
        $this->assertFalse($_SESSION[SessionKeys::AUTHENTICATED]);
    }

    public function testMfaEnrolledUserOnTheLoginPostIsSentToTheVerificationForm(): void
    {
        $this->mfaEnabled = true;
        $this->storeCredentials('alice', self::PASSWORD);
        $this->mfa->method('isMfaEnabled')->with(7)->willReturn(true);
        $this->attempts->expects($this->once())->method('recordAttempt')->with('alice', '203.0.113.9', true);
        $this->audit->expects($this->once())->method('logLoginSuccess')->with(AuthMethod::SQL);

        $outcome = $this->authenticator($this->userRow())->authenticate($this->login('alice', self::PASSWORD));

        $this->assertSame(AuthOutcomeStatus::MfaRequired, $outcome->status);
        $this->assertSame('/mfa/verify', $outcome->redirectPath);
        $this->assertSame(7, $_SESSION[SessionKeys::PENDING_USERID]);
        $this->assertArrayNotHasKey(SessionKeys::USERID, $_SESSION);
    }

    private function assertFailure(AuthOutcome $outcome, string $message): void
    {
        $this->assertSame(AuthOutcomeStatus::Failure, $outcome->status);
        $this->assertSame($message, $outcome->message);
        $this->assertFalse($outcome->endSession);
        $this->assertNull($outcome->redirectPath);
    }

    private function login(string $username, string $password): LoginCredentials
    {
        return new LoginCredentials($username, $password);
    }

    private function storeCredentials(string $username, string $password): void
    {
        $_SESSION[SessionKeys::USERLOGIN] = $username;
        $_SESSION[SessionKeys::USERPWD] = (new PasswordEncryptionService(self::SESSION_KEY))->encrypt($password);
    }

    private function userRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'fullname' => 'Alice',
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'active' => 1,
            'email' => 'alice@example.test',
        ];
    }

    private function authenticator(array|false $row, bool $expectQuery = true): SqlAuthenticator
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($row);
        $db = $this->createMock(PDO::class);
        $db->expects($expectQuery ? $this->once() : $this->never())->method('prepare')->willReturn($statement);

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $section, string $key, $default = null) => match ("$section.$key") {
            'security.session_key' => self::SESSION_KEY,
            'security.password_encryption' => 'bcrypt',
            'security.password_cost' => 4,
            'security.mfa.enabled' => $this->mfaEnabled,
            default => $default,
        });

        return new SqlAuthenticator(
            $db,
            $config,
            $this->audit,
            $this->csrf,
            new NullLogger(),
            $this->attempts,
            new ClientContext('203.0.113.9', 'phpunit', 'Unknown', false),
            $this->mfa,
            $this->users
        );
    }
}
