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
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionClass;
use ReflectionMethod;

/**
 * Basic auth identifies the caller for one API request only. Writing the user into
 * $_SESSION would hand it to whatever cookie session came along with the request.
 */
class BasicAuthenticationMiddlewareSessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['userid' => 7, 'auth_used' => 'oidc'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testASuccessfulLoginLeavesTheSessionAlone(): void
    {
        $middleware = (new ReflectionClass(BasicAuthenticationMiddleware::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BasicAuthenticationMiddleware::class, 'onAuthSuccess');

        $this->assertSame(42, $method->invoke($middleware, 42));
        $this->assertSame(['userid' => 7, 'auth_used' => 'oidc'], $_SESSION);
    }
}
