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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Security tests for password reset functionality
 * Tests that OIDC and SAML users cannot reset their passwords
 */
class PasswordResetSecurityTest extends TestCase
{
    private $tokenRepository;
    private $userRepository;
    private $mailService;
    private ConfigurationManager $config;
    private $authService;
    private $ipRetriever;
    private $logger;
    private PasswordResetService $passwordResetService;

    protected function setUp(): void
    {
        // Mock all dependencies except ConfigurationManager
        $this->tokenRepository = $this->createMock(DbPasswordResetTokenRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->authService = $this->createMock(UserAuthenticationService::class);
        $this->ipRetriever = $this->createMock(IpAddressRetriever::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Setup ConfigurationManager with test data using reflection
        $this->config = ConfigurationManager::getInstance();
        $this->mockConfigurationManager([
            'security' => [
                'password_reset' => [
                    'enabled' => true,
                    'token_lifetime' => 3600,
                    'rate_limit_attempts' => 3,
                    'rate_limit_window' => 3600,
                    'min_time_between_requests' => 300
                ]
            ],
            'interface' => [
                'application_url' => 'https://test.example'
            ]
        ]);

        $this->ipRetriever->method('getClientIp')->willReturn('192.168.1.1');

        $this->passwordResetService = new PasswordResetService(
            $this->tokenRepository,
            $this->userRepository,
            $this->mailService,
            $this->config,
            $this->authService,
            $this->ipRetriever,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationManager();
    }

    /**
     * Reset the ConfigurationManager singleton between tests
     */
    private function resetConfigurationManager(): void
    {
        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $instanceProperty = $reflectionClass->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue(ConfigurationManager::getInstance(), false);
    }

    /**
     * Mock the ConfigurationManager with specific settings
     */
    private function mockConfigurationManager(array $settings): void
    {
        $configManager = ConfigurationManager::getInstance();

        $reflectionClass = new ReflectionClass(ConfigurationManager::class);
        $settingsProperty = $reflectionClass->getProperty('settings');
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($configManager, $settings);

        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($configManager, true);
    }

    /**
     * Test that OIDC users cannot request password reset
     */
    public function testOidcUserCannotResetPassword(): void
    {
        $email = 'oidc-user@example.com';

        // Mock rate limit checks
        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        // Mock user with OIDC auth method
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 1,
                'username' => 'oidc-user',
                'email' => $email,
                'fullname' => 'OIDC User',
                'auth_method' => 'oidc'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'oidc'
                        && isset($context['user_id']);
                })
            );

        // Token should NOT be created for OIDC users
        $this->tokenRepository->expects($this->never())
            ->method('create');

        // Email should NOT be sent to OIDC users
        $this->mailService->expects($this->never())
            ->method('sendMail');

        // Call should return true (to not reveal auth method)
        $result = $this->passwordResetService->createResetRequest($email);
        $this->assertTrue($result);
    }

