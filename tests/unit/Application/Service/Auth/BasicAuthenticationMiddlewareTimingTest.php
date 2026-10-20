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
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TestHelpers\FakeConfiguration;

/**
 * A missing, disabled or hashless account must cost one full hash check before
 * Basic Auth refuses it, or the response time tells the caller which accounts exist.
 */
class BasicAuthenticationMiddlewareTimingTest extends TestCase
{
    private const DUMMY_HASH = '$2y$04$dummy';

    private function middleware(UserAuthenticationService $authService, ?UserRepositoryInterface $repository = null): BasicAuthenticationMiddleware
    {
        $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
        $attempts = $this->createMock(LoginAttemptService::class);
        $attempts->method('isAccountLocked')->willReturn(false);

        foreach (
            [
                'config' => new FakeConfiguration([]),
                'authService' => $authService,
                'userRepository' => $repository,
                'loginAttemptService' => $attempts,
            ] as $name => $value
        ) {
            if ($value !== null) {
                (new ReflectionProperty(BasicAuthenticationMiddleware::class, $name))->setValue($middleware, $value);
            }
        }
        return $middleware;
    }

    private function authServiceExpectingDummyCheck(string $password): UserAuthenticationService
    {
        $authService = $this->createMock(UserAuthenticationService::class);
        $authService->method('dummyVerificationHash')->willReturn(self::DUMMY_HASH);
        $authService->expects($this->once())
            ->method('verifyPassword')
            ->with($password, self::DUMMY_HASH)
            ->willReturn(false);
        return $authService;
    }

    private function authenticate(BasicAuthenticationMiddleware $middleware, string $username, string $password): int
    {
        return (new ReflectionMethod(BasicAuthenticationMiddleware::class, 'authenticateAndGetUserId'))
            ->invoke($middleware, $username, $password);
    }

    public function testUnknownUsernameRunsOneHashCheck(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('findByUsername')->willReturn(null);

        $middleware = $this->middleware($this->authServiceExpectingDummyCheck('guess'), $repository);

        $this->assertSame(0, $this->authenticate($middleware, 'nobody', 'guess'));
    }

    public function testDisabledAccountRunsOneHashCheck(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('findByUsername')->willReturn(new User(5, '$2y$04$stored', false));
        $repository->method('findBasicAuthUser')->willReturn(null);

        $middleware = $this->middleware($this->authServiceExpectingDummyCheck('guess'), $repository);

        $this->assertSame(0, $this->authenticate($middleware, 'disabled', 'guess'));
    }

    public function testEmptyHashRunsOneHashCheck(): void
    {
        $middleware = $this->middleware($this->authServiceExpectingDummyCheck('guess'));

        $result = (new ReflectionMethod(BasicAuthenticationMiddleware::class, 'sqlAuthenticatorApiAuth'))
            ->invoke($middleware, new User(1, '', false), 'guess');

        $this->assertFalse($result);
    }
}
