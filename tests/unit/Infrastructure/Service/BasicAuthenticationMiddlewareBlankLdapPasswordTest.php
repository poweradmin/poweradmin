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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * An empty Basic Auth password must be refused before any directory bind: binding a
 * user DN with a zero-length password is an unauthenticated bind, which Active
 * Directory and other servers report as a success.
 */
class BasicAuthenticationMiddlewareBlankLdapPasswordTest extends TestCase
{
    private function ldapAuth(string $password): bool
    {
        // Reading any LDAP setting means the method went on towards a bind
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, mixed $default = null): mixed {
                if ($group === 'ldap') {
                    throw new RuntimeException("LDAP setting {$key} read");
                }
                return $default;
            }
        );

        $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BasicAuthenticationMiddleware::class, 'config'))->setValue($middleware, $config);

        $method = new ReflectionMethod(BasicAuthenticationMiddleware::class, 'ldapAuthenticatorApiAuth');
        return $method->invoke($middleware, 1, 'ldapuser', $password);
    }

    public function testEmptyPasswordIsRefusedBeforeTheDirectoryIsContacted(): void
    {
        $this->assertFalse($this->ldapAuth(''));
    }

    public function testNonEmptyPasswordStillGoesToTheDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->ldapAuth('secret');
    }
}