    /**
     * Test that a reset is declined when the email is shared by multiple accounts,
     * since we cannot safely tell which account the request is for.
     */
    public function testSharedEmailDeclinesResetRequest(): void
    {
        $email = 'shared@example.com';

        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 1,
                'username' => 'first-user',
                'email' => $email,
                'fullname' => 'First User',
                'auth_method' => 'sql'
            ]);

        $this->userRepository->method('countUsersByEmail')
            ->with($email)
            ->willReturn(2);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset declined for email shared by multiple accounts',
                $this->callback(fn ($context) => $context['email'] === $email)
            );

        // No token minted and no email sent for an ambiguous address.
        $this->tokenRepository->expects($this->never())->method('create');
        $this->mailService->expects($this->never())->method('sendMail');

        // Returns true so the response doesn't reveal the shared-email condition.
        $this->assertTrue($this->passwordResetService->createResetRequest($email));
    }

    /**
     * Test that SAML users cannot request password reset
     */
    public function testSamlUserCannotResetPassword(): void
    {
        $email = 'saml-user@example.com';

        // Mock rate limit checks
        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        // Mock user with SAML auth method
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 2,
                'username' => 'saml-user',
                'email' => $email,
                'fullname' => 'SAML User',
                'auth_method' => 'saml'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'saml'
                        && isset($context['user_id']);
                })
            );

        // Token should NOT be created for SAML users
        $this->tokenRepository->expects($this->never())
            ->method('create');

        // Email should NOT be sent to SAML users
        $this->mailService->expects($this->never())
            ->method('sendMail');

        // Call should return true (to not reveal auth method)
        $result = $this->passwordResetService->createResetRequest($email);
        $this->assertTrue($result);
    }

    /**
     * Test that LDAP users cannot request password reset
     */
    public function testLdapUserCannotResetPassword(): void
    {
        $email = 'ldap-user@example.com';

        // Mock rate limit checks
        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        // Mock user with LDAP auth method
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 3,
                'username' => 'ldap-user',
                'email' => $email,
                'fullname' => 'LDAP User',
                'auth_method' => 'ldap'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'ldap'
                        && isset($context['user_id']);
                })
            );

        // Token should NOT be created for LDAP users
        $this->tokenRepository->expects($this->never())
            ->method('create');

        // Email should NOT be sent to LDAP users
        $this->mailService->expects($this->never())
            ->method('sendMail');

        // Call should return true (to not reveal auth method)
        $result = $this->passwordResetService->createResetRequest($email);
        $this->assertTrue($result);
    }

    /**
     * Test that SQL users CAN request password reset
     */
    public function testSqlUserCanResetPassword(): void
    {
        $email = 'sql-user@example.com';

        // Mock rate limit checks
        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        // Mock user with SQL auth method
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 3,
                'username' => 'sql-user',
                'email' => $email,
                'fullname' => 'SQL User',
                'auth_method' => 'sql'
            ]);

        // Token SHOULD be created for SQL users
        $this->tokenRepository->expects($this->once())
            ->method('create')
            ->willReturn(true);

        // Email SHOULD be sent to SQL users
        $this->mailService->expects($this->once())
            ->method('sendMail')
            ->willReturn(true);

        // Warning should NOT be logged for SQL users
        $this->logger->expects($this->never())
            ->method('warning')
            ->with('Password reset blocked for external auth user');

        $result = $this->passwordResetService->createResetRequest($email);
        $this->assertTrue($result);
    }

    /**
     * Test that users with missing auth_method (defaults to 'sql') can reset password
     */
    public function testUserWithMissingAuthMethodCanResetPassword(): void
    {
        $email = 'legacy-user@example.com';

        // Mock rate limit checks
        $this->tokenRepository->method('countRecentAttempts')->willReturn(0);
        $this->tokenRepository->method('getLastAttemptTime')->willReturn(null);
        $this->tokenRepository->method('countRecentAttemptsByIp')->willReturn(0);

        // Mock user without auth_method field (legacy user)
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 4,
                'username' => 'legacy-user',
                'email' => $email,
                'fullname' => 'Legacy User'
                // auth_method is missing
            ]);

        // Token SHOULD be created (defaults to SQL)
        $this->tokenRepository->expects($this->once())
            ->method('create')
            ->willReturn(true);

        // Email SHOULD be sent
        $this->mailService->expects($this->once())
            ->method('sendMail')
            ->willReturn(true);

        $result = $this->passwordResetService->createResetRequest($email);
        $this->assertTrue($result);
    }

    /**
     * Test that OIDC users cannot use a valid token even if one exists
     */
    public function testOidcUserCannotValidateToken(): void
    {
        $token = 'valid-token-12345';
        $email = 'oidc-user@example.com';

        // Repository returns the row for the hashed candidate lookup.
        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken($token))
            ->willReturn([
                'id' => 1,
                'token' => DbPasswordResetTokenRepository::hashToken($token),
                'email' => $email,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'used' => 0
            ]);

        // Mock OIDC user
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 1,
                'username' => 'oidc-user',
                'email' => $email,
                'auth_method' => 'oidc'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset token validation blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'oidc';
                })
            );

        // Token validation should fail for OIDC users
        $result = $this->passwordResetService->validateToken($token);
        $this->assertNull($result);
    }

    /**
     * Test that SAML users cannot use a valid token even if one exists
     */
    public function testSamlUserCannotValidateToken(): void
    {
        $token = 'valid-token-67890';
        $email = 'saml-user@example.com';

        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken($token))
            ->willReturn([
                'id' => 2,
                'token' => DbPasswordResetTokenRepository::hashToken($token),
                'email' => $email,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'used' => 0
            ]);

        // Mock SAML user
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 2,
                'username' => 'saml-user',
                'email' => $email,
                'auth_method' => 'saml'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset token validation blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'saml';
                })
            );

        // Token validation should fail for SAML users
        $result = $this->passwordResetService->validateToken($token);
        $this->assertNull($result);
    }

    /**
     * Test that LDAP users cannot use a valid token even if one exists
     */
    public function testLdapUserCannotValidateToken(): void
    {
        $token = 'valid-token-11111';
        $email = 'ldap-user@example.com';

        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken($token))
            ->willReturn([
                'id' => 3,
                'token' => DbPasswordResetTokenRepository::hashToken($token),
                'email' => $email,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'used' => 0
            ]);

        // Mock LDAP user
        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 3,
                'username' => 'ldap-user',
                'email' => $email,
                'auth_method' => 'ldap'
            ]);

        // Expect warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Password reset token validation blocked for external auth user',
                $this->callback(function ($context) use ($email) {
                    return $context['email'] === $email
                        && $context['auth_method'] === 'ldap';
                })
            );

        // Token validation should fail for LDAP users
        $result = $this->passwordResetService->validateToken($token);
        $this->assertNull($result);
    }

    /**
     * Valid SQL-user token must successfully validate.
     */
    public function testValidateTokenReturnsUserForMatchingSqlToken(): void
    {
        $token = str_repeat('a', 64);
        $email = 'sql@example.com';

        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken($token))
            ->willReturn([
                'id' => 10,
                'token' => DbPasswordResetTokenRepository::hashToken($token),
                'email' => $email,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'used' => 0,
            ]);

        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn([
                'id' => 7,
                'username' => 'sql',
                'email' => $email,
                'auth_method' => 'sql',
            ]);

        $result = $this->passwordResetService->validateToken($token);
        $this->assertIsArray($result);
        $this->assertSame(10, $result['token_id']);
        $this->assertSame(7, $result['user']['id']);
    }

    /**
     * Wrong token of the same length must NOT validate. Timing safety now
     * comes from a single indexed lookup on the hashed candidate rather
     * than a hash_equals scan, but the rejection contract is unchanged.
     */
    public function testValidateTokenRejectsSameLengthWrongToken(): void
    {
        $submittedToken = str_repeat('a', 63) . 'b';

        // Repository returns null because the hashed candidate doesn't match
        // any stored row - the previous full-scan + hash_equals iteration is gone.
        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken($submittedToken))
            ->willReturn(null);

        $this->userRepository->expects($this->never())->method('getUserByEmail');

        $result = $this->passwordResetService->validateToken($submittedToken);
        $this->assertNull($result);
    }

    /**
     * Wrong token of different length must also be rejected. With hashing,
     * candidate length no longer matters at the SQL layer - every input
     * collapses to a 64-hex digest - but the rejection contract still holds.
     */
    public function testValidateTokenRejectsDifferentLengthToken(): void
    {
        // Short candidate hashes to a 64-hex SHA-256 that no stored row matches.
        $this->tokenRepository->method('findByToken')
            ->with(DbPasswordResetTokenRepository::hashToken('short-token'))
            ->willReturn(null);

        $this->userRepository->expects($this->never())->method('getUserByEmail');

        $result = $this->passwordResetService->validateToken('short-token');
        $this->assertNull($result);
    }

    /**
     * Pass-the-hash defense: a candidate that already looks like the stored
     * hash format (sha256$ prefix) must be rejected before the lookup runs.
     * Blocks an attacker who read the column from authenticating.
     */
    public function testValidateTokenRejectsSubmittedHashCandidate(): void
    {
        $stolenHash = DbPasswordResetTokenRepository::hashToken(str_repeat('b', 64));

        $this->tokenRepository->expects($this->never())->method('findByToken');
        $this->userRepository->expects($this->never())->method('getUserByEmail');

        $result = $this->passwordResetService->validateToken($stolenHash);
        $this->assertNull($result);
    }

    /**
     * Confirms the hash helper produces a prefixed SHA-256 hex string.
     */
    public function testHashTokenProducesPrefixedFormat(): void
    {
        $hash = DbPasswordResetTokenRepository::hashToken('any-input');
        $this->assertStringStartsWith('sha256$', $hash);
        $this->assertSame(7 + 64, strlen($hash));
    }

    /**
     * Test canUserResetPassword method returns correct results
     */
    public function testCanUserResetPasswordMethod(): void
    {
        // Test OIDC user - should not be allowed
        $this->userRepository->method('getUserByEmail')
            ->willReturnMap([
                ['oidc@example.com', ['id' => 1, 'email' => 'oidc@example.com', 'auth_method' => 'oidc']],
                ['saml@example.com', ['id' => 2, 'email' => 'saml@example.com', 'auth_method' => 'saml']],
                ['ldap@example.com', ['id' => 3, 'email' => 'ldap@example.com', 'auth_method' => 'ldap']],
                ['sql@example.com', ['id' => 4, 'email' => 'sql@example.com', 'auth_method' => 'sql']],
                ['nonexistent@example.com', null]
            ]);

        // OIDC user
        $result = $this->passwordResetService->canUserResetPassword('oidc@example.com');
        $this->assertFalse($result['allowed']);
        $this->assertEquals('oidc', $result['auth_method']);

        // SAML user
        $result = $this->passwordResetService->canUserResetPassword('saml@example.com');
        $this->assertFalse($result['allowed']);
        $this->assertEquals('saml', $result['auth_method']);

        // LDAP user
        $result = $this->passwordResetService->canUserResetPassword('ldap@example.com');
        $this->assertFalse($result['allowed']);
        $this->assertEquals('ldap', $result['auth_method']);

        // SQL user - should be allowed
        $result = $this->passwordResetService->canUserResetPassword('sql@example.com');
        $this->assertTrue($result['allowed']);
        $this->assertArrayNotHasKey('auth_method', $result);

        // Non-existent user - should return allowed (timing safe)
        $result = $this->passwordResetService->canUserResetPassword('nonexistent@example.com');
        $this->assertTrue($result['allowed']);
    }

    /**
     * A shared email must stay neutral in the preflight even when the first row is
     * an external-auth account, so it doesn't leak the auth method and is left for
     * createResetRequest() to decline.
     */
    public function testCanUserResetPasswordNeutralForSharedEmail(): void
    {
        $email = 'shared@example.com';

        $this->userRepository->method('getUserByEmail')
            ->with($email)
            ->willReturn(['id' => 1, 'email' => $email, 'auth_method' => 'oidc']);

        $this->userRepository->method('countUsersByEmail')
            ->with($email)
            ->willReturn(2);

        $result = $this->passwordResetService->canUserResetPassword($email);

        $this->assertTrue($result['allowed']);
        $this->assertArrayNotHasKey('auth_method', $result);
    }
}
