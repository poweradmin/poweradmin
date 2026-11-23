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
 */

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\BasicAuthenticationMiddleware;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TestHelpers\FakeConfiguration;

/**
 * A refused Basic auth request writes the failed sign-in line the web login writes,
 * so the documented fail2ban filter counts API guessing too.
 */
class BasicAuthenticationMiddlewareLoginLogTest extends TestCase
{
    private AuditLoggerInterface&MockObject $audit;

    protected function setUp(): void
    {
        $this->audit = $this->createMock(AuditLoggerInterface::class);
    }

    private function authenticate(?UserRepositoryInterface $repository, bool $locked = false, bool $passwordOk = false, array $config = [], string $password = 'guess', ?int $expectedAttempts = null): int
    {
        $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
        $attempts = $this->createMock(LoginAttemptService::class);
        if ($expectedAttempts !== null) {
            $attempts->expects($this->exactly($expectedAttempts))->method('recordAttempt');
        }
        $attempts->method('isAccountLocked')->willReturn($locked);
        $authService = $this->createMock(UserAuthenticationService::class);
        $authService->method('dummyVerificationHash')->willReturn('$2y$04$dummy');
        $authService->method('verifyPassword')->willReturn($passwordOk);

        foreach (
            [
                'config' => new FakeConfiguration($config),
                'authService' => $authService,
                'userRepository' => $repository,
                'loginAttemptService' => $attempts,
                'audit' => $this->audit,
            ] as $name => $value
        ) {
            (new ReflectionProperty(BasicAuthenticationMiddleware::class, $name))->setValue($middleware, $value);
        }

        return (new ReflectionMethod(BasicAuthenticationMiddleware::class, 'authenticateAndGetUserId'))
            ->invoke($middleware, 'alice', $password);
    }

    private function repository(bool $known, ?array $basicUser): UserRepositoryInterface
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('findByUsername')->willReturn($known ? new User(5, '$2y$04$stored', false) : null);
        $repository->method('findBasicAuthUser')->willReturn($basicUser);

        return $repository;
    }

    public function testUnknownUserIsLoggedAsNoSuchUser(): void
    {
        $this->audit->expects($this->once())->method('logLoginFailedFor')->with('alice', AuthMethod::SQL, LoginFailureReason::NO_SUCH_USER);

        $this->assertSame(0, $this->authenticate($this->repository(false, null)));
    }

    public function testLockedAccountIsLoggedAsLocked(): void
    {
        $this->audit->expects($this->once())->method('logLoginLockedFor')->with('alice', AuthMethod::SQL);
        $this->audit->expects($this->never())->method('logLoginFailedFor');

        $this->assertSame(0, $this->authenticate($this->repository(true, null), locked: true));
    }

    public function testDisabledAccountIsLoggedAsDisabled(): void
    {
        $this->audit->expects($this->once())->method('logLoginFailedFor')->with('alice', AuthMethod::SQL, LoginFailureReason::ACCOUNT_DISABLED);

        $this->assertSame(0, $this->authenticate($this->repository(true, null)));
    }

    public function testWrongPasswordIsLoggedAndASuccessIsNot(): void
    {
        $row = ['id' => 5, 'password' => '$2y$04$stored', 'use_ldap' => 0];
        $this->audit->expects($this->once())->method('logLoginFailedFor')->with('alice', AuthMethod::SQL, LoginFailureReason::WRONG_PASSWORD);
        $this->assertSame(0, $this->authenticate($this->repository(true, $row)));

        $this->setUp();
        $this->audit->expects($this->never())->method('logLoginFailedFor');
        $this->assertSame(5, $this->authenticate($this->repository(true, $row), passwordOk: true));
    }

    /** @return array<string, mixed> */
    private function ldapConfig(string $uri): array
    {
        return ['ldap' => ['enabled' => true, 'uri' => $uri, 'base_dn' => 'dc=example,dc=com', 'bind_dn' => 'cn=svc', 'bind_password' => 'x']];
    }

    private function ldapRepository(): UserRepositoryInterface
    {
        return $this->repository(true, ['id' => 5, 'password' => 'LDAP_USER', 'use_ldap' => 1]);
    }

    public function testLdapWrongPasswordIsLoggedAsLoginFailed(): void
    {
        $this->audit->expects($this->once())->method('logLoginFailedFor')->with('alice', AuthMethod::LDAP, LoginFailureReason::WRONG_PASSWORD);
        $this->audit->expects($this->never())->method('logLoginErrorFor');

        // A bad credential still counts towards lockout
        $this->assertSame(0, $this->authenticate($this->ldapRepository(), config: $this->ldapConfig('ldap://127.0.0.1:1'), password: '', expectedAttempts: 1));
    }

    /** A directory outage must not read as a bad credential, or the jail bans every LDAP client */
    public function testLdapOutageIsALoginErrorAndNeverALoginFailed(): void
    {
        $this->audit->expects($this->never())->method('logLoginFailedFor');
        $this->audit->expects($this->once())->method('logLoginErrorFor')
            ->with('alice', AuthMethod::LDAP, $this->logicalOr(
                $this->identicalTo(LoginFailureReason::LDAP_CONNECT_FAILED),
                $this->identicalTo(LoginFailureReason::LDAP_BIND_FAILED)
            ));

        $this->assertSame(0, $this->authenticate($this->ldapRepository(), config: $this->ldapConfig('ldap://127.0.0.1:1'), password: 'secret', expectedAttempts: 0));
    }

    public function testLdapMisconfigurationIsALoginErrorNotALoginFailed(): void
    {
        $this->audit->expects($this->never())->method('logLoginFailedFor');
        $this->audit->expects($this->once())->method('logLoginErrorFor')->with('alice', AuthMethod::LDAP, LoginFailureReason::LDAP_CONNECT_FAILED);

        $this->assertSame(0, $this->authenticate($this->ldapRepository(), config: ['ldap' => ['enabled' => true]], password: 'secret', expectedAttempts: 0));
    }

    public function testALockedUnknownNameIsLoggedAsLocked(): void
    {
        $this->audit->expects($this->once())->method('logLoginLockedFor')->with('alice', AuthMethod::SQL);
        $this->audit->expects($this->never())->method('logLoginFailedFor');

        $this->assertSame(0, $this->authenticate($this->repository(false, null), locked: true));
    }
}
