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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\User;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A provisioned user (LDAP/OIDC/SAML) has an empty local password hash. Basic SQL
 * auth against an empty hash must fail cleanly (return false) instead of throwing
 * "Unable to determine hash algorithm", which surfaced a 500 and skipped lockout
 * recording (audit M28).
 */
class BasicAuthenticationMiddlewareEmptyHashTest extends TestCase
{
    private function middleware(): BasicAuthenticationMiddleware
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnMap([
            ['security', 'password_encryption', 'bcrypt', 'bcrypt'],
            ['security', 'password_cost', 12, 12],
        ]);

        $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
        $configProp = new ReflectionProperty(BasicAuthenticationMiddleware::class, 'config');
        $configProp->setAccessible(true);
        $configProp->setValue($middleware, $config);
        return $middleware;
    }

    private function sqlAuth(User $user, string $password): bool
    {
        $method = new ReflectionMethod(BasicAuthenticationMiddleware::class, 'sqlAuthenticatorApiAuth');
        $method->setAccessible(true);
        return $method->invoke($this->middleware(), $user, $password);
    }

    public function testEmptyHashFailsWithoutThrowing(): void
    {
        $this->assertFalse($this->sqlAuth(new User(1, '', false), 'any-password'));
    }

    public function testValidHashStillVerifies(): void
    {
        $hash = password_hash('correct-horse', PASSWORD_BCRYPT);
        $this->assertTrue($this->sqlAuth(new User(1, $hash, false), 'correct-horse'));
        $this->assertFalse($this->sqlAuth(new User(1, $hash, false), 'wrong'));
    }
}
